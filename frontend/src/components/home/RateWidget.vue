<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { RateCategory } from '@/types/api'
import { api } from '@/api/client'
import { useLocalizedField } from '@/composables/useLocalizedField'
import RateCategoryCard, { type RateCategoryRow } from './RateCategoryCard.vue'

/** Витрина всегда показывает эти три валюты — самые запрашиваемые в нише. */
const CURRENCIES = ['USD', 'EUR', 'RUB']

const { t } = useI18n()
const { name } = useLocalizedField()

const status = ref<'loading' | 'loaded' | 'error'>('loading')
const rowsByCategory = ref<Record<RateCategory, RateCategoryRow[]>>({ transfer: [], cash: [] })

async function loadCategory(category: RateCategory): Promise<RateCategoryRow[]> {
  const results = await Promise.all(
    CURRENCIES.map(async (currency) => {
      // op=buy: клиент покупает валюту — лучший банк даёт минимальный sell.
      const { data } = await api.getBestRate({ currency, category, op: 'buy' })
      if (!data || data.buy == null || data.sell == null) return null
      return {
        currency,
        buy: data.buy,
        sell: data.sell,
        bankName: name(data.bank),
      } satisfies RateCategoryRow
    }),
  )
  return results.filter((r): r is RateCategoryRow => r !== null)
}

onMounted(async () => {
  try {
    const [transfer, cash] = await Promise.all([loadCategory('transfer'), loadCategory('cash')])
    rowsByCategory.value = { transfer, cash }
    status.value = transfer.length === 0 && cash.length === 0 ? 'error' : 'loaded'
  } catch {
    status.value = 'error'
  }
})

// Ничего показать нечего — секция скрывается целиком, страница не падает.
const isVisible = computed(() => status.value === 'loaded')
</script>

<template>
  <section v-if="status !== 'error'" class="block tinted" aria-label="Курс валют">
    <div class="container">
      <div class="section-head">
        <div class="section-eyebrow">{{ t('home.rates.eyebrow') }}</div>
        <h2 class="section-title">{{ t('home.rates.title') }}</h2>
      </div>

      <div v-if="status === 'loading'" class="rate-categories rate-categories--loading" aria-busy="true">
        <div v-for="n in 2" :key="n" class="rate-skeleton">
          <div class="rate-skeleton__block" />
          <div v-for="line in 3" :key="line" class="rate-skeleton__line" />
        </div>
      </div>

      <template v-else-if="isVisible">
        <div class="rate-categories">
          <RateCategoryCard
            :title="t('home.rates.transfer')"
            tag="Transfer"
            :rows="rowsByCategory.transfer"
          />
          <RateCategoryCard :title="t('home.rates.cash')" tag="Cash" :rows="rowsByCategory.cash" />
        </div>
        <div class="rate-categories__footer">
          <RouterLink class="section-cta" to="/credit">{{ t('home.rates.allBanks') }} →</RouterLink>
        </div>
      </template>
    </div>
  </section>
</template>

<style scoped>
.block {
  padding: var(--space-16) 0;
}
.block.tinted {
  background: var(--color-bg-section);
}
.section-head {
  margin-bottom: var(--space-8);
  border-bottom: 2px solid var(--color-text-primary);
  padding-bottom: var(--space-5);
}
.section-eyebrow {
  font-size: var(--fs-xs);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--color-primary);
  font-weight: 700;
  margin-bottom: var(--space-2);
}
.section-title {
  font-family: var(--font-display);
  font-size: var(--fs-3xl);
  font-weight: 800;
  letter-spacing: -0.02em;
  margin: 0;
}
.rate-categories {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-6);
}
.rate-categories__footer {
  text-align: center;
  margin-top: var(--space-8);
}
.section-cta {
  font-weight: 700;
  font-size: var(--fs-sm);
  color: var(--color-primary);
}
.section-cta:hover {
  color: var(--color-primary-dark);
}

.rate-skeleton {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--space-6);
  background: var(--color-bg);
}
.rate-skeleton__block {
  height: 24px;
  width: 60%;
  border-radius: var(--radius-sm);
  margin-bottom: var(--space-5);
}
.rate-skeleton__block,
.rate-skeleton__line {
  background: linear-gradient(
    90deg,
    var(--color-bg-section) 25%,
    var(--color-bg-offwhite) 50%,
    var(--color-bg-section) 75%
  );
  background-size: 200% 100%;
  animation: rate-shimmer 1.3s infinite;
}
.rate-skeleton__line {
  height: 48px;
  border-radius: var(--radius-sm);
  margin-bottom: var(--space-3);
}
@keyframes rate-shimmer {
  to {
    background-position: -200% 0;
  }
}

@media (max-width: 860px) {
  .rate-categories,
  .rate-categories--loading {
    grid-template-columns: 1fr;
  }
}
</style>
