<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Bank } from '@/types/api'
import { api } from '@/api/client'
import { useHead } from '@/composables/useHead'
import { useLocalizedField } from '@/composables/useLocalizedField'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BankReviews from '@/components/bank/BankReviews.vue'

const { t } = useI18n()
const { name } = useLocalizedField()

const clearHead = useHead({
  title: t('reviewsPage.seoTitle'),
  description: t('reviewsPage.seoDescription'),
})
onUnmounted(clearHead)

const banks = ref<Bank[]>([])
const selectedBankId = ref('')

onMounted(async () => {
  try {
    banks.value = (await api.getBanks()).data
  } catch {
    /* список банков не критичен — просто нечего будет выбрать */
  }
})

const bankOptions = computed(() => [
  { value: '', label: t('reviewsPage.selectPlaceholder') },
  ...banks.value.map((b) => ({ value: String(b.id), label: name(b) })),
])

const selectedId = computed(() => (selectedBankId.value ? Number(selectedBankId.value) : null))
</script>

<template>
  <div class="reviewpage container">
    <header class="reviewpage__header">
      <div class="section-eyebrow">{{ t('home.reviews.eyebrow') }}</div>
      <h1 class="reviewpage__title">{{ t('reviewsPage.title') }}</h1>
      <p class="reviewpage__subtitle">{{ t('reviewsPage.subtitle') }}</p>
    </header>

    <div class="reviewpage__picker">
      <BaseSelect
        v-model="selectedBankId"
        :label="t('reviewsPage.bankLabel')"
        :options="bankOptions"
      />
    </div>

    <BankReviews v-if="selectedId" :key="selectedId" :bank-id="selectedId" />
  </div>
</template>

<style scoped>
.reviewpage {
  padding-block: var(--space-8) var(--space-16);
  max-width: 640px;
}
.reviewpage__header {
  margin-bottom: var(--space-6);
}
.section-eyebrow {
  font-size: var(--fs-xs);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: var(--color-primary);
  font-weight: 700;
  margin-bottom: var(--space-2);
}
.reviewpage__title {
  font-family: var(--font-display);
  font-size: var(--fs-3xl);
  font-weight: 800;
  letter-spacing: -0.02em;
  margin: 0 0 var(--space-3);
}
.reviewpage__subtitle {
  color: var(--color-text-secondary);
}
.reviewpage__picker {
  max-width: 360px;
  margin-bottom: var(--space-8);
}
</style>
