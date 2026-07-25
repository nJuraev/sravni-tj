import { describe, it, expect, afterEach } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createHead } from '@unhead/vue/client'
import { i18n } from '@/i18n'
import { router } from '@/router'
import { useSeo } from './useSeo'

function TestPage(options: Parameters<typeof useSeo>[0]) {
  return defineComponent({
    setup() {
      useSeo(options)
      return () => h('div')
    },
  })
}

let wrapper: VueWrapper | undefined

function mountAt(options: Parameters<typeof useSeo>[0]) {
  wrapper = mount(TestPage(options), {
    global: { plugins: [i18n, router, createHead()] },
  })
  return wrapper
}

/** @unhead/vue's client DOM patch is debounced via setTimeout(0) — a real
 * macrotask, which flushPromises() (microtasks only) doesn't wait for. */
async function flushHead() {
  await flushPromises()
  await new Promise((resolve) => setTimeout(resolve, 5))
}

afterEach(async () => {
  wrapper?.unmount()
  wrapper = undefined
  await flushHead()
})

describe('useSeo + useHreflang', () => {
  it('emits a self-canonical and reciprocal hreflang for a ru route', async () => {
    await router.push('/credit')
    mountAt({ title: 'T', description: 'D' })
    await flushHead()

    expect(document.head.querySelector('link[rel="canonical"]')?.getAttribute('href')).toBe(
      'https://sravni.tj/credit',
    )
    expect(document.head.querySelector('link[hreflang="ru"]')?.getAttribute('href')).toBe(
      'https://sravni.tj/credit',
    )
    expect(document.head.querySelector('link[hreflang="tg"]')?.getAttribute('href')).toBe(
      'https://sravni.tj/tj/credit',
    )
    expect(document.head.querySelector('link[hreflang="x-default"]')?.getAttribute('href')).toBe(
      'https://sravni.tj/credit',
    )
  })

  it('emits a self-canonical pointing at the /tj URL for a tj route', async () => {
    await router.push('/tj/credit')
    mountAt({ title: 'T', description: 'D' })
    await flushHead()

    expect(document.head.querySelector('link[rel="canonical"]')?.getAttribute('href')).toBe(
      'https://sravni.tj/tj/credit',
    )
    // x-default always points at ru, even when the current page is the tj one.
    expect(document.head.querySelector('link[hreflang="x-default"]')?.getAttribute('href')).toBe(
      'https://sravni.tj/credit',
    )
  })

  it('emits JSON-LD as a script tag', async () => {
    await router.push('/credit')
    mountAt({ title: 'T', description: 'D', jsonLd: [{ '@type': 'Thing', name: 'Test' }] })
    await flushHead()

    const script = document.head.querySelector('script[type="application/ld+json"]')
    expect(script?.textContent).toContain('"@type":"Thing"')
  })

  it('adds robots noindex and skips hreflang when configured', async () => {
    await router.push('/compare')
    mountAt({ title: 'T', description: 'D', noindex: true, hreflang: false })
    await flushHead()

    expect(document.head.querySelector('meta[name="robots"]')?.getAttribute('content')).toBe('noindex,follow')
    expect(document.head.querySelector('link[rel="canonical"]')).toBeNull()
  })
})
