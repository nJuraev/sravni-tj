<script setup lang="ts">
import { h, onMounted, ref } from 'vue'
import {
  NDataTable, NCard, NSpace, NSelect, NButton,
  type DataTableColumns, type PaginationProps, type SelectOption,
} from 'naive-ui'
import { adminApi } from '@/api/admin'
import type { AdminBank, AdminCurrencyRate } from '@/types/admin'

const rates = ref<AdminCurrencyRate[]>([])
const banks = ref<AdminBank[]>([])
const loading = ref(true)

const bankFilter = ref<number | null>(null)
const currencyFilter = ref<string | null>(null)
const categoryFilter = ref<string | null>(null)

const page = ref(1)
const pageCount = ref(1)
const total = ref(0)

const currencyOptions: SelectOption[] = [
  { label: 'TJS', value: 'TJS' },
  { label: 'USD', value: 'USD' },
  { label: 'EUR', value: 'EUR' },
]
const categoryOptions: SelectOption[] = [
  { label: 'Наличные', value: 'cash' },
  { label: 'Безналичные', value: 'transfer' },
]
const bankOptions = ref<SelectOption[]>([])

async function loadBanks() {
  const res = await adminApi.listBanks()
  banks.value = res.data
  bankOptions.value = res.data.map((b) => ({ label: b.name_ru, value: b.id }))
}

async function load() {
  loading.value = true
  try {
    const res = await adminApi.listCurrencyRates({
      bank_id: bankFilter.value ?? undefined,
      currency: currencyFilter.value ?? undefined,
      category: categoryFilter.value ?? undefined,
      page: page.value,
      per_page: 25,
    })
    rates.value = res.data
    pageCount.value = res.meta.last_page
    total.value = res.meta.total
    page.value = res.meta.current_page
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await loadBanks()
  await load()
})

function applyFilters() {
  page.value = 1
  load()
}
function onPage(p: number) {
  page.value = p
  load()
}
function resetFilters() {
  bankFilter.value = null
  currencyFilter.value = null
  categoryFilter.value = null
  applyFilters()
}

function fmtDate(iso: string | null) {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('ru-RU')
}
function fmtNum(n: number | null) {
  return n !== null ? n.toFixed(4).replace(/\.?0+$/, '') : '—'
}

const pagination = (): PaginationProps => ({
  page: page.value, pageCount: pageCount.value, pageSize: 25,
  itemCount: total.value, onUpdatePage: onPage,
})

const categoryLabels: Record<string, string> = { cash: 'Наличные', transfer: 'Безналичные' }

const columns: DataTableColumns<AdminCurrencyRate> = [
  { title: 'Дата', key: 'rate_date', width: 110, render: (r) => fmtDate(r.rate_date) },
  { title: 'Банк', key: 'bank', render: (r) => r.bank?.name_ru ?? '—' },
  { title: 'Валюта', key: 'currency', width: 90, render: (r) => h('strong', r.currency) },
  { title: 'Тип', key: 'category', width: 120, render: (r) => categoryLabels[r.category] ?? r.category },
  { title: 'Покупка', key: 'buy', width: 100, align: 'right', render: (r) => fmtNum(r.buy) },
  { title: 'Продажа', key: 'sell', width: 100, align: 'right', render: (r) => fmtNum(r.sell) },
]
</script>

<template>
  <div>
    <div class="head">
      <h1 class="head__title">Курсы валют <span class="head__count">({{ total }})</span></h1>
      <n-space align="center">
        <n-select
          v-model:value="bankFilter" :options="bankOptions" placeholder="Банк" clearable filterable
          style="width: 220px" @update:value="applyFilters"
        />
        <n-select
          v-model:value="currencyFilter" :options="currencyOptions" placeholder="Валюта" clearable
          style="width: 120px" @update:value="applyFilters"
        />
        <n-select
          v-model:value="categoryFilter" :options="categoryOptions" placeholder="Тип" clearable
          style="width: 150px" @update:value="applyFilters"
        />
        <n-button quaternary @click="resetFilters">Сбросить</n-button>
      </n-space>
    </div>

    <n-card :bordered="false">
      <n-data-table
        remote :columns="columns" :data="rates" :loading="loading"
        :pagination="pagination()" :row-key="(r: AdminCurrencyRate) => r.id"
      />
    </n-card>
  </div>
</template>

<style scoped>
.head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.head__title { font-size: 24px; font-weight: 700; margin: 0; }
.head__count { color: #999; font-size: 18px; font-weight: 400; }
</style>
