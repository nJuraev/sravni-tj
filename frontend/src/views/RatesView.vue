<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Bank, Rate, RateCategory } from '@/types/api'
import { useApi } from '@/composables/useApi'
import { useSeo } from '@/composables/useSeo'
import { useLocalizedField } from '@/composables/useLocalizedField'
import { bankLogoUrl } from '@/lib/bankIcon'
import BankPicker from '@/components/ui/BankPicker.vue'
import StateMessage from '@/components/ui/StateMessage.vue'
import SkeletonCard from '@/components/ui/SkeletonCard.vue'
import BankRateCard from '@/components/rates/BankRateCard.vue'
import FaqBlock, { type FaqItem } from '@/components/seo/FaqBlock.vue'

interface BankRateGroup {
  bank: Bank
  byCategory: Record<RateCategory, Rate[]>
}

const { t } = useI18n()
const api = useApi()
const { name } = useLocalizedField()

const status = ref<'loading' | 'loaded' | 'error'>('loading')
const rates = ref<Rate[]>([])
const selectedBankIds = ref<number[]>([])

// Awaited (not onMounted, which never fires server-side) so SSR renders real data.
try {
  const res = await api.getRates()
  rates.value = res.data
  status.value = 'loaded'
} catch {
  status.value = 'error'
}

/** ExchangeRateSpecification per bank×currency quote — GEO citability (see docs/next-seo-homepage.md). */
function buildRatesJsonLd(list: Rate[]): Record<string, unknown>[] {
  return list
    .filter((r) => r.buy != null || r.sell != null)
    .map((r) => ({
      '@context': 'https://schema.org',
      '@type': 'ExchangeRateSpecification',
      name: `${r.currency}/TJS — ${name(r.bank)}`,
      currency: r.currency,
      currentExchangeRate: {
        '@type': 'UnitPriceSpecification',
        price: r.buy ?? r.sell,
        priceCurrency: 'TJS',
      },
      ...(r.buy != null && r.sell != null ? { exchangeRateSpread: Number((r.sell - r.buy).toFixed(4)) } : {}),
      provider: { '@type': 'BankOrCreditUnion', name: name(r.bank) },
      ...(r.rate_date ? { dateModified: r.rate_date } : {}),
    }))
}

useSeo({
  title: t('ratesPage.seoTitle'),
  description: t('ratesPage.seoDescription'),
  jsonLd: buildRatesJsonLd(rates.value),
})

// GEO Q&A — concrete numbers from the data already on this page (never
// fabricated), plus a link to the NBT's official rate as the citable source.
// Copy owner should expand/refine this list with real content; this is the
// engineering scaffold (see project memory: sravni-seo-competitors, GEO §4).
const faqItems = computed<FaqItem[]>(() => {
  const usd = rates.value.filter((r) => r.currency === 'USD' && r.buy != null && r.sell != null)
  if (!usd.length) return []
  const bestBuy = Math.max(...usd.map((r) => r.buy as number))
  const bestSell = Math.min(...usd.map((r) => r.sell as number))
  return [
    {
      question: t('ratesPage.faq.q1'),
      answer: t('ratesPage.faq.a1', { buy: bestSell.toFixed(2), sell: bestBuy.toFixed(2) }),
    },
    { question: t('ratesPage.faq.q2'), answer: t('ratesPage.faq.a2') },
  ]
})

/** One tile per bank that quotes at least one rate — used both as the filter and as the source of truth for grouping. */
const bankTiles = computed(() => {
  const seen = new Map<number, Bank>()
  for (const r of rates.value) seen.set(r.bank.id, r.bank)
  return [...seen.values()].map((b) => ({ id: b.id, name: name(b), icon: bankLogoUrl(b) }))
})

const groups = computed<BankRateGroup[]>(() => {
  const byBank = new Map<number, BankRateGroup>()
  for (const r of rates.value) {
    if (selectedBankIds.value.length && !selectedBankIds.value.includes(r.bank.id)) continue
    let group = byBank.get(r.bank.id)
    if (!group) {
      group = { bank: r.bank, byCategory: { cash: [], transfer: [] } }
      byBank.set(r.bank.id, group)
    }
    group.byCategory[r.category].push(r)
  }
  return [...byBank.values()].sort((a, b) => name(a.bank).localeCompare(name(b.bank)))
})

const isEmpty = computed(() => status.value === 'loaded' && groups.value.length === 0)
</script>

<template>
  <div class="rates container">
    <header class="rates__header">
      <div class="section-eyebrow">{{ t('home.rates.eyebrow') }}</div>
      <h1 class="rates__title">{{ t('ratesPage.title') }}</h1>
      <p class="rates__subtitle">{{ t('ratesPage.subtitle') }}</p>
    </header>

    <div v-if="status === 'loaded' && bankTiles.length" class="rates__filter">
      <BankPicker v-model="selectedBankIds" :banks="bankTiles" />
      <button
        v-if="selectedBankIds.length"
        type="button"
        class="rates__clear"
        @click="selectedBankIds = []"
      >
        {{ t('ratesPage.filterAll') }}
      </button>
    </div>

    <div v-if="status === 'loading'" class="rates__grid" aria-busy="true">
      <SkeletonCard v-for="n in 4" :key="n" />
    </div>

    <StateMessage
      v-else-if="status === 'error'"
      tone="error"
      :title="t('ratesPage.errorTitle')"
      :hint="t('ratesPage.errorHint')"
    />

    <StateMessage
      v-else-if="isEmpty"
      :title="t('ratesPage.empty')"
      :hint="t('ratesPage.emptyHint')"
    />

    <div v-else class="rates__grid">
      <BankRateCard v-for="g in groups" :key="g.bank.id" :bank="g.bank" :by-category="g.byCategory" />
    </div>

    <FaqBlock class="rates__faq" :title="t('ratesPage.faq.title')" :items="faqItems" />
  </div>
</template>

<style scoped>
.rates {
  padding-block: var(--space-8) var(--space-16);
}
.rates__header {
  max-width: 60ch;
  margin-bottom: var(--space-6);
}
.section-eyebrow {
  font-size: var(--fs-xs);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--color-primary);
  font-weight: 700;
  margin-bottom: var(--space-2);
}
.rates__title {
  font-family: var(--font-display);
  font-size: var(--fs-3xl);
  font-weight: 800;
  letter-spacing: -0.02em;
  margin: 0 0 var(--space-3);
}
.rates__subtitle {
  color: var(--color-text-secondary);
}
.rates__filter {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-4);
  padding-bottom: var(--space-6);
  margin-bottom: var(--space-6);
  border-bottom: 2px solid var(--color-text-primary);
}
.rates__clear {
  font-size: var(--fs-sm);
  font-weight: 700;
  color: var(--color-primary);
  background: none;
  border: none;
  cursor: pointer;
  white-space: nowrap;
}
.rates__clear:hover {
  color: var(--color-primary-dark);
}
.rates__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: var(--space-6);
}
.rates__faq {
  margin-top: var(--space-10);
  max-width: 720px;
}
</style>
