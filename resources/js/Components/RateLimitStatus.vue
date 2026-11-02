<script setup>
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import FocusSheet from '@/Components/FocusSheet.vue';
import { rateLimitSummary } from '@/rateLimit';
import { formatLocalTime } from '@/dateTime';

const props = defineProps({ agentId: { type: Number, default: null } });
const open = ref(false);
const snapshot = ref(null);
const failed = ref(false);
const now = ref(Date.now());
const receivedAt = ref(0);
let timer;
let request;
const stale = computed(() => snapshot.value && now.value - receivedAt.value > 15000);
const summary = computed(() => failed.value || stale.value
    ? { level: 'unknown', label: 'Unavailable', message: 'Could not get a current budget. Slow down until status returns.' }
    : rateLimitSummary(snapshot.value));
const shortLabel = computed(() => ({
    'Room available': 'Ready', 'Slow down': 'Slow', 'Cooling down': 'Wait',
    'Unavailable': 'Unknown', 'No API data': 'No data', 'Practice': 'Offline', 'Pacing off': 'Pacing off', 'Checking': '…',
})[summary.value.label]);
const cooldown = computed(() => Math.max(0, Math.ceil((summary.value.cooldown || 0) - (now.value - receivedAt.value) / 1000)));
const buckets = computed(() => Object.entries(snapshot.value?.buckets || {}));
const number = value => Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 });
const time = seconds => formatLocalTime(new Date(seconds * 1000).toISOString());
async function refresh() {
    if (document.hidden || request) return;
    const controller = new AbortController();
    request = controller;
    try {
        const response = await axios.get(route('game.rate-limit'), {
            params: props.agentId ? { agent: props.agentId } : {}, signal: controller.signal, timeout: 10000,
        });
        if (request !== controller) return;
        snapshot.value = response.data;
        receivedAt.value = Date.now();
        now.value = receivedAt.value;
        failed.value = false;
    } catch (error) {
        if (!controller.signal.aborted) failed.value = true;
    } finally {
        if (request === controller) request = null;
    }
}
function reset() {
    request?.abort();
    request = null;
    snapshot.value = null;
    failed.value = false;
    refresh();
}
watch(() => props.agentId, reset);
onMounted(() => {
    refresh();
    timer = setInterval(() => { now.value = Date.now(); refresh(); }, 5000);
    document.addEventListener('visibilitychange', refresh);
});
onBeforeUnmount(() => {
    clearInterval(timer);
    request?.abort();
    document.removeEventListener('visibilitychange', refresh);
});
</script>

