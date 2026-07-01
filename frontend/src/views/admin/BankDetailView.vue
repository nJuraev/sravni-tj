<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { adminApi } from '@/api/admin'
import { ApiError } from '@/api/errors'
import type { AdminBank, AdminProduct, FeatureKey, ProductPayload } from '@/types/admin'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'

const props = defineProps<{ id: number }>()
const router = useRouter()

const bank = ref<AdminBank | null>(null)
const products = ref<AdminProduct[]>([])
const loading = ref(true)
const tab = ref<'products' | 'info'>('products')

const STATUS_LABEL: Record<string, string> = {
  active: 'активен', draft: 'черновик', hidden: 'скрыт', outdated: 'устарел',
}
const STATUS_BADGE: Record<string, string> = {
  active: 'adm-badge--green', draft: 'adm-badge--amber', hidden: 'adm-badge--gray', outdated: 'adm-badge--red',
}
const FEATURE_KEYS: FeatureKey[] = ['online_application', 'no_guarantor', 'capitalization', 'replenishable']
const FEATURE_LABEL: Record<FeatureKey, string> = {
  online_application: 'Онлайн-заявка',
  no_guarantor: 'Без поручителя',
  capitalization: 'Капитализация',
  replenishable: 'Пополняемый',
}

const modalOpen = ref(false)
const editing = ref<AdminProduct | null>(null)
const saving = ref(false)
const formError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

function emptyForm(): ProductPayload {
  return {
    bank_id: props.id,
    category: 'credit',
    subcategory: null,
    is_special: false,
    status: 'draft',
    currency: 'TJS',
    name_ru: '',
    name_tg: '',
    description_ru: '',
    description_tg: '',
    rate_min: 0,
    rate_max: 0,
    amount_min: null,
    amount_max: null,
    term_min: null,
    term_max: null,
    features: {},
  }
}
const form = ref<ProductPayload>(emptyForm())

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

function openCreate() {
  editing.value = null
  form.value = emptyForm()
  fieldErrors.value = {}
  formError.value = ''
  modalOpen.value = true
}
function openEdit(p: AdminProduct) {
  editing.value = p
  form.value = {
    bank_id: props.id,
    category: p.category,
    subcategory: p.subcategory,
    is_special: p.is_special,
    status: p.status,
    currency: p.currency,
    name_ru: p.name_ru ?? '',
    name_tg: p.name_tg ?? '',
    description_ru: p.description_ru ?? '',
    description_tg: p.description_tg ?? '',
    rate_min: p.rate_min ?? 0,
    rate_max: p.rate_max ?? 0,
    amount_min: p.amount_min,
    amount_max: p.amount_max,
    term_min: p.term_min,
    term_max: p.term_max,
    features: { ...p.features },
  }
  fieldErrors.value = {}
  formError.value = ''
  modalOpen.value = true
}

async function save() {
  saving.value = true
  formError.value = ''
  fieldErrors.value = {}
  try {
    if (editing.value) await adminApi.updateProduct(editing.value.id, form.value)
    else await adminApi.createProduct(form.value)
    modalOpen.value = false
    await load()
  } catch (e) {
    if (e instanceof ApiError && e.isValidation) {
      fieldErrors.value = e.fieldErrors
      formError.value = Object.values(e.fieldErrors)[0]?.[0] ?? 'Проверьте поля формы.'
    } else if (e instanceof ApiError) formError.value = e.message
    else formError.value = 'Ошибка сохранения.'
  } finally {
    saving.value = false
  }
}

async function toggle(p: AdminProduct) {
  try {
    const res = await adminApi.toggleProduct(p.id)
    const idx = products.value.findIndex((x) => x.id === p.id)
    if (idx >= 0) products.value[idx] = res.data
  } catch (e) {
    alert(e instanceof ApiError ? e.message : 'Не удалось переключить.')
  }
}

async function remove(p: AdminProduct) {
  if (!confirm(`Удалить продукт «${p.name_ru ?? p.name_tg}»?`)) return
  try {
    await adminApi.deleteProduct(p.id)
    await load()
  } catch (e) {
    alert(e instanceof ApiError ? e.message : 'Не удалось удалить.')
  }
}

function err(field: string): string {
  return fieldErrors.value[field]?.[0] ?? ''
}
</script>

