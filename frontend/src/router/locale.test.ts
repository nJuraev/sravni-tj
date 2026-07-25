import { describe, it, expect } from 'vitest'
import type { RouteLocationNormalizedLoaded } from 'vue-router'
import { getLocaleFromPath, getLocaleFromRoute, getLocalizedRouteTarget } from './locale'

describe('getLocaleFromPath', () => {
  it('returns tj for /tj and /tj/... paths', () => {
    expect(getLocaleFromPath('/tj')).toBe('tj')
    expect(getLocaleFromPath('/tj/credit')).toBe('tj')
  })

  it('returns ru for everything else, including a bare /tj-prefixed word', () => {
    expect(getLocaleFromPath('/')).toBe('ru')
    expect(getLocaleFromPath('/credit')).toBe('ru')
    expect(getLocaleFromPath('/tjsomething')).toBe('ru')
  })
})

describe('getLocaleFromRoute', () => {
  it('prefers meta.locale when present', () => {
    expect(getLocaleFromRoute({ meta: { locale: 'tj' }, path: '/credit' })).toBe('tj')
    expect(getLocaleFromRoute({ meta: { locale: 'ru' }, path: '/tj/credit' })).toBe('ru')
  })

  it('falls back to path-sniffing when meta.locale is absent (not-found route)', () => {
    expect(getLocaleFromRoute({ meta: {}, path: '/tj/bogus' })).toBe('tj')
    expect(getLocaleFromRoute({ meta: {}, path: '/bogus' })).toBe('ru')
  })
})

describe('getLocalizedRouteTarget', () => {
  function route(overrides: Partial<RouteLocationNormalizedLoaded>): RouteLocationNormalizedLoaded {
    return { params: {}, query: {}, hash: '', ...overrides } as RouteLocationNormalizedLoaded
  }

  it('swaps a named route to its -tj twin', () => {
    const r = route({ name: 'catalog', params: { category: 'credit' } })
    expect(getLocalizedRouteTarget(r, 'tj')).toEqual({
      name: 'catalog-tj',
      params: { category: 'credit' },
      query: {},
      hash: '',
    })
  })

  it('swaps a -tj named route back to ru', () => {
    const r = route({ name: 'catalog-tj', params: { category: 'credit' } })
    expect(getLocalizedRouteTarget(r, 'ru')).toEqual({
      name: 'catalog',
      params: { category: 'credit' },
      query: {},
      hash: '',
    })
  })

  it('falls back to path-toggling for the unnamed catch-all', () => {
    const r = route({ name: undefined, path: '/bogus' })
    expect(getLocalizedRouteTarget(r, 'tj')).toEqual({ path: '/tj/bogus', query: {}, hash: '' })
  })
})
