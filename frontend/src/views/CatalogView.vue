<script setup lang="ts">
import { ref, watch, computed, toRef } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Category, Pagination, Product, ProductQuery } from '@/types/api'
import { useApi } from '@/composables/useApi'
import { useSeo } from '@/composables/useSeo'
import { ApiError } from '@/api/errors'
import { useCatalogQuery } from '@/composables/useCatalogQuery'
import CatalogFilters from '@/components/catalog/CatalogFilters.vue'
import CatalogPagination from '@/components/catalog/CatalogPagination.vue'
import ProductCard from '@/components/catalog/ProductCard.vue'
import SkeletonCard from '@/components/ui/SkeletonCard.vue'
import StateMessage from '@/components/ui/StateMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'

const props = defineProps<{ category: Category }>()

const { t } = useI18n()
const api = useApi()
const category = toRef(props, 'category')
const { query, apply, setPage, reset } = useCatalogQuery(() => category.value)

const sortOptions = computed(() => [
  { value: 'rate_min', label: t('catalog.sort.rate_min') },
  { value: '-rate_max', label: t('catalog.sort.-rate_max') },
  { value: 'amount_min', label: t('catalog.sort.amount_min') },
  { value: 'term_min', label: t('catalog.sort.term_min') },
])

function onSortChange(value: string) {
  apply({ ...query.value, sort: value })
}

const products = ref<Product[]>([])
const pagination = ref<Pagination | null>(null)
const status = ref<'loading' | 'loaded' | 'error'>('loading')

const title = computed(() => {
  if (category.value === 'deposit') return t('catalog.depositsTitle')
  if (category.value === 'installment') return t('catalog.installmentsTitle')
  return t('catalog.creditsTitle')
})

// Reactive (not snapshotted at setup time) — /credit ↔ /deposit ↔ /installment
// share this same route/component instance, so category changes client-side
// without a remount; useSeo's title/description must track that.
useSeo({
  title: computed(() => {
    if (category.value === 'deposit') return t('catalog.seoTitleDeposits')
    if (category.value === 'installment') return t('catalog.seoTitleInstallments')
    return t('catalog.seoTitleCredits')
  }),
  description: computed(() => {
    if (category.value === 'deposit') return t('catalog.seoDescriptionDeposits')
    if (category.value === 'installment') return t('catalog.seoDescriptionInstallments')
    return t('catalog.seoDescriptionCredits')
  }),
})

const isEmpty = computed(() => status.value === 'loaded' && products.value.length === 0)

let requestId = 0
async function load(q: ProductQuery) {
  const id = ++requestId
  status.value = 'loading'
  try {
    const res = await api.getProducts(q)
    if (id !== requestId) return // stale response
    products.value = res.data
    pagination.value = res.pagination
    status.value = 'loaded'
  } catch (err) {
    if (id !== requestId) return
    // 422 (bad filter) is also surfaced as an error state with retry.
    status.value = 'error'
    products.value = []
    pagination.value = null
    if (!(err instanceof ApiError)) throw err
  }
}

// Awaited so SSR's renderToString actually waits for real data instead of
// rendering the permanent loading skeleton; later query changes (filters,
// pagination) are still picked up client-side via the watch below.
await load(query.value)
watch(query, (q) => load(q), { deep: true })
</script>

<template>
  <div class="catalog container">
    <header class="catalog__header">
      <h1>{{ title }}</h1>
    </header>

    <CatalogFilters :query="query" @apply="apply" @reset="reset" />

    <div class="catalog__results-head">
      <p v-if="pagination" class="catalog__count">
        {{ t('catalog.found', { count: pagination.total_items }) }}
      </p>
      <BaseSelect
        :model-value="query.sort ?? ''"
        :label="t('catalog.sort.label')"
        :options="sortOptions"
        class="catalog__sort"
        @update:model-value="onSortChange"
      />
    </div>

    <section class="catalog__results" aria-live="polite">
      <div v-if="status === 'loading'" class="catalog__grid">
        <SkeletonCard v-for="n in 6" :key="n" />
      </div>

      <StateMessage
        v-else-if="status === 'error'"
        tone="error"
        :title="t('catalog.errorTitle')"
        :hint="t('catalog.errorHint')"
      >
        <template #action>
          <BaseButton @click="load(query)">{{ t('common.retry') }}</BaseButton>
        </template>
      </StateMessage>

      <StateMessage
        v-else-if="isEmpty"
        :title="t('catalog.empty')"
        :hint="t('catalog.emptyHint')"
      >
        <template #action>
          <BaseButton variant="secondary" @click="reset">{{ t('common.reset') }}</BaseButton>
        </template>
      </StateMessage>

      <template v-else>
        <div class="catalog__list">
          <ProductCard v-for="p in products" :key="p.id" :product="p" />
        </div>
        <CatalogPagination v-if="pagination" :pagination="pagination" @change="setPage" />
      </template>
    </section>
  </div>
</template>

<style scoped>
.catalog {
  padding-block: var(--space-8);
}
.catalog__header {
  margin-bottom: var(--space-6);
}
.catalog__results-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
  margin-block: var(--space-5);
}
.catalog__count {
  color: var(--color-text-secondary);
}
.catalog__sort {
  width: 260px;
}
.catalog__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: var(--space-5);
}
.catalog__list {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  margin-bottom: var(--space-6);
}
@media (max-width: 720px) {
  .catalog__results-head {
    flex-direction: column;
    align-items: stretch;
  }
  .catalog__sort {
    width: 100%;
  }
}
</style>
