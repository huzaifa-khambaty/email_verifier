<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import api from '../lib/api'
import AppShell from '../layouts/AppShell.vue'

// Groups mirror VerificationJob::STATUS_GROUPS on the backend — every
// engine status falls into exactly one, so these counts always add up to
// Total. Catch-all is deliberately its own bucket rather than folded into
// Valid: the domain accepts every address, so a 250 there isn't proof
// this specific mailbox exists.
const CARDS = [
  { key: 'total', label: 'Total', filter: null, color: '#52514e' },
  { key: 'pending', label: 'Pending', filter: 'pending', color: '#2a78d6' },
  { key: 'valid', label: 'Valid', filter: 'valid', color: '#0ca30c' },
  { key: 'catch_all', label: 'Catch-all', filter: 'catch_all', color: '#fab219' },
  { key: 'invalid', label: 'Invalid', filter: 'invalid', color: '#d03b3b' },
  { key: 'unknown', label: 'Unknown', filter: 'unknown', color: '#ec835a' },
]

// Per-status dot colour in the list rows, so a row reads the same as the
// card that would contain it.
const STATUS_COLOR = {
  PENDING: '#898781',
  PROCESSING: '#2a78d6',
  VALID: '#0ca30c',
  CATCH_ALL: '#fab219',
  INVALID: '#d03b3b',
  NO_MX: '#d03b3b',
  UNKNOWN: '#ec835a',
  TEMP_FAILURE: '#ec835a',
}

const stats = ref(null)
const rows = ref([])
const meta = ref(null)
const loading = ref(true)
const error = ref('')

const activeFilter = ref(null)
const search = ref('')
const from = ref('')
const to = ref('')
const page = ref(1)

let filterTimer = null

// Search + date range, shared by the list, the stat cards AND the
// download, so all three always describe the same set. If the cards
// ignored the date range they'd contradict the list sitting under them.
const filterParams = computed(() => {
  const params = {}
  if (search.value.trim()) params.search = search.value.trim()
  if (from.value) params.from = from.value
  if (to.value) params.to = to.value
  return params
})

const dateRangeActive = computed(() => Boolean(from.value || to.value))

const queryParams = computed(() => {
  const params = { page: page.value, per_page: 25, ...filterParams.value }
  if (activeFilter.value) params.status = activeFilter.value
  return params
})

async function fetchStats() {
  try {
    const { data } = await api.get('/emails/stats', { params: filterParams.value })
    stats.value = data
  } catch {
    // Non-fatal: the list below is still usable without the cards.
  }
}

async function fetchEmails() {
  loading.value = true
  try {
    const { data } = await api.get('/emails', { params: queryParams.value })
    rows.value = data.data
    meta.value = { current: data.current_page, last: data.last_page, total: data.total, from: data.from, to: data.to }
    error.value = ''
  } catch {
    error.value = 'Could not load emails.'
  } finally {
    loading.value = false
  }
}

function selectFilter(filter) {
  activeFilter.value = filter
  page.value = 1
  fetchEmails()
}

