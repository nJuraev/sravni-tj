import { describe, it, expect, vi } from 'vitest'
import type { Bank, Product } from '@/types/api'
import { render } from './entry-server'

function makeProduct(id = 1): Product {
  return {
    id,
    category: 'credit',
    subcategory: 'consumer',
    is_special: false,
    currency: 'TJS',
    name_ru: `Кредит ${id}`,
    name_tg: null,
    description_ru: 'Описание',
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

function makeBank(id = 1): Bank {
  return { id, name_ru: 'Банк', name_tg: null, is_partner: false, rating_count: 0 }
}

vi.mock('@/api/client', () => ({
  USE_MOCKS: false,
  api: {
    getProducts: vi.fn().mockResolvedValue({
      data: [makeProduct()],
      pagination: { page: 1, per_page: 20, total_items: 1, total_pages: 1 },
    }),
    getProduct: vi.fn().mockResolvedValue({ data: makeProduct() }),
    getBanks: vi.fn().mockResolvedValue({ data: [makeBank()] }),
    getBank: vi.fn().mockResolvedValue({ data: makeBank() }),
    getBestRate: vi.fn().mockResolvedValue({ data: null }),
    getRates: vi.fn().mockResolvedValue({ data: [] }),
    getBankReviews: vi.fn().mockResolvedValue({ data: [], pagination: { page: 1, per_page: 20, total_items: 0, total_pages: 0 } }),
  },
}))

const PUBLIC_URLS_200 = [
  '/',
  '/tj',
  '/credit',
  '/tj/credit',
  '/deposit',
  '/tj/deposit',
  '/installment',
  '/tj/installment',
  '/product/1',
  '/tj/product/1',
  '/bank/1',
  '/tj/bank/1',
  '/compare',
  '/tj/compare',
  '/kurs-valyut',
  '/tj/kurs-valyut',
  '/otzyvy',
  '/tj/otzyvy',
]

const NOT_FOUND_URLS_404 = ['/bogus-page', '/tj/bogus-page']

describe('entry-server render()', () => {
  it.each(PUBLIC_URLS_200)('renders %s with real (mocked) data and a 200 status', async (url) => {
    const result = await render(url)
    expect(result.status).toBe(200)
    expect(result.html.length).toBeGreaterThan(0)
  })

  it.each(NOT_FOUND_URLS_404)('renders %s as a real 404', async (url) => {
    const result = await render(url)
    expect(result.status).toBe(404)
    expect(result.html.length).toBeGreaterThan(0)
  })
})
