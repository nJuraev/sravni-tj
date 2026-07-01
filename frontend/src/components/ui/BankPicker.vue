<script setup lang="ts">
interface BankTile {
  id: number
  name: string
  icon: string
}

const props = defineProps<{
  modelValue: number[]
  banks: BankTile[]
}>()
const emit = defineEmits<{ 'update:modelValue': [number[]] }>()

function isActive(id: number): boolean {
  return props.modelValue.includes(id)
}

function toggle(id: number): void {
  const set = new Set(props.modelValue)
  if (set.has(id)) set.delete(id)
  else set.add(id)
  emit('update:modelValue', [...set])
}

// При битой/отсутствующей иконке прячем <img> — под ним остаётся буква-фолбэк.
function hideBroken(e: Event): void {
  ;(e.target as HTMLImageElement).style.visibility = 'hidden'
}
</script>

<template>
  <div class="bankpick" role="group">
    <button
      v-for="b in banks"
      :key="b.id"
      type="button"
      class="bankpick__item"
      :class="{ 'is-active': isActive(b.id) }"
      :aria-pressed="isActive(b.id)"
      :title="b.name"
      @click="toggle(b.id)"
    >
      <span class="bankpick__fallback" aria-hidden="true">{{ b.name.charAt(0) }}</span>
      <img
        v-if="b.icon"
        :src="b.icon"
        :alt="b.name"
        class="bankpick__img"
        loading="lazy"
        @error="hideBroken"
      />
    </button>
  </div>
</template>

<style scoped>
.bankpick {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
}
.bankpick__item {
  position: relative;
  width: 40px;
  height: 40px;
  padding: 0;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md, 8px);
  background: var(--color-bg);
  cursor: pointer;
  overflow: hidden;
  display: grid;
  place-items: center;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.bankpick__item:hover {
  border-color: var(--color-primary-light);
}
.bankpick__item.is-active {
  border-color: var(--color-primary);
  box-shadow: 0 0 0 2px var(--color-primary-soft, rgba(0, 80, 200, 0.18));
}
.bankpick__fallback {
  font-family: var(--font-display);
  font-weight: 700;
  font-size: var(--fs-sm);
  color: var(--color-text-secondary);
}
.bankpick__img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: contain;
  padding: 6px;
  background: var(--color-bg);
}
</style>
