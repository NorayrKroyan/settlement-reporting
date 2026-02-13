<template>
  <div class="page">
    <div class="topbar">
      <div>
        <h2 class="h2">Inbound Load Matching (Queue)</h2>
        <div class="sub">
          Step 1 — match driver by Name and/or Truck. GREEN only if both confirm same driver.
        </div>
      </div>

      <div class="controls">
        <input
            class="input"
            v-model="q"
            placeholder="Search driver / truck / job / terminal / load #"
            @keydown.enter="load"
        />

        <select class="input" v-model="match" @change="load">
          <option value="">All</option>
          <option value="GREEN">GREEN</option>
          <option value="YELLOW">YELLOW</option>
          <option value="RED">RED</option>
        </select>

        <select class="input" v-model="only" @change="load">
          <option value="unprocessed">Unprocessed</option>
          <option value="all">All</option>
        </select>

        <button class="btn" :disabled="loading" @click="load">Refresh</button>
      </div>
    </div>

    <div v-if="err" class="err">{{ err }}</div>

    <div class="card">
      <table class="table">
        <thead>
        <tr>
          <th>ID</th>
          <th>Driver (import)</th>
          <th>Truck</th>
          <th>Jobname</th>
          <th>Terminal</th>
          <th>Load #</th>
          <th>Status</th>
          <th>Driver Match</th>
          <th>Confidence</th>
          <th>Pull Point</th>
          <th>Pad Location</th>
          <th>Journey</th>
          <th>Actions</th>
        </tr>
        </thead>

        <tbody>
        <tr v-for="r in rows" :key="r.import_id">
          <td class="mono">
            {{ r.import_id }}
            <div v-if="r.is_processed" class="pill pill-gray mt6">PROCESSED</div>
          </td>

          <td :title="r.raw_carrier || r.raw_original || ''">
            {{ r.driver_name || '—' }}
          </td>

          <td class="mono" :title="r.raw_truck || r.raw_original || ''">
            {{ r.truck_number || '—' }}
          </td>

          <td>{{ r.jobname || '—' }}</td>
          <td>{{ r.terminal || '—' }}</td>
          <td class="mono">{{ r.load_number || '—' }}</td>
          <td class="mono">{{ r.state || '—' }}</td>

          <td>
            <div class="small">
              <div><b>Status:</b> {{ r.match?.driver?.status || '—' }}</div>

              <div v-if="r.match?.driver?.resolved">
                <b>Resolved:</b>
                <span class="mono">id_driver={{ r.match.driver.resolved.id_driver }}</span>,
                <span class="mono">id_contact={{ r.match.driver.resolved.id_contact }}</span>,
                <span class="mono">id_vehicle={{ r.match.driver.resolved.id_vehicle ?? 'null' }}</span>
                <span class="pill pill-gray">{{ r.match.driver.resolved.method }}</span>
              </div>

              <div v-if="r.match?.driver?.notes" class="warn">{{ r.match.driver.notes }}</div>
            </div>
          </td>

          <td>
              <span :class="['pill', pillClass(r.match?.confidence)]">
                {{ r.match?.confidence || 'RED' }}
              </span>
          </td>

          <td class="small">
            <div><b>Status:</b> {{ r.match?.pull_point?.status || '—' }}</div>
            <div v-if="r.match?.pull_point?.resolved">
              <span class="mono">id={{ r.match.pull_point.resolved.id_pull_point }}</span>
              <div>{{ r.match.pull_point.resolved.pp_job }}</div>
              <span class="pill pill-gray">{{ r.match.pull_point.resolved.method }}</span>
            </div>
            <div v-else-if="r.match?.pull_point?.notes" class="warn">{{ r.match.pull_point.notes }}</div>
          </td>

          <td class="small">
            <div><b>Status:</b> {{ r.match?.pad_location?.status || '—' }}</div>
            <div v-if="r.match?.pad_location?.resolved">
              <span class="mono">id={{ r.match.pad_location.resolved.id_pad_location }}</span>
              <div>{{ r.match.pad_location.resolved.pl_job }}</div>
              <span class="pill pill-gray">{{ r.match.pad_location.resolved.method }}</span>
            </div>
            <div v-else-if="r.match?.pad_location?.notes" class="warn">{{ r.match.pad_location.notes }}</div>
          </td>

          <td class="small">
              <span
                  class="pill"
                  :class="r.match?.journey?.status === 'READY'
                  ? 'pill-green'
                  : (r.match?.journey?.status === 'PARTIAL' ? 'pill-yellow' : 'pill-red')"
              >
                {{ r.match?.journey?.status || 'NONE' }}
              </span>

            <div class="mono" v-if="r.match?.journey?.pull_point_id || r.match?.journey?.pad_location_id">
              pp={{ r.match.journey.pull_point_id ?? 'null' }},
              pl={{ r.match.journey.pad_location_id ?? 'null' }}
            </div>

            <div class="mono" v-if="r.match?.journey?.join_id">
              join={{ r.match.journey.join_id }}
            </div>
          </td>

          <td>
            <button
                class="btn2"
                :disabled="!canProcess(r) || processingId === r.import_id"
                @click="processRow(r)"
            >
              {{ processingId === r.import_id ? 'Processing...' : 'Process' }}
            </button>

            <div v-if="r.is_processed" class="small mt6">
              <div class="mono">load={{ r.processed_load_id ?? '—' }}</div>
              <div class="mono">detail={{ r.processed_load_detail_id ?? '—' }}</div>
            </div>
          </td>
        </tr>

        <tr v-if="rows.length === 0">
          <td colspan="13" class="empty">No rows found.</td>
        </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { fetchJson } from '../utils/api'

