import { createRouter, createWebHistory } from 'vue-router'
import { useAuth } from '../composables/useAuth'
import Dashboard from '../pages/Dashboard.vue'
import Emails from '../pages/Emails.vue'
import Imports from '../pages/Imports.vue'
import Login from '../pages/Login.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: Login },
    { path: '/', name: 'dashboard', component: Dashboard, meta: { requiresAuth: true } },
    { path: '/emails', name: 'emails', component: Emails, meta: { requiresAuth: true } },
    { path: '/imports', name: 'imports', component: Imports, meta: { requiresAuth: true } },
  ],
})

router.beforeEach(async (to) => {
  const { isAuthenticated, initialized, fetchUser } = useAuth()

  // Single-tenant: session state is resolved once on first navigation
  // (via the /api/user cookie check), then reused — see DECISIONS.md
  // "Auth & users" for why this is cookie-based Sanctum SPA auth.
  if (!initialized.value) {
    await fetchUser()
  }

  if (to.meta.requiresAuth && !isAuthenticated.value) {
    return { name: 'login' }
  }

  if (to.name === 'login' && isAuthenticated.value) {
    return { name: 'dashboard' }
  }

  return true
})

export default router
