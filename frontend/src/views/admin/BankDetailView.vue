<script setup lang="ts">
import { h, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  NDataTable, NButton, NInput, NInputNumber, NSelect, NTag, NSpace, NModal, NCard,
  NForm, NFormItem, NSwitch, NTabs, NTabPane, NCheckbox, NCheckboxGroup, NIcon,
  NDescriptions, NDescriptionsItem, useMessage, useDialog, type DataTableColumns,
} from 'naive-ui'
import { AddOutline, ArrowBackOutline } from '@vicons/ionicons5'
import { adminApi } from '@/api/admin'
import { ApiError } from '@/api/errors'
import type { AdminBank, AdminProduct, FeatureKey, ProductPayload } from '@/types/admin'

const props = defineProps<{ id: number }>()
const router = useRouter()
const message = useMessage()
const dialog = useDialog()

const bank = ref<AdminBank | null>(null)
const products = ref<AdminProduct[]>([])
const loading = ref(true)
const tab = ref<'products' | 'info'>('products')

const FEATURE_KEYS: FeatureKey[] = ['online_application', 'no_guarantor', 'capitalization', 'replenishable']
const FEATURE_LABEL: Record<FeatureKey, string> = {
  online_application: 'Онлайн-заявка', no_guarantor: 'Без поручителя',
  capitalization: 'Капитализация', replenishable: 'Пополняемый',
}
const STATUS_META: Record<string, { label: string; type: 'success' | 'warning' | 'default' | 'error' }> = {
  active: { label: 'активен', type: 'success' }, draft: { label: 'черновик', type: 'warning' },
  hidden: { label: 'скрыт', type: 'default' }, outdated: { label: 'устарел', type: 'error' },
}

const showModal = ref(false)
const editing = ref<AdminProduct | null>(null)
const saving = ref(false)
const fieldErrors = reactive<Record<string, string>>({})
const featureList = ref<FeatureKey[]>([])

function emptyForm(): ProductPayload {
  return {
    bank_id: props.id, category: 'credit', subcategory: null, is_special: false,
    status: 'draft', currency: 'TJS', name_ru: '', name_tg: '', description_ru: '',
    description_tg: '', rate_min: 0, rate_max: 0, amount_min: null, amount_max: null,
    term_min: null, term_max: null, features: {},
  }
}
const form = reactive<ProductPayload>(emptyForm())

async function load() {
  loading.value = true
  try {
    const [b, p] = await Promise.all([adminApi.getBank(props.id), adminApi.listBankProducts(props.id)])
    bank.value = b.data
    products.value = p.data
  } finally {
    loading.value = false
  }
}
onMounted(load)

function clearErrors() { for (const k of Object.keys(fieldErrors)) delete fieldErrors[k] }

function syncFeatures() {
  form.features = Object.fromEntries(featureList.value.map((k) => [k, true]))
}

function openCreate() {
  editing.value = null
  Object.assign(form, emptyForm())
  featureList.value = []
  clearErrors()
  showModal.value = true
}
function openEdit(p: AdminProduct) {
  editing.value = p
  Object.assign(form, {
    bank_id: props.id, category: p.category, subcategory: p.subcategory, is_special: p.is_special,
    status: p.status, currency: p.currency, name_ru: p.name_ru ?? '', name_tg: p.name_tg ?? '',
    description_ru: p.description_ru ?? '', description_tg: p.description_tg ?? '',
    rate_min: p.rate_min ?? 0, rate_max: p.rate_max ?? 0, amount_min: p.amount_min,
    amount_max: p.amount_max, term_min: p.term_min, term_max: p.term_max, features: { ...p.features },
  })
  featureList.value = FEATURE_KEYS.filter((k) => p.features[k])
  clearErrors()
  showModal.value = true
}

