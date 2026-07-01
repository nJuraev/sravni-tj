import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import type { Category } from '@/types/api'

/** Categories that map 1:1 to a catalog route segment. */
export const CATALOG_CATEGORIES: Category[] = ['credit', 'deposit', 'installment']

function isCategory(value: unknown): value is Category {
  return typeof value === 'string' && (CATALOG_CATEGORIES as string[]).includes(value)
}

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    redirect: '/credit',
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
  },
  {
    path: '/product/:id(\\d+)',
    name: 'product',
    component: () => import('@/views/ProductDetailView.vue'),
    props: (route) => ({ id: Number(route.params.id) }),
  },
  {
    path: '/compare',
    name: 'compare',
    component: () => import('@/views/CompareView.vue'),
  },
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
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/NotFoundView.vue'),
  },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior(to, _from, savedPosition) {
    if (savedPosition) return savedPosition
    if (to.hash) return { el: to.hash }
    return { top: 0 }
  },
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
