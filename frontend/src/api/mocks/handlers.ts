import type {
  BankListResponse,
  BankResponse,
  BestRateQuery,
  BestRateResponse,
  FeatureKey,
  LeadRequest,
  LeadResponse,
  Product,
  ProductListResponse,
  ProductQuery,
  ProductResponse,
} from '@/types/api'
import { ApiError } from '@/api/errors'
import { mockBanks, mockProducts, mockRates } from './fixtures'

/**
 * In-memory mock backend replicating contracts.md semantics:
 * intersection filters for amount/term/rate, AND-features, sort, pagination,
 * and lead validation (consent must be true).
 */

const SIMULATED_DELAY = 280

function delay<T>(value: T): Promise<T> {
  return new Promise((resolve) => setTimeout(() => resolve(value), SIMULATED_DELAY))
}

/** ranges [a, b] and [c, d] intersect; null upper bound = +infinity. */
function rangesIntersect(a: number, b: number | null, c?: number, d?: number): boolean {
  const aHigh = b ?? Number.POSITIVE_INFINITY
  const cLow = c ?? Number.NEGATIVE_INFINITY
  const dHigh = d ?? Number.POSITIVE_INFINITY
  return a <= dHigh && aHigh >= cLow
}

function hasAllFeatures(p: Product, features: FeatureKey[]): boolean {
  return features.every((f) => p.features[f] === true)
}

function sortProducts(list: Product[], sort: string): Product[] {
  const desc = sort.startsWith('-')
  const key = (desc ? sort.slice(1) : sort) as keyof Product
  const valid = ['rate_min', 'rate_max', 'amount_min', 'term_min', 'created_at']
  const field = valid.includes(key as string) ? key : 'rate_min'
  return [...list].sort((x, y) => {
    const xv = (x[field as keyof Product] as number) ?? 0
    const yv = (y[field as keyof Product] as number) ?? 0
    return desc ? yv - xv : xv - yv
  })
}

export async function mockGetProducts(query: ProductQuery): Promise<ProductListResponse> {
  let list = mockProducts.filter((p) => {
    if (query.category && p.category !== query.category) return false
    if (query.subcategory?.length && !(p.subcategory && query.subcategory.includes(p.subcategory)))
      return false
    if (query.bank_id?.length && !query.bank_id.includes(p.bank.id)) return false
    // «Особые» скрыты, пока не запрошены явно (?special=true).
    if (!query.special && p.is_special) return false
    if (query.currency && p.currency !== query.currency) return false
    if (
      (query.amount_min !== undefined || query.amount_max !== undefined) &&
      !rangesIntersect(p.amount_min ?? 0, p.amount_max, query.amount_min, query.amount_max)
    )
      return false
    if (
      (query.term_min !== undefined || query.term_max !== undefined) &&
      !rangesIntersect(p.term_min ?? 0, p.term_max, query.term_min, query.term_max)
    )
      return false
    if (
      (query.rate_min !== undefined || query.rate_max !== undefined) &&
      !rangesIntersect(p.rate_min, p.rate_max, query.rate_min, query.rate_max)
    )
      return false
    if (query.features?.length && !hasAllFeatures(p, query.features)) return false
    return true
  })

  list = sortProducts(list, query.sort ?? 'rate_min')

  const page = query.page ?? 1
  const perPage = query.per_page ?? 20
  const totalItems = list.length
  const totalPages = Math.ceil(totalItems / perPage)
  const start = (page - 1) * perPage
  const data = list.slice(start, start + perPage)

  return delay({
    data,
    pagination: { page, per_page: perPage, total_items: totalItems, total_pages: totalPages },
  })
}

export async function mockGetProduct(id: number): Promise<ProductResponse> {
  const product = mockProducts.find((p) => p.id === id)
  if (!product) {
    return Promise.reject(new ApiError(404, { message: 'Resource not found.' }))
  }
  return delay({ data: product })
}

export async function mockGetBanks(): Promise<BankListResponse> {
  return delay({ data: mockBanks })
}

export async function mockGetBank(id: number): Promise<BankResponse> {
  const bank = mockBanks.find((b) => b.id === id)
  if (!bank) {
    return Promise.reject(new ApiError(404, { message: 'Resource not found.' }))
  }
  return delay({ data: bank })
}

/**
 * Отражает RateController::best — op описывает операцию КЛИЕНТА:
 * buy (клиент покупает валюту) → лучший = минимальный sell среди банков;
 * sell (клиент продаёт валюту) → лучший = максимальный buy среди банков.
 */
export async function mockGetBestRate(query: BestRateQuery): Promise<BestRateResponse> {
  const currency = query.currency.toUpperCase()
  const candidates = mockRates.filter(
    (r) =>
      r.currency === currency &&
      r.category === query.category &&
      (query.op === 'buy' ? r.sell !== null : r.buy !== null),
  )
  if (candidates.length === 0) return delay({ data: null })

  const best =
    query.op === 'buy'
      ? candidates.reduce((a, b) => ((b.sell as number) < (a.sell as number) ? b : a))
      : candidates.reduce((a, b) => ((b.buy as number) > (a.buy as number) ? b : a))

  return delay({ data: best })
}

export async function mockPostLead(body: LeadRequest): Promise<LeadResponse> {
  const errors: Record<string, string[]> = {}

  if (!body.full_name || body.full_name.trim().length < 2) {
    errors.full_name = ['Поле ФИО обязательно.']
  }
  if (!body.phone || body.phone.trim().length < 5) {
    errors.phone = ['Поле телефон обязательно.']
  }
  const product = mockProducts.find((p) => p.id === body.product_id)
  if (!product) {
    errors.product_id = ['Выбранный продукт недоступен.']
  }
  if (body.consent !== true) {
    errors.consent = ['Необходимо согласие на обработку персональных данных.']
  }

  if (Object.keys(errors).length > 0) {
    return Promise.reject(new ApiError(422, { message: 'The given data was invalid.', errors }))
  }

  return delay({
    data: {
      id: Math.floor(Math.random() * 90000) + 1000,
      product_id: body.product_id,
      bank_id: product!.bank.id,
      full_name: body.full_name.trim(),
      phone: body.phone.replace(/[^\d+]/g, ''),
      consent: true,
      created_at: new Date().toISOString(),
    },
    message: 'Заявка принята.',
  })
}
