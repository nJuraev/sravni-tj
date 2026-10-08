<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  title: string
  subtitle: string
  url: string | null
}>()

const { t } = useI18n()
</script>

<template>
  <div v-if="url" class="telegram-cta">
    <div class="telegram-cta__text">
      <p class="telegram-cta__title">{{ title }}</p>
      <p class="telegram-cta__subtitle">{{ subtitle }}</p>
    </div>
    <a class="telegram-cta__btn" :href="url" target="_blank" rel="noopener noreferrer">
      <svg class="telegram-cta__icon" viewBox="0 0 24 24" aria-hidden="true">
        <path
          fill="currentColor"
          d="M21.5 3.5 2.6 10.9c-1.2.5-1.2 1.2-.2 1.5l4.8 1.5 1.9 5.7c.2.6.4.8.8.8.3 0 .5-.1.7-.3l2.6-2.5 4.9 3.6c.9.5 1.5.2 1.7-.8L23.9 4.7c.3-1.3-.5-1.8-1.4-1.2ZM8.9 13.5l9.5-6-8 7.3-.3 3.1z"
        />
      </svg>
      {{ t('telegramChannelCta.button') }}
    </a>
  </div>
</template>

<style scoped>
.telegram-cta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-4);
  padding: var(--space-5);
  background: var(--color-primary-soft);
  border-radius: var(--radius-lg);
}
.telegram-cta__text {
  flex: 1;
  min-width: 220px;
}
.telegram-cta__title {
  font-weight: 700;
}
.telegram-cta__subtitle {
  color: var(--color-text-secondary);
  font-size: var(--fs-sm);
}
.telegram-cta__btn {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-3) var(--space-5);
  border: 1.5px solid var(--color-primary);
  border-radius: var(--radius-md);
  background: var(--color-bg);
  color: var(--color-text-primary);
  font-family: var(--font-display);
  font-weight: 600;
  font-size: var(--fs-base);
  text-decoration: none;
  white-space: nowrap;
  transition: background var(--transition-fast);
}
.telegram-cta__btn:hover {
  background: var(--color-primary-soft);
}
.telegram-cta__icon {
  width: 1.1em;
  height: 1.1em;
}
</style>
