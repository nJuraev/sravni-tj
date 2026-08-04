<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RateAlert } from '@/types/api'
import { profileApi } from '@/api/profile'
import { ApiError } from '@/api/errors'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseSelect from '@/components/ui/BaseSelect.vue'
import BaseTextField from '@/components/ui/BaseTextField.vue'

const emit = defineEmits<{ created: [alert: RateAlert] }>()

const { t } = useI18n()

const form = reactive({
  category: 'cash' as 'cash' | 'transfer',
  currency: 'USD',
  op: 'buy' as 'buy' | 'sell',
  direction: 'above' as 'above' | 'below',
  threshold: '' as string | number,
})

const categoryOptions = computed(() => [
  { value: 'cash', label: t('profilePage.alerts.cash') },
  { value: 'transfer', label: t('profilePage.alerts.transfer') },
])
const currencyOptions = [
  { value: 'USD', label: 'USD' },
  { value: 'EUR', label: 'EUR' },
]
const opOptions = computed(() => [
  { value: 'buy', label: t('profilePage.alerts.buy') },
  { value: 'sell', label: t('profilePage.alerts.sell') },
])
const directionOptions = computed(() => [
  { value: 'above', label: t('profilePage.alerts.above') },
  { value: 'below', label: t('profilePage.alerts.below') },
])

const fieldErrors = reactive<Record<string, string | undefined>>({})
const generalError = ref<string | null>(null)
const submitting = ref(false)

const canSubmit = computed(() => Number(form.threshold) > 0)

// BaseSelect has no error slot — category/currency/op validation errors
// (incl. the "duplicate alert" 422 attached to `currency`) surface here instead.
const selectionError = computed(
  () => fieldErrors.category ?? fieldErrors.currency ?? fieldErrors.op ?? fieldErrors.direction ?? null,
)

function clearErrors() {
  fieldErrors.category = undefined
  fieldErrors.currency = undefined
  fieldErrors.op = undefined
  fieldErrors.direction = undefined
  fieldErrors.threshold = undefined
  generalError.value = null
}

async function submit() {
  if (!canSubmit.value || submitting.value) return
  clearErrors()
  submitting.value = true
  try {
    const res = await profileApi.createAlert({
      category: form.category,
      currency: form.currency,
      op: form.op,
      direction: form.direction,
      threshold: Number(form.threshold),
    })
    emit('created', res.data)
    form.threshold = ''
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
  <form class="alert-form" novalidate @submit.prevent="submit">
    <div class="alert-form__row">
      <BaseSelect
        v-model="form.category"
        :label="t('profilePage.alerts.categoryLabel')"
        :options="categoryOptions"
      />
      <BaseSelect
        v-model="form.currency"
        :label="t('profilePage.alerts.currencyLabel')"
        :options="currencyOptions"
      />
      <BaseSelect v-model="form.op" :label="t('profilePage.alerts.opLabel')" :options="opOptions" />
      <BaseSelect
        v-model="form.direction"
        :label="t('profilePage.alerts.directionLabel')"
        :options="directionOptions"
      />
      <BaseTextField
        v-model="form.threshold"
        type="number"
        inputmode="decimal"
        :min="0"
        :label="t('profilePage.alerts.thresholdLabel')"
        :error="fieldErrors.threshold"
      />
    </div>

    <p v-if="selectionError || generalError" class="alert-form__error" role="alert">
      {{ selectionError ?? generalError }}
    </p>

    <BaseButton type="submit" variant="secondary" :disabled="!canSubmit" :loading="submitting">
      {{ t('profilePage.alerts.add') }}
    </BaseButton>
  </form>
</template>

<style scoped>
.alert-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}
.alert-form__row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: var(--space-3);
}
.alert-form__error {
  padding: var(--space-3);
  border-radius: var(--radius-md);
  background: var(--color-danger-soft);
  color: var(--color-danger);
  font-size: var(--fs-sm);
}
</style>
