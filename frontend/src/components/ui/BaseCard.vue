<script setup lang="ts">
withDefaults(
  defineProps<{
    as?: string
    interactive?: boolean
    padded?: boolean
  }>(),
  { as: 'div', interactive: false, padded: true },
)
</script>

<template>
  <component
    :is="as"
    class="card"
    :class="{ 'card--interactive': interactive, 'card--padded': padded }"
  >
    <slot />
  </component>
</template>

<style scoped>
.card {
  background: var(--color-bg);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-sm);
  transition:
    box-shadow var(--transition),
    border-color var(--transition),
    transform var(--transition);
}
.card--padded {
  padding: var(--space-5);
}
.card--interactive:hover {
  box-shadow: var(--shadow-md);
  border-color: var(--color-primary-light);
  transform: translateY(-2px);
}
</style>
