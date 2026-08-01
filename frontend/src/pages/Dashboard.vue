<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import api from '../lib/api'
import AppShell from '../layouts/AppShell.vue'

// Stat tiles per v2 §8. The "Charts" half of §8 (daily processed, status
// distribution, top domains) is deferred to the Dashboard milestone proper
// — building those correctly means running the dataviz skill's categorical
// palette + validator end to end, which is a separate pass from this
// bootstrap. These stat tiles use the skill's fixed status palette
// (references/palette.md) since that part is small enough to do now.
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

const stats = ref(null)
const loading = ref(true)
const error = ref('')
let pollHandle = null

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

onMounted(() => {
  fetchDashboard()
  pollHandle = setInterval(fetchDashboard, 30000)
})

onUnmounted(() => {
  if (pollHandle) clearInterval(pollHandle)
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

      <div class="mt-3 grid grid-cols-2 gap-3 sm:mt-4 sm:gap-4">
        <div class="rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-3.5 dark:border-[#2c2c2a] dark:bg-[#1a1a19] sm:p-4">
          <p class="text-xs font-medium text-[#52514e] dark:text-[#c3c2b7]">Queue speed</p>
          <p class="mt-2 text-xl font-semibold text-[#0b0b0b] dark:text-white sm:text-2xl">
            {{ loading ? '—' : `${stats?.queue_speed_per_min ?? 0}/min` }}
          </p>
        </div>
        <div class="rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-3.5 dark:border-[#2c2c2a] dark:bg-[#1a1a19] sm:p-4">
          <p class="text-xs font-medium text-[#52514e] dark:text-[#c3c2b7]">Active workers</p>
          <p class="mt-2 text-xl font-semibold text-[#0b0b0b] dark:text-white sm:text-2xl">
            {{ loading ? '—' : (stats?.active_workers ?? 0) }}
          </p>
        </div>
      </div>

      <p class="mt-6 text-xs text-[#898781]">
        Daily processed / status distribution / top domains charts land with the Dashboard milestone.
      </p>
    </div>
  </AppShell>
</template>
