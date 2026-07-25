import type { RouteRecordRaw } from 'vue-router'
import { LOCALE_PREFIX } from './locale'

function prefixPath(path: string): string {
  return path === '/' ? LOCALE_PREFIX : `${LOCALE_PREFIX}${path}`
}

/**
 * Builds the `/tj`-prefixed twin of a flat public route tree: prefixed path,
 * `-tj`-suffixed name (keeps named-route resolution unique), `meta.locale`
 * tagged. Assumes flat routes (no `children`) — the only nested tree today
 * is `/admin`, which stays single-language and never passes through here.
 */
export function withLocalePrefix(routes: RouteRecordRaw[]): RouteRecordRaw[] {
  return routes.map((route) => ({
    ...route,
    path: prefixPath(route.path),
    name: route.name ? `${String(route.name)}-tj` : undefined,
    meta: { ...route.meta, locale: 'tj' },
  }))
}
