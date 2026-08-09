<script setup lang="ts">
import { h, onMounted, reactive, ref } from 'vue'
import {
  NDataTable, NButton, NInput, NCard, NSpace, NModal, NForm, NFormItem, NSwitch,
  NTag, NIcon, NTimeline, NTimelineItem, useMessage, useDialog,
  type DataTableColumns, type PaginationProps,
} from 'naive-ui'
import { AddOutline } from '@vicons/ionicons5'
import { adminApi } from '@/api/admin'
import { ApiError } from '@/api/errors'
import type {
  AdminFinancePost, AdminPostTopic, FinancePostKind, NewsPostPayload, PostTopicPayload, PostTopicPreviewDay,
} from '@/types/admin'

const message = useMessage()
const dialog = useDialog()

const kindLabels: Record<FinancePostKind, string> = {
  generic: 'Тема',
  product: 'Продукт дня',
  currency: 'Курсы валют',
  news: 'По новости',
}
const statusLabels: Record<AdminFinancePost['status'], { text: string; type: 'success' | 'error' | 'warning' }> = {
  sent: { text: 'отправлен', type: 'success' },
  failed: { text: 'ошибка', type: 'error' },
  pending: { text: 'в очереди', type: 'warning' },
}

function fmtDate(iso: string | null) {
  if (!iso) return '—'
  return new Date(iso).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' })
}
function fmtDay(dateStr: string) {
  return new Date(`${dateStr}T00:00:00`).toLocaleDateString('ru-RU', { weekday: 'short', day: 'numeric', month: 'short' })
}

// --- Превью на 7 дней ---
const preview = ref<PostTopicPreviewDay[]>([])
const previewLoading = ref(true)
async function loadPreview() {
  previewLoading.value = true
  try {
    const res = await adminApi.previewPostTopics()
    preview.value = res.data
  } finally {
    previewLoading.value = false
  }
}

// --- Темы ---
const topics = ref<AdminPostTopic[]>([])
const topicsLoading = ref(true)
const showModal = ref(false)
const editing = ref<AdminPostTopic | null>(null)
const saving = ref(false)
const fieldErrors = reactive<Record<string, string>>({})

function emptyForm(): PostTopicPayload {
  return { title: '', prompt: '', is_active: true }
}
const form = reactive<PostTopicPayload>(emptyForm())

async function loadTopics() {
  topicsLoading.value = true
  try {
    const res = await adminApi.listPostTopics()
    topics.value = res.data
  } finally {
    topicsLoading.value = false
  }
}

function clearErrors() {
  for (const k of Object.keys(fieldErrors)) delete fieldErrors[k]
}
function openCreate() {
  editing.value = null
  Object.assign(form, emptyForm())
  clearErrors()
  showModal.value = true
}
function openEdit(t: AdminPostTopic) {
  editing.value = t
  Object.assign(form, { title: t.title, prompt: t.prompt, is_active: t.is_active })
  clearErrors()
  showModal.value = true
}

