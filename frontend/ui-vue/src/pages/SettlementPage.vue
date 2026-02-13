<!-- src/pages/SettlementPage.vue -->
<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { fetchJson } from '../utils/api'
import { money } from '../utils/format'
import { computeRange } from '../utils/dateRange'

const route = useRoute()
const router = useRouter()

function pad2(n) {
  const x = Number(n || 0)
  return x < 10 ? `0${x}` : `${x}`
}

// Accepts: "YYYY-MM-DD", "MM-DD-YYYY", "MM/DD/YYYY"
function formatMMDDYYYY(s) {
  if (!s) return ''
  const str = String(s).trim()

  const m1 = str.match(/^(\d{4})-(\d{2})-(\d{2})$/) // YYYY-MM-DD
  if (m1) return `${m1[2]}-${m1[3]}-${m1[1]}`

  const m2 = str.match(/^(\d{2})-(\d{2})-(\d{4})$/) // MM-DD-YYYY
  if (m2) return `${m2[1]}-${m2[2]}-${m2[3]}`

  const m3 = str.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/) // M/D/YYYY
  if (m3) return `${pad2(m3[1])}-${pad2(m3[2])}-${m3[3]}`

  return str
}

function parseDateAnyToTs(s) {
  if (!s) return 0
  const str = String(s).trim()

  const a = str.match(/^(\d{4})-(\d{2})-(\d{2})$/)
  if (a) return new Date(Number(a[1]), Number(a[2]) - 1, Number(a[3])).getTime()

  const b = str.match(/^(\d{2})-(\d{2})-(\d{4})$/)
  if (b) return new Date(Number(b[3]), Number(b[1]) - 1, Number(b[2])).getTime()

  const c = str.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/)
  if (c) return new Date(Number(c[3]), Number(c[1]) - 1, Number(c[2])).getTime()

  const t = Date.parse(str)
  return Number.isFinite(t) ? t : 0
}

function safeFilePart(v) {
  return (
      String(v ?? '')
          .trim()
          .replaceAll(/[^a-zA-Z0-9._-]+/g, '_')
          .replaceAll(/_+/g, '_')
          .replaceAll(/^_+|_+$/g, '')
          .slice(0, 60) || 'settlement'
  )
}

// ---------------- state ----------------
const clients = ref([])
const carriers = ref([])

const clientId = ref(null)
const carrierId = ref(null)

const clientName = ref('')
const carrierName = ref('')

const dateRange = ref('This Quarter')
const startDate = ref('2026-01-01')
const endDate = ref('2026-03-31')

const depositDate = ref('')
const factorPercent = ref('2.5')

const building = ref(false)
const downloading = ref(false)
const err = ref('')
const settlement = ref(null)

const reportRef = ref(null)

// Edit mode
const editingId = ref(null) // number|null
const prefilling = ref(false)

// ---------------- derived ----------------
const isEditing = computed(() => Number.isFinite(Number(editingId.value)) && Number(editingId.value) > 0)

const canBuild = computed(() => {
  return (
      !building.value &&
      String(clientName.value || '').trim() !== '' &&
      String(carrierName.value || '').trim() !== '' &&
      String(startDate.value || '').trim() !== '' &&
      String(endDate.value || '').trim() !== ''
  )
})

const factorDeduction = computed(() => -Number(settlement.value?.factorcostamount || 0))

const driverRows = computed(() => {
  return [...(settlement.value?.driver_rows || [])].map((r) => ({
    ...r,
    _tons: Number(r.tons || 0).toFixed(2),
  }))
})

const loadRowsDecorated = computed(() => {
  const rows = [...(settlement.value?.load_rows || [])]
  rows.sort((a, b) => {
    const da = String(a.driver || '').toLowerCase()
    const db = String(b.driver || '').toLowerCase()
    if (da < db) return -1
    if (da > db) return 1
    return parseDateAnyToTs(a.date) - parseDateAnyToTs(b.date)
  })

  let currentDriver = null
  let driverBand = 0
  return rows.map((r) => {
    const drv = String(r.driver || '')
    if (drv !== currentDriver) {
      currentDriver = drv
      driverBand += 1
    }
    return { ...r, _band: driverBand }
  })
})

