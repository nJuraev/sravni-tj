import { createAppRouter } from '@/router'
import { createI18nInstance } from '@/i18n'
import { api } from '@/api/client'
import { SITE_ORIGIN } from '@/lib/siteOrigin'

interface UrlPair {
  ru: string
  tj: string
}

/**
 * Every public, indexable ru/tj URL pair — static pages plus every active
 * product/bank. Built from the SAME route table used by the app itself (a
 * throwaway router instance, `.resolve()` only, no navigation) so this can
 * never drift out of sync with the real `-tj` naming convention (see
 * router/localizedRoutes.ts) the way a hand-duplicated path list could.
 * Admin/compare/404 are simply never added — no separate noindex bookkeeping
 * needed.
 */
export async function collectSitemapUrls(): Promise<UrlPair[]> {
  const router = createAppRouter(createI18nInstance('ru'))
  const pairs: UrlPair[] = []

  function addNamed(name: string, params: Record<string, unknown> = {}) {
    const ru = `${SITE_ORIGIN}${router.resolve({ name, params }).fullPath}`
    const tj = `${SITE_ORIGIN}${router.resolve({ name: `${name}-tj`, params }).fullPath}`
    pairs.push({ ru, tj })
  }

  addNamed('home')
  addNamed('rates')
  addNamed('reviews')
  for (const category of ['credit', 'deposit', 'installment']) {
    addNamed('catalog', { category })
  }

  // Dynamic: the API already filters to active products/banks only.
  const [credits, deposits, installments, banks] = await Promise.all([
    api.getProducts('ru', { category: 'credit', per_page: 500 }),
    api.getProducts('ru', { category: 'deposit', per_page: 500 }),
    api.getProducts('ru', { category: 'installment', per_page: 500 }),
    api.getBanks('ru'),
  ])
  for (const p of [...credits.data, ...deposits.data, ...installments.data]) {
    addNamed('product', { id: p.id })
  }
  for (const b of banks.data) {
    addNamed('bank', { id: b.id })
  }

  return pairs
}

function urlEntry(loc: string, pair: UrlPair): string {
  return [
    '  <url>',
    `    <loc>${loc}</loc>`,
    `    <xhtml:link rel="alternate" hreflang="ru" href="${pair.ru}" />`,
    `    <xhtml:link rel="alternate" hreflang="tg" href="${pair.tj}" />`,
    '  </url>',
  ].join('\n')
}

/** One <url> entry per language for each pair, each carrying both hreflang alternates (reciprocal, per Google's sitemap i18n guidance). */
export function renderSitemapXml(pairs: UrlPair[]): string {
  const entries = pairs.flatMap((pair) => [urlEntry(pair.ru, pair), urlEntry(pair.tj, pair)]).join('\n')
  return `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">\n${entries}\n</urlset>\n`
}

export function renderRobotsTxt(): string {
  return `User-agent: *\nAllow: /\nDisallow: /admin\n\nSitemap: ${SITE_ORIGIN}/sitemap.xml\n`
}
