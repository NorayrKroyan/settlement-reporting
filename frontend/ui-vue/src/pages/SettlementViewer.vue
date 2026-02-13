<template>
  <div class="settlement-page">
    <div class="viewer-topbar">
      <div>
        <h3 class="h3">Settlements List</h3>
      </div>

      <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <button :disabled="loading" @click="downloadListPdf">
          Download List PDF
        </button>
      </div>
    </div>

    <!-- Filters -->
    <div style="margin-bottom: 4px;">
      <div style="display:flex; gap:4px; align-items:end; flex-wrap:wrap;">
        <div style="min-width: 110px;">
          <label style="display:block; margin-bottom:4px;">Client</label>
          <select class="input" v-model="filterClient">
            <option value="">All Clients</option>
            <option v-for="c in clientOptions" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>

        <div style="min-width: 130px;">
          <label style="display:block; margin-bottom:4px;">Carrier</label>
          <select class="input" v-model="filterCarrier">
            <option value="">All Carriers</option>
            <option v-for="c in carrierOptions" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>

        <div style="display:flex; gap:4px; align-items:center;">
          <button class="input" style="width:auto; margin-top:0;" @click="clearFilters" :disabled="loading">
            Clear
          </button>
        </div>

        <div style="margin-left:auto; color:#666; font-size:12px;">
          Showing {{ pagedRows.length }} of {{ filteredRows.length }} (Total loaded: {{ rows.length }})
        </div>
      </div>
    </div>

    <div v-if="err" class="err">{{ err }}</div>

    <div class="card">
      <table class="table">
        <colgroup>
          <col style="width: 5%" />
          <col style="width: 10%" />
          <col style="width: 20%" />
          <col style="width: 14%" />
          <col style="width: 12%" />
          <col style="width: 12%" />
          <col style="width: 12%" />
          <col style="width: 10%" />
        </colgroup>

        <thead>
        <tr>
          <th class="th">ID</th>
          <th class="th">Client</th>
          <th class="th">Carrier</th>
          <th class="th">Date Range</th>
          <th class="th">Gross Amount</th>
          <th class="th">Factoring fee</th>
          <th class="th">Adjustments</th>
          <th class="th">Net Deposit</th>
          <th class="th">Action</th>
        </tr>
        </thead>

        <tbody>
        <tr v-if="loading">
          <td class="td" colspan="9"><span class="sub">Loading…</span></td>
        </tr>

        <tr v-else-if="filteredRows.length === 0">
          <td class="td" colspan="9"><span class="sub">No settlements found.</span></td>
        </tr>

        <tr
            v-else
            v-for="(r, idx) in pagedRows"
            :key="r.id"
            :class="idx % 2 === 1 ? 'row-alt' : ''"
        >
          <td class="td">{{ r.id }}</td>
          <td class="td">{{ r.client_name }}</td>
          <td class="td">{{ r.carrier_name }}</td>
          <td class="td">{{ r.startdate }} → {{ r.enddate }}</td>
          <td class="td">{{ money(r.grossamount) || '-' }}</td>
          <td class="td">{{ money(r.factorcostamount) || '-' }}</td>
          <td class="td">{{ money(r.chargebackamount) || '-' }}</td>
          <td class="td">{{ money(r.netamount) }}</td>
          <td class="td" style="text-align: center; white-space: nowrap">
            <button @click="editSettlement(r.id)">Edit</button>
          </td>
        </tr>
        </tbody>
      </table>

      <!-- Pagination -->
      <div
          style="display:flex; justify-content:space-between; align-items:center; margin-top:6px; gap:4px; flex-wrap:wrap;"
      >
        <div style="display:flex; align-items:center; gap:4px;">
          <span class="sub">Rows per page</span>
          <select class="sub" v-model.number="pageSize" style="width: 60px;">
            <option :value="10">10</option>
            <option :value="20">20</option>
            <option :value="50">50</option>
          </select>
        </div>

        <div class="sub">Page {{ page }} of {{ totalPages }}</div>

        <div style="display:flex; gap:4px; align-items:center;">
          <button class="sub" style="width:auto; margin-top:0;" @click="firstPage" :disabled="page <= 1">
            « First
          </button>
          <button class="sub" style="width:auto; margin-top:0;" @click="prevPage" :disabled="page <= 1">
            ‹ Prev
          </button>
          <button class="sub" style="width:auto; margin-top:0;" @click="nextPage" :disabled="page >= totalPages">
            Next ›
          </button>
          <button class="sub" style="width:auto; margin-top:0;" @click="lastPage" :disabled="page >= totalPages">
            Last »
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { computeRange } from '../utils/dateRange' // ✅ FIX: import missing before

const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://127.0.0.1:8000'
const api = (path) => `${API_BASE}${path}`