function changePage(delta) {
  const next = page.value + delta
  if (next < 1 || (meta.value && next > meta.value.last)) return
  page.value = next
  fetchEmails()
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function clearDates() {
  from.value = ''
  to.value = ''
}

function download() {
  // Plain navigation rather than an XHR: the endpoint streams the file
  // with Content-Disposition, and same-origin means the session cookie
  // rides along, so the browser handles it as a normal download.
  const params = new URLSearchParams(filterParams.value)
  if (activeFilter.value) params.set('status', activeFilter.value)
  const qs = params.toString()
  window.location.href = `/api/emails/export${qs ? '?' + qs : ''}`
}

// One debounced reload for every filter input. Typing a date is
// incremental (2026, 2026-0, 2026-08…), so firing per keystroke would
// spam the API with half-formed values; the backend ignores unparseable
// dates but there's no reason to ask it 12 times.
watch([search, from, to], () => {
  clearTimeout(filterTimer)
  filterTimer = setTimeout(() => {
    page.value = 1
    fetchEmails()
    fetchStats()
  }, 350)
})

onMounted(() => {
  fetchEmails()
  fetchStats()
})
</script>

<template>
  <AppShell>
    <div class="mx-auto max-w-6xl">
      <div class="mb-5 flex items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-[#0b0b0b] dark:text-white">Emails</h2>
        <button
          class="shrink-0 rounded-md bg-[#2a78d6] px-3 py-2 text-xs font-medium text-white hover:bg-[#256abf] sm:text-sm"
          @click="download"
        >
          Download CSV
        </button>
      </div>

      <!-- Stat cards double as filters; the active one is outlined so the
           list below is never showing a filtered view with no indication why. -->
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <button
          v-for="card in CARDS"
          :key="card.key"
          class="rounded-lg border bg-[#fcfcfb] p-3.5 text-left transition-colors dark:bg-[#1a1a19] sm:p-4"
          :class="activeFilter === card.filter
            ? 'border-[#2a78d6] ring-1 ring-[#2a78d6]'
            : 'border-[#e1e0d9] hover:border-[#c3c2b7] dark:border-[#2c2c2a] dark:hover:border-[#383835]'"
          @click="selectFilter(card.filter)"
        >
          <div class="flex items-center gap-2">
            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: card.color }" />
            <span class="text-xs font-medium text-[#52514e] dark:text-[#c3c2b7]">{{ card.label }}</span>
          </div>
          <p class="mt-2 text-xl font-semibold text-[#0b0b0b] dark:text-white sm:text-2xl">
            {{ stats ? (stats[card.key] ?? 0).toLocaleString() : '—' }}
          </p>
        </button>
      </div>

      <div class="mt-4 space-y-3">
        <input
          v-model="search"
          type="search"
          placeholder="Search email address…"
          class="w-full rounded-md border border-[#c3c2b7] bg-[#fcfcfb] px-3 py-2.5 text-sm text-[#0b0b0b] focus:border-[#2a78d6] focus:outline-none focus:ring-1 focus:ring-[#2a78d6] dark:border-[#383835] dark:bg-[#1a1a19] dark:text-white"
        />

        <!-- Stacked on mobile so each control keeps a full-width tap
             target; side by side once there's room. -->
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
          <label class="flex-1">
            <span class="mb-1 block text-xs font-medium text-[#52514e] dark:text-[#c3c2b7]">Verified from</span>
            <input
              v-model="from"
              type="datetime-local"
              class="w-full rounded-md border border-[#c3c2b7] bg-[#fcfcfb] px-3 py-2.5 text-sm text-[#0b0b0b] focus:border-[#2a78d6] focus:outline-none focus:ring-1 focus:ring-[#2a78d6] dark:border-[#383835] dark:bg-[#1a1a19] dark:text-white"
            />
          </label>
          <label class="flex-1">
            <span class="mb-1 block text-xs font-medium text-[#52514e] dark:text-[#c3c2b7]">Verified to</span>
            <input
              v-model="to"
              type="datetime-local"
              class="w-full rounded-md border border-[#c3c2b7] bg-[#fcfcfb] px-3 py-2.5 text-sm text-[#0b0b0b] focus:border-[#2a78d6] focus:outline-none focus:ring-1 focus:ring-[#2a78d6] dark:border-[#383835] dark:bg-[#1a1a19] dark:text-white"
            />
          </label>
          <button
            v-if="dateRangeActive"
            class="rounded-md border border-[#c3c2b7] px-3 py-2.5 text-sm font-medium text-[#52514e] hover:bg-[#f0efec] dark:border-[#383835] dark:text-[#c3c2b7] dark:hover:bg-[#2c2c2a]"
            @click="clearDates"
          >
            Clear dates
          </button>
        </div>

        <!-- Pending rows have no verification time yet, so a date range
             necessarily excludes them. Say so rather than let the Pending
             card silently read 0 and look like data went missing. -->
        <p v-if="dateRangeActive" class="text-xs text-[#898781]">
          Date range filters on when each address was verified, so
          still-pending addresses are excluded while it's active.
        </p>
      </div>

      <p v-if="error" class="mt-4 text-sm text-[#d03b3b]">{{ error }}</p>

      <p v-if="loading" class="mt-6 text-sm text-[#898781]">Loading…</p>
      <p v-else-if="rows.length === 0" class="mt-6 text-sm text-[#898781]">
        No emails match this filter.
      </p>

      <template v-else>
        <!-- Mobile: stacked cards. A table at 390px would either overflow
             horizontally or crush every column, so the same data is
             re-laid-out rather than shrunk. -->
        <ul class="mt-4 space-y-2 sm:hidden">
          <li
            v-for="row in rows"
            :key="row.id"
            class="rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-3.5 dark:border-[#2c2c2a] dark:bg-[#1a1a19]"
          >
            <p class="truncate text-sm font-medium text-[#0b0b0b] dark:text-white">{{ row.email }}</p>
            <p v-if="row.first_name || row.last_name" class="truncate text-xs text-[#898781]">
              {{ [row.first_name, row.last_name].filter(Boolean).join(' ') }}
            </p>
            <div class="mt-2 flex items-center justify-between gap-2">
              <span class="flex items-center gap-1.5 text-xs text-[#52514e] dark:text-[#c3c2b7]">
                <span class="h-1.5 w-1.5 shrink-0 rounded-full" :style="{ backgroundColor: STATUS_COLOR[row.status] }" />
                {{ row.status }}
              </span>
              <span v-if="row.smtp_code" class="text-xs text-[#898781]">SMTP {{ row.smtp_code }}</span>
            </div>
          </li>
        </ul>

        <!-- Desktop: table, in its own scroll container so a long MX host
             can never push the page itself sideways. -->
        <div class="mt-4 hidden overflow-x-auto rounded-lg border border-[#e1e0d9] dark:border-[#2c2c2a] sm:block">
          <table class="w-full min-w-[42rem] border-collapse bg-[#fcfcfb] text-sm dark:bg-[#1a1a19]">
            <thead>
              <tr class="border-b border-[#e1e0d9] text-left text-xs uppercase tracking-wide text-[#898781] dark:border-[#2c2c2a]">
                <th class="px-4 py-2.5 font-medium">Email</th>
                <th class="px-4 py-2.5 font-medium">Name</th>
                <th class="px-4 py-2.5 font-medium">Status</th>
                <th class="px-4 py-2.5 font-medium">SMTP</th>
                <th class="px-4 py-2.5 font-medium">Processed</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in rows"
                :key="row.id"
                class="border-b border-[#e1e0d9] last:border-0 dark:border-[#2c2c2a]"
              >
                <td class="px-4 py-2.5 text-[#0b0b0b] dark:text-white">{{ row.email }}</td>
                <td class="px-4 py-2.5 text-[#52514e] dark:text-[#c3c2b7]">
                  {{ [row.first_name, row.last_name].filter(Boolean).join(' ') || '—' }}
                </td>
                <td class="px-4 py-2.5">
                  <span class="flex items-center gap-1.5 text-[#52514e] dark:text-[#c3c2b7]">
                    <span class="h-1.5 w-1.5 shrink-0 rounded-full" :style="{ backgroundColor: STATUS_COLOR[row.status] }" />
                    {{ row.status }}
                  </span>
                </td>
                <td class="px-4 py-2.5 text-[#898781]">{{ row.smtp_code || '—' }}</td>
                <td class="px-4 py-2.5 text-[#898781]">{{ row.processed_at?.slice(0, 16).replace('T', ' ') || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="meta" class="mt-4 flex items-center justify-between gap-3">
          <p class="text-xs text-[#898781]">
            {{ meta.from?.toLocaleString() }}–{{ meta.to?.toLocaleString() }} of {{ meta.total?.toLocaleString() }}
          </p>
          <div class="flex gap-2">
            <button
              class="rounded-md border border-[#c3c2b7] px-3 py-1.5 text-xs font-medium text-[#52514e] disabled:opacity-40 hover:enabled:bg-[#f0efec] dark:border-[#383835] dark:text-[#c3c2b7] dark:hover:enabled:bg-[#2c2c2a]"
              :disabled="meta.current <= 1"
              @click="changePage(-1)"
            >
              Previous
            </button>
            <button
              class="rounded-md border border-[#c3c2b7] px-3 py-1.5 text-xs font-medium text-[#52514e] disabled:opacity-40 hover:enabled:bg-[#f0efec] dark:border-[#383835] dark:text-[#c3c2b7] dark:hover:enabled:bg-[#2c2c2a]"
              :disabled="meta.current >= meta.last"
              @click="changePage(1)"
            >
              Next
            </button>
          </div>
        </div>
      </template>
    </div>
  </AppShell>
</template>
