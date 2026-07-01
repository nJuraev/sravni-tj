<script setup lang="ts">
import { useId } from 'vue'

defineProps<{
  modelValue: string
  label?: string
  options: { value: string; label: string }[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const fieldId = useId()

function onChange(event: Event) {
  emit('update:modelValue', (event.target as HTMLSelectElement).value)
}
</script>

<template>
  <div class="select">
    <label v-if="label" :for="fieldId" class="select__label">{{ label }}</label>
    <div class="select__control">
      <select :id="fieldId" class="select__input" :value="modelValue" @change="onChange">
        <option v-for="opt in options" :key="opt.value" :value="opt.value">
          {{ opt.label }}
        </option>
      </select>
      <svg class="select__chevron" viewBox="0 0 20 20" aria-hidden="true">
        <path d="M5 7.5 10 12.5 15 7.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </div>
  </div>
</template>

<style scoped>
.select {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}
.select__label {
  font-size: var(--fs-sm);
  font-weight: 600;
  color: var(--color-text-secondary);
}
.select__control {
  position: relative;
  display: flex;
  align-items: center;
}
.select__input {
  width: 100%;
  appearance: none;
  padding: var(--space-3) var(--space-8) var(--space-3) var(--space-4);
  background: var(--color-bg);
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text-primary);
  cursor: pointer;
  transition:
    border-color var(--transition-fast),
    box-shadow var(--transition-fast);
}
.select__input:focus {
  outline: none;
  border-color: var(--color-primary);
  box-shadow: var(--shadow-focus);
}
.select__chevron {
  position: absolute;
  right: var(--space-3);
  width: 18px;
  height: 18px;
  color: var(--color-text-secondary);
  pointer-events: none;
}
</style>
