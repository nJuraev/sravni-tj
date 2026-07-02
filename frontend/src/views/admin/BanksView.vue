<script setup lang="ts">
import { h, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  NDataTable, NButton, NInput, NSelect, NTag, NSpace, NModal, NCard, NForm,
  NFormItem, NSwitch, NIcon, useMessage, useDialog, type DataTableColumns,
} from 'naive-ui'
import { AddOutline, SearchOutline } from '@vicons/ionicons5'
import { adminApi } from '@/api/admin'
import { ApiError } from '@/api/errors'
import type { AdminBank, BankPayload } from '@/types/admin'

const router = useRouter()
const message = useMessage()
const dialog = useDialog()

const banks = ref<AdminBank[]>([])
const loading = ref(true)
const search = ref('')
const statusFilter = ref<string | null>(null)

const showModal = ref(false)
const editing = ref<AdminBank | null>(null)
const saving = ref(false)
const fieldErrors = reactive<Record<string, string>>({})

function emptyForm(): BankPayload {
  return {
    name_ru: '', name_tg: '', slug: '', status: 'active', is_partner: false,
    contact_email: '', website: '', phone: '', address_ru: '', address_tg: '',
    about_ru: '', about_tg: '', logo_url: '',
  }
}
const form = reactive<BankPayload>(emptyForm())

async function load() {
  loading.value = true
  try {
    const res = await adminApi.listBanks({ search: search.value, status: statusFilter.value ?? undefined })
    banks.value = res.data
  } finally {
    loading.value = false
  }
}
onMounted(load)

function clearErrors() {
  for (const k of Object.keys(fieldErrors)) delete fieldErrors[k]
}

function openCreate() {
  editing.value = null
  Object.assign(form, emptyForm())
  clearErrors()
  showModal.value = true
}
function openEdit(b: AdminBank) {
  editing.value = b
  Object.assign(form, {
    name_ru: b.name_ru, name_tg: b.name_tg ?? '', slug: b.slug, status: b.status,
    is_partner: b.is_partner, contact_email: b.contact_email ?? '', website: b.website ?? '',
    phone: b.phone ?? '', address_ru: b.address_ru ?? '', address_tg: b.address_tg ?? '',
    about_ru: b.about_ru ?? '', about_tg: b.about_tg ?? '', logo_url: b.logo_url ?? '',
  })
  clearErrors()
  showModal.value = true
}

async function save() {
  saving.value = true
  clearErrors()
  try {
    if (editing.value) await adminApi.updateBank(editing.value.id, { ...form })
    else await adminApi.createBank({ ...form })
    message.success(editing.value ? 'Банк обновлён' : 'Банк создан')
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

function remove(b: AdminBank) {
  dialog.warning({
    title: 'Удалить банк',
    content: `Удалить «${b.name_ru}»? Продукты банка тоже будут удалены.`,
    positiveText: 'Удалить',
    negativeText: 'Отмена',
    onPositiveClick: async () => {
      try {
        await adminApi.deleteBank(b.id)
        message.success('Банк удалён')
        await load()
      } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'Не удалось удалить (возможно, есть заявки)')
      }
    },
  })
}

const statusOptions = [
  { label: 'Активен', value: 'active' },
  { label: 'Выключен', value: 'inactive' },
]
const statusFilterOptions = [{ label: 'Все статусы', value: '' }, ...statusOptions]

const columns: DataTableColumns<AdminBank> = [
  {
    title: 'Название', key: 'name_ru',
    render: (b) => h(NSpace, { align: 'center', size: 6 }, () => [
      h('strong', b.name_ru),
      b.is_partner ? h(NTag, { size: 'small', type: 'info', bordered: false }, () => 'партнёр') : null,
    ]),
  },
  { title: 'Slug', key: 'slug', render: (b) => h('code', b.slug) },
  {
    title: 'Статус', key: 'status', width: 120,
    render: (b) => h(NTag, { size: 'small', type: b.status === 'active' ? 'success' : 'default', bordered: false },
      () => (b.status === 'active' ? 'активен' : 'выключен')),
  },
  { title: 'Продукты', key: 'products_count', width: 100, render: (b) => b.products_count ?? 0 },
  { title: 'Заявки', key: 'leads_count', width: 90, render: (b) => b.leads_count ?? 0 },
  {
    title: '', key: 'actions', width: 300, align: 'right',
    render: (b) => h(NSpace, { justify: 'end', size: 8 }, () => [
      h(NButton, { size: 'small', secondary: true, type: 'primary', onClick: () => router.push({ name: 'admin-bank', params: { id: b.id } }) }, () => 'Продукты'),
      h(NButton, { size: 'small', quaternary: true, onClick: () => openEdit(b) }, () => 'Изм.'),
      h(NButton, { size: 'small', quaternary: true, type: 'error', onClick: () => remove(b) }, () => 'Удалить'),
    ]),
  },
]
</script>