// ✅ FIX: while editing, show period from FORM values so it changes immediately
const periodText = computed(() => {
  const s = settlement.value
  const a = isEditing.value ? startDate.value : (s?.startdate || '')
  const b = isEditing.value ? endDate.value : (s?.enddate || '')
  if (!a || !b) return ''
  return `${formatMMDDYYYY(a)} → ${formatMMDDYYYY(b)}`
})

// ---------------- watchers / lifecycle ----------------
watch(
    dateRange,
    (v) => {
      const r = computeRange(String(v || 'Custom'), new Date())
      if (!r?.isCustom && r?.start && r?.end) {
        startDate.value = r.start
        endDate.value = r.end
      }
    },
    { immediate: true }
)

function onStartDateChange(v) {
  if (dateRange.value !== 'Custom') dateRange.value = 'Custom'
  startDate.value = v
}

function onEndDateChange(v) {
  if (dateRange.value !== 'Custom') dateRange.value = 'Custom'
  endDate.value = v
}

async function loadClients() {
  const d = await fetchJson('/api/lookups/clients', { method: 'GET' })
  clients.value = d?.data || []
}

async function loadCarriersForClient(client) {
  const d = await fetchJson(`/api/lookups/carriers?client_name=${encodeURIComponent(client)}`, { method: 'GET' })
  carriers.value = d?.data || []
}

onMounted(async () => {
  err.value = ''
  try {
    await loadClients()
  } catch (e) {
    err.value = e?.message || 'Failed to load clients'
  }

  const qid = route.query.edit
  if (qid) await startEdit(qid)
})

watch(clientName, async (v) => {
  if (prefilling.value) return

  const val = String(v || '').trim()
  carrierName.value = ''
  carriers.value = []
  clientId.value = null
  carrierId.value = null

  if (!val) return

  try {
    await loadCarriersForClient(val)
  } catch (e) {
    err.value = e?.message || 'Failed to load carriers'
  }
})

watch(
    () => route.query.edit,
    async (newId) => {
      if (!newId) {
        editingId.value = null
        return
      }
      await startEdit(newId)
    }
)

async function startEdit(idAny) {
  const id = Number(idAny)
  if (!Number.isFinite(id) || id <= 0) return

  err.value = ''
  settlement.value = null
  editingId.value = id

  prefilling.value = true
  try {
    const view = await fetchJson(`/api/settlements/${id}`, { method: 'GET' })
    const s = view?.data
    if (!s) throw new Error('Settlement not found')

    clientId.value = s.clientid ?? null
    carrierId.value = s.carrierid ?? null

    // Fill form fields (ALL EDITABLE)
    clientName.value = String(s.client_name || '')
    carriers.value = []
    if (clientName.value.trim()) {
      await loadCarriersForClient(clientName.value.trim())
    }
    carrierName.value = String(s.carrier_name || '')

    startDate.value = String(s.startdate || startDate.value)
    endDate.value = String(s.enddate || endDate.value)
    dateRange.value = 'Custom'

    factorPercent.value = String(s.factorpercent ?? factorPercent.value)

    const storedDeposit = s.deposit_date || s.expectdepositdate || s.paiddate || ''
    depositDate.value = storedDeposit ? String(storedDeposit) : ''

    // show current settlement on right
    settlement.value = s
  } catch (e) {
    err.value = e?.message || 'Failed to load settlement for edit'
    editingId.value = null
  } finally {
    prefilling.value = false
  }
}

function cancelEdit() {
  editingId.value = null
  router.push('/settlements/viewer')
  settlement.value = null
  err.value = ''
}

