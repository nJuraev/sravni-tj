<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Pagination } from '@/types/api'

const props = defineProps<{ pagination: Pagination }>()
const emit = defineEmits<{ change: [page: number] }>()

const { t } = useI18n()

// Compact page window with first/last anchors and ellipsis gaps.
const pages = computed<(number | '…')[]>(() => {
  const { page, total_pages: total } = props.pagination
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)
  const out: (number | '…')[] = [1]
  const from = Math.max(2, page - 1)
  const to = Math.min(total - 1, page + 1)
  if (from > 2) out.push('…')
  for (let p = from; p <= to; p++) out.push(p)
  if (to < total - 1) out.push('…')
  out.push(total)
  return out
})

function go(page: number) {
  if (page < 1 || page > props.pagination.total_pages || page === props.pagination.page) return
  emit('change', page)
}
</script>

<template>
  <nav v-if="pagination.total_pages > 1" class="pager" :aria-label="t('pagination.label')">
    <button
      type="button"
      class="pager__btn pager__btn--arrow"
      :disabled="pagination.page <= 1"
      :aria-label="t('pagination.prev')"
      @click="go(pagination.page - 1)"
    >
      ‹
    </button>
    <template v-for="(p, i) in pages" :key="`${p}-${i}`">
      <span v-if="p === '…'" class="pager__gap" aria-hidden="true">…</span>
      <button
        v-else
        type="button"
        class="pager__btn"
        :class="{ 'pager__btn--active': p === pagination.page }"
        :aria-current="p === pagination.page ? 'page' : undefined"
        @click="go(p)"
      >
        {{ p }}
      </button>
    </template>
    <button
      type="button"
      class="pager__btn pager__btn--arrow"
      :disabled="pagination.page >= pagination.total_pages"
      :aria-label="t('pagination.next')"
      @click="go(pagination.page + 1)"
    >
      ›
    </button>
  </nav>
</template>

<style scoped>
.pager {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-1);
  margin-top: var(--space-8);
}
.pager__btn {
  min-width: 40px;
  height: 40px;
  padding: 0 var(--space-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-bg);
  color: var(--color-text-primary);
  font-family: var(--font-display);
  font-weight: 600;
  font-size: var(--fs-base);
  cursor: pointer;
  transition:
    background var(--transition-fast),
    border-color var(--transition-fast),
    color var(--transition-fast);
}
.pager__btn:hover:not(:disabled):not(.pager__btn--active) {
  border-color: var(--color-primary);
  color: var(--color-primary);
}
.pager__btn--active {
  background: var(--color-primary);
  border-color: var(--color-primary);
  color: #fff;
  cursor: default;
}
.pager__btn--arrow {
  font-size: var(--fs-lg);
  line-height: 1;
}
.pager__btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
.pager__gap {
  padding: 0 var(--space-1);
  color: var(--color-text-muted);
}
</style>