<template>
  <div>
    <div class="head">
      <h1 class="head__title">Банки</h1>
      <n-space align="center">
        <n-input v-model:value="search" placeholder="Поиск…" clearable style="width: 220px" @keyup.enter="load">
          <template #prefix><n-icon><SearchOutline /></n-icon></template>
        </n-input>
        <n-select
          v-model:value="statusFilter" :options="statusFilterOptions" placeholder="Статус"
          clearable style="width: 150px" @update:value="load"
        />
        <n-button type="primary" @click="openCreate">
          <template #icon><n-icon><AddOutline /></n-icon></template>
          Банк
        </n-button>
      </n-space>
    </div>

    <n-card :bordered="false">
      <n-data-table
        :columns="columns" :data="banks" :loading="loading"
        :pagination="{ pageSize: 15 }" :row-key="(b: AdminBank) => b.id"
      />
    </n-card>

    <n-modal
      v-model:show="showModal" preset="card" style="width: 640px"
      :title="editing ? 'Редактировать банк' : 'Новый банк'"
    >
      <n-form label-placement="top" @submit.prevent="save">
        <n-space :wrap-item="false" style="gap: 16px" vertical>
          <div class="grid2">
            <n-form-item label="Название (RU)" :validation-status="fieldErrors.name_ru ? 'error' : undefined" :feedback="fieldErrors.name_ru">
              <n-input v-model:value="form.name_ru" />
            </n-form-item>
            <n-form-item label="Название (TG)">
              <n-input v-model:value="form.name_tg" />
            </n-form-item>
          </div>
          <div class="grid2">
            <n-form-item label="Slug" :validation-status="fieldErrors.slug ? 'error' : undefined" :feedback="fieldErrors.slug">
              <n-input v-model:value="form.slug" placeholder="eskhata" />
            </n-form-item>
            <n-form-item label="Статус">
              <n-select v-model:value="form.status" :options="statusOptions" />
            </n-form-item>
          </div>
          <div class="grid2">
            <n-form-item label="Email (справочный)" :validation-status="fieldErrors.contact_email ? 'error' : undefined" :feedback="fieldErrors.contact_email">
              <n-input v-model:value="form.contact_email" />
            </n-form-item>
            <n-form-item label="Сайт">
              <n-input v-model:value="form.website" />
            </n-form-item>
          </div>
          <div class="grid2">
            <n-form-item label="Телефон">
              <n-input v-model:value="form.phone" />
            </n-form-item>
            <n-form-item label="Логотип (URL)">
              <n-input v-model:value="form.logo_url" />
            </n-form-item>
          </div>
          <n-form-item label="Адрес (RU)">
            <n-input v-model:value="form.address_ru" />
          </n-form-item>
          <div class="grid2">
            <n-form-item label="О банке (RU)">
              <n-input v-model:value="form.about_ru" type="textarea" :autosize="{ minRows: 2, maxRows: 5 }" />
            </n-form-item>
            <n-form-item label="О банке (TG)">
              <n-input v-model:value="form.about_tg" type="textarea" :autosize="{ minRows: 2, maxRows: 5 }" />
            </n-form-item>
          </div>
          <n-form-item label="Партнёр">
            <n-switch v-model:value="form.is_partner" />
          </n-form-item>
        </n-space>
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
.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}
.head__title { font-size: 24px; font-weight: 700; margin: 0; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 560px) { .grid2 { grid-template-columns: 1fr; } }
</style>
