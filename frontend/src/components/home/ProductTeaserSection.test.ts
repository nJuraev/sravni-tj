import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { i18n } from '@/i18n'
import ProductTeaserSection from './ProductTeaserSection.vue'
import ProductCard from '@/components/catalog/ProductCard.vue'
import { api } from '@/api/client'
import { ApiError } from '@/api/errors'
import type { Product } from '@/types/api'

vi.mock('@/api/client', () => ({
  api: { getProducts: vi.fn() },
  setApiLocale: vi.fn(),
}))

const RouterLinkStub = { template: '<a><slot /></a>' }

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
    rate_min: 20,
    rate_max: 25,
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

function mountSection() {
  return mount(ProductTeaserSection, {
    props: { category: 'credit', title: 'Топ кредитов', ctaLabel: 'Все кредиты', ctaTo: '/credit' },
    global: { plugins: [i18n], stubs: { RouterLink: RouterLinkStub } },
  })
}

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('ProductTeaserSection', () => {
  it('requests exactly top-3 products for the given category', async () => {
    vi.mocked(api.getProducts).mockResolvedValue({
      data: [makeProduct(1), makeProduct(2), makeProduct(3)],
      pagination: { page: 1, per_page: 3, total_items: 3, total_pages: 1 },
    })

    const wrapper = mountSection()
    await flushPromises()

    expect(api.getProducts).toHaveBeenCalledWith({ category: 'credit', per_page: 3 })
    expect(wrapper.findAllComponents(ProductCard)).toHaveLength(3)
  })

  it('hides the section when the catalog returns no products', async () => {
    vi.mocked(api.getProducts).mockResolvedValue({
      data: [],
      pagination: { page: 1, per_page: 3, total_items: 0, total_pages: 0 },
    })

    const wrapper = mountSection()
    await flushPromises()

    expect(wrapper.find('section').exists()).toBe(false)
  })

  it('hides the section when the request fails', async () => {
    vi.mocked(api.getProducts).mockRejectedValue(new ApiError(500, { message: 'Server error.' }))

    const wrapper = mountSection()
    await flushPromises()

    expect(wrapper.find('section').exists()).toBe(false)
  })
})