async function fetchJson(url, options) {
  const res = await fetch(url, options)
  const text = await res.text()

  try {
    const json = JSON.parse(text)
    if (!res.ok) throw new Error(json?.message || `Request failed (${res.status})`)
    return json
  } catch (e) {
    if (!res.ok) {
      const snippet = (text || '').slice(0, 200).replace(/\s+/g, ' ')
      throw new Error(`Request failed (${res.status}). Server returned: ${snippet}`)
    }
    throw e
  }
}

function money(n) {
  const x = Math.abs(Number(n || 0))
  return x.toLocaleString(undefined, { style: 'currency', currency: 'USD' })
}

const router = useRouter()
const rows = ref([])
const err = ref('')
const loading = ref(false)

// Filters
const filterClient = ref('')
const filterCarrier = ref('')

// Keep it for PDF filtering (if you later add UI select)
const dateRange = ref('Custom')

// Pagination
const page = ref(1)
const pageSize = ref(10)

// Options derived from loaded data
const clientOptions = computed(() => {
  const set = new Set()
  for (const r of rows.value || []) {
    const v = String(r?.client_name || '').trim()
    if (v) set.add(v)
  }
  return Array.from(set).sort((a, b) => a.localeCompare(b))
})

const carrierOptions = computed(() => {
  const set = new Set()
  for (const r of rows.value || []) {
    const v = String(r?.carrier_name || '').trim()
    if (v) set.add(v)
  }
  return Array.from(set).sort((a, b) => a.localeCompare(b))
})

// Apply filters client+carrier (exact match)
const filteredRows = computed(() => {
  const c = String(filterClient.value || '').trim()
  const k = String(filterCarrier.value || '').trim()

  return (rows.value || []).filter((r) => {
    const rc = String(r?.client_name || '').trim()
    const rk = String(r?.carrier_name || '').trim()
    if (c && rc !== c) return false
    if (k && rk !== k) return false
    return true
  })
})

const totalPages = computed(() => {
  const n = filteredRows.value.length
  return Math.max(1, Math.ceil(n / Number(pageSize.value || 10)))
})

const pagedRows = computed(() => {
  const p = Math.min(Math.max(1, Number(page.value || 1)), totalPages.value)
  const size = Number(pageSize.value || 10)
  const start = (p - 1) * size
  return filteredRows.value.slice(start, start + size)
})

// Reset to page 1 whenever filters or pageSize change
watch([filterClient, filterCarrier, pageSize], () => {
  page.value = 1
})

// Keep page within bounds if data changes
watch(totalPages, (tp) => {
  if (page.value > tp) page.value = tp
})

function clearFilters() {
  filterClient.value = ''
  filterCarrier.value = ''
  page.value = 1
}

function firstPage() {
  page.value = 1
}
function lastPage() {
  page.value = totalPages.value
}
function prevPage() {
  page.value = Math.max(1, page.value - 1)
}
function nextPage() {
  page.value = Math.min(totalPages.value, page.value + 1)
}

async function load() {
  err.value = ''
  loading.value = true
  try {
    const json = await fetchJson(api('/api/settlements/history?limit=200'), { method: 'GET' })
    rows.value = json?.data || []
  } catch (e) {
    err.value = e?.message || 'Failed to load settlement history'
  } finally {
    loading.value = false
  }
}

async function downloadListPdf() {
  err.value = ''
  try {
    const params = new URLSearchParams()

    // Keep consistent with UI loaded rows
    params.set('limit', '200')

    const c = String(filterClient.value || '').trim()
    const k = String(filterCarrier.value || '').trim()
    if (c) params.set('client', c)
    if (k) params.set('carrier', k)

    // Date range filters (only applies if dateRange != Custom in your backend)
    const r = computeRange(String(dateRange.value || 'Custom'), new Date())
    if (r?.start) params.set('start_date', r.start)
    if (r?.end) params.set('end_date', r.end)

    params.set('mode', 'all')

    const url = api(`/api/settlements/history/pdf?${params.toString()}`)

    const res = await fetch(url, { method: 'GET' })
    if (!res.ok) {
      const txt = await res.text().catch(() => '')
      throw new Error(`PDF download failed (${res.status}). ${txt?.slice(0, 160) || ''}`.trim())
    }

    const blob = await res.blob()
    const blobUrl = URL.createObjectURL(blob)

    const a = document.createElement('a')
    a.href = blobUrl
    a.download = `settlement_viewer_list_${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.pdf`
    document.body.appendChild(a)
    a.click()
    a.remove()

    setTimeout(() => URL.revokeObjectURL(blobUrl), 1500)
  } catch (e) {
    err.value = e?.message || 'Failed to download list PDF.'
  }
}

function editSettlement(id) {
  router.push({ path: '/', query: { edit: String(id), return: 'viewer' } })
}

onMounted(load)
</script>

<style>
/* DO NOT use scoped here — we want global table/button styles to apply. */
.viewer-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.viewer-refresh {
  width: auto;
  margin-top: 0;
  padding: 10px 14px;
}
.viewer-footer {
  margin-top: 10px;
}
</style>
