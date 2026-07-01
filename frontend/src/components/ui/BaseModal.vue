<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps<{
  open: boolean
  title?: string
}>()

const emit = defineEmits<{ close: [] }>()

const panel = ref<HTMLElement | null>(null)

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('close')
}

watch(
  () => props.open,
  (isOpen) => {
    document.body.style.overflow = isOpen ? 'hidden' : ''
    if (isOpen) {
      requestAnimationFrame(() => panel.value?.focus())
    }
  },
)

onMounted(() => document.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div v-if="open" class="modal" @click.self="emit('close')">
        <div
          ref="panel"
          class="modal__panel"
          role="dialog"
          aria-modal="true"
          :aria-label="title"
          tabindex="-1"
        >
          <header class="modal__head">
            <h2 v-if="title" class="modal__title">{{ title }}</h2>
            <button type="button" class="modal__close" aria-label="close" @click="emit('close')">
              <svg viewBox="0 0 20 20" aria-hidden="true">
                <path d="M5 5 15 15M15 5 5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
              </svg>
            </button>
          </header>
          <div class="modal__body">
            <slot />
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  padding: var(--space-4);
  background: var(--color-overlay);
  backdrop-filter: blur(2px);
}
.modal__panel {
  width: 100%;
  max-width: 460px;
  max-height: 92vh;
  overflow-y: auto;
  background: var(--color-bg);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-lg);
  outline: none;
}
.modal__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-5) var(--space-5) 0;
}
.modal__title {
  font-size: var(--fs-xl);
}
.modal__close {
  display: inline-flex;
  width: 36px;
  height: 36px;
  align-items: center;
  justify-content: center;
  border: 0;
  border-radius: var(--radius-md);
  background: var(--color-bg-section);
  color: var(--color-text-secondary);
  cursor: pointer;
}
.modal__close:hover {
  background: var(--color-border);
}
.modal__close svg {
  width: 18px;
  height: 18px;
}
.modal__body {
  padding: var(--space-5);
}

@media (min-width: 560px) {
  .modal {
    align-items: center;
  }
}

.modal-enter-active,
.modal-leave-active {
  transition: opacity var(--transition);
}
.modal-enter-active .modal__panel,
.modal-leave-active .modal__panel {
  transition: transform var(--transition);
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
.modal-enter-from .modal__panel,
.modal-leave-to .modal__panel {
  transform: translateY(16px);
}
</style>
