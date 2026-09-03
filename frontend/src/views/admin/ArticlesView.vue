<script setup lang="ts">
import { h, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  NDataTable, NButton, NInput, NSelect, NTag, NSpace, NCard, NIcon,
  useMessage, useDialog, type DataTableColumns,
} from 'naive-ui'
import { AddOutline, SearchOutline } from '@vicons/ionicons5'
import { adminApi } from '@/api/admin'
import { ApiError } from '@/api/errors'
import type { AdminArticle, AdminArticleCategory } from '@/types/admin'

const router = useRouter()
const message = useMessage()
const dialog = useDialog()

const articles = ref<AdminArticle[]>([])
const categories = ref<AdminArticleCategory[]>([])
const loading = ref(true)
const search = ref('')
const statusFilter = ref<string | null>(null)
const categoryFilter = ref<number | null>(null)

async function load() {
  loading.value = true
  try {
    const res = await adminApi.listArticles({
      search: search.value || undefined,
      status: statusFilter.value ?? undefined,
      category_id: categoryFilter.value ?? undefined,
    })
    articles.value = res.data
  } finally {
    loading.value = false
  }
}
onMounted(async () => {
  const [cats] = await Promise.all([adminApi.listArticleCategories(), load()])
  categories.value = cats.data
})

function remove(a: AdminArticle) {
  dialog.warning({
    title: 'Удалить статью',
    content: `Удалить «${a.title_ru}»?`,
    positiveText: 'Удалить',
    negativeText: 'Отмена',
    onPositiveClick: async () => {
      try {
        await adminApi.deleteArticle(a.id)
        message.success('Статья удалена')
        await load()
      } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'Не удалось удалить')
      }
    },
  })
}

const statusOptions = [
  { label: 'Черновик', value: 'draft' },
  { label: 'Опубликована', value: 'published' },
]
const statusFilterOptions = [{ label: 'Все статусы', value: '' }, ...statusOptions]

function formatDate(v: string | null): string {
  if (!v) return '—'
  return new Date(v).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const columns: DataTableColumns<AdminArticle> = [
  {
    title: 'Заголовок', key: 'title_ru',
    render: (a) => h('strong', a.title_ru),
  },
  {
    title: 'Категория', key: 'category', width: 160,
    render: (a) => a.category?.name_ru ?? '—',
  },
  {
    title: 'Статус', key: 'status', width: 130,
    render: (a) => h(NTag, { size: 'small', type: a.status === 'published' ? 'success' : 'warning', bordered: false },
      () => (a.status === 'published' ? 'опубликована' : 'черновик')),
  },
  { title: 'Опубликована', key: 'published_at', width: 150, render: (a) => formatDate(a.published_at) },
  {
    title: '', key: 'actions', width: 200, align: 'right',
    render: (a) => h(NSpace, { justify: 'end', size: 8 }, () => [
      h(NButton, { size: 'small', quaternary: true, onClick: () => router.push({ name: 'admin-article', params: { id: a.id } }) }, () => 'Изм.'),
      h(NButton, { size: 'small', quaternary: true, type: 'error', onClick: () => remove(a) }, () => 'Удалить'),
    ]),
  },
]
</script>

<template>
  <div>
    <div class="head">
      <h1 class="head__title">Статьи блога</h1>
      <n-space align="center">
        <n-input v-model:value="search" placeholder="Поиск…" clearable style="width: 220px" @keyup.enter="load">
          <template #prefix><n-icon><SearchOutline /></n-icon></template>
        </n-input>
        <n-select
          v-model:value="statusFilter" :options="statusFilterOptions" placeholder="Статус"
          clearable style="width: 160px" @update:value="load"
        />
        <n-select
          v-model:value="categoryFilter"
          :options="categories.map((c) => ({ label: c.name_ru, value: c.id }))"
          placeholder="Категория" clearable style="width: 180px" @update:value="load"
        />
        <n-button type="primary" @click="router.push({ name: 'admin-article-new' })">
          <template #icon><n-icon><AddOutline /></n-icon></template>
          Статья
        </n-button>
      </n-space>
    </div>

    <n-card :bordered="false">
      <n-data-table
        :columns="columns" :data="articles" :loading="loading"
        :pagination="{ pageSize: 15 }" :row-key="(a: AdminArticle) => a.id"
      />
    </n-card>
  </div>
</template>

<style scoped>
.head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.head__title { font-size: 24px; font-weight: 700; margin: 0; }
</style>
