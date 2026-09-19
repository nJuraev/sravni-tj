<script setup lang="ts">
import { computed, h, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  NDataTable, NButton, NInput, NInputNumber, NSelect, NTag, NSpace, NModal, NCard,
  NForm, NFormItem, NSwitch, NTabs, NTabPane, NIcon, NTooltip,
  NDescriptions, NDescriptionsItem, useMessage, useDialog, type DataTableColumns,
} from 'naive-ui'
import {
  AddOutline, ArrowBackOutline, PowerOutline, CreateOutline, TrashOutline, OpenOutline,
} from '@vicons/ionicons5'
import { adminApi } from '@/api/admin'
import { ApiError } from '@/api/errors'
import { pipelineFreshness } from '@/lib/format'
import type { AdminBank, AdminProduct, BankPayload } from '@/types/admin'

const props = defineProps<{ id: number }>()
const router = useRouter()
const message = useMessage()
const dialog = useDialog()

const bank = ref<AdminBank | null>(null)
const products = ref<AdminProduct[]>([])
const loading = ref(true)
const tab = ref<'products' | 'info'>('products')

function formatDate(v: string | null | undefined): string {
  if (!v) return '—'
  return new Date(v).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const STATUS_META: Record<string, { label: string; type: 'success' | 'warning' | 'default' | 'error' }> = {
  active: { label: 'активен', type: 'success' }, draft: { label: 'черновик', type: 'warning' },
  hidden: { label: 'скрыт', type: 'default' }, outdated: { label: 'устарел', type: 'error' },
}

const showBankModal = ref(false)
const bankSaving = ref(false)
const bankFieldErrors = reactive<Record<string, string>>({})
const bankStatusOptions = [
  { label: 'Активен', value: 'active' },
  { label: 'Выключен', value: 'inactive' },
]
function emptyBankForm(): BankPayload {
  return {
    name_ru: '', name_tg: '', slug: '', status: 'active', is_partner: false, sort_coefficient: 0,
    contact_email: '', website: '', phone: '', address_ru: '', address_tg: '',
    about_ru: '', about_tg: '', logo_url: '',
  }
}
const bankForm = reactive<BankPayload>(emptyBankForm())

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

function clearBankErrors() { for (const k of Object.keys(bankFieldErrors)) delete bankFieldErrors[k] }

function openBankEdit() {
  if (!bank.value) return
  const b = bank.value
  Object.assign(bankForm, {
    name_ru: b.name_ru, name_tg: b.name_tg ?? '', slug: b.slug, status: b.status,
    is_partner: b.is_partner, sort_coefficient: b.sort_coefficient, contact_email: b.contact_email ?? '', website: b.website ?? '',
    phone: b.phone ?? '', address_ru: b.address_ru ?? '', address_tg: b.address_tg ?? '',
    about_ru: b.about_ru ?? '', about_tg: b.about_tg ?? '', logo_url: b.logo_url ?? '',
  })
  clearBankErrors()
  showBankModal.value = true
}

async function saveBank() {
  if (!bank.value) return
  bankSaving.value = true
  clearBankErrors()
  try {
    const res = await adminApi.updateBank(bank.value.id, { ...bankForm })
    bank.value = res.data
    message.success('Банк обновлён')
    showBankModal.value = false
  } catch (e) {
    if (e instanceof ApiError && e.isValidation) {
      for (const [k, v] of Object.entries(e.fieldErrors)) bankFieldErrors[k] = v[0]
      message.error('Проверьте поля формы')
    } else {
      message.error(e instanceof ApiError ? e.message : 'Ошибка сохранения')
    }
  } finally {
    bankSaving.value = false
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

// Группа валют одного продукта — тот же ключ, что и в публичном API
// (ProductController::dedupeToGroupRepresentatives): source_url_id, либо
// сам продукт как единственный представитель своей группы.
const CURRENCY_ORDER: Record<string, number> = { TJS: 0, USD: 1, EUR: 2 }
function groupKeyOf(p: AdminProduct): string {
  return p.source_url_id !== null ? `u:${p.source_url_id}` : `s:${p.id}`
}

type RowWithSpan = AdminProduct & { _rowSpan: number }

// Перегруппировываем список так, чтобы строки одной группы шли подряд
// (rowSpan у naive-ui схлопывает только соседние строки), не теряя общий
// порядок, заданный бэкендом (категория → название).
const groupedProducts = computed<RowWithSpan[]>(() => {
  const list = products.value
  const firstIndex = new Map<string, number>()
  list.forEach((p, i) => {
    const key = groupKeyOf(p)
    if (!firstIndex.has(key)) firstIndex.set(key, i)
  })
  const sorted = [...list].sort((a, b) => {
    const ka = groupKeyOf(a)
    const kb = groupKeyOf(b)
    if (ka !== kb) return firstIndex.get(ka)! - firstIndex.get(kb)!
    return (CURRENCY_ORDER[a.currency] ?? 99) - (CURRENCY_ORDER[b.currency] ?? 99)
  })
  const spans = new Map<number, number>()
  let i = 0
  while (i < sorted.length) {
    const key = groupKeyOf(sorted[i])
    let j = i
    while (j < sorted.length && groupKeyOf(sorted[j]) === key) j++
    spans.set(sorted[i].id, j - i)
    i = j
  }
  return sorted.map((p) => ({ ...p, _rowSpan: spans.get(p.id) ?? 1 }))
})

const columns: DataTableColumns<RowWithSpan> = [
  {
    title: 'Название', key: 'name',
    rowSpan: (p) => p._rowSpan,
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
    title: 'Обновлён', key: 'parsed_at', width: 130,
    render: (p) => formatDate(p.parsed_at ?? p.updated_at),
  },
  {
    title: 'Статус', key: 'status', width: 110,
    render: (p) => h(NTag, { size: 'small', type: STATUS_META[p.status]?.type ?? 'default', bordered: false },
      () => STATUS_META[p.status]?.label ?? p.status),
  },
  {
    title: '', key: 'actions', width: 160, align: 'right',
    render: (p) => h(NSpace, { justify: 'end', size: 4 }, () => [
      p.source_url
        ? iconLink('Оригинал на сайте банка', OpenOutline, p.source_url)
        : null,
      iconButton(p.status === 'active' ? 'Отключить' : 'Включить', PowerOutline, p.status === 'active' ? 'default' : 'primary', () => toggle(p)),
      iconButton('Изменить', CreateOutline, 'default', () => router.push({ name: 'admin-product', params: { id: p.id } })),
      iconButton('Удалить', TrashOutline, 'error', () => remove(p)),
    ]),
  },
]

function iconButton(tooltip: string, icon: typeof PowerOutline, type: 'default' | 'primary' | 'error', onClick: () => void) {
  return h(NTooltip, null, {
    trigger: () => h(NButton, { size: 'small', quaternary: true, circle: true, type, onClick },
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
      <div>
        <n-button quaternary size="small" @click="router.push({ name: 'admin-banks' })">
          <template #icon><n-icon><ArrowBackOutline /></n-icon></template>
          Банки
        </n-button>
        <h1 class="head__title">{{ bank?.name_ru ?? '…' }}</h1>
      </div>
      <n-button
        v-if="tab === 'products'" type="primary"
        @click="router.push({ name: 'admin-product-new', query: { bankId: props.id } })"
      >
        <template #icon><n-icon><AddOutline /></n-icon></template>
        Продукт
      </n-button>
      <n-button v-else-if="tab === 'info'" type="primary" @click="openBankEdit">
        Редактировать
      </n-button>
    </div>

    <n-tabs v-model:value="tab" type="line" animated>
      <n-tab-pane name="products" :tab="`Продукты (${products.length})`">
        <n-card :bordered="false">
          <n-data-table :columns="columns" :data="groupedProducts" :loading="loading" :row-key="(p: AdminProduct) => p.id" />
        </n-card>
      </n-tab-pane>

      <n-tab-pane name="info" tab="Информация">
        <n-card :bordered="false">
          <n-descriptions label-placement="left" :column="2" bordered>
            <n-descriptions-item label="Slug"><code>{{ bank?.slug }}</code></n-descriptions-item>
            <n-descriptions-item label="Статус">{{ bank?.status }}</n-descriptions-item>
            <n-descriptions-item label="Партнёр">{{ bank?.is_partner ? 'да' : 'нет' }}</n-descriptions-item>
            <n-descriptions-item label="Коэф. сортировки">{{ bank?.sort_coefficient }}</n-descriptions-item>
            <n-descriptions-item label="Email">{{ bank?.contact_email ?? '—' }}</n-descriptions-item>
            <n-descriptions-item label="Сайт">
              <a v-if="bank?.website" :href="bank.website" target="_blank" rel="noopener noreferrer">{{ bank.website }}</a>
              <template v-else>—</template>
            </n-descriptions-item>
            <n-descriptions-item label="Телефон">{{ bank?.phone ?? '—' }}</n-descriptions-item>
            <n-descriptions-item label="Адрес" :span="2">{{ bank?.address_ru ?? '—' }}</n-descriptions-item>
            <n-descriptions-item label="Заявок">{{ bank?.leads_count ?? 0 }}</n-descriptions-item>
            <n-descriptions-item label="Продукты обновлены">
              <n-space align="center" :size="8">
                <span>{{ formatDate(bank?.products_updated_at) }}</span>
                <n-tag size="small" :type="pipelineFreshness(bank?.products_updated_at ?? null, 3).type" :bordered="false">
                  {{ pipelineFreshness(bank?.products_updated_at ?? null, 3).label }}
                </n-tag>
              </n-space>
            </n-descriptions-item>
            <n-descriptions-item label="Курсы обновлены">
              <n-space align="center" :size="8">
                <span>{{ formatDate(bank?.rates_updated_at) }}</span>
                <n-tag size="small" :type="pipelineFreshness(bank?.rates_updated_at ?? null, 1).type" :bordered="false">
                  {{ pipelineFreshness(bank?.rates_updated_at ?? null, 1).label }}
                </n-tag>
              </n-space>
            </n-descriptions-item>
          </n-descriptions>
        </n-card>
      </n-tab-pane>
    </n-tabs>

    <n-modal
      v-model:show="showBankModal" preset="card" style="width: 640px"
      title="Редактировать банк"
    >
      <n-form label-placement="top" @submit.prevent="saveBank">
        <n-space :wrap-item="false" style="gap: 16px" vertical>
          <div class="grid2">
            <n-form-item label="Название (RU)" :validation-status="bankFieldErrors.name_ru ? 'error' : undefined" :feedback="bankFieldErrors.name_ru">
              <n-input v-model:value="bankForm.name_ru" />
            </n-form-item>
            <n-form-item label="Название (TG)">
              <n-input v-model:value="bankForm.name_tg" />
            </n-form-item>
          </div>
          <div class="grid2">
            <n-form-item label="Slug" :validation-status="bankFieldErrors.slug ? 'error' : undefined" :feedback="bankFieldErrors.slug">
              <n-input v-model:value="bankForm.slug" placeholder="eskhata" />
            </n-form-item>
            <n-form-item label="Статус">
              <n-select v-model:value="bankForm.status" :options="bankStatusOptions" />
            </n-form-item>
          </div>
          <div class="grid2">
            <n-form-item label="Email (справочный)" :validation-status="bankFieldErrors.contact_email ? 'error' : undefined" :feedback="bankFieldErrors.contact_email">
              <n-input v-model:value="bankForm.contact_email" />
            </n-form-item>
            <n-form-item label="Сайт">
              <n-input v-model:value="bankForm.website" />
            </n-form-item>
          </div>
          <div class="grid2">
            <n-form-item label="Телефон">
              <n-input v-model:value="bankForm.phone" />
            </n-form-item>
            <n-form-item label="Логотип (URL)">
              <n-input v-model:value="bankForm.logo_url" />
            </n-form-item>
          </div>
          <n-form-item label="Адрес (RU)">
            <n-input v-model:value="bankForm.address_ru" />
          </n-form-item>
          <div class="grid2">
            <n-form-item label="О банке (RU)">
              <n-input v-model:value="bankForm.about_ru" type="textarea" :autosize="{ minRows: 2, maxRows: 5 }" />
            </n-form-item>
            <n-form-item label="О банке (TG)">
              <n-input v-model:value="bankForm.about_tg" type="textarea" :autosize="{ minRows: 2, maxRows: 5 }" />
            </n-form-item>
          </div>
          <div class="grid2">
            <n-form-item label="Партнёр">
              <n-switch v-model:value="bankForm.is_partner" />
            </n-form-item>
            <n-form-item label="Коэф. сортировки" :validation-status="bankFieldErrors.sort_coefficient ? 'error' : undefined" :feedback="bankFieldErrors.sort_coefficient ?? 'Дефолтная сортировка каталога: больше — выше в выдаче'">
              <n-input-number v-model:value="bankForm.sort_coefficient" style="width: 100%" />
            </n-form-item>
          </div>
        </n-space>
      </n-form>
      <template #footer>
        <n-space justify="end">
          <n-button @click="showBankModal = false">Отмена</n-button>
          <n-button type="primary" :loading="bankSaving" @click="saveBank">Сохранить</n-button>
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
