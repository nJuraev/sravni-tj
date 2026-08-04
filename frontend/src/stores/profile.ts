import { defineStore } from 'pinia'
import { getToken, profileApi, setToken } from '@/api/profile'
import type { Profile, ProfileUpdateRequest } from '@/types/api'

interface ProfileState {
  user: Profile | null
  token: string | null
  /** True once the initial session check has resolved. */
  ready: boolean
}

/** Telegram-based profile session: token in localStorage, no password. */
export const useProfileStore = defineStore('profile', {
  state: (): ProfileState => ({
    user: null,
    token: getToken(),
    ready: false,
  }),
  getters: {
    isAuthenticated: (state): boolean => !!state.user,
  },
  actions: {
    /** Restore session from a stored token; no-op if none. */
    async init(): Promise<void> {
      if (this.ready) return
      if (!this.token) {
        this.ready = true
        return
      }
      try {
        const res = await profileApi.me()
        this.user = res.data
      } catch {
        this.clearSession()
      } finally {
        this.ready = true
      }
    },

    /** Called when the page loads with ?token=... from the Telegram bot deep-link. */
    async consumeToken(token: string): Promise<void> {
      this.token = token
      setToken(token)
      try {
        const res = await profileApi.me()
        this.user = res.data
      } catch {
        this.clearSession()
      } finally {
        this.ready = true
      }
    },

    async update(payload: ProfileUpdateRequest): Promise<void> {
      const res = await profileApi.update(payload)
      this.user = res.data
    },

    /** Clear local session (used on 401 from any profile call, or invalid token). */
    clearSession(): void {
      this.token = null
      this.user = null
      setToken(null)
    },
  },
})
