<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  NButton, NInput, NInputNumber, NSelect, NSwitch, NCheckbox, NCheckboxGroup,
  NDynamicTags, NIcon, NSpace, NCard, NForm, NFormItem, NTag, useMessage,
} from 'naive-ui'
import { ArrowBackOutline } from '@vicons/ionicons5'
import { adminApi } from '@/api/admin'
import { ApiError } from '@/api/errors'
import type { AdminBank, FeatureKey, ProductPayload, ProductStatus } from '@/types/admin'
import type { Category, Currency, Subcategory } from '@/types/api'

const props = defineProps<{ id?: number }>()
const route = useRoute()
const router = useRouter()
const message = useMessage()

const isEdit = computed(() => props.id != null)
const loading = ref(true)
const saving = ref(false)
const fieldErrors = reactive<Record<string, string>>({})

const banks = ref<AdminBank[]>([])
const lockedFields = ref<string[]>([])
const sourceUrl = ref<string | null>(null)

const FEATURE_KEYS: FeatureKey[] = ['online_application', 'no_guarantor', 'capitalization', 'replenishable']
const FEATURE_LABEL: Record<FeatureKey, string> = {
  online_application: 'Онлайн-заявка', no_guarantor: 'Без поручителя',
  capitalization: 'Капитализация', replenishable: 'Пополняемый',
}
const featureList = ref<FeatureKey[]>([])

const categoryOptions = [
  { label: 'Кредит', value: 'credit' }, { label: 'Депозит', value: 'deposit' }, { label: 'Рассрочка', value: 'installment' },
]
const subcategoryOptions = [
  { label: 'Потребительский', value: 'consumer' }, { label: 'Ипотека', value: 'mortgage' },
  { label: 'Автокредит', value: 'auto' }, { label: 'Бизнес', value: 'business' },
  { label: 'Аграрный', value: 'agro' }, { label: 'Образование', value: 'education' },
  { label: 'Рефинансирование', value: 'refinance' }, { label: 'Ломбардный', value: 'pawn' },
  { label: 'Срочный', value: 'term' }, { label: 'Накопительный', value: 'savings' },
  { label: 'До востребования', value: 'demand' }, { label: 'Детский', value: 'kids' },
  { label: 'Прочее', value: 'other' },
]
const currencyOptions = [{ label: 'TJS', value: 'TJS' }, { label: 'USD', value: 'USD' }, { label: 'EUR', value: 'EUR' }]
const statusOptions = [
  { label: 'Черновик', value: 'draft' }, { label: 'Активен', value: 'active' },
  { label: 'Скрыт', value: 'hidden' }, { label: 'Устарел', value: 'outdated' },
]

interface FormState {
  bank_id: number
  category: Category
  subcategory: Subcategory | null
  is_special: boolean
  status: ProductStatus
  currency: Currency
  name_ru: string | null
  name_tg: string | null
  description_ru: string | null
  description_tg: string | null
  key_conditions_ru: string[]
  key_conditions_tg: string[]
  documents_ru: string[]
  documents_tg: string[]
  rate_min: number
  rate_max: number
  amount_min: number | null
  amount_max: number | null
  term_min: number | null
  term_max: number | null
  features: Partial<Record<FeatureKey, boolean>>
}

function emptyForm(): FormState {
  const bankIdFromQuery = Number(route.query.bankId)
  return {
    bank_id: Number.isFinite(bankIdFromQuery) && bankIdFromQuery > 0 ? bankIdFromQuery : 0,
    category: 'credit', subcategory: null, is_special: false,
    status: 'draft', currency: 'TJS', name_ru: '', name_tg: '',
    description_ru: '', description_tg: '',
    key_conditions_ru: [], key_conditions_tg: [], documents_ru: [], documents_tg: [],
    rate_min: 0, rate_max: 0, amount_min: null, amount_max: null,
    term_min: null, term_max: null, features: {},
  }
}
const form = reactive<FormState>(emptyForm())

function clearErrors() { for (const k of Object.keys(fieldErrors)) delete fieldErrors[k] }

function backTarget() {
  return form.bank_id ? { name: 'admin-bank', params: { id: form.bank_id } } : { name: 'admin-products' }
}

