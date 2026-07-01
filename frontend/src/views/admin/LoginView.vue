<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAdminStore } from '@/stores/admin'
import { ApiError } from '@/api/errors'
import BaseButton from '@/components/ui/BaseButton.vue'
import '@/assets/styles/admin.css'

const admin = useAdminStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

async function onSubmit() {
  error.value = ''
  loading.value = true
  try {
    await admin.login(email.value, password.value)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : null
    router.push(redirect ?? { name: 'admin-banks' })
  } catch (e) {
    if (e instanceof ApiError) {
      error.value = e.fieldErrors.email?.[0] ?? e.message
    } else {
      error.value = 'Не удалось войти.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login">
    <form class="login__card adm-card" @submit.prevent="onSubmit">
      <div class="login__brand">Sravni · Админка</div>
      <p class="login__hint">Войдите для управления данными</p>

      <div class="adm-field">
        <label class="adm-field__label" for="login-email">Email</label>
        <input
          id="login-email"
          v-model="email"
          class="adm-input"
          type="email"
          autocomplete="username"
          required
        />
      </div>

      <div class="adm-field">
        <label class="adm-field__label" for="login-password">Пароль</label>
        <input
          id="login-password"
          v-model="password"
          class="adm-input"
          type="password"
          autocomplete="current-password"
          required
        />
      </div>

      <p v-if="error" class="adm-alert">{{ error }}</p>

      <BaseButton type="submit" block :loading="loading">Войти</BaseButton>
    </form>
  </div>
</template>

<style scoped>
.login {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-4);
  background: var(--color-bg-offwhite);
}
.login__card {
  width: 100%;
  max-width: 380px;
  padding: var(--space-7) var(--space-6);
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}
.login__brand {
  font-family: var(--font-display);
  font-weight: 700;
  font-size: var(--fs-xl);
  color: var(--color-primary);
}
.login__hint {
  color: var(--color-text-muted);
  font-size: var(--fs-sm);
  margin-top: calc(-1 * var(--space-2));
}
</style>
