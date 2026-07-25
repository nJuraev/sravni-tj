import { computed } from 'vue'
import { useHead } from '@unhead/vue'
import { useRoute, useRouter } from 'vue-router'
import { WIRE_LOCALE } from '@/types/api'
import { getLocaleFromRoute, getLocalizedRouteTarget } from '@/router/locale'
import { SITE_ORIGIN } from '@/lib/siteOrigin'

/**
 * Reciprocal hreflang (ru self + tj alternate + x-default→ru) and a
 * self-referencing canonical, as absolute URLs — computed from the route's
 * `-tj`-suffixed-name pairing (see router/localizedRoutes.ts), not string
 * surgery on paths. Call once per indexable view (skip on user-specific or
 * noindex pages, e.g. /compare, 404 — see useSeo's `hreflang` option).
 *
 * Reactive to the route, not snapshotted at setup time: catalog's three
 * categories (/credit, /deposit, /installment) share one route/component
 * instance, so the URL can change client-side without a remount.
 */
export function useHreflang(): void {
  const route = useRoute()
  const router = useRouter()

  const selfUrl = computed(() => `${SITE_ORIGIN}${route.fullPath}`)
  const otherUrl = computed(() => {
    const locale = getLocaleFromRoute(route)
    const target = getLocalizedRouteTarget(route, locale === 'ru' ? 'tj' : 'ru')
    return `${SITE_ORIGIN}${router.resolve(target).fullPath}`
  })
  const ruUrl = computed(() => (getLocaleFromRoute(route) === 'ru' ? selfUrl.value : otherUrl.value))
  const tjUrl = computed(() => (getLocaleFromRoute(route) === 'tj' ? selfUrl.value : otherUrl.value))

  useHead({
    link: [
      { rel: 'canonical', href: selfUrl },
      { rel: 'alternate', hreflang: WIRE_LOCALE.ru, href: ruUrl },
      { rel: 'alternate', hreflang: WIRE_LOCALE.tj, href: tjUrl },
      { rel: 'alternate', hreflang: 'x-default', href: ruUrl },
    ],
  })
}
