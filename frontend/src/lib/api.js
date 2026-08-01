import axios from 'axios'

// Sanctum SPA (cookie-based) auth: withCredentials so the session cookie
// is sent, and the CSRF cookie must be fetched once before any
// state-changing request. See DECISIONS.md "Auth & users".
const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  headers: { Accept: 'application/json' },
})

export async function ensureCsrfCookie() {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

export default api
