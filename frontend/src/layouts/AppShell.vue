<script setup>
import { useAuth } from '../composables/useAuth'

const NAV = [
  {
    to: '/',
    label: 'Dashboard',
    icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
  },
  {
    to: '/imports',
    label: 'Imports',
    icon: 'M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10',
  },
]

const { user, logout } = useAuth()
</script>

<template>
  <div class="min-h-screen bg-[#f9f9f7] dark:bg-[#0d0d0d]">
    <!-- Top bar: identity + sign out, present at every breakpoint -->
    <header class="sticky top-0 z-20 flex items-center justify-between border-b border-[#e1e0d9] bg-[#fcfcfb]/95 px-4 py-3 backdrop-blur dark:border-[#2c2c2a] dark:bg-[#1a1a19]/95 sm:px-6">
      <h1 class="text-base font-semibold text-[#0b0b0b] dark:text-white">NextMatchMail</h1>
      <div class="flex items-center gap-3 text-sm text-[#52514e] dark:text-[#c3c2b7]">
        <span class="hidden sm:inline">{{ user?.email }}</span>
        <button
          class="rounded-md border border-[#c3c2b7] px-3 py-1.5 text-xs hover:bg-[#f0efec] dark:border-[#383835] dark:hover:bg-[#2c2c2a] sm:text-sm"
          @click="logout"
        >
          Sign out
        </button>
      </div>
    </header>

    <div class="sm:flex">
      <!-- Desktop/tablet sidebar (mobile gets the bottom tab bar instead) -->
      <nav class="hidden w-56 shrink-0 border-r border-[#e1e0d9] p-4 dark:border-[#2c2c2a] sm:block">
        <RouterLink
          v-for="item in NAV"
          :key="item.to"
          :to="item.to"
          class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-[#52514e] hover:bg-[#f0efec] dark:text-[#c3c2b7] dark:hover:bg-[#2c2c2a]"
          active-class="!bg-[#e2e4ef] !text-[#0b0b0b] dark:!bg-[#2c2c2a] dark:!text-white"
        >
          <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
          </svg>
          {{ item.label }}
        </RouterLink>
      </nav>

      <!-- pb-20 reserves room for the fixed mobile tab bar so content isn't hidden behind it -->
      <main class="flex-1 px-4 py-6 pb-20 sm:px-6 sm:pb-6">
        <slot />
      </main>
    </div>

    <!-- Mobile bottom tab bar -->
    <nav class="fixed inset-x-0 bottom-0 z-20 flex border-t border-[#e1e0d9] bg-[#fcfcfb] pb-[env(safe-area-inset-bottom)] dark:border-[#2c2c2a] dark:bg-[#1a1a19] sm:hidden">
      <RouterLink
        v-for="item in NAV"
        :key="item.to"
        :to="item.to"
        class="flex flex-1 flex-col items-center gap-1 py-2.5 text-[11px] font-medium text-[#898781]"
        active-class="!text-[#2a78d6] dark:!text-[#3987e5]"
      >
        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
        </svg>
        {{ item.label }}
      </RouterLink>
    </nav>
  </div>
</template>
