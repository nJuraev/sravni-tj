<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import {
  NConfigProvider,
  NCard,
  NForm,
  NFormItem,
  NInput,
  NButton,
  NAlert,
  NIcon,
  type FormInst,
} from 'naive-ui'
import { LockClosedOutline } from '@vicons/ionicons5'
import { useAdminStore } from '@/stores/admin'
import { ApiError } from '@/api/errors'
import { adminThemeOverrides } from './theme'

const admin = useAdminStore()
const router = useRouter()
const route = useRoute()

const formRef = ref<FormInst | null>(null)
const model = ref({ email: '', password: '' })
const error = ref('')
const loading = ref(false)

const rules = {
  email: { required: true, message: 'Введите email', trigger: ['blur', 'input'] },
  password: { required: true, message: 'Введите пароль', trigger: ['blur', 'input'] },
}

async function onSubmit() {
  error.value = ''
  try {
    await formRef.value?.validate()
  } catch {
    return
  }
  loading.value = true
  try {
    await admin.login(model.value.email, model.value.password)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : null
    router.push(redirect ?? { name: 'admin-banks' })
  } catch (e) {
    error.value = e instanceof ApiError ? (e.fieldErrors.email?.[0] ?? e.message) : 'Не удалось войти.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <n-config-provider :theme-overrides="adminThemeOverrides">
    <div class="login">
      <n-card class="login__card" :bordered="false">
        <div class="login__brand">
          <n-icon size="28" color="#0050C8"><LockClosedOutline /></n-icon>
          <div>
            <div class="login__title">Sravni · Админка</div>
            <div class="login__hint">Вход для управления данными</div>
          </div>
        </div>

        <n-form ref="formRef" :model="model" :rules="rules" @submit.prevent="onSubmit">
          <n-form-item label="Email" path="email">
            <n-input
              v-model:value="model.email"
              placeholder="admin@sravni.tj"
              @keydown.enter.prevent="onSubmit"
            />
          </n-form-item>
          <n-form-item label="Пароль" path="password">
            <n-input
              v-model:value="model.password"
              type="password"
              show-password-on="click"
              placeholder="••••••••"
              @keydown.enter.prevent="onSubmit"
            />
          </n-form-item>

          <n-alert v-if="error" type="error" style="margin-bottom: 16px">{{ error }}</n-alert>

          <n-button type="primary" block :loading="loading" attr-type="submit" @click="onSubmit">
            Войти
          </n-button>
        </n-form>
      </n-card>
    </div>
  </n-config-provider>
</template>

<style scoped>
.login {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  background: linear-gradient(135deg, #eaf1fb 0%, #f6f7f9 100%);
}
.login__card {
  width: 100%;
  max-width: 400px;
  box-shadow: 0 12px 40px rgba(0, 40, 100, 0.12);
  border-radius: 14px;
}
.login__brand {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 24px;
}
.login__title {
  font-weight: 700;
  font-size: 18px;
  color: #0050c8;
}
.login__hint {
  font-size: 13px;
  color: #999;
}
</style>
