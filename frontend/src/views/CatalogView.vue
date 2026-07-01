<script setup lang="ts">
import { ref, watch, computed, toRef } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Category, Pagination, Product, ProductQuery } from '@/types/api'
import { api } from '@/api/client'
import { ApiError } from '@/api/errors'
import { useCatalogQuery } from '@/composables/useCatalogQuery'
import CatalogFilters from '@/components/catalog/CatalogFilters.vue'
import CatalogPagination from '@/components/catalog/CatalogPagination.vue'
import ProductCard from '@/components/catalog/ProductCard.vue'
import SkeletonCard from '@/components/ui/SkeletonCard.vue'
import StateMessage from '@/components/ui/StateMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'

const props = defineProps<{ category: Category }>()

const { t } = useI18n()
const category = toRef(props, 'category')
const { query, apply, setPage, reset } = useCatalogQuery(() => category.value)

const products = ref<Product[]>([])
const pagination = ref<Pagination | null>(null)
const status = ref<'loading' | 'loaded' | 'error'>('loading')

const title = computed(() => {
  if (category.value === 'deposit') return t('catalog.depositsTitle')
  if (category.value === 'installment') return t('catalog.installmentsTitle')
  return t('catalog.creditsTitle')
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

watch(query, (q) => load(q), { immediate: true, deep: true })
</script>

<template>
  <div class="catalog container">
    <header class="catalog__header">
      <h1>{{ title }}</h1>
      <p v-if="pagination" class="catalog__count">
        {{ t('catalog.found', { count: pagination.total_items }) }}
      </p>
    </header>

    <div class="catalog__layout">
      <aside class="catalog__sidebar">
        <CatalogFilters :query="query" @apply="(q) => apply(q)" @reset="reset" />
      </aside>

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
  </div>
</template>

<style scoped>
.catalog {
  padding-block: var(--space-8);
}
.catalog__header {
  margin-bottom: var(--space-6);
}
.catalog__count {
  margin-top: var(--space-1);
  color: var(--color-text-secondary);
}
.catalog__layout {
  display: grid;
  grid-template-columns: 300px 1fr;
  gap: var(--space-6);
  align-items: start;
}
.catalog__sidebar {
  position: sticky;
  top: calc(var(--header-height) + var(--space-4));
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
@media (max-width: 900px) {
  .catalog__layout {
    grid-template-columns: 1fr;
  }
  .catalog__sidebar {
    position: static;
  }
}
</style>
