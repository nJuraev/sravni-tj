import express from 'express'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'
import { transformHtmlTemplate } from '@unhead/vue/server'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(__dirname, '..')
const isProd = process.env.NODE_ENV === 'production'
const port = Number(process.env.PORT) || 8080

const app = express()

/** @type {import('vite').ViteDevServer | undefined} */
let vite
let prodTemplate
let prodEntry

if (!isProd) {
  // Dev-mode SSR preview — mirrors prod's render path but reads source
  // directly through Vite (HMR, no build step). `npm run dev` still exists
  // separately for pure-CSR fast iteration; this is for previewing real SSR.
  const { createServer } = await import('vite')
  vite = await createServer({
    root,
    server: { middlewareMode: true },
    appType: 'custom',
  })
  app.use(vite.middlewares)
} else {
  app.use(express.static(path.resolve(root, 'dist/client'), { index: false }))
  prodTemplate = readFileSync(path.resolve(root, 'dist/client/index.html'), 'utf-8')
  prodEntry = await import('../dist/server/entry-server.js')
}

/** Dev reloads the module (and template) fresh per request for HMR; prod reuses the one static import. */
async function loadEntry() {
  return isProd ? prodEntry : vite.ssrLoadModule('/src/entry-server.ts')
}

// Admin (/admin/*) is CSR-only — SSR-rendering it would always show the
// logged-out state (its auth token lives in the browser's localStorage,
// unreachable from Node). Serve the static shell and let the client fully
// own it, exactly as before this migration.
app.use(async (req, res, next) => {
  if (req.path !== '/admin' && !req.path.startsWith('/admin/')) return next()
  const html = isProd ? prodTemplate : await vite.transformIndexHtml(req.originalUrl, readFileSync(path.resolve(root, 'index.html'), 'utf-8'))
  res.status(200).set({ 'Content-Type': 'text/html' }).end(html)
})

app.get('/robots.txt', async (req, res) => {
  const { renderRobotsTxt } = await loadEntry()
  res.set({ 'Content-Type': 'text/plain' }).end(renderRobotsTxt())
})

// Content is DB-backed (parser cron + admin edits) and changes without a
// rebuild, so this is generated live rather than at build time — a short
// in-memory cache keeps repeated crawler hits from hammering the API.
const SITEMAP_CACHE_MS = 10 * 60 * 1000
let sitemapCache = null

app.get('/sitemap.xml', async (req, res) => {
  try {
    if (!sitemapCache || Date.now() - sitemapCache.at > SITEMAP_CACHE_MS) {
      const { collectSitemapUrls, renderSitemapXml } = await loadEntry()
      const xml = renderSitemapXml(await collectSitemapUrls())
      sitemapCache = { xml, at: Date.now() }
    }
    res.set({ 'Content-Type': 'application/xml' }).end(sitemapCache.xml)
  } catch (err) {
    console.error('[sitemap] generation failed:', err)
    res.status(500).end('Internal Server Error')
  }
})

app.use(async (req, res) => {
  const url = req.originalUrl

  try {
    let template = prodTemplate
    if (!isProd) {
      template = await vite.transformIndexHtml(url, readFileSync(path.resolve(root, 'index.html'), 'utf-8'))
    }
    const { render } = await loadEntry()

    const { html, head, status } = await render(url)
    const finalHtml = transformHtmlTemplate(head, template).replace('<!--app-html-->', html)

    res.status(status).set({ 'Content-Type': 'text/html' }).end(finalHtml)
  } catch (err) {
    vite?.ssrFixStacktrace(err)
    console.error(err)
    res.status(500).end(isProd ? 'Internal Server Error' : err.stack)
  }
})

app.listen(port, () => {
  console.log(`SSR server listening on http://localhost:${port}`)
})
