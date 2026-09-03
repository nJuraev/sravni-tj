<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  NButton, NInput, NInputNumber, NSelect, NSwitch, NDatePicker, NIcon, NSpace,
  NCard, NForm, NFormItem, NTabs, NTabPane, NModal, NUpload, useMessage,
  type UploadCustomRequestOptions,
} from 'naive-ui'
import { ArrowBackOutline, AddOutline } from '@vicons/ionicons5'
import { adminApi } from '@/api/admin'
import { ApiError } from '@/api/errors'
import ArticleEditor from '@/components/admin/ArticleEditor.vue'
import type {
  AdminArticleCategory, AdminArticleTag, AdminBank, ArticleCategoryPayload, ArticlePayload,
} from '@/types/admin'

const props = defineProps<{ id?: number }>()
const router = useRouter()
const message = useMessage()

const isEdit = computed(() => props.id != null)
const loading = ref(isEdit.value)
const saving = ref(false)
const fieldErrors = reactive<Record<string, string>>({})

const categories = ref<AdminArticleCategory[]>([])
const tags = ref<AdminArticleTag[]>([])
const banks = ref<AdminBank[]>([])
const telegramSentAt = ref<string | null>(null)
const sendingTelegram = ref(false)

interface FormState {
  title_ru: string
  title_tg: string | null
  slug: string
  excerpt_ru: string | null
  excerpt_tg: string | null
  /** ArticleEditor (Tiptap) требует string, не null — конвертация в null на payload при сохранении. */
  body_ru: string
  body_tg: string
  cover_image: string | null
  youtube_url: string | null
  category_id: number | null
  tag_ids: number[]
  status: 'draft' | 'published'
  published_at: number | null
  author_name: string | null
  related_bank_id: number | null
  related_product_id: number | null
}

function emptyForm(): FormState {
  return {
    title_ru: '', title_tg: null, slug: '', excerpt_ru: null, excerpt_tg: null,
    body_ru: '', body_tg: '', cover_image: null, youtube_url: null,
    category_id: null, tag_ids: [], status: 'draft', published_at: null,
    author_name: null, related_bank_id: null, related_product_id: null,
  }
}
const form = reactive<FormState>(emptyForm())
const bodyTab = ref<'ru' | 'tg'>('ru')

// Кириллица → латиница, для авто-подсказки slug (владелец не техспециалист).
const TRANSLIT: Record<string, string> = {
  а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z', и: 'i',
  й: 'y', к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't',
  у: 'u', ф: 'f', х: 'h', ц: 'ts', ч: 'ch', ш: 'sh', щ: 'sch', ъ: '', ы: 'y', ь: '',
  э: 'e', ю: 'yu', я: 'ya',
}
function slugify(text: string): string {
  return text
    .toLowerCase()
    .split('')
    .map((ch) => TRANSLIT[ch] ?? ch)
    .join('')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}
function suggestSlug(): void {
  if (form.title_ru) form.slug = slugify(form.title_ru)
}

function clearErrors() {
  for (const k of Object.keys(fieldErrors)) delete fieldErrors[k]
}

async function load(): Promise<void> {
  const [cats, tagList, bankList] = await Promise.all([
    adminApi.listArticleCategories(),
    adminApi.listArticleTags(),
    adminApi.listBanks(),
  ])
  categories.value = cats.data
  tags.value = tagList.data
  banks.value = bankList.data

  if (props.id != null) {
    const res = await adminApi.getArticle(props.id)
    const a = res.data
    Object.assign(form, {
      title_ru: a.title_ru, title_tg: a.title_tg, slug: a.slug,
      excerpt_ru: a.excerpt_ru, excerpt_tg: a.excerpt_tg,
      body_ru: a.body_ru, body_tg: a.body_tg ?? '',
      cover_image: a.cover_image, youtube_url: a.youtube_url,
      category_id: a.category_id, tag_ids: a.tag_ids ?? [],
      status: a.status, published_at: a.published_at ? new Date(a.published_at).getTime() : null,
      author_name: a.author_name, related_bank_id: a.related_bank_id, related_product_id: a.related_product_id,
    })
    telegramSentAt.value = a.telegram_sent_at
  }
  loading.value = false
}
onMounted(load)

