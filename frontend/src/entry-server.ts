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
  preloadLinks: string
}

/** Vite's per-build client manifest: module id -> chunk/asset files it pulled in. */
type SsrManifest = Record<string, string[]>

/**
 * Renders one request. A fresh app/router/i18n/head is created per call (see
 * app.ts) — no shared state across concurrent requests, since one Node
 * process serves many requests at once.
 *
 * `manifest` (prod only, from dist/client/.vite/ssr-manifest.json) maps the
 * modules this render actually touched to their built files, so we can emit
 * <link rel="stylesheet"> / modulepreload tags for the current route's
 * code-split chunk — without it, that chunk's CSS only loads once the
 * client JS chunk evaluates, flashing unstyled content first.
 */
export async function render(url: string, manifest?: SsrManifest): Promise<RenderResult> {
  const { app, router, head } = createSravniApp({ createHead })
  const statusCtx = { status: 200 }
  app.provide(HTTP_STATUS_KEY, statusCtx)

  await router.push(url)
  await router.isReady()

  const ctx: { modules?: Set<string> } = {}

  try {
    const html = await renderToString(app, ctx)
    const preloadLinks = manifest ? renderPreloadLinks(ctx.modules, manifest) : ''
    return { html, head, status: statusCtx.status, preloadLinks }
  } catch (err) {
    // A genuine bug (e.g. CatalogView's non-ApiError rethrow) — surface as a
    // 500 rather than crashing the whole Node process.
    console.error(`[SSR] render failed for ${url}:`, err)
    return { html: '', head, status: 500, preloadLinks: '' }
  }
}

function renderPreloadLinks(modules: Set<string> | undefined, manifest: SsrManifest): string {
  const seen = new Set<string>()
  let links = ''
  modules?.forEach((id) => {
    manifest[id]?.forEach((file) => {
      if (seen.has(file)) return
      seen.add(file)
      if (file.endsWith('.css')) {
        links += `<link rel="stylesheet" href="${file}">`
      } else if (file.endsWith('.js')) {
        links += `<link rel="modulepreload" crossorigin href="${file}">`
      }
    })
  })
  return links
}
