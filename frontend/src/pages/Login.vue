<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuth } from '../composables/useAuth'

const email = ref('')
const password = ref('')
const error = ref('')
const { login, loading } = useAuth()
const router = useRouter()

async function onSubmit() {
  error.value = ''
  try {
    await login(email.value, password.value)
    router.push('/')
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Login failed.'
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-[#f9f9f7] px-4 py-10 dark:bg-[#0d0d0d]">
    <form
      class="w-full max-w-sm space-y-5 rounded-xl border border-[#e1e0d9] bg-[#fcfcfb] p-6 shadow-sm dark:border-[#2c2c2a] dark:bg-[#1a1a19] sm:p-8"
      @submit.prevent="onSubmit"
    >
      <div class="text-center">
        <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-[#2a78d6]">
          <svg class="h-5 w-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
          </svg>
        </div>
        <h1 class="mt-3 text-lg font-semibold text-[#0b0b0b] dark:text-white">NextMatchMail</h1>
        <p class="text-xs text-[#898781]">Sign in to the admin dashboard</p>
      </div>

      <div class="space-y-1">
        <label class="block text-sm font-medium text-[#52514e] dark:text-[#c3c2b7]" for="email">Email</label>
        <input
          id="email"
          v-model="email"
          type="email"
          required
          autocomplete="username"
          autofocus
          class="w-full rounded-md border border-[#c3c2b7] bg-[#fcfcfb] px-3 py-2.5 text-sm text-[#0b0b0b] focus:border-[#2a78d6] focus:outline-none focus:ring-1 focus:ring-[#2a78d6] dark:border-[#383835] dark:bg-[#1a1a19] dark:text-white"
        />
      </div>

      <div class="space-y-1">
        <label class="block text-sm font-medium text-[#52514e] dark:text-[#c3c2b7]" for="password">Password</label>
        <input
          id="password"
          v-model="password"
          type="password"
          required
          autocomplete="current-password"
          class="w-full rounded-md border border-[#c3c2b7] bg-[#fcfcfb] px-3 py-2.5 text-sm text-[#0b0b0b] focus:border-[#2a78d6] focus:outline-none focus:ring-1 focus:ring-[#2a78d6] dark:border-[#383835] dark:bg-[#1a1a19] dark:text-white"
        />
      </div>

      <p v-if="error" class="text-sm text-[#d03b3b]">{{ error }}</p>

      <button
        type="submit"
        :disabled="loading"
        class="w-full rounded-md bg-[#2a78d6] px-3 py-2.5 text-sm font-medium text-white hover:bg-[#256abf] disabled:opacity-50"
      >
        {{ loading ? 'Signing in…' : 'Sign in' }}
      </button>
    </form>
  </div>
</template>
