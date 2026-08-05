import { createMemoryHistory, createRouter, createWebHistory, type Router, type RouteRecordRaw } from 'vue-router'
import type { Category } from '@/types/api'
import { i18n, setLocale, type AppI18n } from '@/i18n'
import { getLocaleFromRoute } from './locale'
import { withLocalePrefix } from './localizedRoutes'

/** Categories that map 1:1 to a catalog route segment. */
export const CATALOG_CATEGORIES: Category[] = ['credit', 'deposit', 'installment']

function isCategory(value: unknown): value is Category {
  return typeof value === 'string' && (CATALOG_CATEGORIES as string[]).includes(value)
}

// Public routes — each gets a `/tj`-prefixed twin (withLocalePrefix below).
// ru is the default/unprefixed locale (also x-default for hreflang).
const publicRoutes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'home',
    component: () => import('@/views/HomeView.vue'),
    meta: { locale: 'ru' },
  },
  {
    // Route param constrained to the three valid categories; anything else
    // falls through to the 404 catch-all below.
    path: '/:category(credit|deposit|installment)',
    name: 'catalog',
    component: () => import('@/views/CatalogView.vue'),
    props: (route) => {
      const category = route.params.category
      return { category: isCategory(category) ? category : 'credit' }
    },
    meta: { locale: 'ru' },
  },
  {
    path: '/product/:id(\\d+)',
    name: 'product',
    component: () => import('@/views/ProductDetailView.vue'),
    props: (route) => ({ id: Number(route.params.id) }),
    meta: { locale: 'ru' },
  },
  {
    path: '/bank/:id(\\d+)',
    name: 'bank',
    component: () => import('@/views/BankView.vue'),
    props: (route) => ({ id: Number(route.params.id) }),
    meta: { locale: 'ru' },
  },
  {
    path: '/compare',
    name: 'compare',
    component: () => import('@/views/CompareView.vue'),
    meta: { locale: 'ru' },
  },
  {
    path: '/kurs-valyut',
    name: 'rates',
    component: () => import('@/views/RatesView.vue'),
    meta: { locale: 'ru' },
  },
  {
    path: '/otzyvy',
    name: 'reviews',
    component: () => import('@/views/ReviewFormView.vue'),
    meta: { locale: 'ru' },
  },
  {
    path: '/profile',
    name: 'profile',
    component: () => import('@/views/ProfileView.vue'),
    meta: { locale: 'ru' },
  },
]

// Admin stays single-language (no `/tj/admin` twin) — CSR-only, no SEO value.
const adminRoutes: RouteRecordRaw[] = [
  {
    path: '/admin/login',
    name: 'admin-login',
    component: () => import('@/views/admin/LoginView.vue'),
    meta: { admin: true, public: true },
  },
  {
    path: '/admin',
    component: () => import('@/views/admin/AdminLayout.vue'),
    meta: { admin: true },
    children: [
      { path: '', redirect: { name: 'admin-banks' } },
      { path: 'banks', name: 'admin-banks', component: () => import('@/views/admin/BanksView.vue') },
      {
        path: 'banks/:id(\\d+)',
        name: 'admin-bank',
        component: () => import('@/views/admin/BankDetailView.vue'),
        props: (route) => ({ id: Number(route.params.id) }),
      },
      { path: 'leads', name: 'admin-leads', component: () => import('@/views/admin/LeadsView.vue') },
      {
        path: 'users',
        name: 'admin-users',
        component: () => import('@/views/admin/UsersView.vue'),
        meta: { adminOnly: true },
      },
    ],
  },
]

const notFoundRoute: RouteRecordRaw = {
  path: '/:pathMatch(.*)*',
  name: 'not-found',
  component: () => import('@/views/NotFoundView.vue'),
}

const routes: RouteRecordRaw[] = [
  ...publicRoutes,
  ...withLocalePrefix(publicRoutes),
  ...adminRoutes,
  notFoundRoute,
]

/**
 * Fresh router factory — used per-request under SSR (app.ts) so concurrent
 * requests never share navigation state. Takes the request's own i18n
 * instance so the locale-sync guard never touches a shared singleton either.
 */
export function createAppRouter(i18nInstance: AppI18n): Router {
  const router = createRouter({
    // No `window`/browser history under Node SSR — createMemoryHistory lets
    // entry-server.ts push the request URL directly (official Vue SSR pattern).
    history: typeof window === 'undefined' ? createMemoryHistory() : createWebHistory(),
    routes,
    scrollBehavior(to, _from, savedPosition) {
      if (savedPosition) return savedPosition
      if (to.hash) return { el: to.hash }
      return { top: 0 }
    },
  })

  // Sync i18n locale to the matched route before anything else runs (admin
  // guard included) — the single source of truth for "what locale is this
  // page" is the URL, not a stored preference.
  router.beforeEach((to) => {
    setLocale(getLocaleFromRoute(to), i18nInstance)
  })

  // Guard for the admin section: require a valid session; gate user-management
  // to the `admin` role. Imported lazily to avoid a circular import at module load.
  router.beforeEach(async (to) => {
    if (!to.meta.admin) return true

    const { useAdminStore } = await import('@/stores/admin')
    const admin = useAdminStore()
    await admin.init()

    // Login page: bounce authenticated users into the panel.
    if (to.meta.public) {
      return admin.isAuthenticated ? { name: 'admin-banks' } : true
    }

    if (!admin.isAuthenticated) {
      return { name: 'admin-login', query: { redirect: to.fullPath } }
    }

    // User management is admin-only; editors get redirected to banks.
    if (to.meta.adminOnly && !admin.isAdmin) {
      return { name: 'admin-banks' }
    }

    return true
  })

  return router
}

// Client-side singleton — used by main.ts and existing tests. The SSR entry
// point (entry-server.ts, Phase 5) calls createAppRouter() directly instead,
// with its own per-request i18n instance.
export const router = createAppRouter(i18n)
