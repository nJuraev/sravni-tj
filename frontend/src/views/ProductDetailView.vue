<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { Locale, Product } from '@/types/api'
import { api } from '@/api/client'
import { ApiError } from '@/api/errors'
import { useLocalizedField } from '@/composables/useLocalizedField'
import { useProductDisplay } from '@/composables/useProductDisplay'
import { useCompareStore } from '@/stores/compare'
import { useLeadModalStore } from '@/stores/leadModal'
import { formatMoney, formatRateRange } from '@/lib/format'
import RateTierTable from '@/components/product/RateTierTable.vue'
import ProductCalculator from '@/components/product/ProductCalculator.vue'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseCard from '@/components/ui/BaseCard.vue'
import StateMessage from '@/components/ui/StateMessage.vue'
import SkeletonCard from '@/components/ui/SkeletonCard.vue'

const props = defineProps<{ id: number }>()

const { t, locale } = useI18n()
const { name, value } = useLocalizedField()
const { activeFeatures, featureLabel, categoryLabel } = useProductDisplay()
const compare = useCompareStore()
const leadModal = useLeadModalStore()

const loc = computed(() => locale.value as Locale)
const product = ref<Product | null>(null)
const status = ref<'loading' | 'loaded' | 'not-found' | 'error'>('loading')

const showsRate = computed(
  () => product.value && !(product.value.category === 'installment' && product.value.rate_max === 0),
)
const inCompare = computed(() => (product.value ? compare.has(product.value.id) : false))
const features = computed(() => (product.value ? activeFeatures(product.value) : []))
const description = computed(() =>
  product.value ? value(product.value.description_ru, product.value.description_tg) : '',
)

const parsedAt = computed(() => {
  if (!product.value?.parsed_at) return ''
  try {
    return new Intl.DateTimeFormat(loc.value === 'tg' ? 'tg-Cyrl-TJ' : 'ru-RU', {
      dateStyle: 'long',
      timeStyle: 'short',
    }).format(new Date(product.value.parsed_at))
  } catch {
    return product.value.parsed_at
  }
})

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

let requestId = 0
async function load(id: number) {
  const reqId = ++requestId
  status.value = 'loading'
  product.value = null
  try {
    const res = await api.getProduct(id)
    if (reqId !== requestId) return
    product.value = res.data
    status.value = 'loaded'
  } catch (err) {
    if (reqId !== requestId) return
    if (err instanceof ApiError && err.isNotFound) status.value = 'not-found'
    else status.value = 'error'
  }
}

function toggleCompare() {
  if (!product.value) return
  const ok = compare.toggle(product.value)
  if (!ok) window.alert(t('compare.limitReached'))
}

watch(() => props.id, (id) => load(id), { immediate: true })
</script>

