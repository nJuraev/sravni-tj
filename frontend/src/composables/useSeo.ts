import { computed, toValue } from 'vue'
import type { MaybeRefOrGetter } from 'vue'
import { useHead } from '@unhead/vue'
import { useHreflang } from '@/composables/useHreflang'

export interface SeoOptions {
  /** Plain string, or a ref/getter for pages that change SEO content without remounting (e.g. catalog category tabs). */
  title: MaybeRefOrGetter<string>
  description: MaybeRefOrGetter<string>
  /**
   * One or more Schema.org JSON-LD blocks (product/bank/rates/FAQ/...).
   * Plain array or a ref/getter — needed when the schema depends on data
   * that can change without a remount (e.g. product detail's currency tabs).
   */
  jsonLd?: MaybeRefOrGetter<Record<string, unknown>[] | undefined>
  /** User-specific or non-canonical pages (e.g. /compare, 404) — adds robots noindex. */
  noindex?: boolean
  /** Reciprocal ru/tj hreflang + canonical. Default true; pass false alongside noindex for pages with no real locale twin. */
  hreflang?: boolean
}

/**
 * SSR-safe head management (title/description/OG/JSON-LD) via @unhead/vue —
 * replaces the old hand-rolled, DOM-only useHead composable (which would
 * throw under Node SSR). `<html lang>` is handled once, centrally, in
 * App.vue — not duplicated per view here.
 */
export function useSeo({ title, description, jsonLd, noindex = false, hreflang = true }: SeoOptions): void {
  useHead({
    title,
    meta: [
      { name: 'description', content: description },
      { property: 'og:site_name', content: 'Sravni.tj' },
      { property: 'og:title', content: title },
      { property: 'og:description', content: description },
      ...(noindex ? [{ name: 'robots', content: 'noindex,follow' }] : []),
    ],
    script: computed(() =>
      toValue(jsonLd)?.map((schema) => ({
        type: 'application/ld+json',
        innerHTML: JSON.stringify(schema),
      })),
    ),
  })

  if (hreflang) useHreflang()
}
