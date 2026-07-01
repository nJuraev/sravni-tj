<script setup lang="ts">
import { computed, useId } from 'vue'

const props = withDefaults(
  defineProps<{
    modelValue: string | number
    label?: string
    type?: string
    placeholder?: string
    error?: string
    min?: number
    inputmode?: 'numeric' | 'decimal' | 'text' | 'tel'
    suffix?: string
  }>(),
  { type: 'text' },
)

const emit = defineEmits<{
  'update:modelValue': [value: string | number]
}>()

const fieldId = useId()
const errorId = computed(() => `${fieldId}-error`)

function onInput(event: Event) {
  const target = event.target as HTMLInputElement
  if (props.type === 'number') {
    emit('update:modelValue', target.value === '' ? '' : Number(target.value))
  } else {
    emit('update:modelValue', target.value)
  }
}
</script>

<template>
  <div class="field">
    <label v-if="label" :for="fieldId" class="field__label">{{ label }}</label>
    <div class="field__control" :class="{ 'field__control--error': error }">
      <input
        :id="fieldId"
        class="field__input"
        :type="type"
        :inputmode="inputmode"
        :value="modelValue"
        :placeholder="placeholder"
        :min="min"
        :aria-invalid="!!error"
        :aria-describedby="error ? errorId : undefined"
        @input="onInput"
      />
      <span v-if="suffix" class="field__suffix">{{ suffix }}</span>
    </div>
    <p v-if="error" :id="errorId" class="field__error" role="alert">{{ error }}</p>
  </div>
</template>

<style scoped>
.field {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}
.field__label {
  font-size: var(--fs-sm);
  font-weight: 600;
  color: var(--color-text-secondary);
}
.field__control {
  display: flex;
  align-items: center;
  background: var(--color-bg);
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius-md);
  transition:
    border-color var(--transition-fast),
    box-shadow var(--transition-fast);
}
.field__control:focus-within {
  border-color: var(--color-primary);
  box-shadow: var(--shadow-focus);
}
.field__control--error {
  border-color: var(--color-danger);
}
.field__control--error:focus-within {
  box-shadow: 0 0 0 3px var(--color-danger-soft);
}
.field__input {
  flex: 1;
  width: 100%;
  padding: var(--space-3) var(--space-4);
  border: 0;
  background: transparent;
  color: var(--color-text-primary);
  font-variant-numeric: tabular-nums;
}
.field__input:focus {
  outline: none;
  box-shadow: none;
}
.field__input::placeholder {
  color: var(--color-text-muted);
}
.field__suffix {
  padding-right: var(--space-4);
  color: var(--color-text-muted);
  font-size: var(--fs-sm);
  white-space: nowrap;
}
.field__error {
  font-size: var(--fs-sm);
  color: var(--color-danger);
}
</style>
