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

  it('resolves the /tj-prefixed twin of every public route', () => {
    expect(router.resolve('/tj').name).toBe('home-tj')
    expect(router.resolve('/tj/credit').name).toBe('catalog-tj')
    const product = router.resolve('/tj/product/5')
    expect(product.name).toBe('product-tj')
    expect(product.params.id).toBe('5')
    expect(router.resolve('/tj/bank/3').name).toBe('bank-tj')
    expect(router.resolve('/tj/kurs-valyut').name).toBe('rates-tj')
    expect(router.resolve('/tj/otzyvy').name).toBe('reviews-tj')
    expect(router.resolve('/tj/compare').name).toBe('compare-tj')
  })

  it('falls through an unmatched /tj path to the shared not-found route', () => {
    expect(router.resolve('/tj/bogus').name).toBe('not-found')
  })

  it('keeps admin single-language (no /tj/admin twin)', () => {
    expect(router.resolve('/tj/admin/banks').name).toBe('not-found')
  })
})
