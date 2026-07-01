<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseTextField from '@/components/ui/BaseTextField.vue'
import BaseCheckbox from '@/components/ui/BaseCheckbox.vue'

interface Option {
  value: number
  label: string
}

const props = defineProps<{
  modelValue: number[]
  options: Option[]
  searchPlaceholder?: string
}>()
const emit = defineEmits<{ 'update:modelValue': [number[]] }>()

const { t } = useI18n()
const search = ref('')

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.options
  return props.options.filter((o) => o.label.toLowerCase().includes(q))
})

function isChecked(v: number): boolean {
  return props.modelValue.includes(v)
}
function toggle(v: number, checked: boolean): void {
  const set = new Set(props.modelValue)
  if (checked) set.add(v)
  else set.delete(v)
  emit('update:modelValue', [...set])
}
function clear(): void {
  emit('update:modelValue', [])
}
</script>

<template>
  <div class="msel">
    <BaseTextField
      v-model="search"
      type="search"
      :placeholder="searchPlaceholder ?? t('common.search')"
    />
    <div class="msel__list" role="group">
      <BaseCheckbox
        v-for="o in filtered"
        :key="o.value"
        :model-value="isChecked(o.value)"
        @update:model-value="(c) => toggle(o.value, c)"
      >
        {{ o.label }}
      </BaseCheckbox>
      <p v-if="filtered.length === 0" class="msel__empty">{{ t('common.nothingFound') }}</p>
    </div>
    <div v-if="modelValue.length" class="msel__foot">
      <span>{{ t('filters.banksSelected', { count: modelValue.length }) }}</span>
      <button type="button" class="msel__clear" @click="clear">{{ t('common.reset') }}</button>
    </div>
  </div>
</template>

<style scoped>
.msel {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}
.msel__list {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  max-height: 188px;
  overflow-y: auto;
  padding: var(--space-2);
  border: 1px solid var(--color-border-subtle);
  border-radius: var(--radius-md, 8px);
}
.msel__empty {
  margin: 0;
  padding: var(--space-1);
  color: var(--color-text-muted);
  font-size: var(--fs-xs);
}
.msel__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: var(--fs-xs);
  color: var(--color-text-secondary);
}
.msel__clear {
  padding: 0;
  border: 0;
  background: none;
  color: var(--color-primary);
  font: inherit;
  cursor: pointer;
}
.msel__clear:hover {
  text-decoration: underline;
}
</style>
