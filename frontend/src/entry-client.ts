import { createHead } from '@unhead/vue/client'
import { configure } from 'vue-gtag'
import { createSravniApp } from './app'

const { app, router } = createSravniApp({ createHead })

// GA4 — client-only (never entry-server.ts, gtag needs window/document). Empty
// in local/preview builds (no VITE_GA_MEASUREMENT_ID baked in) — no-op there.
// Official gtag.js snippet (Google Analytics admin > Data Streams > Google tag)
// has no consent() call — that's only required for EEA/UK/CH traffic, and this
// site has neither a cookie banner nor EEA users, so the plain default applies.
const GA_MEASUREMENT_ID = import.meta.env.VITE_GA_MEASUREMENT_ID as string | undefined
if (GA_MEASUREMENT_ID) {
  configure({
    tagId: GA_MEASUREMENT_ID,
    pageTracker: {
      router,
      // Admin (/admin/*) is CSR-only internal tooling, not public traffic.
      exclude: (to) => to.meta.admin === true,
    },
  })
}

// Stale tab after a deploy: route chunks are hashed filenames, and each deploy
// replaces the previous build's assets outright (no old-version retention).
// A tab left open across a deploy still holds the old index.html/module graph,
// so its next lazy-loaded route 404s on the now-gone hash. Fetching the target
// URL fresh (not router.push) pulls the current index.html + matching chunks.
// Guarded to fire once per target path so a genuine outage doesn't loop.
router.onError((error, to) => {
  if (!/dynamically imported module|importing a module script failed/i.test(error.message)) return

  const key = 'sravni:chunk-reload'
  if (sessionStorage.getItem(key) === to.fullPath) return
  sessionStorage.setItem(key, to.fullPath)
  window.location.href = to.fullPath
})

// Wait for the initial navigation (runs the locale-sync + admin guards)
// before mounting, so hydration matches whatever entry-server.ts rendered.
router.isReady().then(() => {
  app.mount('#app')
})
