import { describe, it, expect, afterEach, vi } from 'vitest'
import { getToken, setToken } from './admin'

// Admin is CSR-only, but the token helpers are guarded as defense-in-depth —
// a future contributor could still trigger useAdminStore() during SSR.
describe('admin token helpers SSR-safety', () => {
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
