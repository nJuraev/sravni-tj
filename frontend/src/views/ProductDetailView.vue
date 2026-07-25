<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import RouterLink from '@/components/nav/LocaleLink.vue'
import type { Currency, Locale, Product, ProductVariant } from '@/types/api'
import { useApi } from '@/composables/useApi'
import { useSeo } from '@/composables/useSeo'
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
import FaqBlock, { type FaqItem } from '@/components/seo/FaqBlock.vue'

const props = defineProps<{ id: number }>()

const { t, locale } = useI18n()
const api = useApi()
const { name, value } = useLocalizedField()
const { activeFeatures, featureLabel, categoryLabel } = useProductDisplay()
const compare = useCompareStore()
const leadModal = useLeadModalStore()

const loc = computed(() => locale.value as Locale)
const product = ref<Product | null>(null)
const status = ref<'loading' | 'loaded' | 'not-found' | 'error'>('loading')

// Валютные варианты той же группы (source_url_id, см. backend ProductController).
// Бэкенд отдаёт их в product.variants только на GET /api/products/{id}; если поле
// отсутствует (старый кэш/моки) — считаем сам загруженный продукт единственным вариантом.
function toVariant(p: Product): ProductVariant {
  return {
    id: p.id,
    currency: p.currency,
    rate_min: p.rate_min,
    rate_max: p.rate_max,
    amount_min: p.amount_min,
    amount_max: p.amount_max,
    term_min: p.term_min,
    term_max: p.term_max,
    rate_tiers: p.rate_tiers,
    features: p.features,
    key_conditions_ru: p.key_conditions_ru,
    key_conditions_tg: p.key_conditions_tg,
    documents_ru: p.documents_ru,
    documents_tg: p.documents_tg,
    source_url: p.source_url,
    parsed_at: p.parsed_at,
  }
}

const variants = computed<ProductVariant[]>(() => {
  if (!product.value) return []
  return product.value.variants?.length ? product.value.variants : [toVariant(product.value)]
})

const activeCurrency = ref<Currency | null>(null)
// Табы валют нужны только когда их больше одной (см. решение по продукту).
const showsCurrencyTabs = computed(() => variants.value.length > 1)

const activeVariant = computed<ProductVariant | null>(
  () => variants.value.find((v) => v.currency === activeCurrency.value) ?? variants.value[0] ?? null,
)

// Общие поля (name/description/bank/category) — из product; валютно-зависимые —
// из activeVariant. Используется для калькулятора/сравнения/заявки, которым нужна
// полная форма Product с учётом выбранной валютной строки (её собственный id).
const displayProduct = computed<Product | null>(() => {
  if (!product.value || !activeVariant.value) return null
  return { ...product.value, ...activeVariant.value }
})

const showsRate = computed(
  () => displayProduct.value && !(displayProduct.value.category === 'installment' && displayProduct.value.rate_max === 0),
)
const inCompare = computed(() => (displayProduct.value ? compare.has(displayProduct.value.id) : false))
const features = computed(() => (displayProduct.value ? activeFeatures(displayProduct.value) : []))
const description = computed(() =>
  product.value ? value(product.value.description_ru, product.value.description_tg) : '',
)
const keyConditions = computed(() =>
  displayProduct.value ? (loc.value === 'tj' && displayProduct.value.key_conditions_tg?.length ? displayProduct.value.key_conditions_tg : displayProduct.value.key_conditions_ru) ?? [] : [],
)
const documents = computed(() =>
  displayProduct.value ? (loc.value === 'tj' && displayProduct.value.documents_tg?.length ? displayProduct.value.documents_tg : displayProduct.value.documents_ru) ?? [] : [],
)

