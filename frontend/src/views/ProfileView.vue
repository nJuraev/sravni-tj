<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import type { RateAlert } from '@/types/api'
import { useProfileStore } from '@/stores/profile'
import { useSeo } from '@/composables/useSeo'
import { useApi } from '@/composables/useApi'
import { profileApi } from '@/api/profile'
import BaseButton from '@/components/ui/BaseButton.vue'
import StateMessage from '@/components/ui/StateMessage.vue'
import ProfileForm from '@/components/profile/ProfileForm.vue'
import RateAlertForm from '@/components/profile/RateAlertForm.vue'
import RateAlertList from '@/components/profile/RateAlertList.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useProfileStore()
const api = useApi()

useSeo({ title: t('profilePage.seoTitle'), description: t('profilePage.seoDescription'), noindex: true, hreflang: false })

const alerts = ref<RateAlert[]>([])
const alertsLoading = ref(false)
const telegramLinking = ref(false)
const telegramError = ref<string | null>(null)

async function loadAlerts() {
  alertsLoading.value = true
  try {
    const res = await profileApi.listAlerts()
    alerts.value = res.data
  } catch {
    alerts.value = []
  } finally {
    alertsLoading.value = false
  }
}

async function bootstrap() {
  const tokenParam = route.query.token
  if (typeof tokenParam === 'string' && tokenParam) {
    await store.consumeToken(tokenParam)
    // Strip the token from the visible URL — it's now in localStorage.
    router.replace({ query: {} })
  } else {
    await store.init()
  }

  if (store.isAuthenticated) {
    await loadAlerts()
  }
}

// Awaited so SSR (and the initial client render) never flashes the wrong state.
await bootstrap()

async function subscribeToTelegram() {
  if (telegramLinking.value) return
  telegramLinking.value = true
  telegramError.value = null
  try {
    const res = await api.initTelegramSubscribe()
    window.location.href = res.data.deep_link
  } catch {
    telegramError.value = t('ratesPage.telegramCta.error')
  } finally {
    telegramLinking.value = false
  }
}

function onAlertCreated(alert: RateAlert) {
  alerts.value = [alert, ...alerts.value]
}
function onAlertRemoved(id: number) {
  alerts.value = alerts.value.filter((a) => a.id !== id)
}
</script>

<template>
  <div class="profile container">
    <h1 class="profile__title">{{ t('profilePage.title') }}</h1>

    <StateMessage
      v-if="!store.isAuthenticated"
      :title="t('profilePage.notLinkedTitle')"
      :hint="t('profilePage.notLinkedHint')"
    >
      <template #action>
        <BaseButton :loading="telegramLinking" @click="subscribeToTelegram">
          {{ t('ratesPage.telegramCta.button') }}
        </BaseButton>
        <p v-if="telegramError" class="profile__error" role="alert">{{ telegramError }}</p>
      </template>
    </StateMessage>

    <template v-else-if="store.user">
      <section class="profile__section">
        <ProfileForm :profile="store.user" />
      </section>

      <section class="profile__section">
        <h2 class="profile__section-title">{{ t('profilePage.alerts.title') }}</h2>
        <RateAlertList v-if="!alertsLoading" :alerts="alerts" @removed="onAlertRemoved" />
        <RateAlertForm @created="onAlertCreated" />
      </section>
    </template>
  </div>
</template>

<style scoped>
.profile {
  padding-block: var(--space-8) var(--space-16);
  max-width: 640px;
}
.profile__title {
  font-family: var(--font-display);
  font-size: var(--fs-3xl);
  font-weight: 800;
  letter-spacing: -0.02em;
  margin: 0 0 var(--space-6);
}
.profile__section {
  margin-bottom: var(--space-10);
}
.profile__section-title {
  font-size: var(--fs-lg);
  font-weight: 700;
  margin: 0 0 var(--space-4);
}
.profile__error {
  margin-top: var(--space-3);
  color: var(--color-danger);
  font-size: var(--fs-sm);
}
</style>
