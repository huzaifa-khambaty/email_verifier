<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import api from '../lib/api'
import AppShell from '../layouts/AppShell.vue'

const FILTERS = [
  { key: null, label: 'All' },
  { key: 'problem', label: 'Low value' },
  { key: 'catch_all', label: 'Catch-all' },
  { key: 'ignored', label: 'Ignored' },
]

const rows = ref([])
const meta = ref(null)
const loading = ref(true)
const error = ref('')
const notice = ref('')
const search = ref('')
const filter = ref(null)
const page = ref(1)
const busy = ref(null)

let debounce = null

const params = computed(() => {
  const p = { page: page.value, per_page: 25 }
  if (search.value.trim()) p.search = search.value.trim()
  if (filter.value) p.filter = filter.value
  return p
})

async function fetchDomains() {
  loading.value = true
  try {
    const { data } = await api.get('/domains', { params: params.value })
    rows.value = data.data
    meta.value = { current: data.current_page, last: data.last_page, total: data.total }
    error.value = ''
  } catch {
    error.value = 'Could not load domains.'
  } finally {
    loading.value = false
  }
}

async function toggleIgnore(row) {
  busy.value = row.id
  notice.value = ''
  try {
    const { data } = await api.patch(`/domains/${row.id}`, { is_ignored: !row.is_ignored })
    notice.value = data.message
    await fetchDomains()
  } catch {
    error.value = `Could not update ${row.name}.`
  } finally {
    busy.value = null
  }
}

function selectFilter(key) {
  filter.value = key
  page.value = 1
  fetchDomains()
}