async function load() {
  loading.value = true
  try {
    const bankList = await adminApi.listBanks()
    banks.value = bankList.data

    if (props.id != null) {
      const res = await adminApi.getProduct(props.id)
      const p = res.data
      Object.assign(form, {
        bank_id: p.bank_id, category: p.category, subcategory: p.subcategory, is_special: p.is_special,
        status: p.status, currency: p.currency, name_ru: p.name_ru ?? '', name_tg: p.name_tg ?? '',
        description_ru: p.description_ru ?? '', description_tg: p.description_tg ?? '',
        key_conditions_ru: p.key_conditions_ru ?? [], key_conditions_tg: p.key_conditions_tg ?? [],
        documents_ru: p.documents_ru ?? [], documents_tg: p.documents_tg ?? [],
        rate_min: p.rate_min ?? 0, rate_max: p.rate_max ?? 0, amount_min: p.amount_min,
        amount_max: p.amount_max, term_min: p.term_min, term_max: p.term_max, features: { ...p.features },
      })
      featureList.value = FEATURE_KEYS.filter((k) => p.features[k])
      lockedFields.value = p.locked_fields ?? []
      sourceUrl.value = p.source_url
    }
  } finally {
    loading.value = false
  }
}
onMounted(load)

function syncFeatures() {
  form.features = Object.fromEntries(featureList.value.map((k) => [k, true]))
}

