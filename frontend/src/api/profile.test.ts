import { describe, it, expect, afterEach, vi } from 'vitest'
import { getToken, setToken } from './profile'

// Profile is CSR-only (auth via a Telegram deep-link token), but the token
// helpers are guarded as defense-in-depth against SSR triggering them.
describe('profile token helpers SSR-safety', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('getToken() returns null when window is undefined', () => {
    vi.stubGlobal('window', undefined)
    expect(() => getToken()).not.toThrow()
    expect(getToken()).toBeNull()
  })

  it('setToken() does not throw when window is undefined', () => {
    vi.stubGlobal('window', undefined)
    expect(() => setToken('token')).not.toThrow()
    expect(() => setToken(null)).not.toThrow()
  })
})
