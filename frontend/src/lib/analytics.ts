import type { Router } from 'vue-router'

declare global {
  interface Window {
    dataLayer: unknown[]
    gtag: (...args: unknown[]) => void
  }
}

/** Empty/unset in local & preview builds — analytics is a no-op there. */
export const GA_MEASUREMENT_ID = import.meta.env.VITE_GA_MEASUREMENT_ID as string | undefined

/**
 * Loads gtag.js and wires SPA pageviews to route changes. Client-only — call
 * only from entry-client.ts, never entry-server.ts (gtag needs `window`/`document`).
 * Admin (/admin/*) is excluded from tracking — internal tool, not public traffic.
 */
export function initAnalytics(router: Router): void {
  if (!GA_MEASUREMENT_ID) return

  const script = document.createElement('script')
  script.async = true
  script.src = `https://www.googletagmanager.com/gtag/js?id=${GA_MEASUREMENT_ID}`
  document.head.appendChild(script)

  window.dataLayer = window.dataLayer ?? []
  window.gtag = function gtag(...args: unknown[]) {
    window.dataLayer.push(args)
  }
  window.gtag('js', new Date())
  // No cookie banner on this site (no EEA/UK compliance obligation) — grant
  // immediately. Without an explicit consent signal, gtag's default-deny
  // silently drops every hit (confirmed in prod: zero /g/collect requests,
  // even with a valid config+event queue, until this call was added).
  window.gtag('consent', 'default', {
    ad_storage: 'granted',
    analytics_storage: 'granted',
    ad_user_data: 'granted',
    ad_personalization: 'granted',
  })
  // send_page_view disabled: every navigation (including the first) is
  // reported explicitly via router.afterEach below, so nothing double-fires.
  window.gtag('config', GA_MEASUREMENT_ID, { send_page_view: false })

  router.afterEach((to) => {
    if (to.meta.admin) return
    window.gtag('event', 'page_view', {
      page_path: to.fullPath,
      page_title: document.title,
    })
  })
}
