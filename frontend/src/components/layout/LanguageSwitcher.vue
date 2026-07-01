<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { Locale } from '@/types/api'
import { setLocale, SUPPORTED_LOCALES } from '@/i18n'

const { locale, t } = useI18n()

function choose(l: Locale) {
  setLocale(l)
}
</script>

<template>
  <div class="lang" role="group" :aria-label="t('lang.switch')">
    <button
      v-for="l in SUPPORTED_LOCALES"
      :key="l"
      type="button"
      class="lang__btn"
      :class="{ 'lang__btn--active': locale === l }"
      :aria-pressed="locale === l"
      @click="choose(l)"
    >
      {{ l.toUpperCase() }}
    </button>
  </div>
</template>

<style scoped>
.lang {
  display: inline-flex;
  padding: 3px;
  background: var(--color-bg-section);
  border-radius: var(--radius-pill);
}
.lang__btn {
  border: 0;
  background: transparent;
  padding: var(--space-1) var(--space-3);
  border-radius: var(--radius-pill);
  font-family: var(--font-display);
  font-size: var(--fs-sm);
  font-weight: 600;
  color: var(--color-text-secondary);
  cursor: pointer;
  transition:
    background var(--transition-fast),
    color var(--transition-fast);
}
.lang__btn--active {
  background: var(--color-bg);
  color: var(--color-primary);
  box-shadow: var(--shadow-sm);
}
</style>
