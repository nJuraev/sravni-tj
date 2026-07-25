import { describe, it, expect, vi } from 'vitest'
import type { Bank, Product } from '@/types/api'

function makeProduct(id: number): Product {
  return {
    id,
    category: 'credit',
    subcategory: 'consumer',
    is_special: false,
    currency: 'TJS',
    name_ru: `Кредит ${id}`,
    name_tg: null,
    description_ru: null,
    description_tg: null,
    rate_min: 10,
    rate_max: 20,
    amount_min: 1000,
    amount_max: 50000,
    term_min: 3,
    term_max: 24,
    rate_tiers: [],
    features: {},
    bank: { id: 1, name_ru: 'Банк', name_tg: null, is_partner: false },
    parsed_at: null,
  }
}

function makeBank(id: number): Bank {
  return { id, name_ru: 'Банк', name_tg: null, is_partner: false }
}

const emptyList = { data: [], pagination: { page: 1, per_page: 500, total_items: 0, total_pages: 0 } }

vi.mock('@/api/client', () => ({
  api: {
    getProducts: vi.fn(async (_locale: string, query: { category?: string }) => {
      if (query?.category === 'credit') {
        return { data: [makeProduct(1), makeProduct(2)], pagination: { page: 1, per_page: 500, total_items: 2, total_pages: 1 } }
      }
      return emptyList
    }),
    getBanks: vi.fn(async () => ({ data: [makeBank(9)] })),
  },
}))

const { collectSitemapUrls, renderSitemapXml, renderRobotsTxt } = await import('./sitemap')

describe('renderRobotsTxt', () => {
  it('allows crawling, disallows /admin, and points at the sitemap', () => {
    const txt = renderRobotsTxt()
    expect(txt).toContain('Allow: /')
    expect(txt).toContain('Disallow: /admin')
    expect(txt).toContain('Sitemap: https://sravni.tj/sitemap.xml')
  })
})

describe('collectSitemapUrls', () => {
  it('includes static pages and every product/bank from the (mocked) API', async () => {
    const pairs = await collectSitemapUrls()
    const ruLocs = pairs.map((p) => p.ru)

    expect(ruLocs).toContain('https://sravni.tj/')
    expect(ruLocs).toContain('https://sravni.tj/credit')
    expect(ruLocs).toContain('https://sravni.tj/kurs-valyut')
    expect(ruLocs).toContain('https://sravni.tj/product/1')
    expect(ruLocs).toContain('https://sravni.tj/product/2')
    expect(ruLocs).toContain('https://sravni.tj/bank/9')

    // Never included — noindex/CSR-only pages have no place in the sitemap.
    expect(ruLocs.some((l) => l.includes('/compare'))).toBe(false)
    expect(ruLocs.some((l) => l.includes('/admin'))).toBe(false)
  })

  it('pairs every ru URL with its /tj twin', async () => {
    const pairs = await collectSitemapUrls()
    const productPair = pairs.find((p) => p.ru === 'https://sravni.tj/product/1')
    expect(productPair?.tj).toBe('https://sravni.tj/tj/product/1')
  })
})

describe('renderSitemapXml', () => {
  it('emits one <url> per language with reciprocal hreflang alternates', () => {
    const xml = renderSitemapXml([{ ru: 'https://sravni.tj/credit', tj: 'https://sravni.tj/tj/credit' }])

    expect(xml).toContain('<?xml version="1.0" encoding="UTF-8"?>')
    expect((xml.match(/<loc>/g) ?? []).length).toBe(2)
    expect(xml).toContain('<loc>https://sravni.tj/credit</loc>')
    expect(xml).toContain('<loc>https://sravni.tj/tj/credit</loc>')
    expect(xml).toContain('hreflang="ru" href="https://sravni.tj/credit"')
    expect(xml).toContain('hreflang="tg" href="https://sravni.tj/tj/credit"')
  })
})
