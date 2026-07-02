import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { i18n } from '@/i18n'
import HomeView from './HomeView.vue'

vi.mock('@/api/client', () => ({
  api: {
    getBestRate: vi.fn().mockResolvedValue({ data: null }),
    getProducts: vi.fn().mockResolvedValue({
      data: [],
      pagination: { page: 1, per_page: 3, total_items: 0, total_pages: 0 },
    }),
    getBanks: vi.fn().mockResolvedValue({ data: [] }),
  },
  setApiLocale: vi.fn(),
}))

const RouterLinkStub = { template: '<a><slot /></a>' }

describe('HomeView', () => {
  it('renders the hero heading even when every supplementary section has no data', async () => {
    setActivePinia(createPinia())

    const wrapper = mount(HomeView, {
      global: { plugins: [i18n], stubs: { RouterLink: RouterLinkStub } },
    })
    await flushPromises()

    expect(wrapper.find('h1').exists()).toBe(true)
    // Курс/кредиты/депозиты/банки без данных скрываются — остаётся только hero.
    expect(wrapper.findAll('section')).toHaveLength(1)
    expect(wrapper.find('section.hero').exists()).toBe(true)
  })
})
