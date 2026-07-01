import { defineStore } from 'pinia'
import { adminApi, getToken, setToken } from '@/api/admin'
import type { AdminUser } from '@/types/admin'

interface AdminAuthState {
  user: AdminUser | null
  token: string | null
  /** True once the initial session check (fetchMe) has resolved. */
  ready: boolean
}

/** Admin-panel auth: token in localStorage, current user, session lifecycle. */
export const useAdminStore = defineStore('admin', {
  state: (): AdminAuthState => ({
    user: null,
    token: getToken(),
    ready: false,
  }),
  getters: {
    isAuthenticated: (state): boolean => !!state.user,
    isAdmin: (state): boolean => state.user?.role === 'admin',
  },
  actions: {
    async login(email: string, password: string): Promise<void> {
      const res = await adminApi.login(email, password)
      this.token = res.data.token
      setToken(res.data.token)
      this.user = res.data.user
      this.ready = true
    },

    /** Restore session from a stored token; no-op if none. */
    async init(): Promise<void> {
      if (this.ready) return
      if (!this.token) {
        this.ready = true
        return
      }
      try {
        const res = await adminApi.me()
        this.user = res.data
      } catch {
        // Invalid/expired token — drop it.
        this.token = null
        this.user = null
        setToken(null)
      } finally {
        this.ready = true
      }
    },

    async logout(): Promise<void> {
      try {
        await adminApi.logout()
      } catch {
        // Ignore network/server errors on logout.
      }
      this.token = null
      this.user = null
      setToken(null)
    },

    /** Clear local session (used on 401 from any admin call). */
    clearSession(): void {
      this.token = null
      this.user = null
      setToken(null)
    },
  },
})