// ---------------- actions ----------------
async function build() {
  err.value = ''

  const c = String(clientName.value || '').trim()
  const k = String(carrierName.value || '').trim()

  if (!c || !k) {
    err.value = 'Select client and carrier'
    return
  }

  // Always derive final range from dateRange unless Custom
  const r = computeRange(String(dateRange.value || 'Custom'), new Date())
  const finalStart = r && !r.isCustom ? r.start : String(startDate.value || '').trim()
  const finalEnd = r && !r.isCustom ? r.end : String(endDate.value || '').trim()

  if (!finalStart || !finalEnd) {
    err.value = 'Select start and end dates'
    return
  }

  building.value = true
  try {
    const payload = {
      client_name: c,
      carrier_name: k,

      client_id: clientId.value,
      carrier_id: carrierId.value,

      start_date: finalStart,
      end_date: finalEnd,

      deposit_date: String(depositDate.value || '').trim() || null,
      factor_percent: Number(factorPercent.value || 0),

      // backend accepts but ALWAYS creates new row
      force_rebuild: !!isEditing.value,
      base_settlement_id: isEditing.value ? Number(editingId.value) : null,
    }

    const data = await fetchJson('/api/settlements/build', {
      method: 'POST',
      data: payload,
    })

    const newId = data?.data?.id || data?.id || data?.settlement_id
    if (!newId) throw new Error('Build succeeded but no settlement id returned.')

    // Load the new settlement for preview (optional)
    const view = await fetchJson(`/api/settlements/${newId}`, { method: 'GET' })
    settlement.value = view?.data || null

    // ✅ REQUIRED: if we were editing, redirect to viewer after creating NEW row
    if (isEditing.value) {
      editingId.value = null
      await router.replace({ path: '/', query: {} })
      await router.push('/settlements/viewer')
      return
    }
  } catch (e) {
    const msg =
        e?.response?.data?.message ||
        (e?.response?.data?.errors ? JSON.stringify(e.response.data.errors) : null) ||
        e?.message ||
        'Build failed'
    err.value = msg
  } finally {
    building.value = false
  }
}

async function downloadCurrentSettlementPdf() {
  err.value = ''
  if (!settlement.value) {
    err.value = 'Build a settlement first, then download it.'
    return
  }

  const id = settlement.value.id || settlement.value.id_bill_settlement || settlement.value.id_bill_settlements
  if (!id) {
    err.value = 'Missing settlement id. Rebuild the settlement.'
    return
  }

  downloading.value = true
  try {
    const fileName =
        [
          'settlement',
          safeFilePart(settlement.value.client_name),
          safeFilePart(settlement.value.carrier_name),
          safeFilePart(settlement.value.startdate),
          safeFilePart(settlement.value.enddate),
        ]
            .filter(Boolean)
            .join('_') + '.pdf'

    const API_BASE = import.meta.env.VITE_API_BASE_URL || 'http://127.0.0.1:8000'
    const pdfUrl = `${API_BASE}/api/settlements/${id}/pdf`

    const res = await fetch(pdfUrl, { method: 'GET' })
    if (!res.ok) {
      const txt = await res.text().catch(() => '')
      throw new Error(`PDF download failed (${res.status}). ${txt?.slice(0, 160) || ''}`.trim())
    }

    const blob = await res.blob()
    if (!String(blob.type || '').includes('pdf')) {
      const txt = await blob.text().catch(() => '')
      throw new Error(`PDF download failed (not a PDF). Server returned: ${(txt || '').slice(0, 160)}`)
    }

    const blobUrl = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = blobUrl
    a.download = fileName
    document.body.appendChild(a)
    a.click()
    a.remove()

    setTimeout(() => URL.revokeObjectURL(blobUrl), 1500)
  } catch (e) {
    err.value = e?.message || 'Failed to download PDF.'
  } finally {
    downloading.value = false
  }
}
</script>

