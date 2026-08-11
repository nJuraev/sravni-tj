<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import RouterLink from '@/components/nav/LocaleLink.vue'
import type { Bank, Locale, Rate, RateCategory } from '@/types/api'
import { useLocalizedField } from '@/composables/useLocalizedField'
import { bankLogoUrl } from '@/lib/bankIcon'
import { formatNumber } from '@/lib/format'

const DEFAULT_VISIBLE = 3
/** Три основные валюты ниши — идут первыми, остальные уходят под «показать ещё». */
const PRIORITY_CURRENCIES = ['USD', 'EUR', 'RUB']

const props = defineProps<{
  bank: Bank
  byCategory: Record<RateCategory, Rate[]>
}>()

const { t, locale } = useI18n()
const { name } = useLocalizedField()
const loc = computed(() => locale.value as Locale)

const CATEGORIES: RateCategory[] = ['cash', 'transfer']

function hasRows(cat: RateCategory): boolean {
  return props.byCategory[cat].length > 0
}

const activeTab = ref<RateCategory>(CATEGORIES.find(hasRows) ?? 'cash')
watch(
  () => props.byCategory,
  () => {
    if (!hasRows(activeTab.value)) activeTab.value = CATEGORIES.find(hasRows) ?? 'cash'
  },
)

const expanded = ref<Record<RateCategory, boolean>>({ cash: false, transfer: false })

const activeRows = computed(() => {
  const rows = props.byCategory[activeTab.value]
  return [...rows].sort((a, b) => {
    const rankA = PRIORITY_CURRENCIES.indexOf(a.currency)
    const rankB = PRIORITY_CURRENCIES.indexOf(b.currency)
    if (rankA === -1 && rankB === -1) return 0
    if (rankA === -1) return 1
    if (rankB === -1) return -1
    return rankA - rankB
  })
})
const visibleRows = computed(() =>
  expanded.value[activeTab.value] ? activeRows.value : activeRows.value.slice(0, DEFAULT_VISIBLE),
)
const hiddenCount = computed(() => Math.max(0, activeRows.value.length - DEFAULT_VISIBLE))

function bankInitial(b: Bank): string {
  return (name(b) || '?').trim().charAt(0).toUpperCase()
}
</script>

<template>
  <article class="bankrates">
    <RouterLink :to="`/bank/${bank.id}`" class="bankrates__head">
      <span class="bankrates__logo" aria-hidden="true">
        <span class="bankrates__fallback">{{ bankInitial(bank) }}</span>
        <img
          v-if="bankLogoUrl(bank)"
          :src="bankLogoUrl(bank)"
          :alt="name(bank)"
          class="bankrates__img"
          loading="lazy"
        />
      </span>
      <span class="bankrates__name">{{ name(bank) }}</span>
    </RouterLink>

    <div class="bankrates__tabs-wrap">
      <div class="bankrates__tabs" role="tablist">
        <button
          v-for="cat in CATEGORIES"
          :key="cat"
          type="button"
          role="tab"
          :disabled="!hasRows(cat)"
          :aria-selected="activeTab === cat"
          class="bankrates__tab"
          :class="{ 'bankrates__tab--active': activeTab === cat }"
          @click="hasRows(cat) && (activeTab = cat)"
        >
          {{ t(`ratesPage.${cat}`) }}
        </button>
      </div>
    </div>

    <div class="bankrates__section">
      <div class="rate-row rate-row--head">
        <span class="rate-row__ccy" />
        <span class="rate-row__lbl">{{ t('home.rates.buy') }}</span>
        <span class="rate-row__lbl">{{ t('home.rates.sell') }}</span>
      </div>
      <div v-for="r in visibleRows" :key="`${r.currency}-${r.category}`" class="rate-row">
        <span class="rate-row__ccy">{{ r.currency }}</span>
        <span class="rate-row__val tabular">
          {{ r.buy != null ? formatNumber(r.buy, loc, 4) : '—' }}
        </span>
        <span class="rate-row__val tabular">
          {{ r.sell != null ? formatNumber(r.sell, loc, 4) : '—' }}
        </span>
      </div>

      <button
        v-if="hiddenCount > 0 || expanded[activeTab]"
        type="button"
        class="bankrates__toggle"
        @click="expanded[activeTab] = !expanded[activeTab]"
      >
        {{ expanded[activeTab] ? t('ratesPage.showLess') : t('ratesPage.showMore', { count: hiddenCount }) }}
      </button>
    </div>
  </article>
</template>

<style scoped>
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
.bankrates__tabs-wrap {
  padding: var(--space-4) var(--space-6) 0;
}
.bankrates__tabs {
  display: flex;
  gap: 2px;
  padding: 3px;
  background: var(--color-bg-section);
  border-radius: var(--radius-md);
}
.bankrates__tab {
  flex: 1;
  padding: var(--space-2) var(--space-2);
  font-size: var(--fs-xs);
  font-weight: 600;
  color: var(--color-text-secondary);
  background: transparent;
  border: none;
  border-radius: calc(var(--radius-md) - 3px);
  cursor: pointer;
  transition: background-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
}
.bankrates__tab--active {
  color: var(--color-text-primary);
  background: var(--color-bg);
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}
.bankrates__tab:disabled {
  color: var(--color-text-muted);
  cursor: not-allowed;
  opacity: 0.5;
}
.bankrates__section {
  padding: 0 var(--space-6) var(--space-2);
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
  text-align: center;
}
.rate-row__val {
  font-weight: 700;
  font-size: var(--fs-md);
  text-align: center;
}
.bankrates__toggle {
  display: block;
  width: 100%;
  margin-top: var(--space-2);
  padding: var(--space-3) 0;
  font-size: var(--fs-sm);
  font-weight: 700;
  color: var(--color-primary);
  background: none;
  border: none;
  border-top: 1px solid var(--color-border-subtle);
  cursor: pointer;
  text-align: center;
}
.bankrates__toggle:hover {
  color: var(--color-primary-dark);
}

@media (max-width: 520px) {
  .rate-row {
    grid-template-columns: 40px 1fr 1fr;
    gap: var(--space-2);
  }
}
</style>
