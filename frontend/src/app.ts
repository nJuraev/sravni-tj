import { createApp as createVueApp } from 'vue'
import { createPinia } from 'pinia'
import type { VueHeadClient } from '@unhead/vue'
import App from './App.vue'
import { createAppRouter } from './router'
import { createI18nInstance } from './i18n'
import './assets/styles/base.css'

export interface CreateSravniAppOptions {
  /**
   * Environment-specific head client factory — `createHead` from
   * `@unhead/vue/client` on the browser, from `@unhead/vue/server` under
   * Node SSR. Injected rather than imported directly so this factory stays
   * usable from both entry-client.ts and entry-server.ts.
   */
  createHead: () => VueHeadClient
}

/**
 * Shared app factory — creates ALL FRESH instances (pinia, router, i18n,
 * head), no module-level singletons. This is what makes SSR safe under
 * concurrent requests: one Node process renders many requests at once, and
 * a shared mutable instance would leak state between them.
 */
export function createSravniApp({ createHead }: CreateSravniAppOptions) {
  const app = createVueApp(App)
  const pinia = createPinia()
  const i18n = createI18nInstance()
  const router = createAppRouter(i18n)
  const head = createHead()

  app.use(pinia)
  app.use(router)
  app.use(i18n)
  app.use(head)

  return { app, router, pinia, i18n, head }
}
