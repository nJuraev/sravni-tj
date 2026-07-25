import { createHead } from '@unhead/vue/client'
import { createSravniApp } from './app'

const { app, router } = createSravniApp({ createHead })

// Wait for the initial navigation (runs the locale-sync + admin guards)
// before mounting, so hydration matches whatever entry-server.ts rendered.
router.isReady().then(() => {
  app.mount('#app')
})
