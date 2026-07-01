<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAdminStore } from '@/stores/admin'
import BaseButton from '@/components/ui/BaseButton.vue'
import '@/assets/styles/admin.css'

const admin = useAdminStore()
const router = useRouter()

async function onLogout() {
  await admin.logout()
  router.push({ name: 'admin-login' })
}
</script>

<template>
  <div class="adm">
    <aside class="adm__side">
      <div class="adm__brand">Sravni · Админка</div>
      <nav class="adm__nav">
        <RouterLink class="adm__link" :to="{ name: 'admin-banks' }">Банки</RouterLink>
        <RouterLink class="adm__link" :to="{ name: 'admin-leads' }">Заявки</RouterLink>
        <RouterLink v-if="admin.isAdmin" class="adm__link" :to="{ name: 'admin-users' }">
          Пользователи
        </RouterLink>
      </nav>
      <div class="adm__side-foot">
        <div>
          <div class="adm__user">{{ admin.user?.name }}</div>
          <div class="adm__user-role">{{ admin.user?.role === 'admin' ? 'Администратор' : 'Редактор' }}</div>
        </div>
        <BaseButton variant="ghost" size="sm" @click="onLogout">Выйти</BaseButton>
      </div>
    </aside>
    <main class="adm__main">
      <RouterView />
    </main>
  </div>
</template>
