<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { Bank } from '@/types/api'
import { api } from '@/api/client'
import { ApiError } from '@/api/errors'
import { useLocalizedField } from '@/composables/useLocalizedField'
import BaseBadge from '@/components/ui/BaseBadge.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseCard from '@/components/ui/BaseCard.vue'
import StateMessage from '@/components/ui/StateMessage.vue'
import SkeletonCard from '@/components/ui/SkeletonCard.vue'
import BankReviews from '@/components/bank/BankReviews.vue'

const props = defineProps<{ id: number }>()

const { t } = useI18n()
const { name, value } = useLocalizedField()

const bank = ref<Bank | null>(null)
const status = ref<'loading' | 'loaded' | 'not-found' | 'error'>('loading')

const bankInitial = computed(() => (bank.value ? (name(bank.value) || '?').trim().charAt(0).toUpperCase() : '?'))
const about = computed(() => (bank.value ? value(bank.value.about_ru ?? null, bank.value.about_tg ?? null) : ''))
const address = computed(() => (bank.value ? value(bank.value.address_ru ?? null, bank.value.address_tg ?? null) : ''))
const ratingValue = computed(() => (bank.value?.rating_avg != null ? bank.value.rating_avg.toFixed(1) : null))
const ratingCount = computed(() => bank.value?.rating_count ?? 0)

let requestId = 0
async function load(id: number) {
  const reqId = ++requestId
  status.value = 'loading'
  bank.value = null
  try {
    const res = await api.getBank(id)
    if (reqId !== requestId) return
    bank.value = res.data
    status.value = 'loaded'
  } catch (err) {
    if (reqId !== requestId) return
    if (err instanceof ApiError && err.isNotFound) status.value = 'not-found'
    else status.value = 'error'
  }
}

watch(() => props.id, (id) => load(id), { immediate: true })
</script>

<template>
  <div class="bankpage container">
    <RouterLink to="/credit" class="bankpage__back">‹ {{ t('common.back') }}</RouterLink>

    <div v-if="status === 'loading'" class="bankpage__loading">
      <SkeletonCard />
    </div>

    <StateMessage
      v-else-if="status === 'not-found'"
      :title="t('bank.notFoundTitle')"
      :hint="t('bank.notFoundHint')"
    >
      <template #action>
        <RouterLink to="/credit">
          <BaseButton variant="secondary">{{ t('common.back') }}</BaseButton>
        </RouterLink>
      </template>
    </StateMessage>

    <StateMessage
      v-else-if="status === 'error'"
      tone="error"
      :title="t('catalog.errorTitle')"
      :hint="t('catalog.errorHint')"
    >
      <template #action>
        <BaseButton @click="load(id)">{{ t('common.retry') }}</BaseButton>
      </template>
    </StateMessage>

    <article v-else-if="bank" class="bankpage__body">
      <header class="bankpage__header">
        <span class="bankpage__logo" aria-hidden="true">{{ bankInitial }}</span>
        <div class="bankpage__headinfo">
          <div class="bankpage__namerow">
            <h1>{{ name(bank) }}</h1>
            <BaseBadge v-if="bank.is_partner" tone="green">{{ t('common.partner') }}</BaseBadge>
          </div>
          <div v-if="ratingCount > 0" class="bankpage__rating">
            <span class="bankpage__star" aria-hidden="true">★</span>
            <span class="tabular">{{ ratingValue }}</span>
            <span class="bankpage__rating-count">{{ t('rating.reviews', { count: ratingCount }) }}</span>
          </div>
          <span v-else class="bankpage__rating bankpage__rating--none">{{ t('rating.none') }}</span>
        </div>
      </header>

      <BaseCard v-if="about">
        <h2 class="bankpage__section-title">{{ t('bank.about') }}</h2>
        <p class="bankpage__about">{{ about }}</p>
      </BaseCard>

      <BaseCard>
        <dl class="bankpage__contacts">
          <div v-if="bank.phone">
            <dt>{{ t('bank.phone') }}</dt>
            <dd><a :href="`tel:${bank.phone.replace(/\s+/g, '')}`">{{ bank.phone }}</a></dd>
          </div>
          <div v-if="bank.contact_email">
            <dt>{{ t('bank.email') }}</dt>
            <dd><a :href="`mailto:${bank.contact_email}`">{{ bank.contact_email }}</a></dd>
          </div>
          <div v-if="address">
            <dt>{{ t('bank.address') }}</dt>
            <dd>{{ address }}</dd>
          </div>
          <div v-if="bank.website">
            <dt>{{ t('bank.website') }}</dt>
            <dd><a :href="bank.website" target="_blank" rel="noopener">{{ bank.website }}</a></dd>
          </div>
        </dl>
      </BaseCard>

      <BankReviews :bank-id="bank.id" />
    </article>
  </div>
</template>

<style scoped>
.bankpage {
  padding-block: var(--space-8);
  max-width: 720px;
}
.bankpage__back {
  display: inline-block;
  margin-bottom: var(--space-5);
  font-weight: 600;
}
.bankpage__loading {
  max-width: 640px;
}
.bankpage__body {
  display: flex;
  flex-direction: column;
  gap: var(--space-5);
}
.bankpage__header {
  display: flex;
  gap: var(--space-4);
  align-items: center;
}
.bankpage__logo {
  flex: none;
  width: 56px;
  height: 56px;
  border-radius: var(--radius-md, 8px);
  background: var(--color-bg-section);
  color: var(--color-primary);
  font-family: var(--font-display);
  font-weight: 700;
  font-size: var(--fs-xl);
  display: grid;
  place-items: center;
}
.bankpage__headinfo {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  min-width: 0;
}
.bankpage__namerow {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}
.bankpage__namerow h1 {
  font-size: var(--fs-xl);
}
.bankpage__rating {
  display: flex;
  align-items: center;
  gap: var(--space-1);
  font-size: var(--fs-sm);
  color: var(--color-text-secondary);
}
.bankpage__star {
  color: #f5a623;
}
.bankpage__rating--none {
  color: var(--color-text-muted);
}
.bankpage__section-title {
  font-size: var(--fs-lg);
  margin-bottom: var(--space-3);
}
.bankpage__about {
  color: var(--color-text-secondary);
}
.bankpage__contacts {
  display: grid;
  gap: var(--space-3);
  margin: 0;
}
.bankpage__contacts dt {
  font-size: var(--fs-xs);
  color: var(--color-text-muted);
  margin-bottom: 2px;
}
.bankpage__contacts dd {
  margin: 0;
  font-weight: 600;
}
.bankpage__contacts a {
  color: var(--color-primary);
}
</style>
