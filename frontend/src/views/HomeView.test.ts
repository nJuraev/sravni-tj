import { describe, it, expect, vi, beforeEach } from 'vitest'
import { defineComponent } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createHead } from '@unhead/vue/client'
import { i18n } from '@/i18n'
import { router } from '@/router'
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
}))

const RouterLinkStub = { template: '<a><slot /></a>' }

// HomeView's data-fetching children (RateWidget, ProductTeaserSection, ...)
// await their data at the top of setup() (SSR-awaitable pattern) — same as
// the real app (App.vue), they must be mounted inside <Suspense>.
const SuspenseHarness = defineComponent({
  components: { HomeView },
  template: '<Suspense><HomeView /></Suspense>',
})

describe('HomeView', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
    await router.push('/')
  })

  it('renders the hero heading even when every supplementary section has no data', async () => {
    const wrapper = mount(SuspenseHarness, {
      global: { plugins: [i18n, router, createHead()], stubs: { RouterLink: RouterLinkStub } },
    })
    await flushPromises()

    expect(wrapper.find('h1').exists()).toBe(true)
    // Курс/кредиты/депозиты/банки без данных скрываются — остаётся только hero.
    expect(wrapper.findAll('section')).toHaveLength(1)
    expect(wrapper.find('section.hero').exists()).toBe(true)
  })
})
