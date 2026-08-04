<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Profile } from '@/types/api'
import { useProfileStore } from '@/stores/profile'
import { ApiError } from '@/api/errors'
import { formatTajikPhone, tajikPhoneDigits, TAJIK_PHONE_DEFAULT } from '@/composables/useTajikPhoneMask'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseTextField from '@/components/ui/BaseTextField.vue'

const props = defineProps<{ profile: Profile }>()

const { t } = useI18n()
const store = useProfileStore()

const form = reactive({
  name: props.profile.name,
  phone: props.profile.phone ? formatTajikPhone(props.profile.phone) : TAJIK_PHONE_DEFAULT,
})

watch(
  () => props.profile,
  (p) => {
    form.name = p.name
    form.phone = p.phone ? formatTajikPhone(p.phone) : TAJIK_PHONE_DEFAULT
  },
)

const phoneModel = computed({
  get: () => form.phone,
  set: (v: string | number) => {
    form.phone = formatTajikPhone(String(v))
  },
})

const fieldErrors = reactive<Record<string, string | undefined>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)
const saved = ref(false)

const canSubmit = computed(() => form.name.trim().length >= 2)

function clearErrors() {
  fieldErrors.name = undefined
  fieldErrors.phone = undefined
  generalError.value = null
}

async function submit() {
  if (!canSubmit.value || submitting.value) return
  clearErrors()
  saved.value = false
  submitting.value = true
  try {
    const phoneDigits = tajikPhoneDigits(form.phone)
    await store.update({
      name: form.name.trim(),
      phone: phoneDigits.length ? form.phone.trim() : null,
    })
    saved.value = true
  } catch (err) {
    if (err instanceof ApiError && err.isValidation) {
      for (const [field, messages] of Object.entries(err.fieldErrors)) {
        fieldErrors[field] = messages[0]
      }
    } else {
      generalError.value = t('profilePage.networkError')
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <form class="profile-form" novalidate @submit.prevent="submit">
    <BaseTextField
      v-model="form.name"
      :label="t('profilePage.nameLabel')"
      :error="fieldErrors.name"
    />
    <BaseTextField
      v-model="phoneModel"
      type="tel"
      inputmode="tel"
      :label="t('profilePage.phoneLabel')"
      :placeholder="t('profilePage.phonePlaceholder')"
      :error="fieldErrors.phone"
    />

    <p v-if="generalError" class="profile-form__error" role="alert">{{ generalError }}</p>

    <BaseButton type="submit" :disabled="!canSubmit" :loading="submitting">
      {{ saved && !submitting ? t('profilePage.saved') : t('profilePage.save') }}
    </BaseButton>
  </form>
</template>

<style scoped>
.profile-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  max-width: 420px;
}
.profile-form__error {
  padding: var(--space-3);
  border-radius: var(--radius-md);
  background: var(--color-danger-soft);
  color: var(--color-danger);
  font-size: var(--fs-sm);
}
</style>
