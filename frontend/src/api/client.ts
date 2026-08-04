import type {
  BankListResponse,
  BankResponse,
  BankReviewCreateResponse,
  BankReviewListResponse,
  BankReviewRequest,
  BestRateQuery,
  BestRateResponse,
  LeadRequest,
  LeadResponse,
  ProductListResponse,
  ProductQuery,
  ProductResponse,
  RateListQuery,
  RateListResponse,
  TelegramSubscribeInitResponse,
} from '@/types/api'
import { WIRE_LOCALE, type Locale } from '@/types/api'
import { ApiError } from './errors'
import {
  mockCreateBankReview,
  mockGetBank,
  mockGetBankReviews,
  mockGetBanks,
  mockGetBestRate,
  mockGetProduct,
  mockGetProducts,
  mockGetRates,
  mockPostLead,
  mockTelegramSubscribeInit,
} from './mocks/handlers'

// Under Node SSR, prefer the private/internal backend URL (e.g. Railway's
// internal network hostname) over the public one baked into the client
// bundle — server-to-server calls skip the public internet and CORS
// entirely. `process.env` is read once at module load, which happens once
// per Node process — safe (not a per-request concurrency hazard) since this
// value is constant for the process's whole lifetime, unlike per-request
// state such as locale.
const SSR_API_BASE_URL = typeof process !== 'undefined' ? process.env?.SSR_API_BASE_URL : undefined
const API_BASE_URL = SSR_API_BASE_URL || import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api'
const USE_MOCKS = import.meta.env.VITE_USE_MOCKS === 'true'

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

async function request<T>(locale: Locale, path: string, init?: RequestInit): Promise<T> {
  let response: Response
  try {
    response = await fetch(`${API_BASE_URL}${path}`, {
      ...init,
      headers: {
        Accept: 'application/json',
        // Контракт бэкенда — ru|tg (docs/api/contracts.md); tj — только код фронта.
        'Accept-Language': WIRE_LOCALE[locale],
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

// Every method takes `locale` explicitly (no shared mutable state) — one Node
// process serves many concurrent SSR requests, each with its own locale.
export const api = {
  getProducts(locale: Locale, query: ProductQuery = {}): Promise<ProductListResponse> {
    if (USE_MOCKS) return mockGetProducts(query)
    const segment = CATEGORY_PATH[query.category ?? 'credit']
    const params = buildProductParams(query)
    const qs = params.toString()
    return request<ProductListResponse>(locale, `/products/${segment}${qs ? `?${qs}` : ''}`)
  },

  getProduct(locale: Locale, id: number): Promise<ProductResponse> {
    if (USE_MOCKS) return mockGetProduct(id)
    return request<ProductResponse>(locale, `/products/${id}`)
  },

  getBanks(locale: Locale): Promise<BankListResponse> {
    if (USE_MOCKS) return mockGetBanks()
    return request<BankListResponse>(locale, '/banks')
  },

  getBank(locale: Locale, id: number): Promise<BankResponse> {
    if (USE_MOCKS) return mockGetBank(id)
    return request<BankResponse>(locale, `/banks/${id}`)
  },

  getBestRate(locale: Locale, query: BestRateQuery): Promise<BestRateResponse> {
    if (USE_MOCKS) return mockGetBestRate(query)
    const params = new URLSearchParams({
      currency: query.currency,
      category: query.category,
      op: query.op,
    })
    return request<BestRateResponse>(locale, `/rates/best?${params.toString()}`)
  },

  createLead(locale: Locale, body: LeadRequest): Promise<LeadResponse> {
    if (USE_MOCKS) return mockPostLead(body)
    return request<LeadResponse>(locale, '/leads', {
      method: 'POST',
      body: JSON.stringify(body),
    })
  },

  getRates(locale: Locale, query: RateListQuery = {}): Promise<RateListResponse> {
    if (USE_MOCKS) return mockGetRates(query)
    const params = new URLSearchParams()
    if (query.currency) params.set('currency', query.currency)
    if (query.category) params.set('category', query.category)
    const qs = params.toString()
    return request<RateListResponse>(locale, `/rates${qs ? `?${qs}` : ''}`)
  },

  getBankReviews(locale: Locale, bankId: number, page = 1): Promise<BankReviewListResponse> {
    if (USE_MOCKS) return mockGetBankReviews(bankId, page)
    return request<BankReviewListResponse>(locale, `/banks/${bankId}/reviews?page=${page}`)
  },

  createBankReview(locale: Locale, bankId: number, body: BankReviewRequest): Promise<BankReviewCreateResponse> {
    if (USE_MOCKS) return mockCreateBankReview(bankId, body)
    return request<BankReviewCreateResponse>(locale, `/banks/${bankId}/reviews`, {
      method: 'POST',
      body: JSON.stringify(body),
    })
  },

  initTelegramSubscribe(locale: Locale): Promise<TelegramSubscribeInitResponse> {
    if (USE_MOCKS) return mockTelegramSubscribeInit()
    return request<TelegramSubscribeInitResponse>(locale, '/telegram/subscribe-init', { method: 'POST' })
  },
}

export { API_BASE_URL, USE_MOCKS }
