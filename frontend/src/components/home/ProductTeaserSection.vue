<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { Category, Product } from '@/types/api'
import { api } from '@/api/client'
import { ApiError } from '@/api/errors'
import ProductCard from '@/components/catalog/ProductCard.vue'
import SkeletonCard from '@/components/ui/SkeletonCard.vue'

const props = defineProps<{
  category: Category
  title: string
  ctaLabel: string
  ctaTo: string
  tinted?: boolean
}>()

const { t } = useI18n()
const status = ref<'loading' | 'loaded' | 'error'>('loading')
const products = ref<Product[]>([])

onMounted(async () => {
  try {
    // Дефолтная сортировка эндпоинта уже «лучшее предложение первым»
    // (credits: rate_min asc, deposits: -rate_max desc) — доп. sort не нужен.
    const res = await api.getProducts({ category: props.category, per_page: 3 })
    products.value = res.data
    status.value = res.data.length === 0 ? 'error' : 'loaded'
  } catch (err) {
    status.value = 'error'
    if (!(err instanceof ApiError)) throw err
  }
})
</script>

<template>
  <section v-if="status !== 'error'" class="block" :class="{ tinted }">
    <div class="container">
      <div class="section-head">
        <h2 class="section-title">{{ title }}</h2>
        <RouterLink class="section-cta" :to="ctaTo">{{ ctaLabel }} →</RouterLink>
      </div>

      <div v-if="status === 'loading'" class="teaser-grid" aria-busy="true" :aria-label="t('common.loading')">
        <SkeletonCard v-for="n in 3" :key="n" />
      </div>
      <div v-else class="teaser-list">
        <ProductCard v-for="p in products" :key="p.id" :product="p" />
      </div>
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
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  margin-bottom: var(--space-8);
  border-bottom: 2px solid var(--color-text-primary);
  padding-bottom: var(--space-5);
  gap: var(--space-4);
}
.section-title {
  font-family: var(--font-display);
  font-size: var(--fs-3xl);
  font-weight: 800;
  letter-spacing: -0.02em;
  margin: 0;
}
.section-cta {
  font-weight: 700;
  font-size: var(--fs-sm);
  color: var(--color-primary);
  white-space: nowrap;
}
.section-cta:hover {
  color: var(--color-primary-dark);
}
.teaser-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: var(--space-6);
}
.teaser-list {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

@media (max-width: 860px) {
  .section-head {
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-3);
  }
  .teaser-grid {
    grid-template-columns: 1fr;
  }
}
</style>