async function save() {
  saving.value = true
  clearErrors()
  syncFeatures()
  const payload: ProductPayload = { ...form }
  try {
    const res = isEdit.value
      ? await adminApi.updateProduct(props.id as number, payload)
      : await adminApi.createProduct(payload)
    message.success(isEdit.value ? 'Продукт обновлён' : 'Продукт создан')
    if (!isEdit.value) router.replace({ name: 'admin-product', params: { id: res.data.id } })
  } catch (e) {
    if (e instanceof ApiError && e.isValidation) {
      for (const [k, v] of Object.entries(e.fieldErrors)) fieldErrors[k] = v[0]
      message.error('Проверьте поля формы')
    } else {
      message.error(e instanceof ApiError ? e.message : 'Ошибка сохранения')
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <div class="head">
      <n-button quaternary size="small" @click="router.push(backTarget())">
        <template #icon><n-icon><ArrowBackOutline /></n-icon></template>
        Назад
      </n-button>
      <h1 class="head__title">{{ isEdit ? 'Редактировать продукт' : 'Новый продукт' }}</h1>
      <n-tag v-if="lockedFields.length" size="small" type="warning" :bordered="false" style="margin-left: auto">
        закреплено: {{ lockedFields.join(', ') }}
      </n-tag>
    </div>

    <n-card v-if="!loading" :bordered="false">
      <n-form label-placement="top">
        <div class="grid2">
          <n-form-item label="Банк" :validation-status="fieldErrors.bank_id ? 'error' : undefined" :feedback="fieldErrors.bank_id">
            <n-select
              v-model:value="form.bank_id"
              :options="banks.map((b) => ({ label: b.name_ru, value: b.id }))"
              placeholder="Выберите банк" filterable
            />
          </n-form-item>
          <n-form-item v-if="sourceUrl" label="Источник">
            <a :href="sourceUrl" target="_blank" rel="noopener noreferrer">{{ sourceUrl }}</a>
          </n-form-item>
        </div>

        <div class="grid2">
          <n-form-item label="Название (RU)" :validation-status="fieldErrors.name_ru ? 'error' : undefined" :feedback="fieldErrors.name_ru">
            <n-input v-model:value="form.name_ru" />
          </n-form-item>
          <n-form-item label="Название (TG)">
            <n-input v-model:value="form.name_tg" />
          </n-form-item>
        </div>

        <div class="grid3">
          <n-form-item label="Категория"><n-select v-model:value="form.category" :options="categoryOptions" /></n-form-item>
          <n-form-item label="Подкатегория">
            <n-select v-model:value="form.subcategory" :options="subcategoryOptions" clearable placeholder="—" />
          </n-form-item>
          <n-form-item label="Валюта"><n-select v-model:value="form.currency" :options="currencyOptions" /></n-form-item>
        </div>

        <div class="grid2">
          <n-form-item label="Статус"><n-select v-model:value="form.status" :options="statusOptions" /></n-form-item>
          <n-form-item label="Специальный продукт">
            <n-switch v-model:value="form.is_special" />
          </n-form-item>
        </div>

        <div class="grid2">
          <n-form-item label="Ставка мин, %" :validation-status="fieldErrors.rate_min ? 'error' : undefined" :feedback="fieldErrors.rate_min">
            <n-input-number v-model:value="form.rate_min" :min="0" :max="100" :step="0.1" style="width: 100%" />
          </n-form-item>
          <n-form-item label="Ставка макс, %" :validation-status="fieldErrors.rate_max ? 'error' : undefined" :feedback="fieldErrors.rate_max">
            <n-input-number v-model:value="form.rate_max" :min="0" :max="100" :step="0.1" style="width: 100%" />
          </n-form-item>
        </div>
        <div class="grid2">
          <n-form-item label="Сумма мин" :validation-status="fieldErrors.amount_min ? 'error' : undefined" :feedback="fieldErrors.amount_min">
            <n-input-number v-model:value="form.amount_min" :min="0" style="width: 100%" clearable />
          </n-form-item>
          <n-form-item label="Сумма макс" :validation-status="fieldErrors.amount_max ? 'error' : undefined" :feedback="fieldErrors.amount_max">
            <n-input-number v-model:value="form.amount_max" :min="0" style="width: 100%" clearable />
          </n-form-item>
        </div>
        <div class="grid2">
          <n-form-item label="Срок мин, мес" :validation-status="fieldErrors.term_min ? 'error' : undefined" :feedback="fieldErrors.term_min">
            <n-input-number v-model:value="form.term_min" :min="1" style="width: 100%" clearable />
          </n-form-item>
          <n-form-item label="Срок макс, мес" :validation-status="fieldErrors.term_max ? 'error' : undefined" :feedback="fieldErrors.term_max">
            <n-input-number v-model:value="form.term_max" :min="1" style="width: 100%" clearable />
          </n-form-item>
        </div>

        <div class="grid2">
          <n-form-item label="Описание (RU)">
            <n-input v-model:value="form.description_ru" type="textarea" :autosize="{ minRows: 2, maxRows: 6 }" />
          </n-form-item>
          <n-form-item label="Описание (TG)">
            <n-input v-model:value="form.description_tg" type="textarea" :autosize="{ minRows: 2, maxRows: 6 }" />
          </n-form-item>
        </div>

        <div class="grid2">
          <n-form-item label="Ключевые условия (RU)">
            <n-dynamic-tags v-model:value="form.key_conditions_ru" />
          </n-form-item>
          <n-form-item label="Ключевые условия (TG)">
            <n-dynamic-tags v-model:value="form.key_conditions_tg" />
          </n-form-item>
        </div>
        <div class="grid2">
          <n-form-item label="Требования / документы (RU)">
            <n-dynamic-tags v-model:value="form.documents_ru" />
          </n-form-item>
          <n-form-item label="Требования / документы (TG)">
            <n-dynamic-tags v-model:value="form.documents_tg" />
          </n-form-item>
        </div>

        <n-form-item label="Особенности">
          <n-checkbox-group v-model:value="featureList">
            <n-space>
              <n-checkbox v-for="k in FEATURE_KEYS" :key="k" :value="k" :label="FEATURE_LABEL[k]" />
            </n-space>
          </n-checkbox-group>
        </n-form-item>
      </n-form>

      <n-space justify="end">
        <n-button @click="router.push(backTarget())">Отмена</n-button>
        <n-button type="primary" :loading="saving" @click="save">Сохранить</n-button>
      </n-space>
    </n-card>
  </div>
</template>

<style scoped>
.head { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
.head__title { font-size: 22px; font-weight: 700; margin: 0; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.grid3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
@media (max-width: 700px) { .grid2, .grid3 { grid-template-columns: 1fr; } }

/* Ключевые условия/документы — длинные предложения-теги: наивный n-tag по
   умолчанию white-space: nowrap и вылезает за колонку формы. */
:deep(.n-dynamic-tags) { width: 100%; }
:deep(.n-dynamic-tags > div) { max-width: 100%; min-width: 0; }
:deep(.n-dynamic-tags .n-tag) {
  max-width: 100%;
  height: auto;
  white-space: normal;
  word-break: break-word;
  align-items: flex-start;
}
</style>
