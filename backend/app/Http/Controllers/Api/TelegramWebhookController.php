<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RateAlertSubscriptionService;
use App\Services\RateDigestService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Принимает update'ы Telegram Bot API (POST /api/telegram/webhook,
 * защищено middleware `telegram.webhook` — секрет проверен ДО контроллера).
 *
 * Обрабатываем:
 *  - `callback_query` — тапы inline-кнопок мастера настройки алерта;
 *  - `message` с `/start <token>` — регистрация пользователя (токен из Cache);
 *  - `message` с текстом кнопок reply-меню — курс валют, уведомления,
 *    подбор кредита/депозита;
 *  - `message` с произвольным текстом, когда для чата активен мастер на шаге
 *    "порог" — интерпретируется как введённое значение порога;
 *  - всё прочее (в т.ч. голый `/start`) — приветствие + меню.
 *
 * Always 200/204 к Telegram (кроме secret-mismatch — это 403 в middleware),
 * иначе Telegram будет ретраить update.
 */
class TelegramWebhookController extends Controller
{
    private const BTN_RATES = '💱 Курс валют';
    private const BTN_ALERTS = '⚙️ Настроить уведомления';
    private const BTN_CREDIT = '🏦 Подобрать кредит';
    private const BTN_DEPOSIT = '💰 Подобрать депозит';
    private const BTN_PROFILE = '👤 Профиль';

    /** Мастер настройки алерта живёт в Cache — Telegram-боты без состояния между запросами. */
    private const WIZARD_TTL_MINUTES = 10;

    public function handle(Request $request, TelegramService $telegram, RateDigestService $digest): Response
    {
        if (is_array($request->input('callback_query'))) {
            return $this->handleCallbackQuery($request, $telegram, $digest);
        }

        $message = $request->input('message');

        if (! is_array($message)) {
            return response()->noContent();
        }

        $text = is_string($message['text'] ?? null) ? trim($message['text']) : null;
        $from = $message['from'] ?? [];
        $telegramId = is_int($from['id'] ?? null) ? (int) $from['id'] : null;
        $chatId = $message['chat']['id'] ?? $telegramId;

        if ($text === null || ! is_int($chatId)) {
            return response()->noContent();
        }

        if (preg_match('/^\/start\s+(\S+)/', $text, $matches)) {
            $this->handleStart($telegram, $matches[1], $message, (int) $chatId);

            return response()->noContent();
        }

        // Мастер на шаге "порог" ждёт свободный текст (число) — перехватываем
        // ДО обычного меню, иначе введённое число попадёт в match($text) ниже.
        $wizardState = Cache::get($this->wizardCacheKey((int) $chatId));

        if (is_array($wizardState) && ($wizardState['step'] ?? null) === 'threshold' && $telegramId !== null) {
            $this->handleWizardThresholdReply($telegram, $digest, (int) $chatId, $telegramId, $wizardState, $text);

            return response()->noContent();
        }

        match ($text) {
            self::BTN_RATES => $this->sendRates($telegram, $digest, (int) $chatId),
            self::BTN_ALERTS => $this->startAlertWizard($telegram, $digest, (int) $chatId, $telegramId),
            self::BTN_CREDIT => $this->sendCatalog($telegram, (int) $chatId, 'credit'),
            self::BTN_DEPOSIT => $this->sendCatalog($telegram, (int) $chatId, 'deposit'),
            self::BTN_PROFILE => $this->sendProfileLink($telegram, (int) $chatId, $telegramId),
            default => $this->sendGreeting($telegram, (int) $chatId),
        };

        return response()->noContent();
    }

