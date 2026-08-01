import { computed, ref } from 'vue'
import api, { ensureCsrfCookie } from '../lib/api'

// Shared (module-level) reactive state, not Pinia — see DECISIONS.md
// "Frontend state management". A single ref imported everywhere gives
// every component the same instance, which is all a single-tenant app
// with one admin session needs.
const user = ref(null)
const loading = ref(false)
const initialized = ref(false)

export function useAuth() {
  const isAuthenticated = computed(() => user.value !== null)

  async function fetchUser() {
    try {
      const { data } = await api.get('/user')
      user.value = data
    } catch {
      user.value = null
    } finally {
      initialized.value = true
    }
  }

  async function login(email, password) {
    loading.value = true
    try {
      await ensureCsrfCookie()
      await api.post('/login', { email, password })
      await fetchUser()
    } finally {
      loading.value = false
    }
  }

  async function logout() {
    await api.post('/logout')
    user.value = null
  }

  return { user, loading, initialized, isAuthenticated, fetchUser, login, logout }
}
