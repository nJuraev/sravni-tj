<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import RouterLink from '@/components/nav/LocaleLink.vue'
import type { Article, Locale } from '@/types/api'
import { useApi } from '@/composables/useApi'
import { useSeo } from '@/composables/useSeo'
import { useLocalizedField } from '@/composables/useLocalizedField'
import { ApiError } from '@/api/errors'
import { formatDate } from '@/lib/format'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseCard from '@/components/ui/BaseCard.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import StateMessage from '@/components/ui/StateMessage.vue'
import SkeletonCard from '@/components/ui/SkeletonCard.vue'

const props = defineProps<{ slug: string }>()

const { t, locale } = useI18n()
const { name, value } = useLocalizedField()
const api = useApi()

const loc = computed(() => locale.value as Locale)
const article = ref<Article | null>(null)
const status = ref<'loading' | 'loaded' | 'not-found' | 'error'>('loading')

const title = computed(() => (article.value ? value(article.value.title_ru, article.value.title_tg) : ''))
const body = computed(() => (article.value ? value(article.value.body_ru, article.value.body_tg) : ''))
const excerpt = computed(() => (article.value ? value(article.value.excerpt_ru, article.value.excerpt_tg) : ''))
const dateText = computed(() => (article.value?.published_at ? formatDate(article.value.published_at, loc.value) : ''))

// youtu.be/<id>, youtube.com/watch?v=<id>, youtube.com/embed/<id> → embed URL.
const youtubeEmbedUrl = computed(() => {
  const url = article.value?.youtube_url
  if (!url) return null
  try {
    const u = new URL(url)
    let id: string | null = null
    if (u.hostname.includes('youtu.be')) id = u.pathname.slice(1)
    else if (u.pathname.startsWith('/embed/')) id = u.pathname.slice('/embed/'.length)
    else id = u.searchParams.get('v')
    return id ? `https://www.youtube-nocookie.com/embed/${id}` : null
  } catch {
    return null
  }
})

const seoTitle = computed(() => (article.value ? t('blog.seoTitleArticle', { title: title.value }) : t('blog.notFoundTitle')))
const seoDescription = computed(() => (article.value ? excerpt.value || title.value : t('blog.notFoundHint')))
const seoJsonLd = computed(() => {
  if (!article.value) return undefined
  return [
    {
      '@context': 'https://schema.org',
      '@type': 'BlogPosting',
      headline: title.value,
      description: excerpt.value || undefined,
      image: article.value.cover_image || undefined,
      datePublished: article.value.published_at || undefined,
      author: article.value.author_name ? { '@type': 'Person', name: article.value.author_name } : undefined,
      publisher: { '@type': 'Organization', name: 'Sravni.tj' },
    },
  ]
})
useSeo({ title: seoTitle, description: seoDescription, jsonLd: seoJsonLd })

let requestId = 0
async function load(slug: string): Promise<void> {
  const id = ++requestId
  status.value = 'loading'
  article.value = null
  try {
    const res = await api.getArticle(slug)
    if (id !== requestId) return
    article.value = res.data
    status.value = 'loaded'
  } catch (err) {
    if (id !== requestId) return
    if (err instanceof ApiError && err.isNotFound) status.value = 'not-found'
    else status.value = 'error'
  }
}

await load(props.slug)
</script>

<template>
  <div class="article container">
    <RouterLink to="/blog" class="article__back">‹ {{ t('common.back') }}</RouterLink>

    <div v-if="status === 'loading'" class="article__loading">
      <SkeletonCard />
      <SkeletonCard />
    </div>

    <StateMessage v-else-if="status === 'not-found'" :title="t('blog.notFoundTitle')" :hint="t('blog.notFoundHint')">
      <template #action>
        <RouterLink to="/blog">
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
        <BaseButton @click="load(slug)">{{ t('common.retry') }}</BaseButton>
      </template>
    </StateMessage>

    <article v-else-if="article" class="article__body">
      <header class="article__header">
        <div class="article__meta">
          <BaseBadge tone="neutral">{{ name(article.category) }}</BaseBadge>
          <time v-if="dateText" class="article__date" :datetime="article.published_at ?? undefined">{{ dateText }}</time>
          <span v-if="article.author_name" class="article__author">{{ article.author_name }}</span>
        </div>
        <h1>{{ title }}</h1>
      </header>

      <div v-if="article.cover_image" class="article__cover">
        <img :src="article.cover_image" :alt="title" />
      </div>

      <!-- eslint-disable-next-line vue/no-v-html -- HTML санитизируется на бэке (см. Task 11 security-review) -->
      <div class="article__content" v-html="body" />

      <div v-if="youtubeEmbedUrl" class="article__video">
        <iframe
          :src="youtubeEmbedUrl"
          :title="title"
          loading="lazy"
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
          allowfullscreen
        />
      </div>

      <ul v-if="article.tags.length" class="article__tags" role="list">
        <li v-for="tg in article.tags" :key="tg.id">
          <BaseBadge tone="muted">{{ name(tg) }}</BaseBadge>
        </li>
      </ul>

      <BaseCard v-if="article.related_bank || article.related_product" class="article__related">
        <h2 class="article__related-title">{{ t('blog.relatedTitle') }}</h2>
        <RouterLink v-if="article.related_bank" :to="`/bank/${article.related_bank.id}`" class="article__related-link">
          {{ value(article.related_bank.name_ru, article.related_bank.name_tg) }}
        </RouterLink>
        <RouterLink v-if="article.related_product" :to="`/product/${article.related_product.id}`" class="article__related-link">
          {{ value(article.related_product.name_ru, article.related_product.name_tg) }}
        </RouterLink>
      </BaseCard>
    </article>
  </div>
</template>

<style scoped>
.article {
  padding-block: var(--space-8);
}
.article__back {
  display: inline-block;
  margin-bottom: var(--space-5);
  font-weight: 600;
}
.article__loading {
  display: grid;
  gap: var(--space-5);
  max-width: 720px;
}
.article__body {
  max-width: 720px;
  margin-inline: auto;
}
.article__header {
  margin-bottom: var(--space-6);
}
.article__meta {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin-bottom: var(--space-3);
}
.article__date,
.article__author {
  font-size: var(--fs-sm);
  color: var(--color-text-muted);
}
.article__cover {
  margin-bottom: var(--space-6);
  border-radius: var(--radius-lg);
  overflow: hidden;
}
.article__cover img {
  width: 100%;
  height: auto;
  display: block;
}
.article__content {
  color: var(--color-text-primary);
  line-height: 1.7;
}
.article__content :deep(h2) {
  margin-top: var(--space-6);
  font-size: var(--fs-xl, 1.5rem);
}
.article__content :deep(h3) {
  margin-top: var(--space-5);
  font-size: var(--fs-lg);
}
.article__content :deep(p) {
  margin-bottom: var(--space-4);
}
.article__content :deep(img) {
  max-width: 100%;
  border-radius: var(--radius-md);
}
.article__content :deep(a) {
  color: var(--color-primary);
}
.article__video {
  position: relative;
  margin-top: var(--space-6);
  padding-top: 56.25%;
}
.article__video iframe {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  border: none;
  border-radius: var(--radius-lg);
}
.article__tags {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin: var(--space-6) 0 0;
  padding: 0;
  list-style: none;
}
.article__related {
  margin-top: var(--space-8);
}
.article__related-title {
  font-size: var(--fs-base);
  margin-bottom: var(--space-3);
}
.article__related-link {
  display: block;
  font-weight: 600;
  color: var(--color-primary);
}
.article__related-link + .article__related-link {
  margin-top: var(--space-2);
}
</style>
