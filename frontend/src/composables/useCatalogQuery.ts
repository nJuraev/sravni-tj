import { computed } from 'vue'
import { useRoute, useRouter, type LocationQuery } from 'vue-router'
import type { Category, Currency, FeatureKey, ProductQuery, Subcategory } from '@/types/api'
import { FEATURE_KEYS, SUBCATEGORIES_BY_CATEGORY } from '@/composables/useProductDisplay'

const ALL_SUBCATEGORIES = Object.values(SUBCATEGORIES_BY_CATEGORY).flat()

/**
 * Two-way bridge between the URL query string and a typed ProductQuery,
 * following docs/api/contracts.md exactly:
 *  currency, amount_min/max, term_min/max, rate_min/max, features[], sort,
 *  page, per_page. `category` is owned by the route, not the query.
 *
 * Shareable links + working back button: every filter change is a router
 * push, so the URL is the single source of truth for catalog state.
 */

const CURRENCIES: Currency[] = ['TJS', 'USD', 'EUR']
const SORTS = ['rate_min', '-rate_max', 'amount_min', 'term_min', 'created_at', '-created_at']

/**
 * Дефолтная сортировка зависит от типа продукта:
 *  - кредиты — по ставке ВОЗР. (выгодное = меньший %);
 *  - депозиты — по ставке УБЫВ. (выгодное = больший %);
 *  - рассрочка — по сроку (ставки нет).
 * Совпадает с дефолтами серверных эндпоинтов; не сериализуется в URL.
 */
export const DEFAULT_SORT_BY_CATEGORY: Record<Category, string> = {
  credit: 'rate_min',
  deposit: '-rate_max',
  installment: 'term_min',
}
export const DEFAULT_SORT = DEFAULT_SORT_BY_CATEGORY.credit
export const DEFAULT_PER_PAGE = 12

function num(value: unknown): number | undefined {
  if (Array.isArray(value)) value = value[0]
  if (value === undefined || value === null || value === '') return undefined
  const n = Number(value)
  return Number.isFinite(n) ? n : undefined
}

function str(value: unknown): string | undefined {
  if (Array.isArray(value)) value = value[0]
  return typeof value === 'string' && value !== '' ? value : undefined
}

function parseFeatures(value: unknown): FeatureKey[] {
  const raw = Array.isArray(value) ? value : value != null ? [value] : []
  return raw.filter((v): v is FeatureKey => FEATURE_KEYS.includes(v as FeatureKey))
}

function parseSubcategories(value: unknown): Subcategory[] {
  const raw = Array.isArray(value) ? value : value != null ? [value] : []
  return raw.filter((v): v is Subcategory => ALL_SUBCATEGORIES.includes(v as Subcategory))
}

function parseBankIds(value: unknown): number[] {
  const raw = Array.isArray(value) ? value : value != null ? [value] : []
  return raw
    .map((v) => Number(v))
    .filter((n) => Number.isFinite(n) && n > 0)
}

/** Build a ProductQuery from the URL query for a given (route-owned) category. */
export function queryFromRoute(q: LocationQuery, category: Category): ProductQuery {
  const currency = str(q.currency)
  const sort = str(q.sort)
  const special = str(q.special)
  const result: ProductQuery = {
    category,
    subcategory: parseSubcategories(q['subcategory[]'] ?? q.subcategory),
    bank_id: parseBankIds(q['bank_id[]'] ?? q.bank_id),
    currency: currency && CURRENCIES.includes(currency as Currency) ? (currency as Currency) : undefined,
    special: special === 'true' || special === '1' ? true : undefined,
    amount_min: num(q.amount_min),
    amount_max: num(q.amount_max),
    term_min: num(q.term_min),
    term_max: num(q.term_max),
    rate_min: num(q.rate_min),
    rate_max: num(q.rate_max),
    features: parseFeatures(q['features[]'] ?? q.features),
    sort: sort && SORTS.includes(sort) ? sort : DEFAULT_SORT_BY_CATEGORY[category],
    page: num(q.page) ?? 1,
    per_page: num(q.per_page) ?? DEFAULT_PER_PAGE,
  }
  return result
}

/** Serialize a ProductQuery back into a URL query (omitting category & defaults). */
export function queryToRoute(query: ProductQuery): LocationQuery {
  const out: LocationQuery = {}
  const setNum = (k: string, v: number | undefined) => {
    if (v !== undefined) out[k] = String(v)
  }
  if (query.currency) out.currency = query.currency
  if (query.special) out.special = 'true'
  setNum('amount_min', query.amount_min)
  setNum('amount_max', query.amount_max)
  setNum('term_min', query.term_min)
  setNum('term_max', query.term_max)
  setNum('rate_min', query.rate_min)
  setNum('rate_max', query.rate_max)
  if (query.features?.length) out['features[]'] = query.features
  if (query.subcategory?.length) out['subcategory[]'] = query.subcategory
  if (query.bank_id?.length) out['bank_id[]'] = query.bank_id.map(String)
  const defaultSort = query.category ? DEFAULT_SORT_BY_CATEGORY[query.category] : DEFAULT_SORT
  if (query.sort && query.sort !== defaultSort) out.sort = query.sort
  if (query.page && query.page > 1) out.page = String(query.page)
  return out
}

export function useCatalogQuery(category: () => Category) {
  const route = useRoute()
  const router = useRouter()

  const query = computed<ProductQuery>(() => queryFromRoute(route.query, category()))

  /** Replace the URL query; resets to page 1 unless `keepPage` is set. */
  function apply(next: ProductQuery, keepPage = false): void {
    const merged: ProductQuery = { ...next }
    if (!keepPage) merged.page = 1
    router.push({ query: queryToRoute(merged) })
  }

  function setPage(page: number): void {
    router.push({ query: queryToRoute({ ...query.value, page }) })
  }

  function reset(): void {
    router.push({ query: {} })
  }

  return { query, apply, setPage, reset }
}
