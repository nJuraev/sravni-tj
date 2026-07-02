<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Product } from '@/types/api'
import { api } from '@/api/client'
import { ApiError } from '@/api/errors'
import { useLocalizedField } from '@/composables/useLocalizedField'
import { formatTajikPhone, tajikPhoneDigits, TAJIK_PHONE_DEFAULT } from '@/composables/useTajikPhoneMask'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseTextField from '@/components/ui/BaseTextField.vue'
import BaseCheckbox from '@/components/ui/BaseCheckbox.vue'

const props = defineProps<{ product: Product }>()
const emit = defineEmits<{ done: [] }>()

const { t } = useI18n()
const { name } = useLocalizedField()

const form = reactive({
  full_name: '',
  phone: TAJIK_PHONE_DEFAULT,
  consent: false,
})

const phoneModel = computed({
  get: () => form.phone,
  set: (v: string | number) => {
    form.phone = formatTajikPhone(String(v))
  },
})

const fieldErrors = reactive<Record<string, string | undefined>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)
const success = ref(false)

// Hard invariant (frontend.md §6): submit disabled until consent is checked.
const canSubmit = computed(
  () =>
    form.consent &&
    form.full_name.trim().length >= 2 &&
    tajikPhoneDigits(form.phone).length === 9,
)

function clearErrors() {
  fieldErrors.full_name = undefined
  fieldErrors.phone = undefined
  fieldErrors.consent = undefined
  fieldErrors.product_id = undefined
  generalError.value = null
}

async function submit() {
  if (!form.consent || submitting.value) return
  clearErrors()
  submitting.value = true
  try {
    await api.createLead({
      full_name: form.full_name.trim(),
      phone: form.phone.trim(),
      product_id: props.product.id,
      consent: form.consent,
    })
    success.value = true
  } catch (err) {
    if (err instanceof ApiError && err.isValidation) {
      for (const [field, messages] of Object.entries(err.fieldErrors)) {
        fieldErrors[field] = messages[0]
      }
    } else if (err instanceof ApiError && err.isNotFound) {
      generalError.value = t('lead.productGone')
    } else {
      // Network / 500 — keep form data, allow retry.
      generalError.value = t('lead.networkError')
    }
  } finally {
    submitting.value = false
  }
}

function reset() {
  form.full_name = ''
  form.phone = TAJIK_PHONE_DEFAULT
  form.consent = false
  success.value = false
  clearErrors()
}

function finish() {
  reset()
  emit('done')
}
</script>

<template>
  <div class="lead">
    <!-- Success state -->
    <div v-if="success" class="lead__success" role="status">
      <div class="lead__success-icon" aria-hidden="true">
        <svg viewBox="0 0 48 48">
          <circle cx="24" cy="24" r="22" fill="var(--color-accent-green-soft)" />
          <path d="M16 24.5 21.5 30 33 18" fill="none" stroke="var(--color-accent-green)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </div>
      <h3 class="lead__success-title">{{ t('lead.successTitle') }}</h3>
      <p class="lead__success-text">{{ t('lead.successText') }}</p>
      <BaseButton variant="secondary" @click="finish">{{ t('common.close') }}</BaseButton>
    </div>

    <!-- Form -->
    <form v-else class="lead__form" novalidate @submit.prevent="submit">
      <p class="lead__product">{{ name(product) }} · {{ name(product.bank) }}</p>
      <p class="lead__subtitle">{{ t('lead.subtitle') }}</p>

      <BaseTextField
        v-model="form.full_name"
        :label="t('lead.fullName')"
        :placeholder="t('lead.fullNamePlaceholder')"
        :error="fieldErrors.full_name"
      />
      <BaseTextField
        v-model="phoneModel"
        type="tel"
        inputmode="tel"
        :label="t('lead.phone')"
        :placeholder="t('lead.phonePlaceholder')"
        :error="fieldErrors.phone"
      />

      <BaseCheckbox v-model="form.consent" :error="fieldErrors.consent">
        {{ t('lead.consentPrefix') }}
        <a href="#" @click.prevent>{{ t('lead.consentPolicy') }}</a>
      </BaseCheckbox>

      <p v-if="generalError" class="lead__general-error" role="alert">{{ generalError }}</p>

      <BaseButton type="submit" block :disabled="!canSubmit" :loading="submitting">
        {{ submitting ? t('lead.submitting') : t('lead.submit') }}
      </BaseButton>
    </form>
  </div>
</template>

<style scoped>
.lead__form {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}
.lead__product {
  font-family: var(--font-display);
  font-weight: 700;
  font-size: var(--fs-md);
}
.lead__subtitle {
  margin-top: calc(-1 * var(--space-2));
  font-size: var(--fs-sm);
  color: var(--color-text-secondary);
}
.lead__general-error {
  padding: var(--space-3);
  border-radius: var(--radius-md);
  background: var(--color-danger-soft);
  color: var(--color-danger);
  font-size: var(--fs-sm);
}
.lead__success {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: var(--space-3);
  padding-block: var(--space-4);
}
.lead__success-icon {
  width: 64px;
  height: 64px;
}
.lead__success-title {
  font-size: var(--fs-xl);
}
.lead__success-text {
  color: var(--color-text-secondary);
  max-width: 32ch;
}
</style>
