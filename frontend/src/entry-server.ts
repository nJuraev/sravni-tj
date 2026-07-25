import { renderToString } from 'vue/server-renderer'
import { createHead } from '@unhead/vue/server'
import type { VueHeadClient } from '@unhead/vue'
import { createSravniApp } from './app'
import { HTTP_STATUS_KEY } from './composables/useHttpStatus'

// Re-exported so server/index.js can pull robots/sitemap generation from this
// same bundled module (`vite build --ssr`) instead of a second build target.
export { collectSitemapUrls, renderSitemapXml, renderRobotsTxt } from './lib/sitemap'

export interface RenderResult {
  html: string
  head: VueHeadClient
  status: number
}

/**
 * Renders one request. A fresh app/router/i18n/head is created per call (see
 * app.ts) — no shared state across concurrent requests, since one Node
 * process serves many requests at once.
 */
export async function render(url: string): Promise<RenderResult> {
  const { app, router, head } = createSravniApp({ createHead })
  const statusCtx = { status: 200 }
  app.provide(HTTP_STATUS_KEY, statusCtx)

  await router.push(url)
  await router.isReady()

  try {
    const html = await renderToString(app)
    return { html, head, status: statusCtx.status }
  } catch (err) {
    // A genuine bug (e.g. CatalogView's non-ApiError rethrow) — surface as a
    // 500 rather than crashing the whole Node process.
    console.error(`[SSR] render failed for ${url}:`, err)
    return { html: '', head, status: 500 }
  }
}
