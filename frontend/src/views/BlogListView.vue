<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import type { Article, ArticleCategory, Pagination } from '@/types/api'
import { useApi } from '@/composables/useApi'
import { useSeo } from '@/composables/useSeo'
import { useLocalizedField } from '@/composables/useLocalizedField'
import { ApiError } from '@/api/errors'
import ArticleCard from '@/components/blog/ArticleCard.vue'
import CatalogPagination from '@/components/catalog/CatalogPagination.vue'
import SkeletonCard from '@/components/ui/SkeletonCard.vue'
import StateMessage from '@/components/ui/StateMessage.vue'
import BaseButton from '@/components/ui/BaseButton.vue'

const { t } = useI18n()
const { name } = useLocalizedField()
const api = useApi()
const route = useRoute()
const router = useRouter()

const activeCategory = computed(() => (typeof route.query.category === 'string' ? route.query.category : ''))
const activePage = computed(() => {
  const p = Number(route.query.page)
  return Number.isFinite(p) && p > 0 ? p : 1
})

const categories = ref<ArticleCategory[]>([])
const articles = ref<Article[]>([])
const pagination = ref<Pagination | null>(null)
const status = ref<'loading' | 'loaded' | 'error'>('loading')
const isEmpty = computed(() => status.value === 'loaded' && articles.value.length === 0)

useSeo({
  title: t('blog.seoTitle'),
  description: t('blog.seoDescription'),
})

let requestId = 0
async function load(): Promise<void> {
  const id = ++requestId
  status.value = 'loading'
  try {
    const res = await api.getArticles({
      category: activeCategory.value || undefined,
      page: activePage.value,
    })
    if (id !== requestId) return
    articles.value = res.data
    pagination.value = res.pagination
    status.value = 'loaded'
  } catch (err) {
    if (id !== requestId) return
    status.value = 'error'
    articles.value = []
    pagination.value = null
    if (!(err instanceof ApiError)) throw err
  }
}

async function loadCategories(): Promise<void> {
  try {
    categories.value = (await api.getArticleCategories()).data
  } catch {
    categories.value = []
  }
}

function selectCategory(slug: string): void {
  router.push({ query: slug ? { category: slug } : {} })
}

function setPage(page: number): void {
  router.push({ query: { ...route.query, page: page > 1 ? String(page) : undefined } })
}

// Awaited so SSR renders real data; category/page changes handled client-side by the watch.
await Promise.all([load(), loadCategories()])
watch([activeCategory, activePage], () => load())
</script>

<template>
  <div class="blog container">
    <header class="blog__header">
      <h1>{{ t('blog.title') }}</h1>
      <p v-if="pagination" class="blog__count">{{ t('catalog.found', { count: pagination.total_items }) }}</p>
    </header>

    <nav v-if="categories.length" class="blog__categories" :aria-label="t('blog.categoriesLabel')">
      <button
        type="button"
        class="blog__cat"
        :class="{ 'blog__cat--active': !activeCategory }"
        @click="selectCategory('')"
      >
        {{ t('blog.allCategories') }}
      </button>
      <button
        v-for="c in categories"
        :key="c.id"
        type="button"
        class="blog__cat"
        :class="{ 'blog__cat--active': activeCategory === c.slug }"
        @click="selectCategory(c.slug)"
      >
        {{ name(c) }}
      </button>
    </nav>

    <div v-if="status === 'loading'" class="blog__grid">
      <SkeletonCard v-for="n in 6" :key="n" />
    </div>

    <StateMessage
      v-else-if="status === 'error'"
      tone="error"
      :title="t('catalog.errorTitle')"
      :hint="t('catalog.errorHint')"
    >
      <template #action>
        <BaseButton @click="load">{{ t('common.retry') }}</BaseButton>
      </template>
    </StateMessage>

    <StateMessage v-else-if="isEmpty" :title="t('blog.empty')" :hint="t('blog.emptyHint')" />

    <template v-else>
      <div class="blog__grid">
        <ArticleCard v-for="a in articles" :key="a.id" :article="a" />
      </div>
      <CatalogPagination v-if="pagination" :pagination="pagination" @change="setPage" />
    </template>
  </div>
</template>

<style scoped>
.blog {
  padding-block: var(--space-8);
}
.blog__header {
  margin-bottom: var(--space-6);
}
.blog__count {
  margin-top: var(--space-1);
  color: var(--color-text-secondary);
}
.blog__categories {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-bottom: var(--space-6);
}
.blog__cat {
  padding: var(--space-2) var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-pill);
  background: var(--color-bg);
  color: var(--color-text-primary);
  font: inherit;
  font-weight: 600;
  font-size: var(--fs-sm);
  cursor: pointer;
  transition: background var(--transition-fast), border-color var(--transition-fast), color var(--transition-fast);
}
.blog__cat:hover:not(.blog__cat--active) {
  border-color: var(--color-primary-light);
  color: var(--color-primary);
}
.blog__cat--active {
  background: var(--color-primary);
  border-color: var(--color-primary);
  color: #fff;
}
.blog__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: var(--space-5);
}
</style>
