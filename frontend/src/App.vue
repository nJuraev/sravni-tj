<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useHead } from '@unhead/vue'
import AppHeader from '@/components/layout/AppHeader.vue'
import AppFooter from '@/components/layout/AppFooter.vue'
import LeadModal from '@/components/lead/LeadModal.vue'
import SkeletonCard from '@/components/ui/SkeletonCard.vue'
import { WIRE_LOCALE } from '@/types/api'
import { getLocaleFromRoute } from '@/router/locale'

const route = useRoute()
// Админка (/admin/*) рендерит собственный layout без публичной шапки/подвала.
const isAdmin = computed(() => route.meta.admin === true)

// Single source of truth for <html lang> — SSR-safe via @unhead/vue (replaces
// the old direct document.documentElement.setAttribute call in i18n/index.ts).
useHead({
  htmlAttrs: { lang: computed(() => WIRE_LOCALE[getLocaleFromRoute(route)]) },
})
</script>

<template>
  <RouterView v-if="isAdmin" />
  <div v-else class="app">
    <AppHeader />
    <main class="app__main">
      <!--
        Every view/child awaits its own data at the top of setup() (see the
        catalog/product/bank/rates/review views + home's child components),
        so SSR's renderToString waits for real data — Suspense is only the
        client-side fallback UI while the next route's setup awaits.
        No page-transition animation here: Vue's Suspense + Transition
        mode="out-in" combo is broken for repeated vue-router navigations
        (content freezes after the first route change) — a known limitation
        of Suspense, which itself remains an experimental Vue API.
      -->
      <RouterView v-slot="{ Component }">
        <Suspense>
          <template #default>
            <component :is="Component" />
          </template>
          <template #fallback>
            <div class="app__route-fallback container" aria-busy="true">
              <SkeletonCard v-for="n in 3" :key="n" />
            </div>
          </template>
        </Suspense>
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
