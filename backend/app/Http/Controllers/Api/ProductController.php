<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductIndexRequest;
use App\Http\Resources\ProductResource;
use App\Models\Bank;
use App\Models\Product;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Каталог продуктов (только чтение). Разнесён по типам продукта:
 *  - GET /api/products/credits       — кредиты, дефолт сорт по ставке ВОЗР. (выгодное = меньший %);
 *  - GET /api/products/deposits      — депозиты, дефолт сорт по ставке УБЫВ. (выгодное = больший %);
 *  - GET /api/products/installments  — рассрочка/исламское финансирование (ставки нет), сорт по сроку.
 *
 * Все выдачи проходят через scopeVisible(): status=active И банк status=active.
 * «Особые» (is_special=true) аномальные продукты по умолчанию СКРЫТЫ —
 * показываются только при ?special=true.
 *
 * Все фильтры строятся через Query Builder/Eloquent, без сырого SQL
 * (jsonb-операторы Postgres допускаются как часть билдера).
 */
class ProductController extends Controller
{
    private const SORT_FIELDS = ['rate_min', 'rate_max', 'amount_min', 'term_min', 'created_at'];

    /** Приоритет валюты как представителя группы (source_url_id) в каталоге: TJS первой. */
    private const CURRENCY_ORDER = ['TJS' => 0, 'USD' => 1, 'EUR' => 2];

    /**
     * Ключ группы «валютные варианты ОДНОГО продукта». source_url_id один
     * ко многим для array-split источников (ССБ/ICB/Арванд — один URL/API
     * отдаёт массив РАЗНЫХ продуктов), поэтому одного source_url_id мало —
     * без имени продукты одной страницы схлопывались бы в один (см. диагностику
     * ССБ: 5 разных кредитов с одним source_url_id вместо 5 карточек).
     */
    private function productGroupKey(Product $p): string
    {
        return $p->source_url_id !== null
            ? $p->source_url_id.'|'.($p->name_ru ?? $p->name_tg ?? '')
            : "single-{$p->id}";
    }

    /**
     * GET /api/products/credits — кредиты, по умолчанию от выгодных (меньшая ставка).
     */
    public function credits(ProductIndexRequest $request): JsonResponse
    {
        return $this->list($request, 'credit', 'rate_min');
    }

    /**
     * GET /api/products/deposits — депозиты, по умолчанию от выгодных (большая ставка).
     */
    public function deposits(ProductIndexRequest $request): JsonResponse
    {
        return $this->list($request, 'deposit', '-rate_max');
    }

    /**
     * GET /api/products/installments — рассрочка (ставки нет): сорт по сроку.
     */
    public function installments(ProductIndexRequest $request): JsonResponse
    {
        return $this->list($request, 'installment', 'term_min');
    }

    /**
     * GET /api/products/{id} — карточка. 404 для скрытого/неактивного/несуществующего.
     */
    public function show(Request $request, int $product): JsonResponse
    {
        /** @var Product $model */
        $model = Product::query()
            ->visible()
            ->with(['bank' => fn ($q) => $q->withReviewStats(), 'rates'])
            ->findOrFail($product);

        // Валютные варианты того же банковского продукта — для табов на странице
        // детали (product.variants в ProductResource). Продукт без source_url_id
        // (одиночный, не привязан к странице банка) — единственный вариант сам по себе.
        $model->setRelation('currencyVariants', $this->loadCurrencyVariants($model));

        return response()->json([
            'data' => new ProductResource($model),
        ]);
    }

    /**
     * @return Collection<int, Product>
     */
    private function loadCurrencyVariants(Product $model): Collection
    {
        $query = Product::query()->visible()->with('rates');

        if ($model->source_url_id !== null) {
            $query->where('source_url_id', $model->source_url_id);
        } else {
            $query->where('id', $model->id);
        }

        $rows = $query->get();

        if ($model->source_url_id !== null) {
            $rows = $rows->filter(fn (Product $p) => $this->productGroupKey($p) === $this->productGroupKey($model))->values();
        }

        // Данные парсера могут содержать несколько строк products на одну и ту
        // же валюту в рамках группы (source_url_id) — таб должен быть один на
        // валюту. Сам запрошенный продукт остаётся собой; для остальных валют —
        // самая свежая по parsed_at строка.
        return $rows
            ->groupBy('currency')
            ->map(fn (Collection $group) => $group->first(fn (Product $p) => $p->id === $model->id)
                ?? $group->sortByDesc(fn (Product $p) => $p->parsed_at)->first())
            ->values();
    }

