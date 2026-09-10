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
  /** Ручной приоритет банка в каталоге: дефолтная сортировка выдачи продуктов (больше — выше). */
  sort_coefficient: number
  contact_email: string | null
  website: string | null
  phone: string | null
  address_ru: string | null
  address_tg: string | null
  about_ru: string | null
  about_tg: string | null
  logo_url: string | null
  products_count?: number
  leads_count?: number
  // Когда парсер последний раз УСПЕШНО (>0 записанных строк) обновил
  // продукты/курсы этого банка; null — ни разу.
  products_updated_at: string | null
  rates_updated_at: string | null
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
  key_conditions_ru: string[] | null
  key_conditions_tg: string[] | null
  documents_ru: string[] | null
  documents_tg: string[] | null
  source_url: string | null
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
  | 'id'
  | 'products_count'
  | 'leads_count'
  | 'products_updated_at'
  | 'rates_updated_at'
  | 'created_at'
  | 'updated_at'
>

export type ProductPayload = Omit<
  AdminProduct,
  | 'id'
  | 'source_url_id'
  | 'external_key'
  | 'locked_fields'
  | 'bank'
  | 'parsed_at'
  | 'created_at'
  | 'updated_at'
  // Контент, извлекаемый только парсером — админка их не редактирует.
  | 'key_conditions_ru'
  | 'key_conditions_tg'
  | 'documents_ru'
  | 'documents_tg'
  | 'source_url'
>

export interface UserPayload {
  name: string
  email: string
  password?: string
  role: AdminRole
  is_active: boolean
}

export type FinancePostKind = 'generic' | 'product' | 'currency' | 'news'
export type FinancePostStatus = 'pending' | 'sent' | 'failed'

export interface AdminPostTopic {
  id: number
  title: string
  prompt: string
  is_active: boolean
  last_used_at: string | null
  created_at: string | null
  updated_at: string | null
}

export type PostTopicPayload = Pick<AdminPostTopic, 'title' | 'prompt' | 'is_active'>

export interface PostTopicPreviewDay {
  date: string
  kind: FinancePostKind
  topic_title: string | null
}

export interface NewsPostPayload {
  source_title?: string
  source_text: string
}

export interface AdminFinancePost {
  id: number
  kind: FinancePostKind
  subject_label: string | null
  body: string
  status: FinancePostStatus
  generated_at: string | null
  send_at: string | null
  sent_at: string | null
  error: string | null
}

export type ArticleStatus = 'draft' | 'published'

export interface AdminArticleCategory {
  id: number
  name_ru: string
  name_tg: string | null
  slug: string
  is_active: boolean
  articles_count?: number
}

export type ArticleCategoryPayload = Omit<AdminArticleCategory, 'id' | 'articles_count'>

export interface AdminArticleTag {
  id: number
  name_ru: string
  name_tg: string | null
  slug: string
}

export type ArticleTagPayload = Omit<AdminArticleTag, 'id'>

export interface AdminArticle {
  id: number
  title_ru: string
  title_tg: string | null
  slug: string
  excerpt_ru: string | null
  excerpt_tg: string | null
  body_ru: string
  body_tg: string | null
  cover_image: string | null
  youtube_url: string | null
  category_id: number
  category?: AdminArticleCategory
  tag_ids: number[]
  tags?: AdminArticleTag[]
  status: ArticleStatus
  published_at: string | null
  telegram_sent_at: string | null
  author_name: string | null
  related_bank_id: number | null
  related_product_id: number | null
  created_at: string | null
  updated_at: string | null
}

export type ArticlePayload = Omit<
  AdminArticle,
  'id' | 'category' | 'tags' | 'telegram_sent_at' | 'created_at' | 'updated_at'
>
