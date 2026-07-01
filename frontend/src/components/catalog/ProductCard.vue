<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { Locale, Product } from '@/types/api'
import { useLocalizedField } from '@/composables/useLocalizedField'
import { useProductDisplay } from '@/composables/useProductDisplay'
import { useCompareStore } from '@/stores/compare'
import { useLeadModalStore } from '@/stores/leadModal'
import { formatMoney, formatRateRangeValue } from '@/lib/format'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'

const props = defineProps<{ product: Product }>()

const { t, locale } = useI18n()
const { name } = useLocalizedField()
const { activeFeatures, featureLabel, categoryLabel, subcategoryLabel } = useProductDisplay()
const compare = useCompareStore()
const leadModal = useLeadModalStore()

const loc = computed(() => locale.value as Locale)

// Interest-free installment (rate_max === 0) hides the percentage entirely.
const showsRate = computed(
  () => !(props.product.category === 'installment' && props.product.rate_max === 0),
)

// Число без «%» — unit рисуется отдельным span'ом (иначе дублировался знак %).
const rateText = computed(() =>
  formatRateRangeValue(props.product.rate_min, props.product.rate_max, loc.value),
)

const amountText = computed(() => {
  const p = props.product
  const from = p.amount_min != null ? formatMoney(p.amount_min, p.currency, loc.value) : null
  if (p.amount_max == null) return from ? `${t('common.from')} ${from}` : '—'
  const to = formatMoney(p.amount_max, p.currency, loc.value)
  return from ? `${from} – ${to}` : `${t('common.to')} ${to}`
})

const termText = computed(() => {
  const p = props.product
  const unit = t('common.months')
  if (p.term_min == null && p.term_max == null) return '—'
  if (p.term_min == null) return `${t('common.to')} ${p.term_max} ${unit}`
  if (p.term_max == null) return `${t('common.from')} ${p.term_min} ${unit}`
  return `${p.term_min}–${p.term_max} ${unit}`
})

const ratingValue = computed(() =>
  props.product.bank.rating_avg != null ? props.product.bank.rating_avg.toFixed(1) : null,
)
const ratingCount = computed(() => props.product.bank.rating_count ?? 0)

// Bank logo placeholder: first letter of the (localized) bank name.
const bankInitial = computed(() => (name(props.product.bank) || '?').trim().charAt(0).toUpperCase())

const inCompare = computed(() => compare.has(props.product.id))
const features = computed(() => activeFeatures(props.product))

function toggleCompare() {
  const ok = compare.toggle(props.product)
  if (!ok) window.alert(t('compare.limitReached'))
}
</script>

<template>
  <article class="prow">
    <!-- Левый блок: банк, рейтинг, название продукта, фичи -->
    <div class="prow__main">
      <div class="prow__bankline">
        <span class="prow__logo" aria-hidden="true">{{ bankInitial }}</span>
        <div class="prow__bankinfo">
          <div class="prow__bankrow">
            <span class="prow__bank">{{ name(product.bank) }}</span>
            <BaseBadge v-if="product.bank.is_partner" tone="green">{{ t('common.partner') }}</BaseBadge>
          </div>
          <div v-if="ratingCount > 0" class="prow__rating">
            <span class="prow__star" aria-hidden="true">★</span>
            <span class="prow__rating-val tabular">{{ ratingValue }}</span>
            <span class="prow__rating-count">{{ t('rating.reviews', { count: ratingCount }) }}</span>
          </div>
          <span v-else class="prow__rating prow__rating--none">{{ t('rating.none') }}</span>
        </div>
      </div>

      <RouterLink :to="`/product/${product.id}`" class="prow__title">
        {{ name(product) }}
      </RouterLink>

      <ul class="prow__features" role="list">
        <li class="prow__cat"><BaseBadge tone="neutral">{{ product.currency }}</BaseBadge></li>
        <li v-if="product.is_special" class="prow__cat">
          <BaseBadge tone="green">{{ t('product.special') }}</BaseBadge>
        </li>
        <li v-for="f in features" :key="f">
          <BaseBadge tone="muted">{{ featureLabel(f) }}</BaseBadge>
        </li>
        <li class="prow__cat"><BaseBadge tone="neutral">{{ categoryLabel(product) }}</BaseBadge></li>
        <li v-if="product.subcategory" class="prow__cat">
          <BaseBadge tone="neutral">{{ subcategoryLabel(product.subcategory) }}</BaseBadge>
        </li>
      </ul>
    </div>

    <!-- Колонки показателей -->
    <dl class="prow__stats">
      <div v-if="showsRate" class="prow__stat prow__stat--rate">
        <dt>{{ t('product.rate') }}</dt>
        <dd class="tabular">{{ rateText }}<span class="prow__unit"> %</span></dd>
      </div>
      <div class="prow__stat">
        <dt>{{ t('product.amount') }}</dt>
        <dd class="tabular">{{ amountText }}</dd>
      </div>
      <div class="prow__stat">
        <dt>{{ t('product.term') }}</dt>
        <dd class="tabular">{{ termText }}</dd>
      </div>
    </dl>

    <!-- Действия -->
    <div class="prow__actions">
      <BaseButton variant="primary" size="sm" @click="leadModal.open(product)">
        {{ t('common.apply') }}
      </BaseButton>
      <BaseButton
        :variant="inCompare ? 'ghost' : 'secondary'"
        size="sm"
        :aria-pressed="inCompare"
        @click="toggleCompare"
      >
        {{ inCompare ? t('common.inCompare') : t('common.addToCompare') }}
      </BaseButton>
      <RouterLink :to="`/product/${product.id}`" class="prow__more">{{ t('common.more') }}</RouterLink>
    </div>
  </article>
