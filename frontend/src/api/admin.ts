import { ApiError } from './errors'
import { API_BASE_URL } from './client'
import type {
  AdminBank,
  AdminLead,
  AdminProduct,
  AdminUser,
  BankPayload,
  LoginResponse,
  Paginated,
  ProductPayload,
  UserPayload,
} from '@/types/admin'

const TOKEN_KEY = 'sravni.admin_token'

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}
export function setToken(token: string | null): void {
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
        ...(init?.body ? { 'Content-Type': 'application/json' } : {}),
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
}
