<script setup lang="ts">
withDefaults(
  defineProps<{
    tone?: 'neutral' | 'primary' | 'green' | 'muted'
  }>(),
  { tone: 'neutral' },
)
</script>

<template>
  <span class="badge" :class="`badge--${tone}`">
    <slot />
  </span>
</template>

<style scoped>
.badge {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  padding: 2px var(--space-2);
  border-radius: var(--radius-pill);
  font-size: var(--fs-xs);
  font-weight: 600;
  line-height: 1.4;
  white-space: nowrap;
}
.badge--neutral {
  background: var(--color-bg-blue-tint);
  color: var(--color-primary-dark);
}
.badge--primary {
  background: var(--color-primary);
  color: #fff;
}
.badge--green {
  background: var(--color-accent-green-soft);
  color: var(--color-accent-green);
}
.badge--muted {
  background: var(--color-bg-section);
  color: var(--color-text-secondary);
}
</style>