</template>

<style scoped>
.prow {
  display: grid;
  grid-template-columns: minmax(0, 1.5fr) minmax(0, 2fr) minmax(160px, auto);
  gap: var(--space-5);
  align-items: center;
  padding: var(--space-5);
  background: var(--color-bg);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  transition: box-shadow 0.15s ease, border-color 0.15s ease;
}
.prow:hover {
  border-color: var(--color-primary-light);
  box-shadow: var(--shadow-md, 0 6px 20px rgba(0, 0, 0, 0.06));
}

.prow__main {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  min-width: 0;
}
.prow__bankline {
  display: flex;
  gap: var(--space-3);
  align-items: center;
}
.prow__logo {
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-md, 8px);
  background: var(--color-bg-section);
  color: var(--color-primary);
  font-family: var(--font-display);
  font-weight: 700;
  font-size: var(--fs-lg);
  display: grid;
  place-items: center;
}
.prow__bankinfo {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}
.prow__bankrow {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}
.prow__bank {
  font-weight: 600;
  font-size: var(--fs-sm);
  color: var(--color-text-secondary);
}
.prow__rating {
  display: flex;
  align-items: center;
  gap: var(--space-1);
  font-size: var(--fs-xs);
}
.prow__star {
  color: #f5a623;
}
.prow__rating-val {
  font-weight: 700;
  color: var(--color-text-primary);
}
.prow__rating-count {
  color: var(--color-text-muted);
}
.prow__rating--none {
  color: var(--color-text-muted);
}
.prow__title {
  font-family: var(--font-display);
  font-weight: 700;
  font-size: var(--fs-lg);
  color: var(--color-text-primary);
  line-height: 1.3;
}
.prow__title:hover {
  color: var(--color-primary);
}
.prow__features {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}

.prow__stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: var(--space-4);
  margin: 0;
  min-width: 0;
}
.prow__stat {
  min-width: 0;
}
.prow__stat dd {
  overflow-wrap: anywhere;
}
.prow__stat dt {
  font-size: var(--fs-xs);
  color: var(--color-text-muted);
  margin-bottom: 2px;
}
.prow__stat dd {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 600;
  font-size: var(--fs-base);
}
.prow__stat--rate dd {
  color: var(--color-primary);
  font-size: var(--fs-xl, 1.5rem);
}
.prow__unit {
  font-size: var(--fs-xs);
  font-weight: 500;
  color: var(--color-text-muted);
}

.prow__actions {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  align-items: stretch;
}
.prow__more {
  text-align: center;
  font-size: var(--fs-xs);
  color: var(--color-text-secondary);
}
.prow__more:hover {
  color: var(--color-primary);
}

@media (max-width: 1024px) {
  .prow {
    grid-template-columns: 1fr;
    gap: var(--space-4);
  }
  .prow__stats {
    padding-top: var(--space-4);
    border-top: 1px solid var(--color-border-subtle);
  }
}
@media (max-width: 480px) {
  .prow__stats {
    grid-template-columns: 1fr;
    gap: var(--space-2);
  }
}
</style>
