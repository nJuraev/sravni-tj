import type { Category, Currency, Subcategory } from '@/types/api'

export type AdminRole = 'admin' | 'editor'
export type BankStatus = 'active' | 'inactive'
export type ProductStatus = 'active' | 'draft' | 'hidden' | 'outdated'
export type FeatureKey = 'online_application' | 'no_guarantor' | 'capitalization' | 'replenishable'

export interface AdminUser {
  id: number
  name: string
  email: string
  role: AdminRole
  is_active: boolean
  created_at: string | null
  updated_at: string | null
}

export interface AdminBank {
  id: number
  name_ru: string
  name_tg: string | null
  slug: string
  status: BankStatus
  is_partner: boolean
  contact_email: string | null
  website: string | null
  phone: string | null
  address_ru: string | null
  address_tg: string | null
  logo_url: string | null
  products_count?: number
  leads_count?: number
  created_at: string | null
  updated_at: string | null
}

export interface AdminProduct {
  id: number
  bank_id: number
  source_url_id: number | null
  external_key: string
  category: Category
  subcategory: Subcategory | null
  is_special: boolean
  status: ProductStatus
  currency: Currency
  name_ru: string | null
  name_tg: string | null
  description_ru: string | null
  description_tg: string | null
  rate_min: number | null
  rate_max: number | null
  amount_min: number | null
  amount_max: number | null
  term_min: number | null
  term_max: number | null
  features: Partial<Record<FeatureKey, boolean>>
  locked_fields: string[]
  bank?: AdminBank
  parsed_at: string | null
  created_at: string | null
  updated_at: string | null
}

export interface AdminLeadProductRef {
  id: number
  name_ru: string | null
  name_tg: string | null
  category: Category
  currency: Currency
}

export interface AdminLeadBankRef {
  id: number
  name_ru: string
  name_tg: string | null
}

export interface AdminLead {
  id: number
  full_name: string
  phone: string
  consent: boolean
  product_id: number | null
  bank_id: number
  product?: AdminLeadProductRef | null
  bank?: AdminLeadBankRef | null
  created_at: string | null
}

/** Laravel paginator envelope (meta + links). */
export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface LoginResponse {
  data: { token: string; user: AdminUser }
}

export type BankPayload = Omit<
  AdminBank,
  'id' | 'products_count' | 'leads_count' | 'created_at' | 'updated_at'
>

export type ProductPayload = Omit<
  AdminProduct,
  'id' | 'source_url_id' | 'external_key' | 'locked_fields' | 'bank' | 'parsed_at' | 'created_at' | 'updated_at'
>

export interface UserPayload {
  name: string
  email: string
  password?: string
  role: AdminRole
  is_active: boolean
}
