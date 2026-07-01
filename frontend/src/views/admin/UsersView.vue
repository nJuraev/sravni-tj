<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { adminApi } from '@/api/admin'
import { ApiError } from '@/api/errors'
import { useAdminStore } from '@/stores/admin'
import type { AdminUser, UserPayload } from '@/types/admin'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'

const admin = useAdminStore()

const users = ref<AdminUser[]>([])
const loading = ref(true)

const modalOpen = ref(false)
const editing = ref<AdminUser | null>(null)
const saving = ref(false)
const formError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

function emptyForm(): UserPayload {
  return { name: '', email: '', password: '', role: 'editor', is_active: true }
}
const form = ref<UserPayload>(emptyForm())

async function load() {
  loading.value = true
  try {
    const res = await adminApi.listUsers()
    users.value = res.data
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
function openEdit(u: AdminUser) {
  editing.value = u
  form.value = { name: u.name, email: u.email, password: '', role: u.role, is_active: u.is_active }
  fieldErrors.value = {}
  formError.value = ''
  modalOpen.value = true
}

async function save() {
  saving.value = true
  formError.value = ''
  fieldErrors.value = {}
  try {
    if (editing.value) await adminApi.updateUser(editing.value.id, form.value)
    else await adminApi.createUser(form.value)
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

async function remove(u: AdminUser) {
  if (!confirm(`Удалить пользователя «${u.name}»?`)) return
  try {
    await adminApi.deleteUser(u.id)
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
      <h1 class="adm__title">Пользователи</h1>
      <BaseButton @click="openCreate">+ Пользователь</BaseButton>
    </div>

    <div class="adm-card">
      <div v-if="loading" class="adm-empty">Загрузка…</div>
      <table v-else class="adm-table">
        <thead>
          <tr>
            <th>Имя</th>
            <th>Email</th>
            <th>Роль</th>
            <th>Статус</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in users" :key="u.id">
            <td>
              <strong>{{ u.name }}</strong>
              <span v-if="u.id === admin.user?.id" class="adm-badge adm-badge--blue" style="margin-left: 6px">вы</span>
            </td>
            <td>{{ u.email }}</td>
            <td>{{ u.role === 'admin' ? 'Администратор' : 'Редактор' }}</td>
            <td>
              <span class="adm-badge" :class="u.is_active ? 'adm-badge--green' : 'adm-badge--gray'">
                {{ u.is_active ? 'активен' : 'выключен' }}
              </span>
            </td>
            <td>
              <div class="adm-table__actions">
                <BaseButton size="sm" variant="ghost" @click="openEdit(u)">Изм.</BaseButton>
                <BaseButton
                  v-if="u.id !== admin.user?.id"
                  size="sm"
                  variant="danger"
                  @click="remove(u)"
                >
                  Удалить
                </BaseButton>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <BaseModal :open="modalOpen" :title="editing ? 'Редактировать пользователя' : 'Новый пользователь'" @close="modalOpen = false">
      <form class="adm-form" @submit.prevent="save">
        <div class="adm-field">
          <label class="adm-field__label">Имя</label>
          <input v-model="form.name" class="adm-input" :class="{ 'adm-input--error': err('name') }" />
          <span v-if="err('name')" class="adm-field__error">{{ err('name') }}</span>
        </div>
        <div class="adm-field">
          <label class="adm-field__label">Email</label>
          <input v-model="form.email" class="adm-input" type="email" :class="{ 'adm-input--error': err('email') }" />
          <span v-if="err('email')" class="adm-field__error">{{ err('email') }}</span>
        </div>
        <div class="adm-field">
          <label class="adm-field__label">
            Пароль <span v-if="editing" style="color: var(--color-text-muted); font-weight: 400">(пусто = не менять)</span>
          </label>
          <input v-model="form.password" class="adm-input" type="password" autocomplete="new-password" :class="{ 'adm-input--error': err('password') }" />
          <span v-if="err('password')" class="adm-field__error">{{ err('password') }}</span>
        </div>
        <div class="adm-grid-2">
          <div class="adm-field">
            <label class="adm-field__label">Роль</label>
            <select v-model="form.role" class="adm-select">
              <option value="editor">Редактор</option>
              <option value="admin">Администратор</option>
            </select>
            <span v-if="err('role')" class="adm-field__error">{{ err('role') }}</span>
          </div>
          <div class="adm-field" style="justify-content: flex-end">
            <label class="adm-checkbox">
              <input v-model="form.is_active" type="checkbox" /> Активен
            </label>
          </div>
        </div>

        <p v-if="formError" class="adm-alert">{{ formError }}</p>

        <div class="adm-form__actions">
          <BaseButton variant="ghost" type="button" @click="modalOpen = false">Отмена</BaseButton>
          <BaseButton type="submit" :loading="saving">Сохранить</BaseButton>
        </div>
      </form>
    </BaseModal>
  </div>
</template>
