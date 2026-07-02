import { describe, it, expect } from 'vitest'
import { router } from './index'

describe('router', () => {
  it('resolves "/" to the home route (not the old redirect to /credit)', () => {
    const resolved = router.resolve('/')
    expect(resolved.name).toBe('home')
    expect(resolved.redirectedFrom).toBeUndefined()
  })

  it('still resolves the catalog routes untouched', () => {
    expect(router.resolve('/credit').name).toBe('catalog')
    expect(router.resolve('/deposit').name).toBe('catalog')
    expect(router.resolve('/installment').name).toBe('catalog')
  })
})
