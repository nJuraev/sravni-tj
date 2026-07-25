import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useCompareStore } from './compare'

// Guards against the SSR crash: Node has no `window`/`localStorage`, and one
// process serves many concurrent requests — state init must not throw.
describe('compare store SSR-safety', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('initializes with an empty list when window is undefined', () => {
    vi.stubGlobal('window', undefined)
    expect(() => useCompareStore()).not.toThrow()
    expect(useCompareStore().items).toEqual([])
  })

  it('persist() does not throw when window is undefined', () => {
    const store = useCompareStore()
    vi.stubGlobal('window', undefined)
    expect(() => store.persist()).not.toThrow()
  })
})