<template>
  <div>
    <div class="adm__head">
      <div>
        <BaseButton variant="ghost" size="sm" @click="router.push({ name: 'admin-banks' })">← Банки</BaseButton>
        <h1 class="adm__title">{{ bank?.name_ru ?? '…' }}</h1>
      </div>
      <BaseButton v-if="tab === 'products'" @click="openCreate">+ Продукт</BaseButton>
    </div>

    <div class="adm-tabs">
      <button class="adm-tab" :class="{ 'adm-tab--active': tab === 'products' }" @click="tab = 'products'">
        Продукты ({{ products.length }})
      </button>
      <button class="adm-tab" :class="{ 'adm-tab--active': tab === 'info' }" @click="tab = 'info'">
        Информация
      </button>
    </div>

    <!-- Products tab -->
    <div v-if="tab === 'products'" class="adm-card">
      <div v-if="loading" class="adm-empty">Загрузка…</div>
      <div v-else-if="!products.length" class="adm-empty">У банка нет продуктов</div>
      <table v-else class="adm-table">
        <thead>
          <tr>
            <th>Название</th>
            <th>Категория</th>
            <th>Валюта</th>
            <th>Ставка</th>
            <th>Статус</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in products" :key="p.id">
            <td>
              <strong>{{ p.name_ru ?? p.name_tg }}</strong>
              <span v-if="p.is_special" class="adm-badge adm-badge--blue" style="margin-left: 6px">спец</span>
              <span
                v-if="p.locked_fields?.length"
                class="adm-badge adm-badge--amber"
                style="margin-left: 6px"
                title="Категория и метки закреплены — парсер их не перезапишет"
              >закреплено</span>
            </td>
            <td>{{ p.category }}</td>
            <td>{{ p.currency }}</td>
            <td>{{ p.rate_min }}–{{ p.rate_max }}%</td>
            <td>
              <span class="adm-badge" :class="STATUS_BADGE[p.status]">{{ STATUS_LABEL[p.status] }}</span>
            </td>
            <td>
              <div class="adm-table__actions">
                <BaseButton
                  size="sm"
                  :variant="p.status === 'active' ? 'secondary' : 'primary'"
                  @click="toggle(p)"
                >
                  {{ p.status === 'active' ? 'Откл.' : 'Вкл.' }}
                </BaseButton>
                <BaseButton size="sm" variant="ghost" @click="openEdit(p)">Изм.</BaseButton>
                <BaseButton size="sm" variant="danger" @click="remove(p)">Удалить</BaseButton>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Info tab -->
    <div v-else class="adm-card" style="padding: var(--space-5)">
      <dl class="info">
        <div><dt>Slug</dt><dd><code>{{ bank?.slug }}</code></dd></div>
        <div><dt>Статус</dt><dd>{{ bank?.status }}</dd></div>
        <div><dt>Партнёр</dt><dd>{{ bank?.is_partner ? 'да' : 'нет' }}</dd></div>
        <div><dt>Email</dt><dd>{{ bank?.contact_email ?? '—' }}</dd></div>
        <div><dt>Сайт</dt><dd>{{ bank?.website ?? '—' }}</dd></div>
        <div><dt>Телефон</dt><dd>{{ bank?.phone ?? '—' }}</dd></div>
        <div><dt>Адрес</dt><dd>{{ bank?.address_ru ?? '—' }}</dd></div>
        <div><dt>Заявок</dt><dd>{{ bank?.leads_count ?? 0 }}</dd></div>
      </dl>
      <div style="margin-top: var(--space-4)">
        <BaseButton variant="secondary" @click="router.push({ name: 'admin-banks' })">
          Редактировать в списке банков
        </BaseButton>
      </div>
    </div>

    <!-- Product modal -->
    <BaseModal :open="modalOpen" :title="editing ? 'Редактировать продукт' : 'Новый продукт'" @close="modalOpen = false">
      <form class="adm-form" @submit.prevent="save">
        <div class="adm-grid-2">
          <div class="adm-field">
            <label class="adm-field__label">Название (RU)</label>
            <input v-model="form.name_ru" class="adm-input" :class="{ 'adm-input--error': err('name_ru') }" />
            <span v-if="err('name_ru')" class="adm-field__error">{{ err('name_ru') }}</span>
          </div>
          <div class="adm-field">
            <label class="adm-field__label">Название (TG)</label>
            <input v-model="form.name_tg" class="adm-input" />
          </div>
        </div>

        <div class="adm-grid-2">
          <div class="adm-field">
            <label class="adm-field__label">Категория</label>
            <select v-model="form.category" class="adm-select">
              <option value="credit">Кредит</option>
              <option value="deposit">Депозит</option>
              <option value="installment">Рассрочка</option>
            </select>
          </div>
          <div class="adm-field">
            <label class="adm-field__label">Валюта</label>
            <select v-model="form.currency" class="adm-select">
              <option value="TJS">TJS</option>
              <option value="USD">USD</option>
              <option value="EUR">EUR</option>
            </select>
          </div>
        </div>

        <div class="adm-grid-2">
          <div class="adm-field">
            <label class="adm-field__label">Ставка мин, %</label>
            <input v-model.number="form.rate_min" class="adm-input" type="number" step="0.001" :class="{ 'adm-input--error': err('rate_min') }" />
            <span v-if="err('rate_min')" class="adm-field__error">{{ err('rate_min') }}</span>
          </div>
          <div class="adm-field">
            <label class="adm-field__label">Ставка макс, %</label>
            <input v-model.number="form.rate_max" class="adm-input" type="number" step="0.001" :class="{ 'adm-input--error': err('rate_max') }" />
            <span v-if="err('rate_max')" class="adm-field__error">{{ err('rate_max') }}</span>
          </div>
        </div>

        <div class="adm-grid-2">
          <div class="adm-field">
            <label class="adm-field__label">Сумма мин</label>
            <input v-model.number="form.amount_min" class="adm-input" type="number" />
          </div>
          <div class="adm-field">
            <label class="adm-field__label">Сумма макс</label>
            <input v-model.number="form.amount_max" class="adm-input" type="number" />
          </div>
        </div>

        <div class="adm-grid-2">
          <div class="adm-field">
            <label class="adm-field__label">Срок мин, мес</label>
            <input v-model.number="form.term_min" class="adm-input" type="number" />
          </div>
          <div class="adm-field">
            <label class="adm-field__label">Срок макс, мес</label>
            <input v-model.number="form.term_max" class="adm-input" type="number" />
          </div>
        </div>

        <div class="adm-grid-2">
          <div class="adm-field">
            <label class="adm-field__label">Статус</label>
            <select v-model="form.status" class="adm-select">
              <option value="draft">Черновик</option>
              <option value="active">Активен</option>
              <option value="hidden">Скрыт</option>
              <option value="outdated">Устарел</option>
            </select>
          </div>
          <div class="adm-field">
            <label class="adm-field__label">Подкатегория</label>
            <input v-model="form.subcategory" class="adm-input" placeholder="consumer, mortgage…" />
          </div>
        </div>

        <div class="adm-field">
          <label class="adm-field__label">Описание (RU)</label>
          <textarea v-model="form.description_ru" class="adm-textarea" />
        </div>

        <div class="adm-field">
          <label class="adm-field__label">Особенности</label>
          <div style="display: flex; flex-wrap: wrap; gap: var(--space-4)">
            <label v-for="k in FEATURE_KEYS" :key="k" class="adm-checkbox">
              <input v-model="form.features[k]" type="checkbox" /> {{ FEATURE_LABEL[k] }}
            </label>
          </div>
        </div>

        <label class="adm-checkbox">
          <input v-model="form.is_special" type="checkbox" /> Специальный продукт
        </label>

        <p v-if="formError" class="adm-alert">{{ formError }}</p>

        <div class="adm-form__actions">
          <BaseButton variant="ghost" type="button" @click="modalOpen = false">Отмена</BaseButton>
          <BaseButton type="submit" :loading="saving">Сохранить</BaseButton>
        </div>
      </form>
    </BaseModal>
  </div>
</template>

<style scoped>
.info {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-4);
}
.info div {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.info dt {
  font-size: var(--fs-xs);
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--color-text-muted);
}
.info dd {
  color: var(--color-text-primary);
  font-size: var(--fs-sm);
}
@media (max-width: 560px) {
  .info { grid-template-columns: 1fr; }
}
</style>