async function save() {
  saving.value = true
  clearErrors()
  try {
    if (editing.value) await adminApi.updatePostTopic(editing.value.id, { ...form })
    else await adminApi.createPostTopic({ ...form })
    message.success(editing.value ? 'Тема обновлена' : 'Тема добавлена — попадёт в один из ближайших дней')
    showModal.value = false
    await Promise.all([loadTopics(), loadPreview()])
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

function remove(t: AdminPostTopic) {
  dialog.warning({
    title: 'Удалить тему', content: `Удалить тему «${t.title}»?`,
    positiveText: 'Удалить', negativeText: 'Отмена',
    onPositiveClick: async () => {
      try {
        await adminApi.deletePostTopic(t.id)
        message.success('Удалено')
        await Promise.all([loadTopics(), loadPreview()])
      } catch (e) {
        message.error(e instanceof ApiError ? e.message : 'Не удалось удалить')
      }
    },
  })
}

const topicColumns: DataTableColumns<AdminPostTopic> = [
  { title: 'Тема', key: 'title', render: (t) => h('strong', t.title) },
  {
    title: 'Активна', key: 'is_active', width: 100,
    render: (t) => h(NTag, { size: 'small', type: t.is_active ? 'success' : 'default', bordered: false },
      () => (t.is_active ? 'да' : 'нет')),
  },
  { title: 'Последний раз', key: 'last_used_at', width: 160, render: (t) => fmtDate(t.last_used_at) },
  {
    title: '', key: 'actions', width: 160, align: 'right',
    render: (t) => h(NSpace, { justify: 'end', size: 8 }, () => [
      h(NButton, { size: 'small', quaternary: true, onClick: () => openEdit(t) }, () => 'Изм.'),
      h(NButton, { size: 'small', quaternary: true, type: 'error', onClick: () => remove(t) }, () => 'Удалить'),
    ]),
  },
]

// --- Пост по новости (внеплановый, kind=news) ---
const newsModal = ref(false)
const newsSaving = ref(false)
const newsFieldErrors = reactive<Record<string, string>>({})

function emptyNewsForm(): NewsPostPayload {
  return { source_title: '', source_text: '' }
}
const newsForm = reactive<NewsPostPayload>(emptyNewsForm())

function openNewsModal() {
  Object.assign(newsForm, emptyNewsForm())
  for (const k of Object.keys(newsFieldErrors)) delete newsFieldErrors[k]
  newsModal.value = true
}

async function saveNewsPost() {
  newsSaving.value = true
  for (const k of Object.keys(newsFieldErrors)) delete newsFieldErrors[k]
  try {
    await adminApi.createNewsPost({ ...newsForm })
    message.success('Пост сгенерирован и поставлен в очередь на отправку')
    newsModal.value = false
    await loadPosts()
  } catch (e) {
    if (e instanceof ApiError && e.isValidation) {
      for (const [k, v] of Object.entries(e.fieldErrors)) newsFieldErrors[k] = v[0]
      message.error('Проверьте поля формы')
    } else {
      message.error(e instanceof ApiError ? e.message : 'Не удалось сгенерировать пост')
    }
  } finally {
    newsSaving.value = false
  }
}

// --- История постов ---
const posts = ref<AdminFinancePost[]>([])
const postsLoading = ref(true)
const postsPage = ref(1)
const postsPageCount = ref(1)
const postsTotal = ref(0)

async function loadPosts() {
  postsLoading.value = true
  try {
    const res = await adminApi.listFinancePosts({ page: postsPage.value, per_page: 20 })
    posts.value = res.data
    postsPageCount.value = res.meta.last_page
    postsTotal.value = res.meta.total
    postsPage.value = res.meta.current_page
  } finally {
    postsLoading.value = false
  }
}
function onPostsPage(p: number) { postsPage.value = p; loadPosts() }
const postsPagination = (): PaginationProps => ({
  page: postsPage.value, pageCount: postsPageCount.value, pageSize: 20,
  itemCount: postsTotal.value, onUpdatePage: onPostsPage,
})

const postColumns: DataTableColumns<AdminFinancePost> = [
  { title: 'Отправка', key: 'send_at', width: 150, render: (p) => fmtDate(p.send_at) },
  { title: 'Тип', key: 'kind', width: 110, render: (p) => kindLabels[p.kind] },
  { title: 'Тема / продукт', key: 'subject_label', render: (p) => p.subject_label ?? '—' },
  {
    title: 'Статус', key: 'status', width: 110,
    render: (p) => h(NTag, { size: 'small', type: statusLabels[p.status].type, bordered: false },
      () => statusLabels[p.status].text),
  },
  { title: 'Текст', key: 'body', render: (p) => h('span', { class: 'body-preview' }, p.body) },
]

onMounted(() => {
  loadPreview()
  loadTopics()
  loadPosts()
})
</script>

<template>
  <div>
    <div class="head">
      <h1 class="head__title">Финансовые посты</h1>
      <n-button type="primary" @click="openNewsModal">
        <template #icon><n-icon><AddOutline /></n-icon></template>
        Пост по новости
      </n-button>
    </div>

    <n-card title="Расписание на 7 дней" :bordered="false" class="section">
      <n-timeline v-if="!previewLoading" horizontal>
        <n-timeline-item
          v-for="day in preview" :key="day.date"
          :type="day.kind === 'generic' ? 'default' : 'success'"
          :title="fmtDay(day.date)"
          :content="day.kind === 'generic' ? (day.topic_title ?? '—') : kindLabels[day.kind]"
        />
      </n-timeline>
    </n-card>

    <n-card :bordered="false" class="section">
      <template #header>
        <div class="section__header">
          Темы (generic-дни)
          <n-button size="small" type="primary" @click="openCreate">
            <template #icon><n-icon><AddOutline /></n-icon></template>
            Тема
          </n-button>
        </div>
      </template>
      <n-data-table
        :columns="topicColumns" :data="topics" :loading="topicsLoading"
        :pagination="{ pageSize: 15 }" :row-key="(t: AdminPostTopic) => t.id"
      />
    </n-card>

    <n-card title="История постов" :bordered="false" class="section">
      <n-data-table
        remote :columns="postColumns" :data="posts" :loading="postsLoading"
        :pagination="postsPagination()" :row-key="(p: AdminFinancePost) => p.id"
      />
    </n-card>

    <n-modal
      v-model:show="showModal" preset="card" style="width: 560px"
      :title="editing ? 'Редактировать тему' : 'Новая тема'"
    >
      <n-form label-placement="top" @submit.prevent="save">
        <n-space :wrap-item="false" style="gap: 16px" vertical>
          <n-form-item label="Заголовок" :validation-status="fieldErrors.title ? 'error' : undefined" :feedback="fieldErrors.title">
            <n-input v-model:value="form.title" />
          </n-form-item>
          <n-form-item
            label="Промпт для LLM" :validation-status="fieldErrors.prompt ? 'error' : undefined" :feedback="fieldErrors.prompt"
          >
            <n-input v-model:value="form.prompt" type="textarea" :autosize="{ minRows: 3, maxRows: 6 }" />
          </n-form-item>
          <n-form-item label="Активна">
            <n-switch v-model:value="form.is_active" />
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

    <n-modal
      v-model:show="newsModal" preset="card" style="width: 560px"
      title="Пост по новости"
    >
      <n-form label-placement="top" @submit.prevent="saveNewsPost">
        <n-space :wrap-item="false" style="gap: 16px" vertical>
          <n-form-item label="Заголовок источника (необязательно)">
            <n-input v-model:value="newsForm.source_title" placeholder="Например: Нацбанк снизил ставку рефинансирования" />
          </n-form-item>
          <n-form-item
            label="Текст новости"
            :validation-status="newsFieldErrors.source_text ? 'error' : undefined"
            :feedback="newsFieldErrors.source_text"
          >
            <n-input
              v-model:value="newsForm.source_text" type="textarea"
              placeholder="Вставь текст новости — LLM перескажет своими словами, не публикуя дословно"
              :autosize="{ minRows: 6, maxRows: 14 }"
            />
          </n-form-item>
        </n-space>
      </n-form>
      <template #footer>
        <n-space justify="end">
          <n-button @click="newsModal = false">Отмена</n-button>
          <n-button type="primary" :loading="newsSaving" @click="saveNewsPost">Сгенерировать и поставить в очередь</n-button>
        </n-space>
      </template>
    </n-modal>
  </div>
</template>

<style scoped>
.head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.head__title { font-size: 24px; font-weight: 700; margin: 0; }
.section { margin-bottom: 20px; }
.section__header { display: flex; align-items: center; justify-content: space-between; gap: 16px; width: 100%; }
.body-preview {
  display: block;
  max-width: 420px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
