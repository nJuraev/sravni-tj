import type { RouteLocationNormalizedLoaded, RouteLocationRaw, RouteRecordNormalized } from 'vue-router'
import type { Locale } from '@/types/api'

export const LOCALE_PREFIX = '/tj'

/**
 * Derive locale purely from a URL path — the single source of truth
 * server-side, where there is no `localStorage` to fall back to.
 */
export function getLocaleFromPath(path: string): Locale {
  return path === LOCALE_PREFIX || path.startsWith(`${LOCALE_PREFIX}/`) ? 'tj' : 'ru'
}

/**
 * Prefer the matched route's own `meta.locale` (set by the route table);
 * fall back to path-sniffing for the shared not-found route, which has no
 * locale-specific twin.
 */
export function getLocaleFromRoute(
  route: RouteLocationNormalizedLoaded | RouteRecordNormalized | { meta: Record<string, unknown>; path: string },
): Locale {
  const metaLocale = route.meta.locale
  if (metaLocale === 'ru' || metaLocale === 'tj') return metaLocale
  return getLocaleFromPath(route.path)
}

function togglePathPrefix(path: string, target: Locale): string {
  const bare = path === LOCALE_PREFIX || path.startsWith(`${LOCALE_PREFIX}/`) ? path.slice(LOCALE_PREFIX.length) || '/' : path
  if (target !== 'tj') return bare
  return bare === '/' ? LOCALE_PREFIX : `${LOCALE_PREFIX}${bare}`
}

/**
 * The same page, in the other locale — used by the language switcher to
 * navigate (never auto-redirect) between the ru/tj trees. Named routes swap
 * via the `-tj` suffix convention (see localizedRoutes.ts); the unnamed
 * catch-all (not-found) falls back to toggling the path prefix directly.
 */
export function getLocalizedRouteTarget(
  route: RouteLocationNormalizedLoaded,
  target: Locale,
): RouteLocationRaw {
  if (!route.name) {
    return { path: togglePathPrefix(route.path, target), query: route.query, hash: route.hash }
  }
  const name = String(route.name)
  const isTjName = name.endsWith('-tj')
  const targetName = target === 'tj' ? (isTjName ? name : `${name}-tj`) : isTjName ? name.slice(0, -3) : name
  return { name: targetName, params: route.params, query: route.query, hash: route.hash }
}