const rows = ref([])
const loading = ref(false)
const err = ref('')

const q = ref('')
const match = ref('GREEN')
const only = ref('unprocessed')

const processingId = ref(null)

function pillClass(c) {
  if (c === 'GREEN') return 'pill-green'
  if (c === 'YELLOW') return 'pill-yellow'
  return 'pill-red'
}

function canProcess(r) {
  if (!r || r.is_processed) return false
  const conf = r?.match?.confidence
  const journeyStatus = r?.match?.journey?.status
  const hasDriver = !!r?.match?.driver?.resolved?.id_driver
  return conf === 'GREEN' && journeyStatus === 'READY' && hasDriver
}

async function load() {
  err.value = ''
  loading.value = true
  try {
    const params = new URLSearchParams()
    params.set('limit', '200')
    params.set('only', only.value || 'unprocessed')
    if (q.value.trim()) params.set('q', q.value.trim())
    if (match.value) params.set('match', match.value)

    const res = await fetchJson(`/api/inbound-loads/queue?${params.toString()}`)
    rows.value = res?.rows || []
  } catch (e) {
    err.value = e?.message || String(e)
    rows.value = []
  } finally {
    loading.value = false
  }
}

async function processRow(r) {
  if (!canProcess(r)) return
  err.value = ''
  processingId.value = r.import_id
  try {
    const resp = await fetch('/api/inbound-loads/process', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ import_id: r.import_id }),
    })

    const res = await resp.json().catch(() => ({}))

    if (!resp.ok || !res?.ok) {
      throw new Error(res?.error || `Process failed (HTTP ${resp.status})`)
    }

    await load()
  } catch (e) {
    err.value = e?.message || String(e)
  } finally {
    processingId.value = null
  }
}

load()
</script>

<style scoped>
.page { padding: 16px; max-width: 1600px; margin: 0 auto; font-family: Arial, sans-serif; }
.topbar { display:flex; justify-content: space-between; gap: 12px; align-items: flex-start; margin-bottom: 12px; }
.h2 { margin: 0; }
.sub { font-size: 12px; color: #666; margin-top: 4px; }
.controls { display:flex; flex-direction: column; gap: 10px; align-items: stretch; min-width: 560px; }
.input { padding: 10px 12px; border: 1px solid #ccc; border-radius: 10px; width: 100%; }
.btn { padding: 10px 12px; border-radius: 10px; border: 1px solid #222; background:#111; color:#fff; cursor:pointer; width: 100%; }
.btn:disabled { opacity: .6; cursor: not-allowed; }

.btn2 { padding: 8px 10px; border-radius: 10px; border: 1px solid #bbb; background:#fff; cursor:pointer; }
.btn2:disabled { opacity: .5; cursor: not-allowed; }

.card { border: 1px solid #ddd; border-radius: 10px; background:#fff; overflow: auto; }
.table { width: 100%; border-collapse: collapse; font-size: 12px; }
.table th, .table td { border-bottom: 1px solid #eee; padding: 10px; vertical-align: top; }
.table th { position: sticky; top: 0; background: #fafafa; z-index: 2; text-align:left; }

.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; }
.small { font-size: 12px; }
.warn { color: #9a4a00; margin-top: 4px; }
.err { margin: 10px 0; padding: 10px; border: 1px solid #f2b8b5; background: #fff5f5; border-radius: 8px; color: #8a1f17; }
.empty { text-align:center; color:#666; padding: 18px; }

.pill { display:inline-block; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; border: 1px solid transparent; }
.pill-green { background: #e8fff1; color:#0f6a2f; border-color:#8be2ac; }
.pill-yellow { background: #fff8e1; color:#7a5a00; border-color:#f0d37a; }
.pill-red { background: #ffecec; color:#8a1f17; border-color:#f2b8b5; }
.pill-gray { background: #f1f1f1; color:#444; border-color:#ddd; margin-left: 6px; }

.mt6 { margin-top: 6px; }
</style>
