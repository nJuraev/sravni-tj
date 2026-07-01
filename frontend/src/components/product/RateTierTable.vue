<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Currency, Locale, RateTier } from '@/types/api'
import { findRateTier } from '@/lib/rateTiers'
import { formatMoney, formatNumber, formatPercent } from '@/lib/format'

const props = defineProps<{
  tiers: RateTier[]
  currency: Currency
  /** Optional user inputs to highlight the matching grid cell. */
  amount?: number
  term?: number
}>()

const { t, locale } = useI18n()
const loc = computed(() => locale.value as Locale)

const highlighted = computed<RateTier | null>(() => {
  if (props.amount === undefined || props.term === undefined) return null
  return findRateTier(props.tiers, props.amount, props.term, props.currency)
})

function isHighlighted(tier: RateTier): boolean {
  return highlighted.value === tier
}

function amountRange(tier: RateTier): string {
  const from = formatMoney(tier.amount_from, tier.currency, loc.value)
  if (tier.amount_to === null) return `${t('common.from')} ${from}`
  return `${from} – ${formatMoney(tier.amount_to, tier.currency, loc.value)}`
}

function termRange(tier: RateTier): string {
  if (tier.term_to === null) return `${t('common.from')} ${tier.term_from} ${t('common.months')}`
  return `${formatNumber(tier.term_from, loc.value)}–${tier.term_to} ${t('common.months')}`
}
</script>

<template>
  <div class="grid">
    <table class="grid__table">
      <thead>
        <tr>
          <th>{{ t('product.tier.amount') }}</th>
          <th>{{ t('product.tier.term') }}</th>
          <th class="grid__rate-col">{{ t('product.tier.rate') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="(tier, i) in tiers"
          :key="i"
          :class="{ 'grid__row--active': isHighlighted(tier) }"
        >
          <td class="tabular">{{ amountRange(tier) }}</td>
          <td class="tabular">{{ termRange(tier) }}</td>
          <td class="grid__rate-col tabular">{{ formatPercent(tier.rate, loc) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.grid {
  overflow-x: auto;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
}
.grid__table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--fs-sm);
}
.grid__table th,
.grid__table td {
  padding: var(--space-3) var(--space-4);
  text-align: left;
  white-space: nowrap;
  border-bottom: 1px solid var(--color-border-subtle);
}
.grid__table thead th {
  background: var(--color-bg-section);
  color: var(--color-text-secondary);
  font-weight: 600;
  font-size: var(--fs-xs);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.grid__table tbody tr:last-child td {
  border-bottom: 0;
}
.grid__rate-col {
  text-align: right;
  font-weight: 700;
  color: var(--color-primary);
}
.grid__row--active {
  background: var(--color-primary-soft);
}
.grid__row--active td {
  font-weight: 700;
}
</style>
