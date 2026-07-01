<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { adminApi } from '@/api/admin'
import { ApiError } from '@/api/errors'
import type { AdminBank, BankPayload } from '@/types/admin'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'

const router = useRouter()

const banks = ref<AdminBank[]>([])
const loading = ref(true)
const search = ref('')

const modalOpen = ref(false)
const editing = ref<AdminBank | null>(null)
const saving = ref(false)
const formError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

function emptyForm(): BankPayload {
  return {
    name_ru: '',
    name_tg: '',
    slug: '',
    status: 'active',
    is_partner: false,
    contact_email: '',
    website: '',
    phone: '',
    address_ru: '',
    address_tg: '',
    logo_url: '',
  }
}
const form = ref<BankPayload>(emptyForm())

async function load() {
  loading.value = true
  try {
    const res = await adminApi.listBanks({ search: search.value })
    banks.value = res.data
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
function openEdit(bank: AdminBank) {
  editing.value = bank
  form.value = {
    name_ru: bank.name_ru,
    name_tg: bank.name_tg ?? '',
    slug: bank.slug,
    status: bank.status,
    is_partner: bank.is_partner,
    contact_email: bank.contact_email ?? '',
    website: bank.website ?? '',
    phone: bank.phone ?? '',
    address_ru: bank.address_ru ?? '',
    address_tg: bank.address_tg ?? '',
    logo_url: bank.logo_url ?? '',
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
    if (editing.value) await adminApi.updateBank(editing.value.id, form.value)
    else await adminApi.createBank(form.value)
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

async function remove(bank: AdminBank) {
  if (!confirm(`Удалить банк «${bank.name_ru}»? Будут удалены и его продукты.`)) return
  try {
    await adminApi.deleteBank(bank.id)
    await load()
  } catch (e) {
    alert(e instanceof ApiError ? e.message : 'Не удалось удалить (возможно, есть заявки).')
  }
}

function err(field: string): string {
  return fieldErrors.value[field]?.[0] ?? ''
}
</script>

<template>
  <div>
    <div class="adm__head">
      <h1 class="adm__title">Банки</h1>
      <div class="adm__toolbar">
        <input
          v-model="search"
          class="adm-input"
          type="search"
          placeholder="Поиск…"
          style="width: 200px"
          @keyup.enter="load"
        />
        <BaseButton variant="secondary" @click="load">Найти</BaseButton>
        <BaseButton @click="openCreate">+ Банк</BaseButton>
      </div>
    </div>

    <div class="adm-card">
      <div v-if="loading" class="adm-empty">Загрузка…</div>
      <div v-else-if="!banks.length" class="adm-empty">Банков нет</div>
      <table v-else class="adm-table">
        <thead>
          <tr>
            <th>Название</th>
            <th>Slug</th>
            <th>Статус</th>
            <th>Продукты</th>
            <th>Заявки</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="bank in banks" :key="bank.id">
            <td>
              <strong>{{ bank.name_ru }}</strong>
              <span v-if="bank.is_partner" class="adm-badge adm-badge--blue" style="margin-left: 6px">партнёр</span>
            </td>
            <td><code>{{ bank.slug }}</code></td>
            <td>
              <span class="adm-badge" :class="bank.status === 'active' ? 'adm-badge--green' : 'adm-badge--gray'">
                {{ bank.status === 'active' ? 'активен' : 'выключен' }}
              </span>
            </td>
            <td>{{ bank.products_count ?? 0 }}</td>
            <td>{{ bank.leads_count ?? 0 }}</td>
            <td>
              <div class="adm-table__actions">
                <BaseButton size="sm" variant="secondary" @click="router.push({ name: 'admin-bank', params: { id: bank.id } })">
                  Продукты
                </BaseButton>
                <BaseButton size="sm" variant="ghost" @click="openEdit(bank)">Изм.</BaseButton>
                <BaseButton size="sm" variant="danger" @click="remove(bank)">Удалить</BaseButton>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <BaseModal :open="modalOpen" :title="editing ? 'Редактировать банк' : 'Новый банк'" @close="modalOpen = false">
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
            <label class="adm-field__label">Slug</label>
            <input v-model="form.slug" class="adm-input" :class="{ 'adm-input--error': err('slug') }" placeholder="eskhata" />
            <span v-if="err('slug')" class="adm-field__error">{{ err('slug') }}</span>
          </div>
          <div class="adm-field">
            <label class="adm-field__label">Статус</label>
            <select v-model="form.status" class="adm-select">
              <option value="active">Активен</option>
              <option value="inactive">Выключен</option>
            </select>
          </div>
        </div>

        <div class="adm-grid-2">
          <div class="adm-field">
            <label class="adm-field__label">Email (справочный)</label>
            <input v-model="form.contact_email" class="adm-input" :class="{ 'adm-input--error': err('contact_email') }" />
            <span v-if="err('contact_email')" class="adm-field__error">{{ err('contact_email') }}</span>
          </div>
          <div class="adm-field">
            <label class="adm-field__label">Сайт</label>
            <input v-model="form.website" class="adm-input" />
          </div>
        </div>

        <div class="adm-grid-2">
          <div class="adm-field">
            <label class="adm-field__label">Телефон</label>
            <input v-model="form.phone" class="adm-input" />
          </div>
          <div class="adm-field">
            <label class="adm-field__label">Логотип (URL)</label>
            <input v-model="form.logo_url" class="adm-input" />
          </div>
        </div>

        <div class="adm-field">
          <label class="adm-field__label">Адрес (RU)</label>
          <input v-model="form.address_ru" class="adm-input" />
        </div>

        <label class="adm-checkbox">
          <input v-model="form.is_partner" type="checkbox" /> Партнёр
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