async function sendTelegram(): Promise<void> {
  if (props.id == null) return
  sendingTelegram.value = true
  try {
    const res = await adminApi.sendArticleTelegram(props.id)
    telegramSentAt.value = res.data.telegram_sent_at
    if (res.data.telegram_sent_at) message.success('Отправлено в Telegram-группу')
    else message.warning('Не удалось отправить — проверьте настройку TELEGRAM_ARTICLES_GROUP_ID/бота')
  } catch (e) {
    message.error(e instanceof ApiError ? e.message : 'Не удалось отправить в Telegram')
  } finally {
    sendingTelegram.value = false
  }
}

function formatSentDate(v: string): string {
  return new Date(v).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

/** Пустая строка из формы → null (не сохраняем "переведено", если поле не заполнено). */
function emptyToNull(v: string | null): string | null {
  return v && v.trim() !== '' ? v : null
}

async function save(): Promise<void> {
  saving.value = true
  clearErrors()
  try {
    const payload: ArticlePayload = {
      ...form,
      title_tg: emptyToNull(form.title_tg),
      excerpt_ru: emptyToNull(form.excerpt_ru),
      excerpt_tg: emptyToNull(form.excerpt_tg),
      body_tg: emptyToNull(form.body_tg),
      youtube_url: emptyToNull(form.youtube_url),
      author_name: emptyToNull(form.author_name),
      category_id: form.category_id as number,
      published_at: form.published_at ? new Date(form.published_at).toISOString() : null,
    }
    const res = isEdit.value
      ? await adminApi.updateArticle(props.id as number, payload)
      : await adminApi.createArticle(payload)
    message.success(isEdit.value ? 'Статья обновлена' : 'Статья создана')
    if (!isEdit.value) router.replace({ name: 'admin-article', params: { id: res.data.id } })
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

// Обложка — та же upload-ручка, что и картинки внутри тела (Task 6b).
const coverUploading = ref(false)
function uploadCover({ file, onFinish, onError }: UploadCustomRequestOptions): void {
  if (!file.file) return
  coverUploading.value = true
  adminApi
    .uploadArticleImage(file.file)
    .then((res) => {
      form.cover_image = res.data.url
      onFinish()
    })
    .catch((e) => {
      message.error(e instanceof ApiError ? e.message : 'Не удалось загрузить обложку')
      onError()
    })
    .finally(() => {
      coverUploading.value = false
    })
}

// Категории — создаются на лету (владелец расширяет список сам, без деплоя).
const showCategoryModal = ref(false)
const categorySaving = ref(false)
function emptyCategoryForm(): ArticleCategoryPayload {
  return { name_ru: '', name_tg: null, slug: '', is_active: true }
}
const categoryForm = reactive<ArticleCategoryPayload>(emptyCategoryForm())
async function saveCategory(): Promise<void> {
  categorySaving.value = true
  try {
    const res = await adminApi.createArticleCategory({
      ...categoryForm,
      slug: categoryForm.slug || slugify(categoryForm.name_ru),
    })
    categories.value.push(res.data)
    form.category_id = res.data.id
    showCategoryModal.value = false
    Object.assign(categoryForm, emptyCategoryForm())
    message.success('Категория создана')
  } catch (e) {
    message.error(e instanceof ApiError ? e.message : 'Не удалось создать категорию')
  } finally {
    categorySaving.value = false
  }
}

// Теги — так же создаются на лету прямо из формы статьи.
const newTagName = ref('')
const tagCreating = ref(false)
async function createTag(): Promise<void> {
  const name = newTagName.value.trim()
  if (!name) return
  tagCreating.value = true
  try {
    const res = await adminApi.createArticleTag({ name_ru: name, name_tg: null, slug: slugify(name) })
    tags.value.push(res.data)
    form.tag_ids = [...form.tag_ids, res.data.id]
    newTagName.value = ''
  } catch (e) {
    message.error(e instanceof ApiError ? e.message : 'Не удалось создать тег')
  } finally {
    tagCreating.value = false
  }
}

const statusOptions = [
  { label: 'Черновик', value: 'draft' },
  { label: 'Опубликована', value: 'published' },
]
</script>

<template>
  <div>
    <div class="head">
      <n-button quaternary size="small" @click="router.push({ name: 'admin-articles' })">
        <template #icon><n-icon><ArrowBackOutline /></n-icon></template>
        Статьи
      </n-button>
      <h1 class="head__title">{{ isEdit ? 'Редактировать статью' : 'Новая статья' }}</h1>
      <n-space v-if="isEdit && form.status === 'published'" align="center" style="margin-left: auto">
        <span v-if="telegramSentAt" class="head__telegram-sent">Отправлено в ТГ: {{ formatSentDate(telegramSentAt) }}</span>
        <n-button size="small" :loading="sendingTelegram" @click="sendTelegram">
          {{ telegramSentAt ? 'Отправить повторно' : 'Отправить в ТГ' }}
        </n-button>
      </n-space>
    </div>

    <n-card v-if="!loading" :bordered="false">
      <n-form label-placement="top">
        <div class="grid2">
          <n-form-item label="Заголовок (RU)" :validation-status="fieldErrors.title_ru ? 'error' : undefined" :feedback="fieldErrors.title_ru">
            <n-input v-model:value="form.title_ru" @blur="!form.slug && suggestSlug()" />
          </n-form-item>
          <n-form-item label="Заголовок (TG)">
            <n-input v-model:value="form.title_tg" />
          </n-form-item>
        </div>

        <div class="grid2">
          <n-form-item label="Slug" :validation-status="fieldErrors.slug ? 'error' : undefined" :feedback="fieldErrors.slug">
            <n-input v-model:value="form.slug" placeholder="kak-vybrat-ipoteku">
              <template #suffix>
                <n-button text size="tiny" @click="suggestSlug">из заголовка</n-button>
              </template>
            </n-input>
          </n-form-item>
          <n-form-item label="Категория" :validation-status="fieldErrors.category_id ? 'error' : undefined" :feedback="fieldErrors.category_id">
            <n-space :wrap-item="false" style="width: 100%">
              <n-select
                v-model:value="form.category_id"
                :options="categories.map((c) => ({ label: c.name_ru, value: c.id }))"
                placeholder="Выберите категорию" style="flex: 1"
              />
              <n-button @click="showCategoryModal = true">
                <template #icon><n-icon><AddOutline /></n-icon></template>
              </n-button>
            </n-space>
          </n-form-item>
        </div>

        <div class="grid2">
          <n-form-item label="Анонс (RU)">
            <n-input v-model:value="form.excerpt_ru" type="textarea" :autosize="{ minRows: 2, maxRows: 4 }" placeholder="Короткое превью для карточки и meta description" />
          </n-form-item>
          <n-form-item label="Анонс (TG)">
            <n-input v-model:value="form.excerpt_tg" type="textarea" :autosize="{ minRows: 2, maxRows: 4 }" />
          </n-form-item>
        </div>

        <n-form-item label="Текст статьи" :validation-status="fieldErrors.body_ru ? 'error' : undefined" :feedback="fieldErrors.body_ru">
          <n-tabs v-model:value="bodyTab" type="segment" style="width: 100%">
            <n-tab-pane name="ru" tab="Русский">
              <ArticleEditor v-model="form.body_ru" />
            </n-tab-pane>
            <n-tab-pane name="tg" tab="Тоҷикӣ (необязательно)">
              <ArticleEditor v-model="form.body_tg" />
            </n-tab-pane>
          </n-tabs>
        </n-form-item>

        <div class="grid2">
          <n-form-item label="Обложка">
            <n-space vertical style="width: 100%">
              <img v-if="form.cover_image" :src="form.cover_image" class="cover-preview" alt="" />
              <n-upload :custom-request="uploadCover" :show-file-list="false" accept="image/png,image/jpeg,image/webp">
                <n-button :loading="coverUploading">{{ form.cover_image ? 'Заменить обложку' : 'Загрузить обложку' }}</n-button>
              </n-upload>
            </n-space>
          </n-form-item>
          <n-form-item label="Ссылка на YouTube" :validation-status="fieldErrors.youtube_url ? 'error' : undefined" :feedback="fieldErrors.youtube_url">
            <n-input v-model:value="form.youtube_url" placeholder="https://youtube.com/watch?v=…" />
          </n-form-item>
        </div>

        <n-form-item label="Теги">
          <n-space vertical style="width: 100%">
            <n-select
              v-model:value="form.tag_ids" multiple
              :options="tags.map((t) => ({ label: t.name_ru, value: t.id }))"
              placeholder="Выберите теги"
            />
            <n-space :wrap-item="false">
              <n-input v-model:value="newTagName" placeholder="Новый тег" size="small" style="width: 200px" @keyup.enter="createTag" />
              <n-button size="small" :loading="tagCreating" @click="createTag">Добавить тег</n-button>
            </n-space>
          </n-space>
        </n-form-item>

        <div class="grid3">
          <n-form-item label="Статус">
            <n-select v-model:value="form.status" :options="statusOptions" />
          </n-form-item>
          <n-form-item label="Дата публикации">
            <n-date-picker v-model:value="form.published_at" type="datetime" clearable style="width: 100%" placeholder="сейчас, если пусто" />
          </n-form-item>
          <n-form-item label="Автор">
            <n-input v-model:value="form.author_name" placeholder="Имя автора" />
          </n-form-item>
        </div>

        <div class="grid2">
          <n-form-item label="Связанный банк (необязательно)">
            <n-select
              v-model:value="form.related_bank_id"
              :options="banks.map((b) => ({ label: b.name_ru, value: b.id }))"
              placeholder="—" clearable filterable
            />
          </n-form-item>
          <n-form-item label="ID связанного продукта (необязательно)">
            <n-input-number v-model:value="form.related_product_id" :min="1" clearable style="width: 100%" />
          </n-form-item>
        </div>
      </n-form>

      <n-space justify="end">
        <n-button @click="router.push({ name: 'admin-articles' })">Отмена</n-button>
        <n-button type="primary" :loading="saving" @click="save">Сохранить</n-button>
      </n-space>
    </n-card>

    <n-modal v-model:show="showCategoryModal" preset="card" style="width: 480px" title="Новая категория">
      <n-form label-placement="top">
        <n-form-item label="Название (RU)">
          <n-input v-model:value="categoryForm.name_ru" />
        </n-form-item>
        <n-form-item label="Название (TG)">
          <n-input v-model:value="categoryForm.name_tg" />
        </n-form-item>
        <n-form-item label="Slug (необязательно — сгенерируется из названия)">
          <n-input v-model:value="categoryForm.slug" placeholder="lifehacks" />
        </n-form-item>
        <n-form-item label="Активна">
          <n-switch v-model:value="categoryForm.is_active" />
        </n-form-item>
      </n-form>
      <template #footer>
        <n-space justify="end">
          <n-button @click="showCategoryModal = false">Отмена</n-button>
          <n-button type="primary" :loading="categorySaving" :disabled="!categoryForm.name_ru" @click="saveCategory">Создать</n-button>
        </n-space>
      </template>
    </n-modal>
  </div>
</template>

<style scoped>
.head { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
.head__title { font-size: 22px; font-weight: 700; margin: 0; }
.head__telegram-sent { font-size: 12px; color: #888; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.grid3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
@media (max-width: 700px) { .grid2, .grid3 { grid-template-columns: 1fr; } }
.cover-preview { max-width: 240px; border-radius: 4px; display: block; }
</style>
