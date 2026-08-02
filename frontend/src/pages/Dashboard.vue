<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import api from '../lib/api'
import AppShell from '../layouts/AppShell.vue'

const TILES = [
  { key: 'pending', label: 'Pending', role: 'neutral' },
  { key: 'processing', label: 'Processing', role: 'info' },
  { key: 'verified', label: 'Verified', role: 'good' },
  { key: 'invalid', label: 'Invalid', role: 'critical' },
  { key: 'unknown', label: 'Unknown', role: 'warning' },
  { key: 'catch_all', label: 'Catch-all', role: 'warning' },
]

// references/palette.md "Status palette (fixed — never themed)".
const ROLE_COLOR = {
  good: '#0ca30c',
  warning: '#fab219',
  serious: '#ec835a',
  critical: '#d03b3b',
  info: '#2a78d6',
  neutral: '#898781',
}

const INSIGHT_STYLE = {
  good: { color: '#0ca30c', label: 'Working well' },
  info: { color: '#2a78d6', label: 'Note' },
  warning: { color: '#fab219', label: 'Worth knowing' },
  critical: { color: '#d03b3b', label: 'Needs attention' },
}

const stats = ref(null)
const loading = ref(true)
const error = ref('')
const now = ref(Date.now())
const lastFetch = ref(Date.now())
let pollHandle = null
let tickHandle = null

async function fetchDashboard() {
  try {
    const { data } = await api.get('/dashboard')
    stats.value = data
    error.value = ''
  } catch {
    error.value = 'Could not load dashboard stats.'
  } finally {
    loading.value = false
  }
}

// Countdowns tick locally between the 15s polls, so "frees up in 4m"
// stays honest instead of freezing at whatever the last fetch said.
// `now` is a reactive dependency of countdown(), so it recomputes.
function countdown(seconds) {
  if (seconds === null || seconds === undefined) return null
  const elapsed = Math.floor((now.value - lastFetch.value) / 1000)
  const left = seconds - elapsed
  if (left <= 0) return 'any moment'
  if (left < 60) return `${left}s`
  const mins = Math.floor(left / 60)
  if (mins < 60) return `${mins}m`
  const hrs = Math.floor(mins / 60)
  return `${hrs}h ${mins % 60}m`
}

const blockers = computed(() => stats.value?.blockers ?? [])
const insights = computed(() => stats.value?.insights ?? [])
const flags = computed(() => stats.value?.domain_flags ?? {})
const waiting = computed(() => (stats.value?.pending ?? 0) + (stats.value?.processing ?? 0))

const throughputPeak = computed(() => {
  const t = stats.value?.throughput ?? []
  return Math.max(1, ...t.map((p) => p.count))
})

onMounted(() => {
  fetchDashboard().then(() => { lastFetch.value = Date.now() })
  pollHandle = setInterval(() => {
    fetchDashboard().then(() => { lastFetch.value = Date.now() })
  }, 15000)
  tickHandle = setInterval(() => { now.value = Date.now() }, 1000)
})

onUnmounted(() => {
  if (pollHandle) clearInterval(pollHandle)
  if (tickHandle) clearInterval(tickHandle)
})
</script>

