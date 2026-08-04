import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const { meMock } = vi.hoisted(() => ({ meMock: vi.fn() }))
vi.mock('@/api/profile', () => ({
  getToken: vi.fn(() => null),
  setToken: vi.fn(),
  profileApi: { me: meMock, update: vi.fn() },
}))

import { useProfileStore } from './profile'

describe('profile store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    meMock.mockReset()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('initializes unauthenticated with no stored token', () => {
    expect(() => useProfileStore()).not.toThrow()
    const store = useProfileStore()
    expect(store.isAuthenticated).toBe(false)
    expect(store.token).toBeNull()
  })

  it('init() is a no-op when there is no token', async () => {
    const store = useProfileStore()
    await store.init()
    expect(store.ready).toBe(true)
    expect(meMock).not.toHaveBeenCalled()
  })

  it('consumeToken() stores the token and loads the profile', async () => {
    meMock.mockResolvedValueOnce({
      data: { id: 1, name: 'Ivan', phone: null, telegram_username: 'ivan', created_at: '' },
    })
    const store = useProfileStore()
    await store.consumeToken('deep-link-token')

    expect(store.token).toBe('deep-link-token')
    expect(store.isAuthenticated).toBe(true)
    expect(store.user?.name).toBe('Ivan')
  })

  it('consumeToken() clears the session when the token is rejected', async () => {
    meMock.mockRejectedValueOnce(new Error('401'))
    const store = useProfileStore()
    await store.consumeToken('bad-token')

    expect(store.token).toBeNull()
    expect(store.isAuthenticated).toBe(false)
  })
})