<template>
  <div class="detail container">
    <RouterLink to="/credit" class="detail__back">‹ {{ t('common.back') }}</RouterLink>

    <div v-if="status === 'loading'" class="detail__loading">
      <SkeletonCard />
      <SkeletonCard />
    </div>

    <StateMessage
      v-else-if="status === 'not-found'"
      :title="t('product.notFoundTitle')"
      :hint="t('product.notFoundHint')"
    >
      <template #action>
        <RouterLink to="/credit">
          <BaseButton variant="secondary">{{ t('common.back') }}</BaseButton>
        </RouterLink>
      </template>
    </StateMessage>

    <StateMessage
      v-else-if="status === 'error'"
      tone="error"
      :title="t('catalog.errorTitle')"
      :hint="t('catalog.errorHint')"
    >
      <template #action>
        <BaseButton @click="load(id)">{{ t('common.retry') }}</BaseButton>
      </template>
    </StateMessage>

    <article v-else-if="product" class="detail__body">
      <header class="detail__header">
        <div class="detail__meta">
          <BaseBadge tone="neutral">{{ categoryLabel(product) }}</BaseBadge>
          <BaseBadge tone="muted">{{ product.currency }}</BaseBadge>
          <BaseBadge v-if="product.bank.is_partner" tone="green">{{ t('common.partner') }}</BaseBadge>
        </div>
        <h1>{{ name(product) }}</h1>
        <p class="detail__bank">{{ name(product.bank) }}</p>
        <p v-if="description" class="detail__desc">{{ description }}</p>
      </header>

      <div class="detail__grid">
        <div class="detail__main">
          <BaseCard>
            <h2 class="detail__section-title">{{ t('product.conditions') }}</h2>
            <dl class="detail__stats">
              <div v-if="showsRate">
                <dt>{{ t('product.rate') }}</dt>
                <dd class="tabular detail__rate">
                  {{ formatRateRange(product.rate_min, product.rate_max, loc) }}
                  <span class="detail__peryear">{{ t('common.perYear') }}</span>
                </dd>
              </div>
              <div>
                <dt>{{ t('product.amount') }}</dt>
                <dd class="tabular">{{ amountText(product) }}</dd>
              </div>
              <div>
                <dt>{{ t('product.term') }}</dt>
                <dd class="tabular">{{ termText(product) }}</dd>
              </div>
            </dl>
          </BaseCard>

          <BaseCard>
            <h2 class="detail__section-title">{{ t('product.rateGrid') }}</h2>
            <RateTierTable :tiers="product.rate_tiers" :currency="product.currency" />
          </BaseCard>

          <BaseCard v-if="features.length">
            <h2 class="detail__section-title">{{ t('product.requirements') }}</h2>
            <ul class="detail__features" role="list">
              <li v-for="f in features" :key="f">
                <BaseBadge tone="muted">{{ featureLabel(f) }}</BaseBadge>
              </li>
            </ul>
          </BaseCard>

          <p v-if="parsedAt" class="detail__parsed">{{ t('product.parsedAt') }}: {{ parsedAt }}</p>
        </div>

        <aside class="detail__aside">
          <ProductCalculator :product="product" />
          <div class="detail__actions">
            <BaseButton variant="primary" block @click="leadModal.open(product)">
              {{ t('common.apply') }}
            </BaseButton>
            <BaseButton
              :variant="inCompare ? 'ghost' : 'secondary'"
              block
              @click="toggleCompare"
            >
              {{ inCompare ? t('common.inCompare') : t('common.addToCompare') }}
            </BaseButton>
          </div>
        </aside>
      </div>
    </article>
  </div>
</template>

<style scoped>
.detail {
  padding-block: var(--space-8);
}
.detail__back {
  display: inline-block;
  margin-bottom: var(--space-5);
  font-weight: 600;
}
.detail__loading {
  display: grid;
  gap: var(--space-5);
  max-width: 640px;
}
.detail__header {
  margin-bottom: var(--space-6);
}
.detail__meta {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-bottom: var(--space-3);
}
.detail__bank {
  margin-top: var(--space-1);
  color: var(--color-text-secondary);
  font-weight: 600;
}
.detail__desc {
  margin-top: var(--space-3);
  max-width: 60ch;
  color: var(--color-text-secondary);
}
.detail__grid {
  display: grid;
  grid-template-columns: 1fr 380px;
  gap: var(--space-6);
  align-items: start;
}
.detail__main {
  display: flex;
  flex-direction: column;
  gap: var(--space-5);
}
.detail__section-title {
  font-size: var(--fs-lg);
  margin-bottom: var(--space-4);
}
.detail__stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: var(--space-4);
  margin: 0;
}
.detail__stats dt {
  font-size: var(--fs-xs);
  color: var(--color-text-muted);
  margin-bottom: 2px;
}
.detail__stats dd {
  margin: 0;
  font-family: var(--font-display);
  font-weight: 600;
  font-size: var(--fs-md);
}
.detail__rate {
  color: var(--color-primary);
}
.detail__peryear {
  font-size: var(--fs-xs);
  font-weight: 500;
  color: var(--color-text-muted);
}
.detail__features {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}
.detail__parsed {
  font-size: var(--fs-sm);
  color: var(--color-text-muted);
}
.detail__aside {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  position: sticky;
  top: calc(var(--header-height) + var(--space-4));
}
.detail__actions {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}
@media (max-width: 900px) {
  .detail__grid {
    grid-template-columns: 1fr;
  }
  .detail__aside {
    position: static;
  }
}
@media (max-width: 480px) {
  .detail__stats {
    grid-template-columns: 1fr;
    gap: var(--space-3);
  }
}
</style>