function changePage(delta) {
  const next = page.value + delta
  if (next < 1 || (meta.value && next > meta.value.last)) return
  page.value = next
  fetchDomains()
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

// Colour the usefulness figure rather than leaving it a bare number —
// it's the column the ignore decision actually hangs on.
function usefulColor(pct) {
  if (pct === null) return '#898781'
  if (pct === 0) return '#d03b3b'
  if (pct < 25) return '#ec835a'
  if (pct < 60) return '#fab219'
  return '#0ca30c'
}

function badges(row) {
  const out = []
  if (row.is_ignored) out.push({ label: 'Ignored', color: '#898781' })
  if (row.is_catch_all) out.push({ label: 'Catch-all', color: '#fab219' })
  if (row.is_unresponsive) out.push({ label: 'Refusing', color: '#d03b3b' })
  return out
}

watch(search, () => {
  clearTimeout(debounce)
  debounce = setTimeout(() => {
    page.value = 1
    fetchDomains()
  }, 350)
})

onMounted(fetchDomains)
</script>

<template>
  <AppShell>
    <div class="mx-auto max-w-6xl">
      <h2 class="mb-1 text-lg font-semibold text-[#0b0b0b] dark:text-white">Domains</h2>
      <p class="mb-4 text-xs text-[#898781]">
        Useful % is the share of settled results that came back Valid. A domain at 0% is spending
        connections without producing anything you can send to — ignoring it frees that throughput.
      </p>

      <div class="mb-3 flex flex-wrap gap-2">
        <button
          v-for="f in FILTERS"
          :key="f.label"
          class="rounded-md border px-3 py-1.5 text-xs font-medium transition-colors"
          :class="filter === f.key
            ? 'border-[#2a78d6] bg-[#2a78d6] text-white'
            : 'border-[#c3c2b7] text-[#52514e] hover:bg-[#f0efec] dark:border-[#383835] dark:text-[#c3c2b7] dark:hover:bg-[#2c2c2a]'"
          @click="selectFilter(f.key)"
        >
          {{ f.label }}
        </button>
      </div>

      <input
        v-model="search"
        type="search"
        placeholder="Search domain…"
        class="mb-3 w-full rounded-md border border-[#c3c2b7] bg-[#fcfcfb] px-3 py-2.5 text-sm text-[#0b0b0b] focus:border-[#2a78d6] focus:outline-none focus:ring-1 focus:ring-[#2a78d6] dark:border-[#383835] dark:bg-[#1a1a19] dark:text-white"
      />

      <p v-if="notice" class="mb-3 rounded-md bg-[#e8f2e8] px-3 py-2 text-xs text-[#0b0b0b] dark:bg-[#1c2a1c] dark:text-white">
        {{ notice }}
      </p>
      <p v-if="error" class="mb-3 text-sm text-[#d03b3b]">{{ error }}</p>

      <p v-if="loading" class="text-sm text-[#898781]">Loading…</p>
      <p v-else-if="!rows.length" class="text-sm text-[#898781]">No domains match.</p>

      <template v-else>
        <!-- Mobile: cards. The same figures a table would show, laid out
             so nothing is clipped at 390px. -->
        <ul class="space-y-2 sm:hidden">
          <li
            v-for="row in rows"
            :key="row.id"
            class="rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-3.5 dark:border-[#2c2c2a] dark:bg-[#1a1a19]"
            :class="row.is_ignored ? 'opacity-60' : ''"
          >
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="truncate text-sm font-medium text-[#0b0b0b] dark:text-white">{{ row.name }}</p>
                <div class="mt-1 flex flex-wrap gap-1">
                  <span
                    v-for="b in badges(row)"
                    :key="b.label"
                    class="rounded px-1.5 py-0.5 text-[10px] font-medium text-white"
                    :style="{ backgroundColor: b.color }"
                  >{{ b.label }}</span>
                </div>
              </div>
              <span class="shrink-0 text-sm font-semibold" :style="{ color: usefulColor(row.useful_pct) }">
                {{ row.useful_pct === null ? '—' : row.useful_pct + '%' }}
              </span>
            </div>

            <dl class="mt-2 grid grid-cols-3 gap-x-2 gap-y-1 text-xs text-[#52514e] dark:text-[#c3c2b7]">
              <div><dt class="inline text-[#898781]">Total</dt> <dd class="inline font-medium">{{ row.total.toLocaleString() }}</dd></div>
              <div><dt class="inline text-[#898781]">Valid</dt> <dd class="inline font-medium">{{ row.valid.toLocaleString() }}</dd></div>
              <div><dt class="inline text-[#898781]">Catch-all</dt> <dd class="inline font-medium">{{ row.catch_all.toLocaleString() }}</dd></div>
              <div><dt class="inline text-[#898781]">Pending</dt> <dd class="inline font-medium">{{ row.pending.toLocaleString() }}</dd></div>
              <div><dt class="inline text-[#898781]">Invalid</dt> <dd class="inline font-medium">{{ row.invalid.toLocaleString() }}</dd></div>
              <div><dt class="inline text-[#898781]">Ignored</dt> <dd class="inline font-medium">{{ row.ignored.toLocaleString() }}</dd></div>
            </dl>

            <p v-if="row.pending > 0" class="mt-1.5 text-xs text-[#898781]">
              ~{{ row.hours_remaining }}h to finish at {{ row.delay_seconds }}s/check
            </p>

            <button
              class="mt-3 w-full rounded-md border px-3 py-2 text-xs font-medium disabled:opacity-50"
              :class="row.is_ignored
                ? 'border-[#2a78d6] text-[#2a78d6]'
                : 'border-[#c3c2b7] text-[#52514e] dark:border-[#383835] dark:text-[#c3c2b7]'"
              :disabled="busy === row.id"
              @click="toggleIgnore(row)"
            >
              {{ busy === row.id ? 'Working…' : (row.is_ignored ? 'Un-ignore' : 'Ignore this domain') }}
            </button>
          </li>
        </ul>

        <div class="hidden overflow-x-auto rounded-lg border border-[#e1e0d9] dark:border-[#2c2c2a] sm:block">
          <table class="w-full min-w-[52rem] border-collapse bg-[#fcfcfb] text-sm dark:bg-[#1a1a19]">
            <thead>
              <tr class="border-b border-[#e1e0d9] text-left text-xs uppercase tracking-wide text-[#898781] dark:border-[#2c2c2a]">
                <th class="px-4 py-2.5 font-medium">Domain</th>
                <th class="px-3 py-2.5 text-right font-medium">Total</th>
                <th class="px-3 py-2.5 text-right font-medium">Pending</th>
                <th class="px-3 py-2.5 text-right font-medium">Valid</th>
                <th class="px-3 py-2.5 text-right font-medium">Catch-all</th>
                <th class="px-3 py-2.5 text-right font-medium">Invalid</th>
                <th class="px-3 py-2.5 text-right font-medium">Useful</th>
                <th class="px-3 py-2.5 text-right font-medium">Left</th>
                <th class="px-4 py-2.5 font-medium"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in rows"
                :key="row.id"
                class="border-b border-[#e1e0d9] last:border-0 dark:border-[#2c2c2a]"
                :class="row.is_ignored ? 'opacity-55' : ''"
              >
                <td class="px-4 py-2.5">
                  <span class="text-[#0b0b0b] dark:text-white">{{ row.name }}</span>
                  <span
                    v-for="b in badges(row)"
                    :key="b.label"
                    class="ml-1.5 rounded px-1.5 py-0.5 text-[10px] font-medium text-white"
                    :style="{ backgroundColor: b.color }"
                  >{{ b.label }}</span>
                </td>
                <td class="px-3 py-2.5 text-right text-[#0b0b0b] dark:text-white">{{ row.total.toLocaleString() }}</td>
                <td class="px-3 py-2.5 text-right text-[#52514e] dark:text-[#c3c2b7]">{{ row.pending.toLocaleString() }}</td>
                <td class="px-3 py-2.5 text-right text-[#52514e] dark:text-[#c3c2b7]">{{ row.valid.toLocaleString() }}</td>
                <td class="px-3 py-2.5 text-right text-[#52514e] dark:text-[#c3c2b7]">{{ row.catch_all.toLocaleString() }}</td>
                <td class="px-3 py-2.5 text-right text-[#52514e] dark:text-[#c3c2b7]">{{ row.invalid.toLocaleString() }}</td>
                <td class="px-3 py-2.5 text-right font-semibold" :style="{ color: usefulColor(row.useful_pct) }">
                  {{ row.useful_pct === null ? '—' : row.useful_pct + '%' }}
                </td>
                <td class="px-3 py-2.5 text-right text-[#898781]">
                  {{ row.pending > 0 ? row.hours_remaining + 'h' : '—' }}
                </td>
                <td class="px-4 py-2.5 text-right">
                  <button
                    class="rounded-md border px-2.5 py-1.5 text-xs font-medium disabled:opacity-50"
                    :class="row.is_ignored
                      ? 'border-[#2a78d6] text-[#2a78d6]'
                      : 'border-[#c3c2b7] text-[#52514e] hover:bg-[#f0efec] dark:border-[#383835] dark:text-[#c3c2b7] dark:hover:bg-[#2c2c2a]'"
                    :disabled="busy === row.id"
                    @click="toggleIgnore(row)"
                  >
                    {{ busy === row.id ? '…' : (row.is_ignored ? 'Un-ignore' : 'Ignore') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="meta" class="mt-4 flex items-center justify-between gap-3">
          <p class="text-xs text-[#898781]">{{ meta.total.toLocaleString() }} domain(s)</p>
          <div class="flex gap-2">
            <button
              class="rounded-md border border-[#c3c2b7] px-3 py-1.5 text-xs font-medium text-[#52514e] disabled:opacity-40 dark:border-[#383835] dark:text-[#c3c2b7]"
              :disabled="meta.current <= 1"
              @click="changePage(-1)"
            >Previous</button>
            <button
              class="rounded-md border border-[#c3c2b7] px-3 py-1.5 text-xs font-medium text-[#52514e] disabled:opacity-40 dark:border-[#383835] dark:text-[#c3c2b7]"
              :disabled="meta.current >= meta.last"
              @click="changePage(1)"
            >Next</button>
          </div>
        </div>
      </template>
    </div>
  </AppShell>
</template>