<template>
    <div class="rate-status" :data-level="summary.level">
        <button type="button" class="rate-trigger" :aria-label="`API rate limit: ${summary.label}. View details`" @click="open = true">
            <span class="rate-dot" aria-hidden="true" /> API <span class="rate-long">{{ summary.label }}</span><span class="rate-short">{{ shortLabel }}</span>
        </button>
        <FocusSheet :open="open" title="API rate limit" @close="open = false">
            <section class="rate-details" aria-label="Current API request budget">
                <p class="rate-verdict" role="status">{{ summary.label }}<span v-if="summary.cooldown"> · {{ cooldown ? `${cooldown}s remaining` : 'Checking budget' }}</span></p>
                <p>{{ summary.message }}</p>
                <p class="rate-note">Updates every 5 seconds while this tab is visible. Checking this panel makes no SpaceTraders API requests.</p>
                <button type="button" class="app-button app-button-secondary" @click="refresh">Refresh status</button>
                <p v-if="snapshot" class="rate-note">Budget checked <time :datetime="new Date(snapshot.as_of * 1000).toISOString()">{{ time(snapshot.as_of) }}</time><span v-if="stale || failed"> · Previous snapshot</span></p>
                <article v-for="[key, bucket] in buckets" :key="key" class="rate-bucket">
                    <h3>{{ key === 'host' ? 'Shared host budget' : 'Selected account budget' }}</h3>
                    <p class="rate-note">{{ key === 'host' ? 'All requests from this app, including workers and other agents.' : 'Shared by this account’s agents in this app.' }}</p>
                    <div class="rate-capacity"><strong>{{ number(bucket.available) }} / {{ number(bucket.burst_capacity) }}</strong><span>local burst capacity available</span></div>
                    <meter :value="bucket.available" min="0" :max="bucket.burst_capacity" :aria-label="`${key} local burst capacity available`" />
                    <dl>
                        <div><dt>Recent pace · 5 seconds</dt><dd>{{ number(bucket.requests_per_second_last_5) }} req/s</dd></div>
                        <div><dt>Average pace · 60 seconds</dt><dd>{{ number(bucket.requests_per_second_last_60) }} req/s</dd></div>
                        <div><dt>Local sustained allowance</dt><dd>{{ number(bucket.sustained_per_second) }} req/s</dd></div>
                        <div><dt>Requests · last 60 seconds</dt><dd>{{ number(bucket.requests_last_60_seconds) }}</dd></div>
                        <div><dt>Rate-limit responses · recorded total</dt><dd>{{ number(bucket.rate_limits) }}</dd></div>
                        <div><dt>Local deferrals · recorded total</dt><dd>{{ number(bucket.deferrals) }}</dd></div>
                    </dl>
                    <template v-if="bucket.last_response">
                        <h4>Last server report</h4>
                        <p class="rate-note">{{ bucket.last_response.scope }} · HTTP {{ bucket.last_response.status }} · <time :datetime="new Date(bucket.last_response.observed_at * 1000).toISOString()">{{ time(bucket.last_response.observed_at) }}</time></p>
                        <dl>
                            <div><dt>Reported rate</dt><dd>{{ bucket.last_response.per_second === null ? 'Not supplied' : `${number(bucket.last_response.per_second)} req/s` }}</dd></div>
                            <div><dt>Reported burst</dt><dd>{{ bucket.last_response.burst === null ? 'Not supplied' : number(bucket.last_response.burst) }}</dd></div>
                            <div><dt>Reported remaining · advisory</dt><dd>{{ bucket.last_response.remaining === null ? 'Not supplied' : number(bucket.last_response.remaining) }}</dd></div>
                        </dl>
                    </template>
                    <p v-else class="rate-note">No server limit report recorded for this scope yet.</p>
                </article>
                <p class="rate-note">Local capacity refills over time. Discovery reads leave capacity for manual actions. The remaining header is advisory and does not represent your full burst allowance. Traffic from other apps or machines sharing your IP or account is not counted here, so this is an estimate, not a guarantee.</p>
            </section>
        </FocusSheet>
    </div>
</template>

<style scoped>
.rate-status { --rate-tone: #a9bbca; flex-shrink: 0; color-scheme: dark; }
.rate-status[data-level="ok"] { --rate-tone: #9ce5bc; }
.rate-status[data-level="warning"] { --rate-tone: #ffda8b; }
.rate-status[data-level="danger"] { --rate-tone: #ffaba6; }
.rate-trigger { display: flex; align-items: center; justify-content: center; gap: 7px; min-height: 44px; padding: 6px 10px; border: 1px solid #405269; border-radius: 10px; color: var(--rate-tone); background: #101b2a; font: 12px/1.3 var(--font-interface); }
.rate-long { max-width: 94px; text-align: left; }
.rate-short { display: none; }
.rate-trigger:focus-visible { outline: 2px solid var(--rate-tone); outline-offset: 3px; }
.rate-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--rate-tone); flex-shrink: 0; }
.rate-details { color: #e5edf5; background: #0b1420; padding: 16px; border-radius: 12px; font: 14px/1.6 var(--font-interface); }
.rate-details p { margin: 0 0 14px; }
.rate-verdict { color: var(--rate-tone); font-weight: 700; font-size: 18px; }
.rate-details .rate-note { color: #b0c2d3; font-size: 13px; }
.rate-bucket { border: 1px solid #405269; background: #142133; border-radius: 12px; padding: 16px; margin: 18px 0; }
.rate-bucket h3 { font-size: 17px; font-weight: 700; margin: 0 0 4px; }
.rate-bucket h4 { font-weight: 700; margin: 18px 0 4px; }
.rate-capacity { display: flex; gap: 10px; flex-wrap: wrap; align-items: baseline; }
.rate-capacity strong { font-size: 24px; }
.rate-capacity span { color: #b0c2d3; }
meter { width: 100%; height: 18px; margin: 8px 0 14px; accent-color: #9ce5bc; }
dl { display: grid; gap: 8px; }
dl > div { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 2px 16px; }
dt { color: #b0c2d3; }
dd { font-variant-numeric: tabular-nums; font-weight: 600; }
@media(max-width: 380px) { .rate-trigger { padding: 6px; gap: 4px; font-size: 11px; } .rate-trigger span:last-child { max-width: 64px; } }
@media(max-width: 600px) { .rate-long { display: none; } .rate-short { display: inline; } }
</style>