    /**
     * Общая выдача типового каталога: фиксирует категорию, скрывает «особые»
     * (если не запрошены), применяет фильтры, дедуплицирует валютные дубли
     * одного банковского продукта (source_url_id) до одной карточки-представителя,
     * применяет сортировку и пагинацию.
     */
    private function list(ProductIndexRequest $request, string $category, string $defaultSort): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        $query = Product::query()
            ->visible()
            ->where('category', $category);

        $this->applySpecialVisibility($query, $request);
        $this->applyFilters($query, $request);

        // Дедуп ПОСЛЕ фильтров (если задан ?currency=, в группе и так остаётся
        // максимум одна валюта) — представитель группы, остальные валюты видны
        // как бейджи (attachAvailableCurrencies), а не отдельными карточками.
        $representativeIds = $this->dedupeToGroupRepresentatives($query);

        $page = (int) $request->integer('page', 1);

        if ($request->filled('sort')) {
            $finalQuery = Product::query()
                ->whereIn('id', $representativeIds)
                ->with(['bank' => fn ($q) => $q->withReviewStats(), 'rates']);
            $this->applySort($finalQuery, (string) $request->input('sort'));

            $paginator = $finalQuery->paginate(perPage: $perPage, page: $page);
        } else {
            $paginator = $this->paginateDiversifiedByDefault($representativeIds, $defaultSort, $perPage, $page);
        }

        $this->attachAvailableCurrencies($paginator->getCollection());