const parsedAt = computed(() => {
  if (!displayProduct.value?.parsed_at) return ''
  try {
    return new Intl.DateTimeFormat(loc.value === 'tj' ? 'tg-Cyrl-TJ' : 'ru-RU', {
      dateStyle: 'long',
      timeStyle: 'short',
    }).format(new Date(displayProduct.value.parsed_at))
  } catch {
    return displayProduct.value.parsed_at
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

const seoTitle = computed(() =>
  displayProduct.value ? t('product.seoTitle', { name: name(displayProduct.value), bank: name(displayProduct.value.bank) }) : t('product.notFoundTitle'),
)
const seoDescription = computed(() =>
  displayProduct.value
    ? t('product.seoDescription', {
        name: name(displayProduct.value),
        bank: name(displayProduct.value.bank),
        rateMin: displayProduct.value.rate_min,
        rateMax: displayProduct.value.rate_max,
        amount: amountText(displayProduct.value),
        term: termText(displayProduct.value),
      })
    : t('product.notFoundHint'),
)
// FinancialProduct JSON-LD — reactive to the currency tabs (rate/amount range
// changes per variant without a remount, same route/component instance).
const seoJsonLd = computed(() => {
  if (!product.value || !displayProduct.value) return undefined
  const p = displayProduct.value
  return [
    {
      '@context': 'https://schema.org',
      '@type': 'FinancialProduct',
      name: name(p),
      description: description.value || undefined,
      category: categoryLabel(product.value),
      provider: { '@type': 'BankOrCreditUnion', name: name(p.bank) },
      interestRate: {
        '@type': 'QuantitativeValue',
        minValue: p.rate_min,
        maxValue: p.rate_max,
        unitText: 'percent per year',
      },
      amount: {
        '@type': 'MonetaryAmount',
        currency: p.currency,
        minValue: p.amount_min ?? undefined,
        maxValue: p.amount_max ?? undefined,
      },
    },
  ]
})
useSeo({ title: seoTitle, description: seoDescription, jsonLd: seoJsonLd })

// GEO Q&A — concrete numbers already loaded for this product (never
// fabricated). Copy owner should expand/refine this list with real content;
// this is the engineering scaffold (see project memory: sravni-seo-competitors, GEO §4).
const faqItems = computed<FaqItem[]>(() => {
  if (!displayProduct.value) return []
  const p = displayProduct.value
  const items: FaqItem[] = [
    {
      question: t('product.faq.q1', { name: name(p), bank: name(p.bank) }),
      answer: t('product.faq.a1', {
        name: name(p),
        bank: name(p.bank),
        rateMin: p.rate_min,
        rateMax: p.rate_max,
        amount: amountText(p),
        term: termText(p),
      }),
    },
  ]
  if (documents.value.length) {
    items.push({
      question: t('product.faq.q2'),
      answer: t('product.faq.a2', { name: name(p), list: documents.value.join(', ') }),
    })
  }
  return items
})

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
  if (!displayProduct.value) return
  const ok = compare.toggle(displayProduct.value)
  if (!ok) window.alert(t('compare.limitReached'))
}

// Реагирует только на смену ЗАГРУЖЕННОГО продукта (не на клик по табу валюты,
// который меняет activeCurrency, но не product.value.id) — при новой загрузке
// открываем ту валюту, по которой пришли (карточка/ссылка на конкретный id).
watch(
  () => product.value?.id,
  () => {
    if (product.value) activeCurrency.value = product.value.currency
  },
)

// Awaited so SSR's renderToString actually waits for real data; later id
// changes (navigating between products without a full reload) are still
// picked up client-side via the watch below.
await load(props.id)
watch(() => props.id, (id) => load(id))
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

    <article v-else-if="product && displayProduct" class="detail__body">
      <header class="detail__header">
        <div class="detail__meta">
          <BaseBadge tone="neutral">{{ categoryLabel(product) }}</BaseBadge>
          <BaseBadge v-if="!showsCurrencyTabs" tone="muted">{{ displayProduct.currency }}</BaseBadge>
          <BaseBadge v-if="product.bank.is_partner" tone="green">{{ t('common.partner') }}</BaseBadge>
        </div>
        <h1>{{ name(product) }}</h1>
        <RouterLink :to="`/bank/${product.bank.id}`" class="detail__bank">{{ name(product.bank) }}</RouterLink>
        <p v-if="description" class="detail__desc">{{ description }}</p>

        <div v-if="showsCurrencyTabs" class="detail__currency-tabs" role="tablist">
          <button
            v-for="v in variants"
            :key="v.currency"
            type="button"
            role="tab"
            :aria-selected="activeCurrency === v.currency"
            class="detail__currency-tab"
            :class="{ 'detail__currency-tab--active': activeCurrency === v.currency }"
            @click="activeCurrency = v.currency"
          >
            {{ v.currency }}
          </button>
        </div>
      </header>

      <div class="detail__grid">
        <div class="detail__main">
          <BaseCard>
            <h2 class="detail__section-title">{{ t('product.conditions') }}</h2>
            <dl class="detail__stats">
              <div v-if="showsRate">
                <dt>{{ t('product.rate') }}</dt>
                <dd class="tabular detail__rate">
                  {{ formatRateRange(displayProduct.rate_min, displayProduct.rate_max, loc) }}
                  <span class="detail__peryear">{{ t('common.perYear') }}</span>
                </dd>
              </div>
              <div>
                <dt>{{ t('product.amount') }}</dt>
                <dd class="tabular">{{ amountText(displayProduct) }}</dd>
              </div>
              <div>
                <dt>{{ t('product.term') }}</dt>
                <dd class="tabular">{{ termText(displayProduct) }}</dd>
              </div>
            </dl>
          </BaseCard>

          <BaseCard>
            <h2 class="detail__section-title">{{ t('product.rateGrid') }}</h2>
            <RateTierTable :tiers="displayProduct.rate_tiers" :currency="displayProduct.currency" />
          </BaseCard>

          <BaseCard v-if="features.length">
            <h2 class="detail__section-title">{{ t('product.requirements') }}</h2>
            <ul class="detail__features" role="list">
              <li v-for="f in features" :key="f">
                <BaseBadge tone="muted">{{ featureLabel(f) }}</BaseBadge>
              </li>
            </ul>
          </BaseCard>

          <BaseCard v-if="keyConditions.length">
            <h2 class="detail__section-title">{{ t('product.keyConditions') }}</h2>
            <ul class="detail__list" role="list">
              <li v-for="(c, i) in keyConditions" :key="i">{{ c }}</li>
            </ul>
          </BaseCard>

          <BaseCard v-if="documents.length">
            <h2 class="detail__section-title">{{ t('product.documents') }}</h2>
            <ul class="detail__list" role="list">
              <li v-for="(d, i) in documents" :key="i">{{ d }}</li>
            </ul>
          </BaseCard>

          <p v-if="parsedAt" class="detail__parsed">{{ t('product.parsedAt') }}: {{ parsedAt }}</p>
          <a
            v-if="displayProduct.source_url"
            :href="displayProduct.source_url"
            target="_blank"
            rel="noopener"
            class="detail__source-link"
          >{{ t('product.viewOnBankSite') }} ↗</a>

          <FaqBlock :title="t('product.faq.title')" :items="faqItems" />
        </div>

        <aside class="detail__aside">
          <ProductCalculator :product="displayProduct" />
          <div class="detail__actions">
            <BaseButton variant="primary" block @click="leadModal.open(displayProduct)">
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
  display: inline-block;
  margin-top: var(--space-1);
  color: var(--color-text-secondary);
  font-weight: 600;
  text-decoration: none;
}
.detail__bank:hover {
  color: var(--color-primary);
}
.detail__desc {
  margin-top: var(--space-3);
  max-width: 60ch;
  color: var(--color-text-secondary);
}
.detail__currency-tabs {
  display: inline-flex;
  gap: 2px;
  margin-top: var(--space-4);
  padding: 3px;
  background: var(--color-bg-section);
  border-radius: var(--radius-md);
}
.detail__currency-tab {
  min-width: 64px;
  padding: var(--space-2) var(--space-4);
  font-size: var(--fs-sm);
  font-weight: 600;
  color: var(--color-text-secondary);
  background: transparent;
  border: none;
  border-radius: calc(var(--radius-md) - 3px);
  cursor: pointer;
  transition: background-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
}
.detail__currency-tab--active {
  color: var(--color-text-primary);
  background: var(--color-bg);
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
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
.detail__list {
  margin: 0;
  padding-left: 1.2em;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  color: var(--color-text-secondary);
}
.detail__source-link {
  font-size: var(--fs-xs);
  color: var(--color-text-muted);
}
.detail__source-link:hover {
  color: var(--color-primary);
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
