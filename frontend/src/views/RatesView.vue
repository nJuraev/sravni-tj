<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { Bank, Locale, Rate, RateCategory } from '@/types/api'
import { api } from '@/api/client'
import { useHead } from '@/composables/useHead'
import { useLocalizedField } from '@/composables/useLocalizedField'
import { bankLogoUrl } from '@/lib/bankIcon'
import { formatNumber } from '@/lib/format'
import BankPicker from '@/components/ui/BankPicker.vue'
import StateMessage from '@/components/ui/StateMessage.vue'
import SkeletonCard from '@/components/ui/SkeletonCard.vue'

interface BankRateGroup {
  bank: Bank
  byCategory: Record<RateCategory, Rate[]>
}

const { t, locale } = useI18n()
const { name } = useLocalizedField()
const loc = computed(() => locale.value as Locale)

const clearHead = useHead({
  title: t('ratesPage.seoTitle'),
  description: t('ratesPage.seoDescription'),
})
onUnmounted(clearHead)

const status = ref<'loading' | 'loaded' | 'error'>('loading')
const rates = ref<Rate[]>([])
const selectedBankIds = ref<number[]>([])

onMounted(async () => {
  try {
    const res = await api.getRates()
    rates.value = res.data
    status.value = 'loaded'
  } catch {
    status.value = 'error'
  }
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

function bankInitial(b: Bank): string {
  return (name(b) || '?').trim().charAt(0).toUpperCase()
}
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
      <article v-for="g in groups" :key="g.bank.id" class="bankrates">
        <RouterLink :to="`/bank/${g.bank.id}`" class="bankrates__head">
          <span class="bankrates__logo" aria-hidden="true">
            <span class="bankrates__fallback">{{ bankInitial(g.bank) }}</span>
            <img
              v-if="bankLogoUrl(g.bank)"
              :src="bankLogoUrl(g.bank)"
              :alt="name(g.bank)"
              class="bankrates__img"
              loading="lazy"
            />
          </span>
          <span class="bankrates__name">{{ name(g.bank) }}</span>
        </RouterLink>

        <div v-for="cat in (['cash', 'transfer'] as const)" :key="cat" class="bankrates__section">
          <template v-if="g.byCategory[cat].length">
            <h3 class="bankrates__section-title">{{ t(`ratesPage.${cat}`) }}</h3>
            <div class="rate-row rate-row--head">
              <span class="rate-row__ccy" />
              <span class="rate-row__lbl">{{ t('home.rates.buy') }}</span>
              <span class="rate-row__lbl">{{ t('home.rates.sell') }}</span>
            </div>
            <div v-for="r in g.byCategory[cat]" :key="`${r.currency}-${r.category}`" class="rate-row">
              <span class="rate-row__ccy">{{ r.currency }}</span>
              <span class="rate-row__val tabular">
                {{ r.buy != null ? formatNumber(r.buy, loc, 4) : '—' }}
              </span>
              <span class="rate-row__val tabular">
                {{ r.sell != null ? formatNumber(r.sell, loc, 4) : '—' }}
              </span>
            </div>
          </template>
        </div>
      </article>
    </div>
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
.bankrates {
  background: var(--color-bg);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  overflow: hidden;
}
.bankrates__head {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-5) var(--space-6);
  background: var(--color-text-primary);
  color: #fff;
}
.bankrates__head:hover {
  color: #fff;
  opacity: 0.9;
}
.bankrates__logo {
  position: relative;
  flex: none;
  width: 32px;
  height: 32px;
  border-radius: var(--radius-sm);
  background: #fff;
  display: grid;
  place-items: center;
  overflow: hidden;
}
.bankrates__fallback {
  font-family: var(--font-display);
  font-weight: 800;
  font-size: var(--fs-sm);
  color: var(--color-primary);
}
.bankrates__img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: contain;
  padding: 4px;
}
.bankrates__name {
  font-family: var(--font-display);
  font-weight: 800;
  font-size: var(--fs-lg);
}
.bankrates__section {
  padding: 0 var(--space-6);
}
.bankrates__section-title {
  font-size: var(--fs-xs);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-muted);
  font-weight: 700;
  padding-top: var(--space-4);
}
.rate-row {
  display: grid;
  grid-template-columns: 52px 1fr 1fr;
  align-items: center;
  gap: var(--space-3);
  padding: var(--space-3) 0;
  border-top: 1px solid var(--color-border-subtle);
}
/* Шапка «БАНК ПОКУПАЕТ / БАНК ПРОДАЁТ» — одна на категорию, без верхней линии. */
.rate-row--head {
  padding-top: var(--space-3);
  padding-bottom: var(--space-1);
  border-top: none;
}
.rate-row--head + .rate-row {
  border-top: none;
}
.rate-row__ccy {
  font-family: var(--font-display);
  font-weight: 800;
  font-size: var(--fs-lg);
}
.rate-row__lbl {
  font-size: var(--fs-xs);
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  font-weight: 700;
}
.rate-row__val {
  font-weight: 700;
  font-size: var(--fs-md);
}
.bankrates__section:last-of-type {
  padding-bottom: var(--space-2);
}

@media (max-width: 520px) {
  .rate-row {
    grid-template-columns: 40px 1fr 1fr;
    gap: var(--space-2);
  }
}
</style>
