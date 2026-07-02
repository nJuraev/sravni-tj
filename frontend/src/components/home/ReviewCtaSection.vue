<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { Bank } from '@/types/api'
import { api } from '@/api/client'
import BaseButton from '@/components/ui/BaseButton.vue'

const { t } = useI18n()

const banks = ref<Bank[]>([])
const status = ref<'loading' | 'loaded' | 'error'>('loading')

onMounted(async () => {
  try {
    const res = await api.getBanks()
    banks.value = res.data
    status.value = banks.value.length === 0 ? 'error' : 'loaded'
  } catch {
    status.value = 'error'
  }
})

// Cold-start: пока ни у одного банка нет одобренных отзывов — зовём стать первым.
const hasAnyReviews = computed(() => banks.value.some((b) => (b.rating_count ?? 0) > 0))
const isVisible = computed(() => status.value === 'loaded')
</script>

<template>
  <section v-if="isVisible" class="block" aria-label="Отзывы о банках">
    <div class="container">
      <div class="reviewcta">
        <div class="reviewcta__text">
          <div class="section-eyebrow">{{ t('home.reviews.eyebrow') }}</div>
          <h2 class="reviewcta__title">{{ t('home.reviews.title') }}</h2>
          <p class="reviewcta__subtitle">
            {{ hasAnyReviews ? t('home.reviews.subtitleHasReviews') : t('home.reviews.subtitleEmpty') }}
          </p>
        </div>
        <RouterLink to="/otzyvy">
          <BaseButton variant="primary" size="lg">{{ t('home.reviews.cta') }}</BaseButton>
        </RouterLink>
      </div>
    </div>
  </section>
</template>

<style scoped>
.block {
  padding: var(--space-12) 0 var(--space-16);
  background: var(--color-bg-section);
}
.section-eyebrow {
  font-size: var(--fs-xs);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--color-primary);
  font-weight: 700;
  margin-bottom: var(--space-2);
}
.reviewcta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-8);
  flex-wrap: wrap;
}
.reviewcta__text {
  max-width: 46ch;
}
.reviewcta__title {
  font-family: var(--font-display);
  font-size: var(--fs-2xl, var(--fs-xl));
  font-weight: 800;
  letter-spacing: -0.02em;
  margin: 0 0 var(--space-2);
}
.reviewcta__subtitle {
  color: var(--color-text-secondary);
}

@media (max-width: 640px) {
  .reviewcta {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>
