<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import AppHeader from '@/components/layout/AppHeader.vue'
import AppFooter from '@/components/layout/AppFooter.vue'
import LeadModal from '@/components/lead/LeadModal.vue'

const route = useRoute()
// Админка (/admin/*) рендерит собственный layout без публичной шапки/подвала.
const isAdmin = computed(() => route.meta.admin === true)
</script>

<template>
  <RouterView v-if="isAdmin" />
  <div v-else class="app">
    <AppHeader />
    <main class="app__main">
      <RouterView v-slot="{ Component }">
        <Transition name="page" mode="out-in">
          <component :is="Component" />
        </Transition>
      </RouterView>
    </main>
    <AppFooter />
    <LeadModal />
  </div>
</template>

<style scoped>
.app {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}
.app__main {
  flex: 1;
}
.page-enter-active,
.page-leave-active {
  transition:
    opacity var(--transition),
    transform var(--transition);
}
.page-enter-from {
  opacity: 0;
  transform: translateY(6px);
}
.page-leave-to {
  opacity: 0;
}
</style>
