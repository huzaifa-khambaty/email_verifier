<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import api from '../lib/api'
import AppShell from '../layouts/AppShell.vue'

const STATUS_STYLE = {
  PENDING: { label: 'Pending', dot: '#898781' },
  IMPORTING: { label: 'Importing', dot: '#2a78d6' },
  COMPLETED: { label: 'Completed', dot: '#0ca30c' },
  FAILED: { label: 'Failed', dot: '#d03b3b' },
}

const batches = ref([])
const loading = ref(true)
const listError = ref('')

const pendingFile = ref(null)
const uploading = ref(false)
const uploadProgress = ref(0)
const uploadError = ref('')
const dragging = ref(false)
const fileInput = ref(null)

let pollHandle = null

async function fetchBatches() {
  try {
    const { data } = await api.get('/import-batches')
    batches.value = data.data ?? data
    listError.value = ''
  } catch {
    listError.value = 'Could not load import batches.'
  } finally {
    loading.value = false
  }
}

const hasActiveBatch = computed(() =>
  batches.value.some((b) => b.status === 'PENDING' || b.status === 'IMPORTING')
)

function onFilePicked(event) {
  const file = event.target.files?.[0]
  if (file) pendingFile.value = file
}

function onDrop(event) {
  dragging.value = false
  const file = event.dataTransfer?.files?.[0]
  if (file) pendingFile.value = file
}

function cancelPending() {
  pendingFile.value = null
  if (fileInput.value) fileInput.value.value = ''
}

async function upload() {
  if (!pendingFile.value) return

  uploading.value = true
  uploadError.value = ''
  uploadProgress.value = 0

  const form = new FormData()
  form.append('file', pendingFile.value)

  try {
    await api.post('/import-batches', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (e) => {
        if (e.total) uploadProgress.value = Math.round((e.loaded / e.total) * 100)
      },
    })
    cancelPending()
    await fetchBatches()
  } catch (e) {
    uploadError.value = e.response?.data?.message ?? 'Upload failed.'
  } finally {
    uploading.value = false
  }
}

function downloadErrors(batch) {
  window.open(`/api/import-batches/${batch.id}/errors`, '_blank')
}