<template>
  <div class="settlement-page">
    <div class="settlement-grid">
      <!-- LEFT -->
      <div class="card">
        <h3 class="h3">Control Center</h3>

        <div v-if="isEditing" class="edit-banner">
          <div class="edit-title">Editing Settlement #{{ editingId }}</div>
          <div class="edit-actions">
            <button class="btn btn-secondary small" @click="cancelEdit" :disabled="building">Cancel</button>
          </div>
        </div>

        <label class="label">Client</label>
        <select class="input" v-model="clientName">
          <option disabled value="">-- Select client --</option>
          <option v-for="c in clients" :key="c" :value="c">{{ c }}</option>
        </select>

        <div class="spacer-12" />

        <label class="label">Carrier</label>
        <select class="input" v-model="carrierName" :disabled="!clientName">
          <option disabled value="">-- Select carrier --</option>
          <option v-for="c in carriers" :key="c" :value="c">{{ c }}</option>
        </select>

        <div style="margin-top: 12px">
          <label class="label">Date Range</label>
          <select class="input" v-model="dateRange">
            <option value="Today">Today</option>
            <option value="Yesterday">Yesterday</option>
            <option value="This Week">This Week</option>
            <option value="Last Week">Last Week</option>
            <option value="2 Weeks Ago">2 Weeks Ago</option>
            <option value="3 Weeks Ago">3 Weeks Ago</option>
            <option value="This Month">This Month</option>
            <option value="Last Month">Last Month</option>
            <option value="This Quarter">This Quarter</option>
            <option value="Last Quarter">Last Quarter</option>
            <option value="Year To Date">Year To Date</option>
            <option value="Custom">Custom</option>
          </select>
        </div>

        <div class="grid-2">
          <div>
            <label class="label">Start</label>
            <input class="input" type="date" v-model="startDate" @change="onStartDateChange(startDate)" />
          </div>
          <div>
            <label class="label">End</label>
            <input class="input" type="date" v-model="endDate" @change="onEndDateChange(endDate)" />
          </div>
        </div>

        <div style="margin-top: 12px">
          <label class="label">Deposit Date</label>
          <input class="input" type="date" v-model="depositDate" />
        </div>

        <div style="margin-top: 12px">
          <label class="label">Factoring %</label>
          <input class="input" v-model="factorPercent" />
        </div>

        <button class="btn" @click="build" :disabled="!canBuild">
          {{ building ? (isEditing ? 'Saving…' : 'Building…') : (isEditing ? 'Save Revision' : 'Build Settlement') }}
        </button>

        <button
            class="btn btn-secondary"
            @click="downloadCurrentSettlementPdf"
            :disabled="!settlement || downloading"
            :title="!settlement ? 'Build a settlement first' : 'Download the current settlement preview as PDF'"
        >
          {{ downloading ? 'Preparing PDF…' : 'Download Current Settlement' }}
        </button>

        <div v-if="err" class="err">{{ err }}</div>
      </div>

      <!-- RIGHT -->
      <div class="card">
        <div ref="reportRef" class="report-root">
          <div class="grid-report-top">
            <div>
              <h3 class="h3" style="margin-bottom: 6px">Settlement Report</h3>
            </div>
          </div>

          <div v-if="!settlement" class="sub">No settlement built yet.</div>

          <template v-else>
            <div class="hr" />

            <div class="grid-3">
              <div>
                <div class="mono-small">Client</div>
                <div class="bold-700">{{ settlement.client_name }}</div>
              </div>
              <div>
                <div class="mono-small">Carrier</div>
                <div class="bold-700">{{ settlement.carrier_name }}</div>
              </div>
              <div>
                <div class="mono-small">Period</div>
                <div class="bold-700">{{ periodText }}</div>
              </div>
            </div>

            <div class="hr" />

            <div class="grid-report">
              <div>
                <div class="section-title">Driver Earnings</div>

                <table class="table">
                  <colgroup>
                    <col style="width: 34%" />
                    <col style="width: 14%" />
                    <col style="width: 14%" />
                    <col style="width: 14%" />
                    <col style="width: 24%" />
                  </colgroup>
                  <thead>
                  <tr>
                    <th class="th">Driver</th>
                    <th class="th num">Loads</th>
                    <th class="th num">Tons</th>
                    <th class="th num">Miles</th>
                    <th class="th num">Revenue</th>
                  </tr>
                  </thead>
                  <tbody>
                  <tr v-for="(r, idx) in driverRows" :key="idx" :class="idx % 2 === 1 ? 'row-alt' : ''">
                    <td class="td">{{ r.driver }}</td>
                    <td class="td num">{{ r.loads }}</td>
                    <td class="td num">{{ r._tons }}</td>
                    <td class="td num">{{ r.miles }}</td>
                    <td class="td num">{{ money(r.revenue) }}</td>
                  </tr>

                  <tr v-if="!(settlement.driver_rows || []).length">
                    <td class="td" colspan="5">
                      <span class="sub">No driver rows found for this period.</span>
                    </td>
                  </tr>
                  </tbody>
                </table>
              </div>

              <div class="totals-box">
                <div class="section-title">Totals</div>

                <div class="totals-row">
                  <div class="totals-label">Total Driver Earnings</div>
                  <div class="totals-val">{{ money(settlement.grossamount) }}</div>
                </div>

                <div class="totals-row">
                  <div class="totals-label">Factoring Fee ({{ Number(settlement.factorpercent || 0).toFixed(2) }}%)</div>
                  <div class="totals-val">{{ money(factorDeduction) }}</div>
                </div>

                <div class="totals-row">
                  <div class="totals-label">Misc Charges &amp; Credits</div>
                  <div class="totals-val">{{ money(settlement.misc_total || 0) }}</div>
                </div>

                <div class="hr" style="margin: 10px 0" />

                <div class="totals-row net-row">
                  <div class="net-label">Net Deposit</div>
                  <div class="net-value">{{ money(settlement.netamount) }}</div>
                </div>
                <div class="totals-row net-row">
                  <div class="net-label">Deposit Date</div>
                  <div class="net-value">{{ depositDate }}</div>
                </div>
              </div>
            </div>

            <div class="hr" />

            <div class="misc-wrap">
              <div class="misc-left">
                <div class="section-title">Misc Charges and Credits</div>

                <div v-if="(settlement.misc_rows || []).length === 0" class="sub">No misc charges/credits yet.</div>

                <table v-else class="table">
                  <colgroup>
                    <col style="width: 44%" />
                    <col style="width: 18%" />
                    <col style="width: 18%" />
                    <col style="width: 20%" />
                  </colgroup>
                  <thead>
                  <tr>
                    <th class="th">Description</th>
                    <th class="th">Date</th>
                    <th class="th num">Charge</th>
                    <th class="th num">Credit</th>
                  </tr>
                  </thead>
                  <tbody>
                  <tr v-for="(m, idx) in settlement.misc_rows || []" :key="m.id ?? idx" :class="idx % 2 === 1 ? 'row-alt' : ''">
                    <td class="td">
                      {{ m.charge_source_desc ? `${m.description} — ${m.charge_source_desc}` : m.description }}
                    </td>
                    <td class="td">{{ formatMMDDYYYY(m.date || '-') }}</td>
                    <td class="td num">{{ money(m.charge || 0) }}</td>
                    <td class="td num">{{ money(m.credit || 0) }}</td>
                  </tr>

                  <tr>
                    <td class="td" colspan="2"><b>Total Misc</b></td>
                    <td class="td num"><b>{{ money(settlement.misc_charge_total || 0) }}</b></td>
                    <td class="td num"><b>{{ money(settlement.misc_credit_total || 0) }}</b></td>
                  </tr>

                  <tr>
                    <td class="td" colspan="2"><span class="sub">Credits − Charges</span></td>
                    <td class="td num" colspan="2"><b>{{ money(settlement.misc_total || 0) }}</b></td>
                  </tr>
                  </tbody>
                </table>
              </div>

              <div class="misc-watermark">
                <div class="draft-watermark">DRAFT COPY<br />FOR REVIEW</div>
              </div>
            </div>

            <div class="hr" />

            <div>
              <div class="section-title">Load Details</div>

              <table class="table">
                <colgroup>
                  <col style="width: 16%" />
                  <col style="width: 14%" />
                  <col style="width: 14%" />
                  <col style="width: 14%" />
                  <col style="width: 14%" />
                  <col style="width: 14%" />
                  <col style="width: 14%" />
                </colgroup>

                <thead>
                <tr>
                  <th class="th">Driver</th>
                  <th class="th">Date</th>
                  <th class="th">Load #</th>
                  <th class="th">Ticket #</th>
                  <th class="th num">Tons</th>
                  <th class="th num">Miles</th>
                  <th class="th num">Carrier Pay</th>
                </tr>
                </thead>

                <tbody>
                <tr v-for="l in loadRowsDecorated" :key="l.id_load" :class="l._band % 2 === 0 ? 'row-band-alt' : ''">
                  <td class="td">{{ l.driver }}</td>
                  <td class="td">{{ formatMMDDYYYY(l.date) }}</td>
                  <td class="td">{{ l.load_number || '-' }}</td>
                  <td class="td">{{ l.ticket_number || '-' }}</td>
                  <td class="td num">{{ Number(l.tons || 0).toFixed(2) }}</td>
                  <td class="td num">{{ l.miles }}</td>
                  <td class="td num">{{ money(l.carrier_pay) }}</td>
                </tr>

                <tr v-if="!(settlement.load_rows || []).length">
                  <td class="td" colspan="7">
                    <span class="sub">No loads linked to this settlement.</span>
                  </td>
                </tr>
                </tbody>
              </table>
            </div>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>
