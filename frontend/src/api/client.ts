import type {
  BankListResponse,
  BankResponse,
  BestRateQuery,
  BestRateResponse,
  LeadRequest,
  LeadResponse,
  ProductListResponse,
  ProductQuery,
  ProductResponse,
} from '@/types/api'
import { WIRE_LOCALE, type Locale } from '@/types/api'
import { ApiError } from './errors'
import {
  mockGetBank,
  mockGetBanks,
  mockGetBestRate,
  mockGetProduct,
  mockGetProducts,
  mockPostLead,
} from './mocks/handlers'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api'
const USE_MOCKS = import.meta.env.VITE_USE_MOCKS === 'true'

/** Active locale, set by i18n; sent as Accept-Language for localized 422s. */
let activeLocale: Locale = 'ru'
export function setApiLocale(locale: Locale): void {
  activeLocale = locale
}

/** Serialize ProductQuery into URLSearchParams per the contract. */
export function buildProductParams(query: ProductQuery): URLSearchParams {
  const params = new URLSearchParams()
  const set = (k: string, v: string | number | undefined) => {
    if (v !== undefined && v !== null && v !== '') params.set(k, String(v))
  }
  // category не сериализуется — она выбирает эндпоинт.
  set('currency', query.currency)
  if (query.special) params.set('special', 'true')
  set('amount_min', query.amount_min)
  set('amount_max', query.amount_max)
  set('term_min', query.term_min)
  set('term_max', query.term_max)
  set('rate_min', query.rate_min)
  set('rate_max', query.rate_max)
  set('sort', query.sort)
  set('page', query.page)
  set('per_page', query.per_page)
  for (const f of query.features ?? []) params.append('features[]', f)
  for (const b of query.bank_id ?? []) params.append('bank_id[]', String(b))
  return params
}

/** Map product category → its catalog endpoint path segment. */
const CATEGORY_PATH: Record<NonNullable<ProductQuery['category']>, string> = {
  credit: 'credits',
  deposit: 'deposits',
  installment: 'installments',
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  let response: Response
  try {
    response = await fetch(`${API_BASE_URL}${path}`, {
      ...init,
      headers: {
        Accept: 'application/json',
        // Контракт бэкенда — ru|tg (docs/api/contracts.md); tj — только код фронта.
        'Accept-Language': WIRE_LOCALE[activeLocale],
        ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
        ...init?.headers,
      },
    })
  } catch {
    // No HTTP response — network failure.
    throw new ApiError(0, { message: 'Network error.' })
  }

  let body: unknown = null
  const text = await response.text()
  if (text) {
    try {
      body = JSON.parse(text)
    } catch {
      body = null
    }
  }

  if (!response.ok) {
    throw new ApiError(response.status, (body as never) ?? undefined)
  }
  return body as T
}

export const api = {
  getProducts(query: ProductQuery = {}): Promise<ProductListResponse> {
    if (USE_MOCKS) return mockGetProducts(query)
    const segment = CATEGORY_PATH[query.category ?? 'credit']
    const params = buildProductParams(query)
    const qs = params.toString()
    return request<ProductListResponse>(`/products/${segment}${qs ? `?${qs}` : ''}`)
  },

  getProduct(id: number): Promise<ProductResponse> {
    if (USE_MOCKS) return mockGetProduct(id)
    return request<ProductResponse>(`/products/${id}`)
  },

  getBanks(): Promise<BankListResponse> {
    if (USE_MOCKS) return mockGetBanks()
    return request<BankListResponse>('/banks')
  },

  getBank(id: number): Promise<BankResponse> {
    if (USE_MOCKS) return mockGetBank(id)
    return request<BankResponse>(`/banks/${id}`)
  },

  getBestRate(query: BestRateQuery): Promise<BestRateResponse> {
    if (USE_MOCKS) return mockGetBestRate(query)
    const params = new URLSearchParams({
      currency: query.currency,
      category: query.category,
      op: query.op,
    })
    return request<BestRateResponse>(`/rates/best?${params.toString()}`)
  },

  createLead(body: LeadRequest): Promise<LeadResponse> {
    if (USE_MOCKS) return mockPostLead(body)
    return request<LeadResponse>('/leads', {
      method: 'POST',
      body: JSON.stringify(body),
    })
  },
}

export { API_BASE_URL, USE_MOCKS }
