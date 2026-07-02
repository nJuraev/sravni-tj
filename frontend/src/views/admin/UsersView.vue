<script setup lang="ts">
import { h, onMounted, reactive, ref } from 'vue'
import {
  NDataTable, NButton, NInput, NSelect, NTag, NSpace, NModal, NCard, NForm,
  NFormItem, NSwitch, NIcon, useMessage, useDialog, type DataTableColumns,
} from 'naive-ui'
import { AddOutline } from '@vicons/ionicons5'
import { adminApi } from '@/api/admin'
import { ApiError } from '@/api/errors'
import { useAdminStore } from '@/stores/admin'
import type { AdminUser, UserPayload } from '@/types/admin'

const admin = useAdminStore()
const message = useMessage()
const dialog = useDialog()

const users = ref<AdminUser[]>([])
const loading = ref(true)

const showModal = ref(false)
const editing = ref<AdminUser | null>(null)
const saving = ref(false)
const fieldErrors = reactive<Record<string, string>>({})

function emptyForm(): UserPayload {
  return { name: '', email: '', password: '', role: 'editor', is_active: true }
}
const form = reactive<UserPayload>(emptyForm())

async function load() {
  loading.value = true
  try { users.value = (await adminApi.listUsers()).data }
  finally { loading.value = false }
}
onMounted(load)

function clearErrors() { for (const k of Object.keys(fieldErrors)) delete fieldErrors[k] }

function openCreate() {
  editing.value = null
  Object.assign(form, emptyForm())
  clearErrors()
  showModal.value = true
}
function openEdit(u: AdminUser) {
  editing.value = u
  Object.assign(form, { name: u.name, email: u.email, password: '', role: u.role, is_active: u.is_active })
  clearErrors()
  showModal.value = true
}

async function save() {
  saving.value = true
  clearErrors()
  try {
    if (editing.value) await adminApi.updateUser(editing.value.id, { ...form })
    else await adminApi.createUser({ ...form })
    message.success(editing.value ? 'Пользователь обновлён' : 'Пользователь создан')
    showModal.value = false
    await load()
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

function remove(u: AdminUser) {
  dialog.warning({
    title: 'Удалить пользователя', content: `Удалить «${u.name}»?`,
    positiveText: 'Удалить', negativeText: 'Отмена',
    onPositiveClick: async () => {
      try { await adminApi.deleteUser(u.id); message.success('Удалён'); await load() }
      catch (e) { message.error(e instanceof ApiError ? e.message : 'Не удалось удалить') }
    },
  })
}

const roleOptions = [{ label: 'Редактор', value: 'editor' }, { label: 'Администратор', value: 'admin' }]

const columns: DataTableColumns<AdminUser> = [
  {
    title: 'Имя', key: 'name',
    render: (u) => h(NSpace, { align: 'center', size: 6 }, () => [
      h('strong', u.name),
      u.id === admin.user?.id ? h(NTag, { size: 'small', type: 'info', bordered: false }, () => 'вы') : null,
    ]),
  },
  { title: 'Email', key: 'email' },
  { title: 'Роль', key: 'role', width: 150, render: (u) => (u.role === 'admin' ? 'Администратор' : 'Редактор') },
  {
    title: 'Статус', key: 'is_active', width: 120,
    render: (u) => h(NTag, { size: 'small', type: u.is_active ? 'success' : 'default', bordered: false },
      () => (u.is_active ? 'активен' : 'выключен')),
  },
  {
    title: '', key: 'actions', width: 180, align: 'right',
    render: (u) => h(NSpace, { justify: 'end', size: 8 }, () => [
      h(NButton, { size: 'small', quaternary: true, onClick: () => openEdit(u) }, () => 'Изм.'),
      u.id !== admin.user?.id
        ? h(NButton, { size: 'small', quaternary: true, type: 'error', onClick: () => remove(u) }, () => 'Удалить')
        : null,
    ]),
  },
]
</script>

<template>
  <div>
    <div class="head">
      <h1 class="head__title">Пользователи</h1>
      <n-button type="primary" @click="openCreate">
        <template #icon><n-icon><AddOutline /></n-icon></template>
        Пользователь
      </n-button>
    </div>

    <n-card :bordered="false">
      <n-data-table :columns="columns" :data="users" :loading="loading" :row-key="(u: AdminUser) => u.id" />
    </n-card>

    <n-modal
      v-model:show="showModal" preset="card" style="width: 480px"
      :title="editing ? 'Редактировать пользователя' : 'Новый пользователь'"
    >
      <n-form label-placement="top">
        <n-form-item label="Имя" :validation-status="fieldErrors.name ? 'error' : undefined" :feedback="fieldErrors.name">
          <n-input v-model:value="form.name" />
        </n-form-item>
        <n-form-item label="Email" :validation-status="fieldErrors.email ? 'error' : undefined" :feedback="fieldErrors.email">
          <n-input v-model:value="form.email" />
        </n-form-item>
        <n-form-item
          :label="editing ? 'Пароль (пусто = не менять)' : 'Пароль'"
          :validation-status="fieldErrors.password ? 'error' : undefined" :feedback="fieldErrors.password"
        >
          <n-input v-model:value="form.password" type="password" show-password-on="click" />
        </n-form-item>
        <div class="grid2">
          <n-form-item label="Роль" :validation-status="fieldErrors.role ? 'error' : undefined" :feedback="fieldErrors.role">
            <n-select v-model:value="form.role" :options="roleOptions" />
          </n-form-item>
          <n-form-item label="Активен">
            <n-switch v-model:value="form.is_active" />
          </n-form-item>
        </div>
      </n-form>
      <template #footer>
        <n-space justify="end">
          <n-button @click="showModal = false">Отмена</n-button>
          <n-button type="primary" :loading="saving" @click="save">Сохранить</n-button>
        </n-space>
      </template>
    </n-modal>
  </div>
</template>

<style scoped>
.head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
.head__title { font-size: 24px; font-weight: 700; margin: 0; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
</style>
