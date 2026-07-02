import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { i18n } from '@/i18n'
import RateWidget from './RateWidget.vue'
import { api } from '@/api/client'

vi.mock('@/api/client', () => ({
  api: { getBestRate: vi.fn() },
  setApiLocale: vi.fn(),
}))

const RouterLinkStub = { template: '<a><slot /></a>' }

function mountWidget() {
  return mount(RateWidget, {
    global: { plugins: [i18n], stubs: { RouterLink: RouterLinkStub } },
  })
}

describe('RateWidget', () => {
  it('hides the whole section when no bank quotes any currency', async () => {
    vi.mocked(api.getBestRate).mockResolvedValue({ data: null })

    const wrapper = mountWidget()
    await flushPromises()

    expect(wrapper.find('section').exists()).toBe(false)
  })

  it('shows the winning bank for each category, using its own buy+sell pair', async () => {
    vi.mocked(api.getBestRate).mockImplementation(async ({ category }) => ({
      data: {
        bank: {
          id: 1,
          name_ru: category === 'cash' ? 'Касса Банк' : 'Трансфер Банк',
          name_tg: null,
          is_partner: false,
        },
        currency: 'USD',
        category,
        buy: 11.2,
        sell: 11.3,
        rate_date: '2026-07-02',
      },
    }))

    const wrapper = mountWidget()
    await flushPromises()

    expect(wrapper.text()).toContain('Денежные переводы')
    expect(wrapper.text()).toContain('Обмен валют')
    expect(wrapper.text()).toContain('Трансфер Банк')
    expect(wrapper.text()).toContain('Касса Банк')
    // Одна и та же строка показывает buy/sell ОДНОГО банка, а не микс.
    // (ru-RU Intl форматирует десятичные через запятую)
    expect(wrapper.text()).toContain('11,2')
    expect(wrapper.text()).toContain('11,3')
  })
})
