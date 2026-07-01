<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { Locale, Product } from '@/types/api'
import { useCompareStore } from '@/stores/compare'
import { useLeadModalStore } from '@/stores/leadModal'
import { useLocalizedField } from '@/composables/useLocalizedField'
import { useProductDisplay } from '@/composables/useProductDisplay'
import { formatMoney, formatRateRange } from '@/lib/format'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import StateMessage from '@/components/ui/StateMessage.vue'

const { t, locale } = useI18n()
const compare = useCompareStore()
const leadModal = useLeadModalStore()
const { name } = useLocalizedField()
const { activeFeatures, featureLabel, categoryLabel } = useProductDisplay()

const loc = computed(() => locale.value as Locale)
const products = computed<Product[]>(() => compare.items)
const isEmpty = computed(() => products.value.length === 0)

// Hide the rate for interest-free installments (rate_max === 0), mirroring cards.
function showsRate(p: Product): boolean {
  return !(p.category === 'installment' && p.rate_max === 0)
}

function rateText(p: Product): string {
  if (!showsRate(p)) return categoryLabel(p)
  return formatRateRange(p.rate_min, p.rate_max, loc.value)
}

function amountText(p: Product): string {
  const from = p.amount_min != null ? formatMoney(p.amount_min, p.currency, loc.value) : null
  if (p.amount_max == null) return from ? `${t('common.from')} ${from}` : '—'
  const to = formatMoney(p.amount_max, p.currency, loc.value)
  return from ? `${from} – ${to}` : `${t('common.to')} ${to}`
}

function termText(p: Product): string {
  const unit = t('common.months')
  if (p.term_min == null && p.term_max == null) return '—'
  if (p.term_min == null) return `${t('common.to')} ${p.term_max} ${unit}`
  if (p.term_max == null) return `${t('common.from')} ${p.term_min} ${unit}`
  return `${p.term_min}–${p.term_max} ${unit}`
}

function featuresText(p: Product): string {
  const list = activeFeatures(p).map((f) => featureLabel(f))
  return list.length ? list.join(', ') : t('compare.noFeatures')
}

/** A row of values differs (highlight) when not every product shares it. */
function rowDiffers(values: string[]): boolean {
  if (values.length < 2) return false
  return new Set(values).size > 1
}

const rows = computed(() => {
  const list = products.value
  return [
    { key: 'category', label: t('compare.attribute.category'), values: list.map((p) => categoryLabel(p)) },
    { key: 'currency', label: t('compare.attribute.currency'), values: list.map((p) => p.currency) },
    { key: 'rate', label: t('compare.attribute.rate'), values: list.map((p) => rateText(p)), accent: true },
    { key: 'amount', label: t('compare.attribute.amount'), values: list.map((p) => amountText(p)) },
    { key: 'term', label: t('compare.attribute.term'), values: list.map((p) => termText(p)) },
    { key: 'features', label: t('compare.attribute.features'), values: list.map((p) => featuresText(p)) },
  ]
})
</script>

<template>
  <div class="compare container">
    <header class="compare__header">
      <h1>{{ t('compare.title2') }}</h1>
      <BaseButton
        v-if="!isEmpty"
        variant="ghost"
        size="sm"
        @click="compare.clear()"
      >
        {{ t('compare.clearAll') }}
      </BaseButton>
    </header>

    <StateMessage
      v-if="isEmpty"
      :title="t('compare.empty')"
      :hint="t('compare.emptyHint')"
    >
      <template #action>
        <RouterLink to="/credit">
          <BaseButton variant="primary">{{ t('compare.goCatalog') }}</BaseButton>
        </RouterLink>
      </template>
    </StateMessage>

    <div v-else class="compare__scroll">
      <table class="compare__table" :style="{ '--cols': products.length }">
        <thead>
          <tr>
            <th class="compare__corner" scope="col">{{ t('compare.attribute.bank') }}</th>
            <th
              v-for="p in products"
              :key="p.id"
              class="compare__producthead"
              scope="col"
            >
              <div class="compare__headinner">
                <div class="compare__bank">
                  <span class="compare__bank-name">{{ name(p.bank) }}</span>
                  <BaseBadge v-if="p.bank.is_partner" tone="green">{{ t('common.partner') }}</BaseBadge>
                </div>
                <RouterLink :to="`/product/${p.id}`" class="compare__title">
                  {{ name(p) }}
                </RouterLink>
                <div class="compare__head-actions">
                  <BaseButton variant="primary" size="sm" @click="leadModal.open(p)">
                    {{ t('common.apply') }}
                  </BaseButton>
                  <BaseButton variant="ghost" size="sm" @click="compare.remove(p.id)">
                    {{ t('compare.remove') }}
                  </BaseButton>
                </div>
              </div>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in rows"
            :key="row.key"
            :class="{ 'compare__row--diff': rowDiffers(row.values) }"
          >
            <th class="compare__rowlabel" scope="row">{{ row.label }}</th>
            <td
              v-for="(val, i) in row.values"
              :key="i"
              class="compare__cell tabular"
              :class="{ 'compare__cell--accent': row.accent }"
            >
              {{ val }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.compare {
  padding-block: var(--space-8);
}
.compare__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
  margin-bottom: var(--space-6);
}
.compare__scroll {
  overflow-x: auto;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-bg);
}
.compare__table {
  width: 100%;
  border-collapse: collapse;
  min-width: 520px;
}
.compare__table th,
.compare__table td {
  padding: var(--space-4);
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid var(--color-border-subtle);
  border-right: 1px solid var(--color-border-subtle);
}
.compare__table th:last-child,
.compare__table td:last-child {
  border-right: 0;
}
.compare__table tbody tr:last-child th,
.compare__table tbody tr:last-child td {
  border-bottom: 0;
}
.compare__corner {
  background: var(--color-bg-section);
  font-size: var(--fs-xs);
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--color-text-secondary);
  width: 140px;
}
.compare__producthead {
  background: var(--color-bg-section);
  min-width: 200px;
}
.compare__bank {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-bottom: var(--space-1);
}
.compare__bank-name {
  font-family: var(--font-display);
  font-weight: 600;
  font-size: var(--fs-sm);
  color: var(--color-text-secondary);
}
.compare__title {
  display: block;
  font-family: var(--font-display);
  font-weight: 700;
  font-size: var(--fs-md);
  color: var(--color-text-primary);
  line-height: 1.3;
  margin-bottom: var(--space-3);
}
.compare__title:hover {
  color: var(--color-primary);
}
.compare__headinner {
  display: flex;
  flex-direction: column;
  height: 100%;
}
.compare__head-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  /* Прижимаем кнопки к низу ячейки — выравниваются по всем колонкам. */
  margin-top: auto;
  padding-top: var(--space-3);
}
.compare__rowlabel {
  font-size: var(--fs-sm);
  font-weight: 600;
  color: var(--color-text-secondary);
  background: var(--color-bg-offwhite);
}
.compare__cell {
  font-size: var(--fs-sm);
  color: var(--color-text-primary);
}
.compare__cell--accent {
  font-family: var(--font-display);
  font-weight: 700;
  color: var(--color-primary);
}
.compare__row--diff .compare__cell {
  background: var(--color-primary-soft);
}
</style>
