import { ApiError } from './errors'
import { API_BASE_URL } from './client'
import type {
  ProfileResponse,
  ProfileUpdateRequest,
  RateAlertListResponse,
  RateAlertRequest,
  RateAlertResponse,
} from '@/types/api'

const TOKEN_KEY = 'sravni.profile_token'

// Profile is CSR-only (auth lives client-side via a token from the Telegram
// deep-link), but guard anyway for SSR safety.
export function getToken(): string | null {
  if (typeof window === 'undefined') return null
  return localStorage.getItem(TOKEN_KEY)
}
export function setToken(token: string | null): void {
  if (typeof window === 'undefined') return
  if (token) localStorage.setItem(TOKEN_KEY, token)
  else localStorage.removeItem(TOKEN_KEY)
}

/** Authenticated request against /api/profile*. Adds Bearer token + JSON. */
async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const token = getToken()
  let response: Response
  try {
    response = await fetch(`${API_BASE_URL}${path}`, {
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

export const profileApi = {
  me(): Promise<ProfileResponse> {
    return request<ProfileResponse>('/profile')
  },
  update(payload: ProfileUpdateRequest): Promise<ProfileResponse> {
    return request<ProfileResponse>('/profile', { method: 'PATCH', body: JSON.stringify(payload) })
  },

  listAlerts(): Promise<RateAlertListResponse> {
    return request<RateAlertListResponse>('/profile/alerts')
  },
  createAlert(payload: RateAlertRequest): Promise<RateAlertResponse> {
    return request<RateAlertResponse>('/profile/alerts', { method: 'POST', body: JSON.stringify(payload) })
  },
  deleteAlert(id: number): Promise<void> {
    return request<void>(`/profile/alerts/${id}`, { method: 'DELETE' })
  },
}
