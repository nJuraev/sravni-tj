<script setup lang="ts">
withDefaults(
  defineProps<{
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
    size?: 'sm' | 'md' | 'lg'
    block?: boolean
    type?: 'button' | 'submit'
    disabled?: boolean
    loading?: boolean
  }>(),
  {
    variant: 'primary',
    size: 'md',
    block: false,
    type: 'button',
    disabled: false,
    loading: false,
  },
)
</script>

<template>
  <button
    :type="type"
    class="btn"
    :class="[`btn--${variant}`, `btn--${size}`, { 'btn--block': block, 'btn--loading': loading }]"
    :disabled="disabled || loading"
    :aria-busy="loading"
  >
    <span v-if="loading" class="btn__spinner" aria-hidden="true" />
    <slot />
  </button>
</template>

<style scoped>
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  border: 1.5px solid transparent;
  border-radius: var(--radius-md);
  font-family: var(--font-display);
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  transition:
    background var(--transition-fast),
    border-color var(--transition-fast),
    color var(--transition-fast),
    transform var(--transition-fast);
}
.btn:active:not(:disabled) {
  transform: translateY(1px);
}
.btn:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.btn--sm {
  padding: var(--space-2) var(--space-3);
  font-size: var(--fs-sm);
}
.btn--md {
  padding: var(--space-3) var(--space-5);
  font-size: var(--fs-base);
}
.btn--lg {
  padding: var(--space-4) var(--space-6);
  font-size: var(--fs-md);
}
.btn--block {
  width: 100%;
}

.btn--primary {
  background: var(--color-primary);
  color: #fff;
}
.btn--primary:hover:not(:disabled) {
  background: var(--color-primary-dark);
}

.btn--secondary {
  background: var(--color-bg);
  border-color: var(--color-primary);
  color: var(--color-text-primary);
}
.btn--secondary:hover:not(:disabled) {
  background: var(--color-primary-soft);
}

.btn--ghost {
  background: transparent;
  color: var(--color-primary);
}
.btn--ghost:hover:not(:disabled) {
  background: var(--color-primary-soft);
}

.btn--danger {
  background: var(--color-danger);
  color: #fff;
}

.btn__spinner {
  width: 1em;
  height: 1em;
  border: 2px solid currentColor;
  border-top-color: transparent;
  border-radius: var(--radius-pill);
  animation: btn-spin 0.7s linear infinite;
}
@keyframes btn-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
