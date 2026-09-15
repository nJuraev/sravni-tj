import { ApiError } from './errors'
import { API_BASE_URL } from './client'
import type {
  AdminArticle,
  AdminArticleCategory,
  AdminArticleTag,
  AdminBank,
  AdminCurrencyRate,
  AdminFinancePost,
  AdminLead,
  AdminPostTopic,
  AdminProduct,
  AdminUser,
  ArticleCategoryPayload,
  ArticlePayload,
  ArticleTagPayload,
  BankPayload,
  LoginResponse,
  NewsPostPayload,
  Paginated,
  PostTopicPayload,
  PostTopicPreviewDay,
  ProductPayload,
  UserPayload,
} from '@/types/admin'

const TOKEN_KEY = 'sravni.admin_token'

// Admin is CSR-only (no SSR value — auth lives client-side), but guard anyway:
// defense-in-depth against a future contributor triggering this during SSR.
export function getToken(): string | null {
  if (typeof window === 'undefined') return null
  return localStorage.getItem(TOKEN_KEY)
}
export function setToken(token: string | null): void {
  if (typeof window === 'undefined') return
  if (token) localStorage.setItem(TOKEN_KEY, token)
  else localStorage.removeItem(TOKEN_KEY)
}

/** Authenticated request against /api/admin/*. Adds Bearer token + JSON. */
async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const token = getToken()
  let response: Response
  try {
    response = await fetch(`${API_BASE_URL}/admin${path}`, {
      ...init,
      headers: {
        Accept: 'application/json',
        // FormData (image upload) sets its own multipart Content-Type + boundary.
        ...(typeof init?.body === 'string' ? { 'Content-Type': 'application/json' } : {}),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...init?.headers,
      },
    })
  } catch {
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

interface ItemResponse<T> {
  data: T
}
interface CollectionResponse<T> {
  data: T[]
}

export const adminApi = {
  // Auth
  login(email: string, password: string): Promise<LoginResponse> {
    return request<LoginResponse>('/login', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    })
  },
  me(): Promise<ItemResponse<AdminUser>> {
    return request<ItemResponse<AdminUser>>('/me')
  },
  logout(): Promise<void> {
    return request<void>('/logout', { method: 'POST' })
  },

  // Banks
  listBanks(params: { search?: string; status?: string } = {}): Promise<CollectionResponse<AdminBank>> {
    const qs = new URLSearchParams()
    if (params.search) qs.set('search', params.search)
    if (params.status) qs.set('status', params.status)
    const suffix = qs.toString() ? `?${qs}` : ''
    return request<CollectionResponse<AdminBank>>(`/banks${suffix}`)
  },
  getBank(id: number): Promise<ItemResponse<AdminBank>> {
    return request<ItemResponse<AdminBank>>(`/banks/${id}`)
  },
  createBank(payload: BankPayload): Promise<ItemResponse<AdminBank>> {
    return request<ItemResponse<AdminBank>>('/banks', { method: 'POST', body: JSON.stringify(payload) })
  },
  updateBank(id: number, payload: BankPayload): Promise<ItemResponse<AdminBank>> {
    return request<ItemResponse<AdminBank>>(`/banks/${id}`, { method: 'PUT', body: JSON.stringify(payload) })
  },
  deleteBank(id: number): Promise<void> {
    return request<void>(`/banks/${id}`, { method: 'DELETE' })
  },

  // Products
  listBankProducts(bankId: number): Promise<CollectionResponse<AdminProduct>> {
    return request<CollectionResponse<AdminProduct>>(`/banks/${bankId}/products`)
  },
  listProducts(
    params: { category?: string; status?: string; bankId?: number; search?: string } = {},
  ): Promise<CollectionResponse<AdminProduct>> {
    const qs = new URLSearchParams()
    if (params.category) qs.set('category', params.category)
    if (params.status) qs.set('status', params.status)
    if (params.bankId) qs.set('bank_id', String(params.bankId))
    if (params.search) qs.set('search', params.search)
    const suffix = qs.toString() ? `?${qs}` : ''
    return request<CollectionResponse<AdminProduct>>(`/products${suffix}`)
  },
  getProduct(id: number): Promise<ItemResponse<AdminProduct>> {
    return request<ItemResponse<AdminProduct>>(`/products/${id}`)
  },
  createProduct(payload: ProductPayload): Promise<ItemResponse<AdminProduct>> {
    return request<ItemResponse<AdminProduct>>('/products', { method: 'POST', body: JSON.stringify(payload) })
  },
  updateProduct(id: number, payload: ProductPayload): Promise<ItemResponse<AdminProduct>> {
    return request<ItemResponse<AdminProduct>>(`/products/${id}`, { method: 'PUT', body: JSON.stringify(payload) })
  },
  deleteProduct(id: number): Promise<void> {
    return request<void>(`/products/${id}`, { method: 'DELETE' })
  },
  toggleProduct(id: number): Promise<ItemResponse<AdminProduct>> {
    return request<ItemResponse<AdminProduct>>(`/products/${id}/toggle`, { method: 'PATCH' })
  },

  // Leads
  listLeads(
    params: { search?: string; bank_id?: number; page?: number; per_page?: number } = {},
  ): Promise<Paginated<AdminLead>> {
    const qs = new URLSearchParams()
    if (params.search) qs.set('search', params.search)
    if (params.bank_id) qs.set('bank_id', String(params.bank_id))
    if (params.page) qs.set('page', String(params.page))
    if (params.per_page) qs.set('per_page', String(params.per_page))
    const suffix = qs.toString() ? `?${qs}` : ''
    return request<Paginated<AdminLead>>(`/leads${suffix}`)
  },
  deleteLead(id: number): Promise<void> {
    return request<void>(`/leads/${id}`, { method: 'DELETE' })
  },

  // Курсы валют банков
  listCurrencyRates(
    params: {
      bank_id?: number
      currency?: string
      category?: string
      rate_date?: string
      page?: number
      per_page?: number
    } = {},
  ): Promise<Paginated<AdminCurrencyRate>> {
    const qs = new URLSearchParams()
    if (params.bank_id) qs.set('bank_id', String(params.bank_id))
    if (params.currency) qs.set('currency', params.currency)
    if (params.category) qs.set('category', params.category)
    if (params.rate_date) qs.set('rate_date', params.rate_date)
    if (params.page) qs.set('page', String(params.page))
    if (params.per_page) qs.set('per_page', String(params.per_page))
    const suffix = qs.toString() ? `?${qs}` : ''
    return request<Paginated<AdminCurrencyRate>>(`/currency-rates${suffix}`)
  },

  // Users
  listUsers(): Promise<CollectionResponse<AdminUser>> {
    return request<CollectionResponse<AdminUser>>('/users')
  },
  createUser(payload: UserPayload): Promise<ItemResponse<AdminUser>> {
    return request<ItemResponse<AdminUser>>('/users', { method: 'POST', body: JSON.stringify(payload) })
  },
  updateUser(id: number, payload: UserPayload): Promise<ItemResponse<AdminUser>> {
    return request<ItemResponse<AdminUser>>(`/users/${id}`, { method: 'PUT', body: JSON.stringify(payload) })
  },
  deleteUser(id: number): Promise<void> {
    return request<void>(`/users/${id}`, { method: 'DELETE' })
  },

  // Финансовые посты (ежедневные публикации в Telegram-канал)
  listPostTopics(): Promise<CollectionResponse<AdminPostTopic>> {
    return request<CollectionResponse<AdminPostTopic>>('/post-topics')
  },
  previewPostTopics(): Promise<{ data: PostTopicPreviewDay[] }> {
    return request<{ data: PostTopicPreviewDay[] }>('/post-topics/preview')
  },
  createPostTopic(payload: PostTopicPayload): Promise<ItemResponse<AdminPostTopic>> {
    return request<ItemResponse<AdminPostTopic>>('/post-topics', { method: 'POST', body: JSON.stringify(payload) })
  },
  updatePostTopic(id: number, payload: PostTopicPayload): Promise<ItemResponse<AdminPostTopic>> {
    return request<ItemResponse<AdminPostTopic>>(`/post-topics/${id}`, { method: 'PUT', body: JSON.stringify(payload) })
  },
  deletePostTopic(id: number): Promise<void> {
    return request<void>(`/post-topics/${id}`, { method: 'DELETE' })
  },
  listFinancePosts(params: { page?: number; per_page?: number } = {}): Promise<Paginated<AdminFinancePost>> {
    const qs = new URLSearchParams()
    if (params.page) qs.set('page', String(params.page))
    if (params.per_page) qs.set('per_page', String(params.per_page))
    const suffix = qs.toString() ? `?${qs}` : ''
    return request<Paginated<AdminFinancePost>>(`/finance-posts${suffix}`)
  },
  createNewsPost(payload: NewsPostPayload): Promise<ItemResponse<AdminFinancePost>> {
    return request<ItemResponse<AdminFinancePost>>('/finance-posts/from-source', {
      method: 'POST',
      body: JSON.stringify(payload),
    })
  },

  // Блог: статьи
  listArticles(params: { status?: string; category_id?: number; search?: string } = {}): Promise<CollectionResponse<AdminArticle>> {
    const qs = new URLSearchParams()
    if (params.status) qs.set('status', params.status)
    if (params.category_id) qs.set('category_id', String(params.category_id))
    if (params.search) qs.set('search', params.search)
    const suffix = qs.toString() ? `?${qs}` : ''
    return request<CollectionResponse<AdminArticle>>(`/articles${suffix}`)
  },
  getArticle(id: number): Promise<ItemResponse<AdminArticle>> {
    return request<ItemResponse<AdminArticle>>(`/articles/${id}`)
  },
  createArticle(payload: ArticlePayload): Promise<ItemResponse<AdminArticle>> {
    return request<ItemResponse<AdminArticle>>('/articles', { method: 'POST', body: JSON.stringify(payload) })
  },
  updateArticle(id: number, payload: ArticlePayload): Promise<ItemResponse<AdminArticle>> {
    return request<ItemResponse<AdminArticle>>(`/articles/${id}`, { method: 'PUT', body: JSON.stringify(payload) })
  },
  deleteArticle(id: number): Promise<void> {
    return request<void>(`/articles/${id}`, { method: 'DELETE' })
  },
  sendArticleTelegram(id: number): Promise<ItemResponse<AdminArticle>> {
    return request<ItemResponse<AdminArticle>>(`/articles/${id}/send-telegram`, { method: 'POST' })
  },
  uploadArticleImage(file: File): Promise<ItemResponse<{ url: string }>> {
    const form = new FormData()
    form.append('image', file)
    return request<ItemResponse<{ url: string }>>('/articles/upload-image', { method: 'POST', body: form })
  },

  // Блог: категории
  listArticleCategories(): Promise<CollectionResponse<AdminArticleCategory>> {
    return request<CollectionResponse<AdminArticleCategory>>('/article-categories')
  },
  createArticleCategory(payload: ArticleCategoryPayload): Promise<ItemResponse<AdminArticleCategory>> {
    return request<ItemResponse<AdminArticleCategory>>('/article-categories', { method: 'POST', body: JSON.stringify(payload) })
  },
  updateArticleCategory(id: number, payload: ArticleCategoryPayload): Promise<ItemResponse<AdminArticleCategory>> {
    return request<ItemResponse<AdminArticleCategory>>(`/article-categories/${id}`, { method: 'PUT', body: JSON.stringify(payload) })
  },
  deleteArticleCategory(id: number): Promise<void> {
    return request<void>(`/article-categories/${id}`, { method: 'DELETE' })
  },

  // Блог: теги
  listArticleTags(): Promise<CollectionResponse<AdminArticleTag>> {
    return request<CollectionResponse<AdminArticleTag>>('/article-tags')
  },
  createArticleTag(payload: ArticleTagPayload): Promise<ItemResponse<AdminArticleTag>> {
    return request<ItemResponse<AdminArticleTag>>('/article-tags', { method: 'POST', body: JSON.stringify(payload) })
  },
  updateArticleTag(id: number, payload: ArticleTagPayload): Promise<ItemResponse<AdminArticleTag>> {
    return request<ItemResponse<AdminArticleTag>>(`/article-tags/${id}`, { method: 'PUT', body: JSON.stringify(payload) })
  },
  deleteArticleTag(id: number): Promise<void> {
    return request<void>(`/article-tags/${id}`, { method: 'DELETE' })
  },
}