async function save() {
  saving.value = true
  clearErrors()
  syncFeatures()
  try {
    if (editing.value) await adminApi.updateProduct(editing.value.id, { ...form })
    else await adminApi.createProduct({ ...form })
    message.success(editing.value ? 'Продукт обновлён' : 'Продукт создан')
    showModal.value = false
    await load()
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

async function toggle(p: AdminProduct) {
  try {
    const res = await adminApi.toggleProduct(p.id)
    const i = products.value.findIndex((x) => x.id === p.id)
    if (i >= 0) products.value[i] = res.data
    message.success(res.data.status === 'active' ? 'Включён' : 'Отключён')
  } catch (e) {
    message.error(e instanceof ApiError ? e.message : 'Не удалось переключить')
  }
}

function remove(p: AdminProduct) {
  dialog.warning({
    title: 'Удалить продукт', content: `Удалить «${p.name_ru ?? p.name_tg}»?`,
    positiveText: 'Удалить', negativeText: 'Отмена',
    onPositiveClick: async () => {
      try { await adminApi.deleteProduct(p.id); message.success('Удалён'); await load() }
      catch (e) { message.error(e instanceof ApiError ? e.message : 'Не удалось удалить') }
    },
  })
}

const categoryOptions = [
  { label: 'Кредит', value: 'credit' }, { label: 'Депозит', value: 'deposit' }, { label: 'Рассрочка', value: 'installment' },
]
const currencyOptions = [{ label: 'TJS', value: 'TJS' }, { label: 'USD', value: 'USD' }, { label: 'EUR', value: 'EUR' }]
const statusOptions = [
  { label: 'Черновик', value: 'draft' }, { label: 'Активен', value: 'active' },
  { label: 'Скрыт', value: 'hidden' }, { label: 'Устарел', value: 'outdated' },
]

const columns: DataTableColumns<AdminProduct> = [
  {
    title: 'Название', key: 'name',
    render: (p) => h(NSpace, { align: 'center', size: 6 }, () => [
      h('strong', p.name_ru ?? p.name_tg ?? '—'),
      p.is_special ? h(NTag, { size: 'small', type: 'info', bordered: false }, () => 'спец') : null,
      p.locked_fields?.length ? h(NTag, { size: 'small', type: 'warning', bordered: false }, () => 'закреплено') : null,
    ]),
  },
  { title: 'Категория', key: 'category', width: 110 },
  { title: 'Валюта', key: 'currency', width: 80 },
  { title: 'Ставка', key: 'rate', width: 110, render: (p) => `${p.rate_min}–${p.rate_max}%` },
  {
    title: 'Статус', key: 'status', width: 110,
    render: (p) => h(NTag, { size: 'small', type: STATUS_META[p.status]?.type ?? 'default', bordered: false },
      () => STATUS_META[p.status]?.label ?? p.status),
  },
  {
    title: '', key: 'actions', width: 270, align: 'right',
    render: (p) => h(NSpace, { justify: 'end', size: 8 }, () => [
      h(NButton, { size: 'small', type: p.status === 'active' ? 'default' : 'primary', secondary: true, onClick: () => toggle(p) },
        () => (p.status === 'active' ? 'Откл.' : 'Вкл.')),
      h(NButton, { size: 'small', quaternary: true, onClick: () => openEdit(p) }, () => 'Изм.'),
      h(NButton, { size: 'small', quaternary: true, type: 'error', onClick: () => remove(p) }, () => 'Удалить'),
    ]),
  },
]
</script>

<template>
  <div>
    <div class="head">
      <div>
        <n-button quaternary size="small" @click="router.push({ name: 'admin-banks' })">
          <template #icon><n-icon><ArrowBackOutline /></n-icon></template>
          Банки
        </n-button>
        <h1 class="head__title">{{ bank?.name_ru ?? '…' }}</h1>
      </div>
      <n-button v-if="tab === 'products'" type="primary" @click="openCreate">
        <template #icon><n-icon><AddOutline /></n-icon></template>
        Продукт
      </n-button>
    </div>

    <n-tabs v-model:value="tab" type="line" animated>
      <n-tab-pane name="products" :tab="`Продукты (${products.length})`">
        <n-card :bordered="false">
          <n-data-table :columns="columns" :data="products" :loading="loading" :row-key="(p: AdminProduct) => p.id" />
        </n-card>
      </n-tab-pane>

      <n-tab-pane name="info" tab="Информация">
        <n-card :bordered="false">
          <n-descriptions label-placement="left" :column="2" bordered>
            <n-descriptions-item label="Slug"><code>{{ bank?.slug }}</code></n-descriptions-item>
            <n-descriptions-item label="Статус">{{ bank?.status }}</n-descriptions-item>
            <n-descriptions-item label="Партнёр">{{ bank?.is_partner ? 'да' : 'нет' }}</n-descriptions-item>
            <n-descriptions-item label="Email">{{ bank?.contact_email ?? '—' }}</n-descriptions-item>
            <n-descriptions-item label="Сайт">{{ bank?.website ?? '—' }}</n-descriptions-item>
            <n-descriptions-item label="Телефон">{{ bank?.phone ?? '—' }}</n-descriptions-item>
            <n-descriptions-item label="Адрес" :span="2">{{ bank?.address_ru ?? '—' }}</n-descriptions-item>
            <n-descriptions-item label="Заявок">{{ bank?.leads_count ?? 0 }}</n-descriptions-item>
          </n-descriptions>
        </n-card>
      </n-tab-pane>
    </n-tabs>

    <n-modal
      v-model:show="showModal" preset="card" style="width: 680px"
      :title="editing ? 'Редактировать продукт' : 'Новый продукт'"
    >
      <n-form label-placement="top">
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
          <n-form-item label="Валюта"><n-select v-model:value="form.currency" :options="currencyOptions" /></n-form-item>
          <n-form-item label="Статус"><n-select v-model:value="form.status" :options="statusOptions" /></n-form-item>
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
          <n-form-item label="Сумма мин"><n-input-number v-model:value="form.amount_min" :min="0" style="width: 100%" clearable /></n-form-item>
          <n-form-item label="Сумма макс"><n-input-number v-model:value="form.amount_max" :min="0" style="width: 100%" clearable /></n-form-item>
        </div>
        <div class="grid3">
          <n-form-item label="Срок мин, мес"><n-input-number v-model:value="form.term_min" :min="1" style="width: 100%" clearable /></n-form-item>
          <n-form-item label="Срок макс, мес"><n-input-number v-model:value="form.term_max" :min="1" style="width: 100%" clearable /></n-form-item>
          <n-form-item label="Подкатегория"><n-input v-model:value="form.subcategory" placeholder="consumer…" /></n-form-item>
        </div>
        <n-form-item label="Описание (RU)">
          <n-input v-model:value="form.description_ru" type="textarea" :autosize="{ minRows: 2, maxRows: 4 }" />
        </n-form-item>
        <n-form-item label="Особенности">
          <n-checkbox-group v-model:value="featureList">
            <n-space>
              <n-checkbox v-for="k in FEATURE_KEYS" :key="k" :value="k" :label="FEATURE_LABEL[k]" />
            </n-space>
          </n-checkbox-group>
        </n-form-item>
        <n-form-item label="Специальный продукт">
          <n-switch v-model:value="form.is_special" />
        </n-form-item>
      </n-form>
      <template #footer>
        <n-space justify="end">
          <n-button @click="showModal = false">Отмена</n-button>
          <n-button type="primary" :loading="saving" @click="save">Сохранить</n-button>
        </n-space>
      </template>
    </n-modal>
  </div>
</template>

<style scoped>
.head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 12px; }
.head__title { font-size: 22px; font-weight: 700; margin: 4px 0 0; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.grid3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
@media (max-width: 560px) { .grid2, .grid3 { grid-template-columns: 1fr; } }
</style>