<template>
  <AppShell>
    <div class="mx-auto max-w-6xl">
      <div class="mb-5 flex items-center justify-between">
        <h2 class="text-lg font-semibold text-[#0b0b0b] dark:text-white">Dashboard</h2>
        <RouterLink
          to="/imports"
          class="rounded-md bg-[#2a78d6] px-3 py-2 text-xs font-medium text-white hover:bg-[#256abf] sm:text-sm"
        >
          + New import
        </RouterLink>
      </div>

      <p v-if="error" class="mb-4 text-sm text-[#d03b3b]">{{ error }}</p>

      <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-6">
        <div
          v-for="tile in TILES"
          :key="tile.key"
          class="rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-3.5 dark:border-[#2c2c2a] dark:bg-[#1a1a19] sm:p-4"
        >
          <div class="flex items-center gap-2">
            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: ROLE_COLOR[tile.role] }" />
            <span class="text-xs font-medium text-[#52514e] dark:text-[#c3c2b7]">{{ tile.label }}</span>
          </div>
          <p class="mt-2 text-xl font-semibold text-[#0b0b0b] dark:text-white sm:text-2xl">
            {{ loading ? '—' : (stats?.[tile.key] ?? 0).toLocaleString() }}
          </p>
        </div>
      </div>

      <div class="mt-3 grid grid-cols-2 gap-3 sm:mt-4 sm:grid-cols-4 sm:gap-4">
        <div class="rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-3.5 dark:border-[#2c2c2a] dark:bg-[#1a1a19] sm:p-4">
          <p class="text-xs font-medium text-[#52514e] dark:text-[#c3c2b7]">Queue speed</p>
          <p class="mt-2 text-xl font-semibold text-[#0b0b0b] dark:text-white sm:text-2xl">
            {{ loading ? '—' : `${stats?.queue_speed_per_min ?? 0}/min` }}
          </p>
        </div>
        <div class="rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-3.5 dark:border-[#2c2c2a] dark:bg-[#1a1a19] sm:p-4">
          <p class="text-xs font-medium text-[#52514e] dark:text-[#c3c2b7]">Workers alive</p>
          <p class="mt-2 text-xl font-semibold text-[#0b0b0b] dark:text-white sm:text-2xl">
            {{ loading ? '—' : `${stats?.active_workers ?? 0}/${stats?.workers_expected ?? 0}` }}
          </p>
        </div>
        <div class="rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-3.5 dark:border-[#2c2c2a] dark:bg-[#1a1a19] sm:p-4">
          <p class="text-xs font-medium text-[#52514e] dark:text-[#c3c2b7]">Processed today</p>
          <p class="mt-2 text-xl font-semibold text-[#0b0b0b] dark:text-white sm:text-2xl">
            {{ loading ? '—' : (stats?.processed_today ?? 0).toLocaleString() }}
          </p>
        </div>
        <div class="rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-3.5 dark:border-[#2c2c2a] dark:bg-[#1a1a19] sm:p-4">
          <p class="text-xs font-medium text-[#52514e] dark:text-[#c3c2b7]">Still to do</p>
          <p class="mt-2 text-xl font-semibold text-[#0b0b0b] dark:text-white sm:text-2xl">
            {{ loading ? '—' : waiting.toLocaleString() }}
          </p>
        </div>
      </div>

      <!-- What's going on. Answers the questions that previously needed
           someone to inspect the database: why is anything still pending,
           what is it waiting for, and when does it move. -->
      <section v-if="!loading && blockers.length" class="mt-6">
        <h3 class="mb-1 text-sm font-semibold text-[#0b0b0b] dark:text-white">
          Why {{ waiting.toLocaleString() }} address(es) are still waiting
        </h3>
        <p class="mb-3 text-xs text-[#898781]">
          Pauses are deliberate — they keep this server off mail-provider blocklists.
        </p>

        <ul class="space-y-2 sm:hidden">
          <li
            v-for="b in blockers"
            :key="b.domain"
            class="rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-3.5 dark:border-[#2c2c2a] dark:bg-[#1a1a19]"
          >
            <div class="flex items-baseline justify-between gap-2">
              <span class="truncate text-sm font-medium text-[#0b0b0b] dark:text-white">{{ b.domain }}</span>
              <span class="shrink-0 text-sm font-semibold text-[#0b0b0b] dark:text-white">{{ b.pending.toLocaleString() }}</span>
            </div>
            <p class="mt-1 text-xs text-[#52514e] dark:text-[#c3c2b7]">{{ b.reason }}</p>
            <p v-if="countdown(b.seconds_until)" class="mt-1 text-xs text-[#898781]">
              Resumes in {{ countdown(b.seconds_until) }}
            </p>
          </li>
        </ul>

        <div class="hidden overflow-x-auto rounded-lg border border-[#e1e0d9] dark:border-[#2c2c2a] sm:block">
          <table class="w-full min-w-[38rem] border-collapse bg-[#fcfcfb] text-sm dark:bg-[#1a1a19]">
            <thead>
              <tr class="border-b border-[#e1e0d9] text-left text-xs uppercase tracking-wide text-[#898781] dark:border-[#2c2c2a]">
                <th class="px-4 py-2.5 font-medium">Domain</th>
                <th class="px-4 py-2.5 font-medium">Waiting</th>
                <th class="px-4 py-2.5 font-medium">Why</th>
                <th class="px-4 py-2.5 font-medium">Resumes</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="b in blockers" :key="b.domain" class="border-b border-[#e1e0d9] last:border-0 dark:border-[#2c2c2a]">
                <td class="px-4 py-2.5 text-[#0b0b0b] dark:text-white">{{ b.domain }}</td>
                <td class="px-4 py-2.5 text-[#0b0b0b] dark:text-white">{{ b.pending.toLocaleString() }}</td>
                <td class="px-4 py-2.5 text-[#52514e] dark:text-[#c3c2b7]">{{ b.reason }}</td>
                <td class="px-4 py-2.5 text-[#898781]">{{ countdown(b.seconds_until) ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Proactive observations, so the numbers arrive already
           interpreted rather than needing to be asked about. -->
      <section v-if="!loading && insights.length" class="mt-6">
        <h3 class="mb-3 text-sm font-semibold text-[#0b0b0b] dark:text-white">What this means</h3>
        <ul class="space-y-2">
          <li
            v-for="(i, idx) in insights"
            :key="idx"
            class="rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-3.5 dark:border-[#2c2c2a] dark:bg-[#1a1a19] sm:p-4"
          >
            <div class="flex items-start gap-2.5">
              <span
                class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full"
                :style="{ backgroundColor: (INSIGHT_STYLE[i.level] ?? INSIGHT_STYLE.info).color }"
              />
              <div class="min-w-0">
                <p class="text-sm font-medium text-[#0b0b0b] dark:text-white">{{ i.title }}</p>
                <p class="mt-1 text-xs leading-relaxed text-[#52514e] dark:text-[#c3c2b7]">{{ i.detail }}</p>
              </div>
            </div>
          </li>
        </ul>
      </section>

      <!-- Domains the engine is deliberately treating differently. -->
      <section
        v-if="!loading && (flags.catch_all?.length || flags.unresponsive?.length || flags.cooling_down?.length)"
        class="mt-6 grid gap-3 sm:grid-cols-3 sm:gap-4"
      >
        <div class="rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-3.5 dark:border-[#2c2c2a] dark:bg-[#1a1a19] sm:p-4">
          <p class="text-xs font-medium text-[#52514e] dark:text-[#c3c2b7]">Catch-all domains</p>
          <p class="mt-1 mb-2 text-xs text-[#898781]">Resolved without SMTP</p>
          <ul class="space-y-1">
            <li v-for="d in flags.catch_all" :key="d.domain" class="flex justify-between gap-2 text-xs">
              <span class="truncate text-[#0b0b0b] dark:text-white">{{ d.domain }}</span>
              <span class="shrink-0 text-[#898781]">{{ d.confirmations.toLocaleString() }}</span>
            </li>
            <li v-if="!flags.catch_all?.length" class="text-xs text-[#898781]">None yet</li>
          </ul>
        </div>

        <div class="rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-3.5 dark:border-[#2c2c2a] dark:bg-[#1a1a19] sm:p-4">
          <p class="text-xs font-medium text-[#52514e] dark:text-[#c3c2b7]">Refusing connections</p>
          <p class="mt-1 mb-2 text-xs text-[#898781]">Paused, retried automatically</p>
          <ul class="space-y-1">
            <li v-for="d in flags.unresponsive" :key="d.domain" class="flex justify-between gap-2 text-xs">
              <span class="truncate text-[#0b0b0b] dark:text-white">{{ d.domain }}</span>
              <span class="shrink-0 text-[#898781]">{{ countdown(d.seconds_until) ?? '—' }}</span>
            </li>
            <li v-if="!flags.unresponsive?.length" class="text-xs text-[#898781]">None</li>
          </ul>
        </div>

        <div class="rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-3.5 dark:border-[#2c2c2a] dark:bg-[#1a1a19] sm:p-4">
          <p class="text-xs font-medium text-[#52514e] dark:text-[#c3c2b7]">Cooling down</p>
          <p class="mt-1 mb-2 text-xs text-[#898781]">Backing off after failures</p>
          <ul class="space-y-1">
            <li v-for="d in flags.cooling_down" :key="d.domain" class="flex justify-between gap-2 text-xs">
              <span class="truncate text-[#0b0b0b] dark:text-white">{{ d.domain }}</span>
              <span class="shrink-0 text-[#898781]">{{ countdown(d.seconds_until) ?? '—' }}</span>
            </li>
            <li v-if="!flags.cooling_down?.length" class="text-xs text-[#898781]">None</li>
          </ul>
        </div>
      </section>

      <!-- Throughput over the last hour: distinguishes "running slowly"
           from "not running", which the tiles alone can't show. -->
      <section v-if="!loading && stats?.throughput?.length" class="mt-6">
        <h3 class="mb-3 text-sm font-semibold text-[#0b0b0b] dark:text-white">Verified per minute (last hour)</h3>
        <div class="rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-3.5 dark:border-[#2c2c2a] dark:bg-[#1a1a19] sm:p-4">
          <div class="flex h-24 items-end gap-px overflow-hidden">
            <div
              v-for="p in stats.throughput"
              :key="p.minute"
              class="min-w-[2px] flex-1 rounded-t-sm bg-[#2a78d6]"
              :style="{ height: Math.max(2, (p.count / throughputPeak) * 100) + '%' }"
              :title="`${p.minute} — ${p.count}`"
            />
          </div>
          <div class="mt-2 flex justify-between text-xs text-[#898781]">
            <span>{{ stats.throughput[0]?.minute }}</span>
            <span>peak {{ throughputPeak }}/min</span>
            <span>{{ stats.throughput[stats.throughput.length - 1]?.minute }}</span>
          </div>
        </div>
      </section>

      <p v-if="!loading && !blockers.length && waiting === 0" class="mt-6 text-sm text-[#898781]">
        Nothing queued — every address has been processed.
      </p>
    </div>
  </AppShell>
</template>
