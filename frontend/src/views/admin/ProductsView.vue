<script setup lang="ts">
import { h, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  NDataTable, NButton, NInput, NSelect, NTag, NSpace, NCard, NIcon, NTabs, NTabPane, NTooltip,
  useMessage, useDialog, type DataTableColumns,
} from 'naive-ui'
import { SearchOutline, CreateOutline, OpenOutline } from '@vicons/ionicons5'
import { adminApi } from '@/api/admin'
import { ApiError } from '@/api/errors'
import type { AdminProduct } from '@/types/admin'

const router = useRouter()
const message = useMessage()
const dialog = useDialog()

const products = ref<AdminProduct[]>([])
const loading = ref(true)
const search = ref('')
const categoryFilter = ref<'' | 'credit' | 'deposit' | 'installment'>('')
const statusFilter = ref<string | null>(null)

async function load() {
  loading.value = true
  try {
    const res = await adminApi.listProducts({
      category: categoryFilter.value || undefined,
      status: statusFilter.value ?? undefined,
      search: search.value || undefined,
    })
    products.value = res.data
  } finally {
    loading.value = false
  }
}
onMounted(load)

const STATUS_META: Record<string, { label: string; type: 'success' | 'warning' | 'default' | 'error' }> = {
  active: { label: 'активен', type: 'success' }, draft: { label: 'черновик', type: 'warning' },
  hidden: { label: 'скрыт', type: 'default' }, outdated: { label: 'устарел', type: 'error' },
}
const CATEGORY_LABEL: Record<string, string> = {
  credit: 'Кредит', deposit: 'Депозит', installment: 'Рассрочка',
}
const statusFilterOptions = [
  { label: 'Все статусы', value: '' },
  { label: 'Активен', value: 'active' },
  { label: 'Черновик', value: 'draft' },
  { label: 'Скрыт', value: 'hidden' },
  { label: 'Устарел', value: 'outdated' },
]

function formatRate(p: AdminProduct): string {
  if (p.rate_min === null && p.rate_max === null) return '—'
  if (p.rate_min === p.rate_max) return `${p.rate_min}%`
  return `${p.rate_min}–${p.rate_max}%`
}

async function toggle(p: AdminProduct) {
  try {
    const res = await adminApi.toggleProduct(p.id)
    const idx = products.value.findIndex((x) => x.id === p.id)
    if (idx !== -1) products.value[idx] = res.data
  } catch (e) {
    message.error(e instanceof ApiError ? e.message : 'Не удалось переключить статус')
  }
}

function remove(p: AdminProduct) {
  dialog.warning({
    title: 'Удалить продукт',
    content: `Удалить «${p.name_ru ?? p.name_tg}»?`,
    positiveText: 'Удалить',
    negativeText: 'Отмена',
    onPositiveClick: async () => {
      try {
        await adminApi.deleteProduct(p.id)
        message.success('Продукт удалён')
        await load()
      } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'Не удалось удалить')
      }
    },
  })
}

const columns: DataTableColumns<AdminProduct> = [
  {
    title: 'Банк', key: 'bank', width: 180,
    render: (p) => p.bank?.name_ru ?? '—',
  },
  {
    title: 'Название', key: 'name_ru',
    render: (p) => h('strong', p.name_ru ?? p.name_tg ?? '—'),
  },
  {
    title: 'Тип', key: 'category', width: 110,
    render: (p) => CATEGORY_LABEL[p.category] ?? p.category,
  },
  { title: 'Ставка', key: 'rate', width: 110, render: (p) => formatRate(p) },
  {
    title: 'Статус', key: 'status', width: 190,
    render: (p) => h(NSpace, { size: 8, align: 'center' }, () => [
      h(NTag, { size: 'small', type: STATUS_META[p.status]?.type ?? 'default', bordered: false },
        () => STATUS_META[p.status]?.label ?? p.status),
      h(NButton, { size: 'tiny', quaternary: true, onClick: () => toggle(p) },
        () => (p.status === 'active' ? 'Скрыть' : 'Включить')),
    ]),
  },
  {
    title: '', key: 'actions', width: 230, align: 'right',
    render: (p) => h(NSpace, { justify: 'end', size: 4, align: 'center' }, () => [
      p.source_url ? iconLink('Оригинал на сайте банка', OpenOutline, p.source_url) : null,
      iconButton('Изменить', CreateOutline, () => router.push({ name: 'admin-product', params: { id: p.id } })),
      h(NButton, {
        size: 'small', quaternary: true,
        onClick: () => router.push({ name: 'admin-bank', params: { id: p.bank_id } }),
      }, () => 'Открыть банк'),
      h(NButton, { size: 'small', quaternary: true, type: 'error', onClick: () => remove(p) }, () => 'Удалить'),
    ]),
  },
]

function iconButton(tooltip: string, icon: typeof CreateOutline, onClick: () => void) {
  return h(NTooltip, null, {
    trigger: () => h(NButton, { size: 'small', quaternary: true, circle: true, onClick },
      { icon: () => h(NIcon, null, () => h(icon)) }),
    default: () => tooltip,
  })
}

function iconLink(tooltip: string, icon: typeof OpenOutline, href: string) {
  return h(NTooltip, null, {
    trigger: () => h(NButton, { size: 'small', quaternary: true, circle: true, tag: 'a', href, target: '_blank', rel: 'noopener noreferrer' },
      { icon: () => h(NIcon, null, () => h(icon)) }),
    default: () => tooltip,
  })
}
</script>

<template>
  <div>
    <div class="head">
      <h1 class="head__title">Все продукты</h1>
      <n-space align="center">
        <n-input v-model:value="search" placeholder="Поиск по названию…" clearable style="width: 220px" @keyup.enter="load">
          <template #prefix><n-icon><SearchOutline /></n-icon></template>
        </n-input>
        <n-select
          v-model:value="statusFilter" :options="statusFilterOptions" placeholder="Статус"
          clearable style="width: 160px" @update:value="load"
        />
      </n-space>
    </div>

    <n-tabs v-model:value="categoryFilter" type="segment" size="small" class="type-tabs" @update:value="load">
      <n-tab-pane name="" tab="Все" />
      <n-tab-pane name="credit" tab="Кредиты" />
      <n-tab-pane name="deposit" tab="Депозиты" />
      <n-tab-pane name="installment" tab="Рассрочка" />
    </n-tabs>

    <n-card :bordered="false">
      <n-data-table
        :columns="columns" :data="products" :loading="loading"
        :pagination="{ pageSize: 20 }" :row-key="(p: AdminProduct) => p.id"
      />
    </n-card>
  </div>
</template>

<style scoped>
.head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.head__title { font-size: 24px; font-weight: 700; margin: 0; }
.type-tabs { max-width: 420px; margin-bottom: 16px; }
</style>
