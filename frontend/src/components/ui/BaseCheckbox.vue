<script setup lang="ts">
import { useId } from 'vue'

defineProps<{
  modelValue: boolean
  error?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

const fieldId = useId()

function onChange(event: Event) {
  emit('update:modelValue', (event.target as HTMLInputElement).checked)
}
</script>

<template>
  <div class="checkbox">
    <label class="checkbox__row" :for="fieldId">
      <input
        :id="fieldId"
        class="checkbox__input"
        type="checkbox"
        :checked="modelValue"
        :aria-invalid="!!error"
        @change="onChange"
      />
      <span class="checkbox__box" aria-hidden="true">
        <svg viewBox="0 0 16 16"><path d="M3 8.5 6.5 12 13 4.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
      </span>
      <span class="checkbox__label"><slot /></span>
    </label>
    <p v-if="error" class="checkbox__error" role="alert">{{ error }}</p>
  </div>
</template>

<style scoped>
.checkbox {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}
.checkbox__row {
  display: flex;
  align-items: flex-start;
  gap: var(--space-3);
  cursor: pointer;
}
.checkbox__input {
  position: absolute;
  opacity: 0;
  width: 0;
  height: 0;
}
.checkbox__box {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 22px;
  height: 22px;
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius-sm);
  background: var(--color-bg);
  color: #fff;
  transition:
    background var(--transition-fast),
    border-color var(--transition-fast);
}
.checkbox__box svg {
  width: 14px;
  height: 14px;
  opacity: 0;
  transform: scale(0.6);
  transition:
    opacity var(--transition-fast),
    transform var(--transition-fast);
}
.checkbox__input:checked + .checkbox__box {
  background: var(--color-primary);
  border-color: var(--color-primary);
}
.checkbox__input:checked + .checkbox__box svg {
  opacity: 1;
  transform: scale(1);
}
.checkbox__input:focus-visible + .checkbox__box {
  box-shadow: var(--shadow-focus);
}
.checkbox__label {
  font-size: var(--fs-base);
  line-height: 1.5;
  color: var(--color-text-primary);
}
.checkbox__error {
  font-size: var(--fs-sm);
  color: var(--color-danger);
  margin-left: calc(22px + var(--space-3));
}
</style>
