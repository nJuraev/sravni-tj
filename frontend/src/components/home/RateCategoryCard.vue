<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { Locale } from '@/types/api'
import { formatNumber } from '@/lib/format'

export interface RateCategoryRow {
  currency: string
  buy: number
  sell: number
  bankName: string
}

defineProps<{
  title: string
  tag: string
  rows: RateCategoryRow[]
}>()

const { t, locale } = useI18n()
</script>

<template>
  <div class="rate-category">
    <div class="rate-category__head">
      <h3>{{ title }}</h3>
      <span class="rate-category__tag">{{ tag }}</span>
    </div>
    <div class="rate-category__body">
      <div v-for="row in rows" :key="row.currency" class="rate-line">
        <div class="rate-line__ccy">{{ row.currency }}</div>
        <div class="rate-line__vals">
          <div class="rate-op">
            <div class="rate-op__lbl">{{ t('home.rates.buy') }}</div>
            <div class="rate-op__val tabular">{{ formatNumber(row.buy, locale as Locale, 4) }}</div>
          </div>
          <div class="rate-op">
            <div class="rate-op__lbl">{{ t('home.rates.sell') }}</div>
            <div class="rate-op__val tabular">{{ formatNumber(row.sell, locale as Locale, 4) }}</div>
          </div>
        </div>
        <div class="rate-line__bank">{{ row.bankName }}</div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.rate-category {
  background: var(--color-bg);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  overflow: hidden;
}
.rate-category__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  padding: var(--space-5) var(--space-6);
  background: var(--color-text-primary);
  color: #fff;
}
.rate-category__head h3 {
  font-family: var(--font-display);
  font-weight: 800;
  font-size: var(--fs-lg);
  margin: 0;
}
.rate-category__tag {
  font-size: var(--fs-xs);
  color: rgba(255, 255, 255, 0.45);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-weight: 700;
}
.rate-category__body {
  padding: 0 var(--space-6);
}
.rate-line {
  display: grid;
  grid-template-columns: 52px 1fr auto;
  align-items: center;
  gap: var(--space-4);
  padding: var(--space-5) 0;
  border-top: 1px solid var(--color-border-subtle);
}
.rate-line:first-child {
  border-top: none;
}
.rate-line__ccy {
  font-family: var(--font-display);
  font-weight: 800;
  font-size: var(--fs-lg);
}
.rate-line__vals {
  display: flex;
  gap: var(--space-6);
}
.rate-op__lbl {
  font-size: var(--fs-xs);
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 2px;
}
.rate-op__val {
  font-weight: 700;
  font-size: var(--fs-md);
}
.rate-line__bank {
  font-size: var(--fs-xs);
  color: var(--color-text-secondary);
  font-weight: 700;
  text-align: right;
}

@media (max-width: 520px) {
  .rate-line {
    grid-template-columns: 1fr;
    row-gap: var(--space-2);
  }
  .rate-line__bank {
    text-align: left;
  }
}
</style>
