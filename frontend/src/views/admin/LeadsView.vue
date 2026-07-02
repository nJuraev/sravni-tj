<script setup lang="ts">
import { h, onMounted, ref } from 'vue'
import {
  NDataTable, NButton, NInput, NCard, NSpace, NIcon, useMessage, useDialog,
  type DataTableColumns, type PaginationProps,
} from 'naive-ui'
import { SearchOutline } from '@vicons/ionicons5'
import { adminApi } from '@/api/admin'
import { ApiError } from '@/api/errors'
import type { AdminLead } from '@/types/admin'

const message = useMessage()
const dialog = useDialog()

const leads = ref<AdminLead[]>([])
const loading = ref(true)
const search = ref('')
const page = ref(1)
const pageCount = ref(1)
const total = ref(0)

async function load() {
  loading.value = true
  try {
    const res = await adminApi.listLeads({ search: search.value, page: page.value, per_page: 20 })
    leads.value = res.data
    pageCount.value = res.meta.last_page
    total.value = res.meta.total
    page.value = res.meta.current_page
  } finally {
    loading.value = false
  }
}
onMounted(load)

function applySearch() { page.value = 1; load() }
function onPage(p: number) { page.value = p; load() }

function remove(lead: AdminLead) {
  dialog.warning({
    title: 'Удалить заявку', content: `Удалить заявку от «${lead.full_name}»?`,
    positiveText: 'Удалить', negativeText: 'Отмена',
    onPositiveClick: async () => {
      try { await adminApi.deleteLead(lead.id); message.success('Удалено'); await load() }
      catch (e) { message.error(e instanceof ApiError ? e.message : 'Не удалось удалить') }
    },
  })
}

function fmtDate(iso: string | null) {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' })
}

const pagination = (): PaginationProps => ({
  page: page.value, pageCount: pageCount.value, pageSize: 20,
  itemCount: total.value, onUpdatePage: onPage,
})

const columns: DataTableColumns<AdminLead> = [
  { title: 'Дата', key: 'created_at', width: 150, render: (l) => fmtDate(l.created_at) },
  { title: 'Имя', key: 'full_name', render: (l) => h('strong', l.full_name) },
  { title: 'Телефон', key: 'phone', render: (l) => h('a', { href: `tel:${l.phone}` }, l.phone) },
  { title: 'Продукт', key: 'product', render: (l) => l.product?.name_ru ?? '—' },
  { title: 'Банк', key: 'bank', render: (l) => l.bank?.name_ru ?? '—' },
  {
    title: '', key: 'actions', width: 110, align: 'right',
    render: (l) => h(NButton, { size: 'small', quaternary: true, type: 'error', onClick: () => remove(l) }, () => 'Удалить'),
  },
]
</script>

<template>
  <div>
    <div class="head">
      <h1 class="head__title">Заявки <span class="head__count">({{ total }})</span></h1>
      <n-space align="center">
        <n-input v-model:value="search" placeholder="Имя или телефон…" clearable style="width: 240px" @keyup.enter="applySearch">
          <template #prefix><n-icon><SearchOutline /></n-icon></template>
        </n-input>
        <n-button type="primary" @click="applySearch">Найти</n-button>
      </n-space>
    </div>

    <n-card :bordered="false">
      <n-data-table
        remote :columns="columns" :data="leads" :loading="loading"
        :pagination="pagination()" :row-key="(l: AdminLead) => l.id"
      />
    </n-card>
  </div>
</template>

<style scoped>
.head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.head__title { font-size: 24px; font-weight: 700; margin: 0; }
.head__count { color: #999; font-size: 18px; font-weight: 400; }
</style>