        return response()->json([
            'data' => ProductResource::collection($paginator->getCollection()),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total_items' => $paginator->total(),
                // lastPage() возвращает минимум 1; по контракту при пустом результате total_pages = 0.
                'total_pages' => $paginator->total() === 0 ? 0 : $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Группирует отфильтрованные строки по productGroupKey() (продукт без
     * source_url_id — одиночная группа сам с собой) и выбирает id
     * представителя на группу: приоритет TJS, иначе алфавитный порядок валюты.
     *
     * @param  Builder<Product>  $query
     * @return array<int, int>
     */
    private function dedupeToGroupRepresentatives(Builder $query): array
    {
        $rows = (clone $query)->get(['id', 'source_url_id', 'currency', 'name_ru', 'name_tg']);

        return $rows
            ->groupBy(fn (Product $p) => $this->productGroupKey($p))
            ->map(fn (Collection $group) => $group
                ->sortBy(fn (Product $p) => self::CURRENCY_ORDER[$p->currency] ?? 99)
                ->first()
                ->id)
            ->values()
            ->all();
    }

    /**
     * Проставляет каждой карточке страницы полный список валют её группы
     * (все visible-строки с тем же source_url_id) для бейджей в каталоге.
     * Игнорирует численные фильтры запроса (сумма/срок/ставка/валюта) —
     * бейджи показывают, что вообще доступно, а не что прошло фильтр.
     */
    private function attachAvailableCurrencies(Collection $products): void
    {
        $sourceUrlIds = $products->pluck('source_url_id')->filter()->unique()->values();

        $currenciesByGroup = Product::query()
            ->visible()
            ->whereIn('source_url_id', $sourceUrlIds)
            ->get(['id', 'source_url_id', 'currency', 'name_ru', 'name_tg'])
            ->groupBy(fn (Product $p) => $this->productGroupKey($p))
            ->map(fn (Collection $group) => $group->pluck('currency')->unique()
                ->sortBy(fn (string $c) => self::CURRENCY_ORDER[$c] ?? 99)
                ->values()
                ->all());

        foreach ($products as $product) {
            $currencies = $product->source_url_id !== null
                ? ($currenciesByGroup[$this->productGroupKey($product)] ?? [$product->currency])
                : [$product->currency];

            $product->setAttribute('available_currencies', $currencies);
        }
    }

    /**
     * «Особые» продукты скрыты по умолчанию; ?special=true подмешивает их к обычным.
     *
     * @param  Builder<Product>  $query
     */
    private function applySpecialVisibility(Builder $query, ProductIndexRequest $request): void
    {
        if (! $request->boolean('special')) {
            $query->where('is_special', false);
        }
    }

    /**
     * Применяет фильтры из запроса к билдеру (Query Builder, без raw SQL).
     * Категория задаётся эндпоинтом, а не клиентом, поэтому здесь не читается.
     *
     * @param  Builder<Product>  $query
     */
    private function applyFilters(Builder $query, ProductIndexRequest $request): void
    {
        if ($request->filled('currency')) {
            $query->where('currency', $request->string('currency'));
        }

        // Мультиселект подкатегорий (subcategory[]): продукты любой из выбранных.
        $subcategories = array_values(array_filter(
            (array) $request->input('subcategory', []),
            static fn ($v): bool => is_string($v) && $v !== '',
        ));
        if ($subcategories !== []) {
            $query->whereIn('subcategory', $subcategories);
        }

        // Мультиселект банков (bank_id[]): продукты любого из выбранных банков.
        $bankIds = array_values(array_filter(
            (array) $request->input('bank_id', []),
            static fn ($v): bool => is_numeric($v),
        ));
        if ($bankIds !== []) {
            $query->whereIn('bank_id', array_map('intval', $bankIds));
        }

        // Сумма — фильтр по ПЕРЕСЕЧЕНИЮ диапазонов; amount_max = NULL → +∞ (backend.md §5.3).
        $amountMin = $request->has('amount_min') ? (float) $request->input('amount_min') : null;
        $amountMax = $request->has('amount_max') ? (float) $request->input('amount_max') : null;

        if ($amountMin !== null) {
            // product.amount_max IS NULL OR product.amount_max >= requested amount_min
            $query->where(function (Builder $q) use ($amountMin): void {
                $q->whereNull('amount_max')->orWhere('amount_max', '>=', $amountMin);
            });
        }
        if ($amountMax !== null) {
            // product.amount_min IS NULL (нет нижней границы) → подходит под любой запрошенный максимум.
            $query->where(function (Builder $q) use ($amountMax): void {
                $q->whereNull('amount_min')->orWhere('amount_min', '<=', $amountMax);
            });
        }

        // Срок — пересечение; term_max = NULL → +∞.
        $termMin = $request->has('term_min') ? (int) $request->input('term_min') : null;
        $termMax = $request->has('term_max') ? (int) $request->input('term_max') : null;

        if ($termMin !== null) {
            $query->where(function (Builder $q) use ($termMin): void {
                $q->whereNull('term_max')->orWhere('term_max', '>=', $termMin);
            });
        }
        if ($termMax !== null) {
            $query->where(function (Builder $q) use ($termMax): void {
                $q->whereNull('term_min')->orWhere('term_min', '<=', $termMax);
            });
        }

        // Ставка (базовый режим): пересечение агрегатов [product.rate_min, rate_max]
        // с запрошенным [rate_min, rate_max] (backend.md §5.4).
        $rateMin = $request->has('rate_min') ? (float) $request->input('rate_min') : null;
        $rateMax = $request->has('rate_max') ? (float) $request->input('rate_max') : null;

        if ($rateMin !== null) {
            $query->where('rate_max', '>=', $rateMin);
        }
        if ($rateMax !== null) {
            $query->where('rate_min', '<=', $rateMax);
        }

        // Признаки: продукт обладает ВСЕМИ перечисленными (features jsonb содержит true
        // по каждому ключу). Контрактный ключ replenishment маппится в хранимый replenishable.
        /** @var array<int, string> $features */
        $features = (array) $request->input('features', []);
        foreach ($features as $contractKey) {
            $storageKey = $contractKey === 'replenishment' ? 'replenishable' : $contractKey;
            // jsonb-оператор @> через whereJsonContains — часть Query Builder, не raw SQL.
            $query->whereJsonContains('features->'.$storageKey, true);
        }

        // Точный режим по тарифной сетке: при currency + amount(*) + term(*) + rate(*)
        // дополнительно требуем существование подходящего тира (backend.md §5.4).
        $this->applyExactTierMode($query, $request, $amountMin, $amountMax, $termMin, $termMax, $rateMin, $rateMax);
    }

    /**
     * «Точный режим»: сужает выдачу до продуктов, у которых существует тир
     * product_rates, попадающий в заданные currency × сумму × срок × ставку.
     *
     * Активируется только когда переданы currency И (amount_min|amount_max)
     * И (term_min|term_max) И (rate_min|rate_max) — как зафиксировано контрактом.
     *
     * @param  Builder<Product>  $query
     */
    private function applyExactTierMode(
        Builder $query,
        ProductIndexRequest $request,
        ?float $amountMin,
        ?float $amountMax,
        ?int $termMin,
        ?int $termMax,
        ?float $rateMin,
        ?float $rateMax,
    ): void {
        $hasCurrency = $request->filled('currency');
        $hasAmount = $amountMin !== null || $amountMax !== null;
        $hasTerm = $termMin !== null || $termMax !== null;
        $hasRate = $rateMin !== null || $rateMax !== null;

        if (! ($hasCurrency && $hasAmount && $hasTerm && $hasRate)) {
            return;
        }

        // Запрошенная «точка» суммы/срока: берём минимально заданную границу
        // (нижнюю, если задана; иначе верхнюю) — это и есть искомое значение пользователя.
        $amount = $amountMin ?? $amountMax;
        $term = $termMin ?? $termMax;

        $query->whereHas('rates', function (Builder $tier) use ($amount, $term, $rateMin, $rateMax): void {
            // Сумма ∈ [tier.amount_from, tier.amount_to]; NULL границы = ±∞.
            if ($amount !== null) {
                $tier->where(function (Builder $q) use ($amount): void {
                    $q->whereNull('amount_min')->orWhere('amount_min', '<=', $amount);
                })->where(function (Builder $q) use ($amount): void {
                    $q->whereNull('amount_max')->orWhere('amount_max', '>=', $amount);
                });
            }

            // Срок ∈ [tier.term_from, tier.term_to]; NULL границы = ±∞.
            if ($term !== null) {
                $tier->where(function (Builder $q) use ($term): void {
                    $q->whereNull('term_min')->orWhere('term_min', '<=', $term);
                })->where(function (Builder $q) use ($term): void {
                    $q->whereNull('term_max')->orWhere('term_max', '>=', $term);
                });
            }

            // tier.rate ∈ [rate_min, rate_max].
            if ($rateMin !== null) {
                $tier->where('rate', '>=', $rateMin);
            }
            if ($rateMax !== null) {
                $tier->where('rate', '<=', $rateMax);
            }
        });
    }

    /**
     * Дефолтная выдача (когда клиент не передал ?sort): приоритет — ручной
     * коэффициент банка (banks.sort_coefficient, больше — выше), внутри банка —
     * исходный дефолт эндпоинта (ставка/срок). НО чистая SQL ORDER BY тут не
     * годится: если у банка с высоким коэффициентом много продуктов, они все
     * встанут подряд и «забьют» топ одним банком — ровно то, из-за чего эту
     * диверсификацию и добавили. Поэтому сортируем в PHP и раскладываем
     * жадным алгоритмом по банкам (roundRobinByBank), а страницу пагинации
     * нарезаем вручную. Явный ?sort= коэффициент игнорирует — applySort() ниже.
     *
     * @param  array<int, int>  $ids  id продуктов-представителей после дедупа/фильтров
     * @return LengthAwarePaginator<int, Product>
     */
    private function paginateDiversifiedByDefault(array $ids, string $defaultSort, int $perPage, int $page): LengthAwarePaginator
    {
        $direction = 'asc';
        $field = $defaultSort;

        if (str_starts_with($defaultSort, '-')) {
            $direction = 'desc';
            $field = substr($defaultSort, 1);
        }

        // Ранжируем ВСЕ отфильтрованные строки (не только страницу) по
        // [коэффициент банка DESC, дефолтное поле, id] — этот порядок и
        // определяет очередь каждого банка для диверсификации ниже.
        $rankedRows = Product::query()
            ->join('banks', 'banks.id', '=', 'products.bank_id')
            ->whereIn('products.id', $ids)
            ->orderByDesc('banks.sort_coefficient')
            ->orderBy('products.'.$field, $direction)
            ->orderBy('products.id')
            ->get(['products.id as id', 'products.bank_id as bank_id']);

        $orderedIds = $this->roundRobinByBank($rankedRows);
        $total = count($orderedIds);
        $pageIds = array_slice($orderedIds, ($page - 1) * $perPage, $perPage);

        $modelsById = Product::query()
            ->whereIn('id', $pageIds)
            ->with(['bank' => fn ($q) => $q->withReviewStats(), 'rates'])
            ->get()
            ->keyBy('id');

        $items = Collection::make($pageIds)->map(fn (int $id) => $modelsById[$id])->filter()->values();

        return new LengthAwarePaginator($items, $total, $perPage, $page);
    }

    /**
     * Раскладывает уже проранжированные строки по банкам (сохраняя порядок
     * внутри банка — по коэффициенту/ставке) и жадно собирает результат так,
     * чтобы соседние карточки не совпадали по банку: на каждом шаге берём
     * следующий id у банка с НАИБОЛЬШИМ остатком очереди среди тех, что не
     * совпадают с только что поставленным (тай-брейк — исходный приоритет
     * банка). Простой round-robin («по одному от каждого по кругу») этого не
     * гарантирует — при перекосе (например, 3 продукта у одного банка против
     * 1+1 у остальных) он всё равно сталкивает лишние продукты в хвосте;
     * жадный выбор «у кого больше всего осталось» — стандартное решение
     * задачи «расставить так, чтобы одинаковые не стояли рядом» и не
     * склеивает их, пока это математически возможно. Склейка неизбежна,
     * только когда один банк — единственный, у кого вообще остались продукты.
     *
     * @param  Collection<int, Product>  $rows
     * @return array<int, int>
     */
    private function roundRobinByBank(Collection $rows): array
    {
        $byBank = [];
        $bankPriority = [];
        foreach ($rows as $row) {
            if (! isset($bankPriority[$row->bank_id])) {
                $bankPriority[$row->bank_id] = count($bankPriority);
            }
            $byBank[$row->bank_id][] = $row->id;
        }

        $result = [];
        $lastBank = null;

        while ($byBank !== []) {
            $bestBank = null;
            $bestKey = null;

            foreach ($byBank as $bankId => $queue) {
                if ($bankId === $lastBank && count($byBank) > 1) {
                    continue;
                }

                // [-остаток, приоритет]: больше остаток — раньше; при равенстве — выше коэффициент.
                $key = [-count($queue), $bankPriority[$bankId]];
                if ($bestKey === null || $key < $bestKey) {
                    $bestKey = $key;
                    $bestBank = $bankId;
                }
            }

            $result[] = array_shift($byBank[$bestBank]);
            if ($byBank[$bestBank] === []) {
                unset($byBank[$bestBank]);
            }
            $lastBank = $bestBank;
        }

        return $result;
    }

    /**
     * Явная сортировка (?sort=field|-field, минус = по убыванию). Тай-брейк —
     * коэффициент банка (при равном значении поля выгоднее продукт банка с
     * более высоким приоритетом), затем id для детерминированной пагинации.
     *
     * @param  Builder<Product>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        $direction = 'asc';
        $field = $sort;

        if (str_starts_with($sort, '-')) {
            $direction = 'desc';
            $field = substr($sort, 1);
        }

        if (! in_array($field, self::SORT_FIELDS, true)) {
            $field = 'rate_min';
            $direction = 'asc';
        }

        $query->orderBy($field, $direction)
            ->orderByDesc(Bank::query()->select('sort_coefficient')->whereColumn('banks.id', 'products.bank_id'))
            ->orderBy('id', 'asc');
    }
}
