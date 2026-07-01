<script setup lang="ts">
defineProps<{
  tone?: 'neutral' | 'error'
  title: string
  hint?: string
}>()
</script>

<template>
  <div class="state" :class="`state--${tone ?? 'neutral'}`" role="status">
    <div class="state__icon" aria-hidden="true">
      <slot name="icon">
        <svg viewBox="0 0 48 48">
          <circle cx="24" cy="24" r="20" fill="none" stroke="currentColor" stroke-width="2.5" />
          <path d="M16 28c2 2.5 5 4 8 4s6-1.5 8-4M18 20h.01M30 20h.01" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
        </svg>
      </slot>
    </div>
    <h3 class="state__title">{{ title }}</h3>
    <p v-if="hint" class="state__hint">{{ hint }}</p>
    <div v-if="$slots.action" class="state__action">
      <slot name="action" />
    </div>
  </div>
</template>

<style scoped>
.state {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: var(--space-16) var(--space-5);
  gap: var(--space-3);
}
.state__icon {
  width: 56px;
  height: 56px;
  color: var(--color-text-muted);
}
.state--error .state__icon {
  color: var(--color-danger);
}
.state__title {
  font-size: var(--fs-lg);
}
.state__hint {
  max-width: 38ch;
  color: var(--color-text-secondary);
}
.state__action {
  margin-top: var(--space-3);
}
</style>
