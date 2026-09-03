<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import RouterLink from '@/components/nav/LocaleLink.vue'
import type { Article, Locale } from '@/types/api'
import { useLocalizedField } from '@/composables/useLocalizedField'
import { formatDate } from '@/lib/format'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseCard from '@/components/ui/BaseCard.vue'

const props = defineProps<{ article: Article }>()

const { t, locale } = useI18n()
const { name, value } = useLocalizedField()

const loc = computed(() => locale.value as Locale)
const title = computed(() => value(props.article.title_ru, props.article.title_tg))
const excerpt = computed(() => value(props.article.excerpt_ru, props.article.excerpt_tg))
const dateText = computed(() => (props.article.published_at ? formatDate(props.article.published_at, loc.value) : ''))
</script>

<template>
  <RouterLink :to="`/blog/${article.slug}`" class="acard-link">
    <BaseCard as="article" interactive :padded="false" class="acard">
      <div v-if="article.cover_image" class="acard__cover">
        <img :src="article.cover_image" :alt="title" loading="lazy" />
      </div>
      <div class="acard__body">
        <div class="acard__meta">
          <BaseBadge tone="neutral">{{ name(article.category) }}</BaseBadge>
          <time v-if="dateText" class="acard__date" :datetime="article.published_at ?? undefined">{{ dateText }}</time>
        </div>
        <h3 class="acard__title">{{ title }}</h3>
        <p v-if="excerpt" class="acard__excerpt">{{ excerpt }}</p>
        <span class="acard__more">{{ t('common.more') }}</span>
      </div>
    </BaseCard>
  </RouterLink>
</template>

<style scoped>
.acard-link {
  display: block;
  color: inherit;
}
.acard {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  height: 100%;
}
.acard__cover {
  aspect-ratio: 16 / 9;
  background: var(--color-bg-section);
  overflow: hidden;
}
.acard__cover img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.acard__body {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  padding: var(--space-5);
  flex: 1;
}
.acard__meta {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}
.acard__date {
  font-size: var(--fs-xs);
  color: var(--color-text-muted);
}
.acard__title {
  font-family: var(--font-display);
  font-weight: 700;
  font-size: var(--fs-lg);
  line-height: 1.3;
  color: var(--color-text-primary);
}
.acard__excerpt {
  color: var(--color-text-secondary);
  font-size: var(--fs-sm);
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
}
.acard__more {
  margin-top: auto;
  padding-top: var(--space-2);
  font-size: var(--fs-xs);
  font-weight: 600;
  color: var(--color-text-secondary);
}
.acard-link:hover .acard__more {
  color: var(--color-primary);
}
</style>
