<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RateAlert } from '@/types/api'
import { profileApi } from '@/api/profile'
import BaseButton from '@/components/ui/BaseButton.vue'

const props = defineProps<{ alerts: RateAlert[] }>()
const emit = defineEmits<{ removed: [id: number] }>()

const { t, locale } = useI18n()
const removingId = ref<number | null>(null)

function categoryLabel(alert: RateAlert): string {
  return alert.category === 'cash' ? t('profilePage.alerts.cash') : t('profilePage.alerts.transfer')
}
function opLabel(alert: RateAlert): string {
  return alert.op === 'buy' ? t('profilePage.alerts.buy') : t('profilePage.alerts.sell')
}
function directionSign(alert: RateAlert): string {
  return alert.direction === 'above' ? '≥' : '≤'
}
function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString(locale.value === 'tj' ? 'tg' : 'ru')
}

async function remove(alert: RateAlert) {
  if (removingId.value !== null) return
  removingId.value = alert.id
  try {
    await profileApi.deleteAlert(alert.id)
    emit('removed', alert.id)
  } finally {
    removingId.value = null
  }
}
</script>

<template>
  <ul v-if="props.alerts.length" class="alert-list">
    <li v-for="alert in props.alerts" :key="alert.id" class="alert-list__item">
      <div class="alert-list__info">
        <p class="alert-list__main">
          {{ alert.currency }} · {{ categoryLabel(alert) }} · {{ opLabel(alert) }}
          {{ directionSign(alert) }} {{ alert.threshold }}
        </p>
        <p v-if="alert.last_notified_at" class="alert-list__meta">
          {{
            t('profilePage.alerts.lastNotified', {
              value: alert.last_notified_value,
              date: formatDate(alert.last_notified_at),
            })
          }}
        </p>
      </div>
      <BaseButton
        variant="ghost"
        size="sm"
        :loading="removingId === alert.id"
        @click="remove(alert)"
      >
        {{ t('profilePage.alerts.remove') }}
      </BaseButton>
    </li>
  </ul>
  <p v-else class="alert-list__empty">{{ t('profilePage.alerts.empty') }}</p>
</template>

<style scoped>
.alert-list {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  list-style: none;
  padding: 0;
  margin: 0 0 var(--space-5);
}
.alert-list__item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
  padding: var(--space-3) var(--space-4);
  background: var(--color-bg-alt, var(--color-bg));
  border: 1.5px solid var(--color-border);
  border-radius: var(--radius-md);
}
.alert-list__main {
  font-weight: 600;
}
.alert-list__meta {
  font-size: var(--fs-sm);
  color: var(--color-text-secondary);
}
.alert-list__empty {
  color: var(--color-text-secondary);
  margin-bottom: var(--space-5);
}
</style>