function formatBytes(bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

onMounted(() => {
  fetchBatches()
  // Poll faster while something is actively importing, otherwise a slow
  // background check is enough — no point hammering the API for a list
  // that rarely changes once everything's settled.
  pollHandle = setInterval(() => {
    if (hasActiveBatch.value || document.visibilityState === 'visible') fetchBatches()
  }, 4000)
})

onUnmounted(() => {
  if (pollHandle) clearInterval(pollHandle)
})
</script>

<template>
  <AppShell>
    <div class="mx-auto max-w-3xl">
      <h2 class="mb-5 text-lg font-semibold text-[#0b0b0b] dark:text-white">Import emails</h2>

      <!-- Upload zone: tap-to-browse is the primary path (mobile has no
           drag-and-drop), drop-target is a bonus for desktop/mouse users. -->
      <div
        class="rounded-xl border-2 border-dashed p-6 text-center transition-colors sm:p-8"
        :class="dragging ? 'border-[#2a78d6] bg-[#e2e4ef]/40' : 'border-[#c3c2b7] dark:border-[#383835]'"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="onDrop"
      >
        <template v-if="!pendingFile">
          <svg class="mx-auto h-10 w-10 text-[#898781]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10" />
          </svg>
          <p class="mt-3 text-sm text-[#52514e] dark:text-[#c3c2b7]">
            Drop a CSV here, or
          </p>
          <button
            class="mt-3 rounded-md bg-[#2a78d6] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#256abf]"
            @click="fileInput.click()"
          >
            Choose file
          </button>
          <input ref="fileInput" type="file" accept=".csv,text/csv" class="hidden" @change="onFilePicked" />
          <p class="mt-3 text-xs text-[#898781]">Columns: First Name, Last Name, Email</p>
        </template>

        <template v-else>
          <p class="text-sm font-medium text-[#0b0b0b] dark:text-white">{{ pendingFile.name }}</p>
          <p class="text-xs text-[#898781]">{{ formatBytes(pendingFile.size) }}</p>

          <div v-if="uploading" class="mx-auto mt-4 max-w-xs">
            <div class="h-2 overflow-hidden rounded-full bg-[#e1e0d9] dark:bg-[#2c2c2a]">
              <div class="h-full bg-[#2a78d6] transition-all" :style="{ width: uploadProgress + '%' }" />
            </div>
            <p class="mt-1.5 text-xs text-[#898781]">Uploading… {{ uploadProgress }}%</p>
          </div>

          <div v-else class="mt-4 flex justify-center gap-2">
            <button
              class="rounded-md bg-[#2a78d6] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#256abf]"
              @click="upload"
            >
              Upload
            </button>
            <button
              class="rounded-md border border-[#c3c2b7] px-4 py-2.5 text-sm font-medium text-[#52514e] hover:bg-[#f0efec] dark:border-[#383835] dark:text-[#c3c2b7] dark:hover:bg-[#2c2c2a]"
              @click="cancelPending"
            >
              Cancel
            </button>
          </div>
        </template>
      </div>
      <p v-if="uploadError" class="mt-2 text-sm text-[#d03b3b]">{{ uploadError }}</p>

      <!-- Batch history -->
      <h3 class="mb-3 mt-8 text-sm font-semibold text-[#52514e] dark:text-[#c3c2b7]">Recent imports</h3>

      <p v-if="listError" class="text-sm text-[#d03b3b]">{{ listError }}</p>
      <p v-else-if="loading" class="text-sm text-[#898781]">Loading…</p>
      <p v-else-if="batches.length === 0" class="text-sm text-[#898781]">
        No imports yet — upload a CSV above to get started.
      </p>

      <ul v-else class="space-y-2">
        <li
          v-for="batch in batches"
          :key="batch.id"
          class="rounded-lg border border-[#e1e0d9] bg-[#fcfcfb] p-3.5 dark:border-[#2c2c2a] dark:bg-[#1a1a19] sm:p-4"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-[#0b0b0b] dark:text-white">{{ batch.original_filename }}</p>
              <div class="mt-1 flex items-center gap-1.5 text-xs text-[#898781]">
                <span
                  class="h-1.5 w-1.5 shrink-0 rounded-full"
                  :class="{ 'animate-pulse': batch.status === 'IMPORTING' }"
                  :style="{ backgroundColor: STATUS_STYLE[batch.status]?.dot }"
                />
                {{ STATUS_STYLE[batch.status]?.label ?? batch.status }}
              </div>
            </div>
            <button
              v-if="batch.error_count > 0"
              class="shrink-0 rounded-md border border-[#c3c2b7] px-2.5 py-1 text-xs font-medium text-[#52514e] hover:bg-[#f0efec] dark:border-[#383835] dark:text-[#c3c2b7] dark:hover:bg-[#2c2c2a]"
              @click="downloadErrors(batch)"
            >
              {{ batch.error_count }} error{{ batch.error_count === 1 ? '' : 's' }}
            </button>
          </div>

          <dl class="mt-3 grid grid-cols-3 gap-2 text-center">
            <div class="rounded-md bg-[#f0efec] py-1.5 dark:bg-[#2c2c2a]">
              <dt class="text-[10px] uppercase tracking-wide text-[#898781]">Imported</dt>
              <dd class="text-sm font-semibold text-[#0b0b0b] dark:text-white">{{ batch.imported_rows }}</dd>
            </div>
            <div class="rounded-md bg-[#f0efec] py-1.5 dark:bg-[#2c2c2a]">
              <dt class="text-[10px] uppercase tracking-wide text-[#898781]">Duplicates</dt>
              <dd class="text-sm font-semibold text-[#0b0b0b] dark:text-white">{{ batch.duplicate_count }}</dd>
            </div>
            <div class="rounded-md bg-[#f0efec] py-1.5 dark:bg-[#2c2c2a]">
              <dt class="text-[10px] uppercase tracking-wide text-[#898781]">Errors</dt>
              <dd class="text-sm font-semibold text-[#0b0b0b] dark:text-white">{{ batch.error_count }}</dd>
            </div>
          </dl>
        </li>
      </ul>
    </div>
  </AppShell>
</template>
