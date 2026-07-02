<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Bank } from '@/types/api'
import { api } from '@/api/client'
import { useLocalizedField } from '@/composables/useLocalizedField'

const { t } = useI18n()
const { name } = useLocalizedField()
const banks = ref<Bank[]>([])
const status = ref<'loading' | 'loaded' | 'error'>('loading')

onMounted(async () => {
  try {
    const res = await api.getBanks()
    banks.value = res.data.filter((b) => b.is_partner)
    status.value = banks.value.length === 0 ? 'error' : 'loaded'
  } catch {
    status.value = 'error'
  }
})

const bankInitial = (b: Bank) => (name(b) || '?').trim().charAt(0).toUpperCase()
const isVisible = computed(() => status.value === 'loaded')
</script>

<template>
  <section v-if="isVisible" class="block">
    <div class="container">
      <div class="section-eyebrow">{{ t('home.banks.eyebrow') }}</div>
      <div class="banks-strip">
        <div v-for="b in banks" :key="b.id" class="bank-logo">
          <span class="bank-logo__mark" aria-hidden="true">{{ bankInitial(b) }}</span>
          <span>{{ name(b) }}</span>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.block {
  padding: var(--space-12) 0 var(--space-16);
}
.section-eyebrow {
  font-size: var(--fs-xs);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--color-primary);
  font-weight: 700;
  margin-bottom: var(--space-6);
}
.banks-strip {
  display: flex;
  align-items: center;
  gap: var(--space-8);
  flex-wrap: wrap;
}
.bank-logo {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  font-family: var(--font-display);
  font-weight: 700;
  font-size: var(--fs-base);
  color: var(--color-text-secondary);
}
.bank-logo__mark {
  width: 32px;
  height: 32px;
  border-radius: var(--radius-sm);
  background: var(--color-bg-section);
  color: var(--color-primary);
  font-weight: 800;
  display: grid;
  place-items: center;
}
</style>