    private function handleStart(TelegramService $telegram, string $token, array $message, int $chatId): void
    {
        if (! Cache::pull("telegram_subscribe:{$token}")) {
            Log::warning('Telegram /start with unknown or expired subscribe token.', ['token' => $token]);
            $this->sendGreeting($telegram, $chatId);

            return;
        }

        $from = $message['from'] ?? [];
        $telegramId = $from['id'] ?? null;

        if (! is_int($telegramId)) {
            Log::error('Telegram /start message missing from.id.', ['payload' => $message]);

            return;
        }

        $username = $from['username'] ?? null;
        $name = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? ''));
        $name = $name !== '' ? $name : ($username ?? 'Telegram user');

        $user = User::query()->where('telegram_id', $telegramId)->first() ?? new User(['telegram_id' => $telegramId]);
        $user->name = $name;
        $user->telegram_username = $username;

        if (empty($user->api_token)) {
            $user->api_token = Str::random(64);
        }

        $user->save();

        // Сообщение 1: подтверждение + меню (настройка уведомлений — прямо в боте).
        $telegram->sendMessage(
            $chatId,
            "Готово! Буду присылать уведомления о курсе валют.\n\n"
            .'Нажмите «'.self::BTN_ALERTS.'» в меню, чтобы настроить валюту и порог.',
            $this->menuKeyboard(),
        );

        // Сообщение 2: мягкий инвайт в канал (best-effort, не блокирует).
        $invite = config('services.telegram.channel_invite_link');

        if (! empty($invite)) {
            $telegram->sendMessage(
                $chatId,
                "Разбираем курсы, банки и финансовый рынок Таджикистана в нашем канале — подпишись, если интересно:\n{$invite}",
            );
        }
    }

    private function sendRates(TelegramService $telegram, RateDigestService $digest, int $chatId): void
    {
        $this->sendRateCategory($telegram, $digest, $chatId, 'cash');
    }

    /** Основной вид: MAIN_CURRENCIES (USD/EUR/RUB) с названиями банков + переключатели. */
    private function sendRateCategory(TelegramService $telegram, RateDigestService $digest, int $chatId, string $category): void
    {
        $otherCategory = $category === 'cash' ? 'transfer' : 'cash';

        $telegram->sendMessage(
            $chatId,
            $digest->botRateSummary($category, RateDigestService::MAIN_CURRENCIES),
            [
                'inline_keyboard' => [
                    [
                        ['text' => 'Другие валюты', 'callback_data' => "rt:more:{$category}"],
                        ['text' => $digest->categoryLabel($otherCategory), 'callback_data' => "rt:cat:{$otherCategory}"],
                    ],
                    [['text' => 'Все курсы на сайте', 'url' => $this->frontend('/kurs-valyut')]],
                ],
            ],
            true,
            'HTML',
        );
    }

    /** По кнопке "Другие валюты" — компактная таблица без банков. */
    private function sendOtherCurrencies(TelegramService $telegram, RateDigestService $digest, int $chatId, string $category): void
    {
        $telegram->sendMessage(
            $chatId,
            '💱 <b>'.$digest->categoryLabel($category)." — другие валюты</b>\n".$digest->botOtherCurrenciesTable($category),
            null,
            true,
            'HTML',
        );
    }

    private function sendProfileLink(TelegramService $telegram, int $chatId, ?int $telegramId): void
    {
        $user = $telegramId !== null
            ? User::query()->where('telegram_id', $telegramId)->first()
            : null;

        if ($user === null || empty($user->api_token)) {
            $this->sendNotSubscribedPrompt($telegram, $chatId);

            return;
        }

        $telegram->sendMessage(
            $chatId,
            'Ваш профиль на сайте — имя, телефон и список уведомлений:',
            $this->linkButton('Открыть профиль', $this->profileUrl($user)),
        );
    }

    private function sendNotSubscribedPrompt(TelegramService $telegram, int $chatId): void
    {
        $telegram->sendMessage(
            $chatId,
            'Похоже, вы ещё не подписаны. Оформите подписку на уведомления на сайте:',
            $this->linkButton('Открыть курсы', $this->frontend('/kurs-valyut')),
        );
    }

    private function sendCatalog(TelegramService $telegram, int $chatId, string $category): void
    {
        [$text, $button, $path] = $category === 'credit'
            ? ['Подбор кредита — сравните ставки банков Таджикистана и оставьте заявку на сайте.', 'Открыть кредиты', '/credit']
            : ['Подбор депозита — сравните доходность вкладов банков Таджикистана на сайте.', 'Открыть депозиты', '/deposit'];

        $telegram->sendMessage($chatId, $text, $this->linkButton($button, $this->frontend($path)));
    }

    private function sendGreeting(TelegramService $telegram, int $chatId): void
    {
        $telegram->sendMessage(
            $chatId,
            "Sravni.tj — курсы валют и подбор банковских продуктов.\n\n"
            .'Жми «'.self::BTN_ALERTS.'» и выбери валюты, курс которых тебе интересен.',
            $this->menuKeyboard(),
        );
    }

    /**
     * Шаг 1 мастера: пользователь уже зарегистрирован (нашли по telegram_id) →
     * список валют, реально котируемых банками, инлайн-кнопками.
     */
    private function startAlertWizard(TelegramService $telegram, RateDigestService $digest, int $chatId, ?int $telegramId): void
    {
        $user = $telegramId !== null
            ? User::query()->where('telegram_id', $telegramId)->first()
            : null;

        if ($user === null || empty($user->api_token)) {
            $this->sendNotSubscribedPrompt($telegram, $chatId);

            return;
        }

        if (app(RateAlertSubscriptionService::class)->hasReachedLimit($user)) {
            $telegram->sendMessage(
                $chatId,
                'У вас уже настроено максимум уведомлений (3). Управляйте ими в профиле на сайте:',
                $this->linkButton('Открыть профиль', $this->profileUrl($user)),
            );

            return;
        }

        $currencies = $digest->availableCurrencies('cash');

        if ($currencies === []) {
            $telegram->sendMessage($chatId, 'Сейчас нет данных по курсам — попробуйте позже.');

            return;
        }

        Cache::put($this->wizardCacheKey($chatId), ['step' => 'currency'], now()->addMinutes(self::WIZARD_TTL_MINUTES));

        $telegram->sendMessage($chatId, 'Выберите валюту для уведомления:', $this->currencyKeyboard($currencies));
    }

    private function handleCallbackQuery(Request $request, TelegramService $telegram, RateDigestService $digest): Response
    {
        $callback = $request->input('callback_query');
        $callbackId = $callback['id'] ?? null;
        $data = $callback['data'] ?? null;
        $chatId = $callback['message']['chat']['id'] ?? null;

        if (! is_string($callbackId) || ! is_string($data) || ! is_int($chatId)) {
            return response()->noContent();
        }

        // Обязательный ack — иначе кнопка у пользователя "крутится".
        $telegram->answerCallbackQuery($callbackId);

        // Кнопки сводки курсов (rt:) — без состояния, не завязаны на мастер алерта.
        if (str_starts_with($data, 'rt:more:')) {
            $this->sendOtherCurrencies($telegram, $digest, $chatId, substr($data, 8));

            return response()->noContent();
        }

        if (str_starts_with($data, 'rt:cat:')) {
            $this->sendRateCategory($telegram, $digest, $chatId, substr($data, 7));

            return response()->noContent();
        }

        $state = Cache::get($this->wizardCacheKey($chatId));

        if (! is_array($state)) {
            return response()->noContent();
        }

        if (($state['step'] ?? null) === 'currency' && str_starts_with($data, 'aw:c:')) {
            $this->wizardCurrencyChosen($telegram, $chatId, substr($data, 5));
        } elseif (($state['step'] ?? null) === 'intent' && str_starts_with($data, 'aw:i:')) {
            $this->wizardIntentChosen($telegram, $digest, $chatId, $state, substr($data, 5));
        }

        return response()->noContent();
    }

    /** Шаг 2: валюта выбрана → спросить намерение клиента (купить/продать). */
    private function wizardCurrencyChosen(TelegramService $telegram, int $chatId, string $currency): void
    {
        Cache::put(
            $this->wizardCacheKey($chatId),
            ['step' => 'intent', 'currency' => $currency],
            now()->addMinutes(self::WIZARD_TTL_MINUTES),
        );

        $telegram->sendMessage($chatId, "Валюта: {$currency}. Что вы хотите сделать?", $this->intentKeyboard());
    }

    /**
     * Шаг 3: намерение клиента → сторона курса (op) + направление (direction).
     * "Хочу купить" — сравниваем с курсом продажи банка (sell), уведомляем,
     * когда стало ДЕШЕВЛЕ порога (below). "Хочу продать" — сравниваем с
     * курсом покупки банка (buy), уведомляем, когда стало ВЫГОДНЕЕ порога
     * (above).
     */
    private function wizardIntentChosen(TelegramService $telegram, RateDigestService $digest, int $chatId, array $state, string $intent): void
    {
        [$op, $direction] = $intent === 'buy' ? ['sell', 'below'] : ['buy', 'above'];
        $currency = (string) ($state['currency'] ?? '');

        Cache::put(
            $this->wizardCacheKey($chatId),
            ['step' => 'threshold', 'currency' => $currency, 'op' => $op, 'direction' => $direction],
            now()->addMinutes(self::WIZARD_TTL_MINUTES),
        );

        $reference = $this->referenceRate($digest, $currency, $op, $direction);

        if ($reference === null) {
            $telegram->sendMessage($chatId, "Сейчас нет данных по курсу {$currency}. Введите порог вручную (число больше 0):");

            return;
        }

        $refText = $digest->formatNumber($reference);
        $telegram->sendMessage(
            $chatId,
            "Текущий курс {$currency}: {$refText}.\n"
            ."Введите порог, при котором прислать уведомление — число в пределах ±50% от текущего курса (например {$refText}):",
        );
    }

    /** Шаг 4: свободный текст интерпретируется как введённый порог. */
    private function handleWizardThresholdReply(
        TelegramService $telegram,
        RateDigestService $digest,
        int $chatId,
        int $telegramId,
        array $state,
        string $text,
    ): void {
        $normalized = str_replace(',', '.', trim($text));
        $threshold = is_numeric($normalized) ? (float) $normalized : null;

        if ($threshold === null || $threshold <= 0) {
            $telegram->sendMessage($chatId, 'Не понял число. Введите порог как число, например 11.2.');

            return;
        }

        $currency = (string) ($state['currency'] ?? '');
        $op = (string) ($state['op'] ?? '');
        $direction = (string) ($state['direction'] ?? '');

        $reference = $this->referenceRate($digest, $currency, $op, $direction);

        if ($reference !== null) {
            $min = $reference * 0.5;
            $max = $reference * 1.5;

            if ($threshold < $min || $threshold > $max) {
                $telegram->sendMessage($chatId, sprintf(
                    'Порог должен быть в пределах %s–%s (±50%% от текущего курса %s). Введите ещё раз:',
                    $digest->formatNumber($min),
                    $digest->formatNumber($max),
                    $digest->formatNumber($reference),
                ));

                return;
            }
        }

        $user = User::query()->where('telegram_id', $telegramId)->first();

        if ($user === null) {
            Cache::forget($this->wizardCacheKey($chatId));

            return;
        }

        $alerts = app(RateAlertSubscriptionService::class);

        if ($alerts->hasReachedLimit($user)) {
            Cache::forget($this->wizardCacheKey($chatId));
            $telegram->sendMessage($chatId, 'У вас уже настроено максимум уведомлений (3).', $this->menuKeyboard());

            return;
        }

        if ($alerts->isDuplicate($user, 'cash', $currency, $op, $direction)) {
            Cache::forget($this->wizardCacheKey($chatId));
            $telegram->sendMessage($chatId, 'Такое уведомление уже настроено.', $this->menuKeyboard());

            return;
        }

        $alerts->create($user, [
            'category' => 'cash',
            'currency' => $currency,
            'op' => $op,
            'direction' => $direction,
            'threshold' => $threshold,
        ]);

        Cache::forget($this->wizardCacheKey($chatId));

        $directionLabel = $direction === 'above' ? 'выше' : 'ниже';
        $telegram->sendMessage(
            $chatId,
            "Готово! Пришлю уведомление, когда курс {$currency} будет {$directionLabel} {$digest->formatNumber($threshold)}.",
            $this->menuKeyboard(),
        );
    }

    /** Текущий рыночный курс для сверки/валидации ±50% — та же логика, что и в DispatchRateAlerts. */
    private function referenceRate(RateDigestService $digest, string $currency, string $op, string $direction): ?float
    {
        if ($currency === '' || $op === '' || $direction === '') {
            return null;
        }

        $rows = $digest->latestRates('cash', $currency);
        $mode = $direction === 'above' ? 'max' : 'min';

        return $digest->extreme($rows, $op, $mode)['value'];
    }

    private function wizardCacheKey(int $chatId): string
    {
        return "alert_wizard:{$chatId}";
    }

    /**
     * @return array<string, mixed>
     */
    private function menuKeyboard(): array
    {
        return [
            'keyboard' => [
                [['text' => self::BTN_RATES], ['text' => self::BTN_ALERTS]],
                [['text' => self::BTN_CREDIT], ['text' => self::BTN_DEPOSIT]],
                [['text' => self::BTN_PROFILE]],
            ],
            'resize_keyboard' => true,
        ];
    }

    /**
     * @param  array<int, string>  $currencies
     * @return array<string, mixed>
     */
    /** По 4 кнопки в ряд — одним рядом на все валюты Telegram-клиент переносит непредсказуемо (часть уезжает за экран). */
    private function currencyKeyboard(array $currencies): array
    {
        $buttons = array_map(fn (string $c): array => ['text' => $c, 'callback_data' => "aw:c:{$c}"], $currencies);

        return ['inline_keyboard' => array_chunk($buttons, 4)];
    }

    /**
     * @return array<string, mixed>
     */
    private function intentKeyboard(): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => 'Хочу купить', 'callback_data' => 'aw:i:buy'],
                ['text' => 'Хочу продать', 'callback_data' => 'aw:i:sell'],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linkButton(string $label, string $url): array
    {
        return ['inline_keyboard' => [[['text' => $label, 'url' => $url]]]];
    }

    private function profileUrl(User $user): string
    {
        return $this->frontend("/profile?token={$user->api_token}");
    }

    private function frontend(string $path): string
    {
        return rtrim((string) config('services.telegram.frontend_url'), '/').$path;
    }
}
