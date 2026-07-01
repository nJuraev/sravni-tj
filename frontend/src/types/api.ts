/**
 * Types mirror docs/api/contracts.md (FROZEN contract). Do not deviate.
 */

export type Category = 'credit' | 'deposit' | 'installment'
export type Currency = 'TJS' | 'USD' | 'EUR'
export type Locale = 'ru' | 'tg'

/** Product subcategory codes (frozen, see docs/api/contracts.md). */
export type CreditSubcategory =
  | 'consumer'
  | 'mortgage'
  | 'auto'
  | 'business'
  | 'agro'
  | 'education'
  | 'refinance'
  | 'pawn'
export type DepositSubcategory = 'term' | 'savings' | 'demand' | 'kids'
export type Subcategory = CreditSubcategory | DepositSubcategory | 'other'

export type FeatureKey =
  | 'online_application'
  | 'no_guarantor'
  | 'capitalization'
  | 'replenishment'

/** features object — unknown/missing keys are treated as false. */
export type ProductFeatures = Partial<Record<FeatureKey, boolean>>

/** A single cell of the tariff grid: rate × term × amount × currency. */
export interface RateTier {
  currency: Currency
  amount_from: number
  amount_to: number | null
  term_from: number
  term_to: number | null
  rate: number
}

export interface Bank {
  id: number
  name_ru: string
  name_tg: string | null
  is_partner: boolean
  website?: string | null
  phone?: string | null
  address_ru?: string | null
  address_tg?: string | null
  contact_email?: string | null
  /** Средний балл по одобренным отзывам; null/undefined, если оценок нет. */
  rating_avg?: number | null
  /** Число одобренных отзывов. */
  rating_count?: number
}

/** product.bank carries the same shape as a standalone bank. */
export type BankRef = Bank

export interface Product {
  id: number
  category: Category
  /** null for installment or when not classified by the parser. */
  subcategory: Subcategory | null
  /** «Особый» (аномальный) продукт — скрыт из выдачи, пока не запрошен. */
  is_special: boolean
  currency: Currency
  name_ru: string
  name_tg: string | null
  description_ru: string | null
  description_tg: string | null
  rate_min: number
  rate_max: number
  amount_min: number | null
  amount_max: number | null
  term_min: number | null
  term_max: number | null
  rate_tiers: RateTier[]
  features: ProductFeatures
  bank: BankRef
  parsed_at: string | null
}

export interface Pagination {
  page: number
  per_page: number
  total_items: number
  total_pages: number
}

export interface ProductListResponse {
  data: Product[]
  pagination: Pagination
}

export interface ProductResponse {
  data: Product
}

export interface BankListResponse {
  data: Bank[]
}

/**
 * Query params for the per-type catalog endpoints
 * (GET /api/products/{credits|deposits|installments}).
 * `category` is not sent — it selects the endpoint.
 */
export interface ProductQuery {
  /** Selects the endpoint; not serialized as a query param. */
  category?: Category
  subcategory?: Subcategory[]
  bank_id?: number[]
  currency?: Currency
  /** «Галочка особые»: подмешать аномальные (is_special) продукты. */
  special?: boolean
  amount_min?: number
  amount_max?: number
  term_min?: number
  term_max?: number
  rate_min?: number
  rate_max?: number
  features?: FeatureKey[]
  sort?: string
  page?: number
  per_page?: number
}

/** POST /api/leads request body. */
export interface LeadRequest {
  full_name: string
  phone: string
  product_id: number
  consent: boolean
}

export interface Lead {
  id: number
  product_id: number
  bank_id: number
  full_name: string
  phone: string
  consent: boolean
  created_at: string
}

export interface LeadResponse {
  data: Lead
  message: string
}

/** Unified error envelope (422 / 404 / 500). */
export interface ApiErrorBody {
  message: string
  errors?: Record<string, string[]>
}
