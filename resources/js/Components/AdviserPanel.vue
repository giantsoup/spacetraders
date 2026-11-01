<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import DataDetails from '@/Components/DataDetails.vue';
import { formatLocalTime, localTimeZone } from '@/dateTime.js';
import { readableLabel } from '@/dataPresentation.js';

const props = defineProps({ agent: Object, ships: Array, selected: String, history: Object, runs: Array, investigations: Array, disabled: Boolean });
const emit = defineEmits(['refresh']);
const policy = computed(() => props.history?.policies[0]);
const cachedDecisions = ref([]);
const cachedPolicies = ref([]);
const policies = computed(() => [...new Map([...cachedPolicies.value, ...(props.history?.policies || [])].map(p => [p.id, p])).values()].sort((a, b) => b.id - a.id));
const decisions = computed(() => [...new Map([...cachedDecisions.value, ...(props.history?.decisions || [])].map(d => [d.id, d])).values()].sort((a, b) => b.id - a.id));
const shipSymbol = ref(policy.value?.ship_symbol || props.selected);
const ship = computed(() => props.ships.find(s => s.symbol === shipSymbol.value)?.snapshot);
const cargo = computed(() => ship.value?.cargo?.inventory || []);
const cargoPermissions = ref({});
const activities = ref(policy.value?.terms.activities || ['wait', 'read', 'mining', 'sale', 'refuel']);
const fields = ref({ reserve: 10000, max_spend: 0, max_loss: 0, max_seconds: 1800, max_fuel: 0, refuel_units: 0, allow_trial: false, api_request_cap: 40, ...policy.value?.terms });
const exploration = ref({ enabled: false, max_cost: 0, max_fuel: 0, max_seconds: 0, interval_seconds: 0, ...policy.value?.terms.exploration });
const lifetime = ref(60);
const acknowledged = ref(false);
const busy = ref(false);
const collecting = ref(false);
const cancelled = ref(false);
const message = ref('');
const isError = ref(false);
const messageNode = ref(null);
const allActivities = ['wait', 'read', 'mining', 'sale', 'refuel', 'scout', 'contracts'];
const now = ref(Date.now());
const clock = window.setInterval(() => { now.value = Date.now(); }, 1000);
const available = computed(() => policy.value && policy.value.available_for_assessment !== false && !props.disabled && !policy.value.paused_at && !policy.value.revoked_at && Date.parse(policy.value.expires_at) > now.value && policy.value.reset_id === props.agent.reset_id);
const active = computed(() => props.runs.find(r => r.active_agent_id && r.ship_symbol === policy.value?.ship_symbol));
const investigation = computed(() => props.investigations.find(i => i.system_symbol === policy.value?.system_symbol && i.strategy === 'mining'));
watch(shipSymbol, () => { cargoPermissions.value = {}; });
onBeforeUnmount(() => { cancelled.value = true; window.clearInterval(clock); });
const credit = value => value === null || value === undefined ? 'Unknown' : `${Number(value).toLocaleString()} CR`;
const refresh = () => new Promise(resolve => emit('refresh', resolve));
function decisionStatus(decision) {
    const grant = policies.value.find(p => p.id === decision.adviser_policy_id);
    if (grant?.paused_at || grant?.revoked_at || (grant && Date.parse(grant.expires_at) <= now.value) || decision.reset_id !== props.agent.reset_id || props.disabled) return 'Policy invalidated';
    return Date.parse(decision.expires_at) <= now.value ? 'Expired' : readableLabel(decision.status);
}
async function post(path, data = {}, concurrent = false) {
    if (busy.value && !concurrent) return null;
    if (!concurrent) busy.value = true;
    message.value = '';
    isError.value = false;
    try {
        const response = await window.axios.post(`/agents/${props.agent.id}/${path}`, data, { headers: { Accept: 'application/json' } });
        await refresh();
        return response.data;
    } catch (error) {
        isError.value = true;
        message.value = Object.values(error.response?.data?.errors || {}).flat().join(' ') || error.response?.data?.message || 'Local request failed. Reload saved history before retrying.';
        await nextTick(); messageNode.value?.focus();
        return null;
    } finally { if (!concurrent) busy.value = false; }
}
async function grant() {
    const numeric = Object.fromEntries(['reserve', 'max_spend', 'max_loss', 'max_seconds', 'max_fuel', 'refuel_units', 'api_request_cap'].map(key => [key, Number(fields.value[key])]));
    const allowance = { enabled: exploration.value.enabled, ...Object.fromEntries(['max_cost', 'max_fuel', 'max_seconds', 'interval_seconds'].map(key => [key, Number(exploration.value[key])])) };
    const result = await post('adviser/policies', { previous_id: policy.value?.id || null, ship_symbol: shipSymbol.value, system_symbol: ship.value?.nav?.systemSymbol,
        expires_at: new Date(Date.now() + Number(lifetime.value) * 60000).toISOString().replace(/\.\d{3}Z$/, 'Z'), acknowledge: acknowledged.value,
        terms: { ...numeric, activities: activities.value, cargo: Object.entries(cargoPermissions.value).filter(([, units]) => Number(units) > 0).map(([symbol, units]) => ({ symbol, units: Number(units) })), allow_trial: fields.value.allow_trial, exploration: allowance } });
    if (result) { acknowledged.value = false; message.value = `Policy version ${result.version} saved for review. Automatic execution and model calls remain disabled.`; }
}
async function assess() {
    const result = await post('adviser/assess', { policy_id: policy.value.id, investigation_id: investigation.value?.id || null });
    if (result) message.value = 'Immutable assessment saved. No gameplay queued and no model called.';
}
async function collect() {
    const record = await post('adviser/collect', { policy_id: policy.value.id });
    if (!record) return;
    await advance(record.id);
}
async function advance(id) {
    collecting.value = true;
    cancelled.value = false;
    try {
        for (let step = 0; step < 40 && !cancelled.value; step++) {
            const result = await post(`adviser/investigations/${id}/advance`);
            if (!result || result.status !== 'investigating') break;
        }
        if (!cancelled.value) message.value = 'Bounded observation collection finished. Inspect coverage in the next assessment. No movement authorized.';
    } finally { collecting.value = false; }
}
async function cancelCollection() {
    cancelled.value = true;
    await post(`adviser/investigations/${investigation.value.id}/cancel`, {}, true);
}
async function pause() {
    const result = await post(`adviser/policies/${policy.value.id}/pause`, {}, true);
    if (result) message.value = 'Automatic decisions paused. Pending assessments are invalidated. Current activity and upstream travel continue; use Stop current activity separately.';
}
async function stop() {
    const result = await post(`runs/${active.value.id}/stop`, {}, true);
    if (result) message.value = 'Stop recorded through the existing activity runner. Reserved work must settle and upstream travel continues.';
}
const olderDecisions = ref(true), olderPolicies = ref(true);
async function older() {
    busy.value = true;
    try {
        const result = await window.axios.get(`/agents/${props.agent.id}/adviser/history`, { params: { before_decision: decisions.value.at(-1)?.id, before_policy: policies.value.at(-1)?.id } });
        cachedDecisions.value.push(...result.data.decisions);
        cachedPolicies.value.push(...result.data.policies);
        olderDecisions.value = result.data.more_decisions;
        olderPolicies.value = result.data.more_policies;
    } catch { message.value = 'Could not load older adviser history. Try again.'; isError.value = true; }
    finally { busy.value = false; }
}
</script>

<template>
    <section id="adviser" class="adviser-panel" aria-labelledby="adviser-heading">
        <h2 id="adviser-heading">Adviser policy and decisions</h2>
        <p>Review one ship's next bounded activity. Automatic execution and model calls are disabled. Saving a grant or assessment queues no gameplay.</p>
        <p class="muted">Times shown in {{ localTimeZone }}. Deadlines are stored and compared in UTC. Pausing decisions is separate from stopping an activity.</p>
        <p v-if="message" ref="messageNode" role="status" tabindex="-1" :class="{ warning: isError }">{{ message }}</p>
        <div v-if="policy" class="panel">
            <h3>Current grant · version {{ policy.version }}</h3>
            <p>{{ policy.ship_symbol }} · {{ policy.system_symbol }} · {{ available ? 'Available for local assessment' : 'Paused, expired or unavailable' }}</p>
            <p>Expires <time :datetime="policy.expires_at">{{ formatLocalTime(policy.expires_at) }}</time></p>
            <div class="button-row">
                <SecondaryButton :disabled="disabled || !!policy.paused_at" @click="pause">Pause automatic decisions</SecondaryButton>
                <DangerButton :disabled="disabled || !active || !!active.stop_requested_at" @click="stop">{{ active?.stop_requested_at ? 'Stop requested' : 'Stop current activity' }}</DangerButton>
            </div>
            <p v-if="!active" class="muted">No current activity on the policy ship. Stop applies to the existing durable activity runner; reserved commands must settle.</p>
            <div class="button-row">
                <PrimaryButton :disabled="!available || busy || collecting" @click="assess">Assess saved evidence</PrimaryButton>
                <SecondaryButton :disabled="!available || busy || collecting" @click="collect">Collect bounded observations</SecondaryButton>
                <SecondaryButton v-if="investigation?.status === 'investigating' && !collecting" :disabled="!available || busy" @click="advance(investigation.id)">Continue observation collection</SecondaryButton>
                <DangerButton v-if="collecting || investigation?.status === 'investigating'" @click="cancelCollection">Cancel observation collection</DangerButton>
            </div>
            <p v-if="investigation">Investigation #{{ investigation.id }} · {{ readableLabel(investigation.status) }} · {{ investigation.coverage.requests }}/{{ investigation.coverage.request_limit }} reads. Gathering missing prices can require a separately approved scout.</p>
        </div>
        <details class="panel" :open="!policy">
            <summary>{{ policy ? 'Review and grant a new policy version' : 'Configure one-ship policy grant' }}</summary>
            <form @submit.prevent="grant">
                <fieldset :disabled="disabled || busy || collecting">
                    <legend>Scope and activity bounds</legend>
                    <label for="adviser-ship">Policy ship</label><select id="adviser-ship" v-model="shipSymbol"><option v-for="owned in ships" :key="owned.symbol" :value="owned.symbol">{{ owned.symbol }}</option></select>
                    <p>Permitted system: {{ ship?.nav?.systemSymbol || 'Unknown. Synchronize first.' }}</p>
                    <div class="policy-grid"><label v-for="activity in allActivities" :key="activity" class="check"><input v-model="activities" type="checkbox" :value="activity">{{ readableLabel(activity) }}{{ activity === 'contracts' ? ' · unsupported in adviser v1' : '' }}</label></div>
                    <label>Grant expires in minutes<input v-model="lifetime" type="number" min="1" max="1440" required></label>
                    <div class="policy-grid">
                        <label v-for="[key, label] in [['reserve','Protected balance in CR'],['max_spend','Maximum activity spending in CR'],['max_loss','Maximum activity cash loss in CR'],['max_seconds','Maximum activity seconds'],['max_fuel','Maximum activity travel fuel'],['refuel_units','Maximum refuel tank units'],['api_request_cap','Observation API request cap']]" :key="key">{{ label }}<input v-model="fields[key]" type="number" :min="['reserve','max_seconds','api_request_cap'].includes(key) ? 1 : 0" step="1" required></label>
                    </div>
                    <label class="check"><input v-model="fields.allow_trial" type="checkbox">Permit one-extraction mining trials with unknown earnings</label>
                    <h3>Starting cargo stays protected</h3>
                    <p>Only these explicit per-activity quantities may be sold. New versions start with no cargo sale permission. Mining requires empty starting cargo.</p>
                    <label v-for="good in cargo" :key="good.symbol">Permitted sale units for {{ good.symbol }}<input v-model="cargoPermissions[good.symbol]" type="number" min="0" :max="good.units" step="1" placeholder="0"></label>
                    <p v-if="!cargo.length" class="muted">No starting cargo observed.</p>
                    <h3>Exploration allowance</h3>
                    <label class="check"><input v-model="exploration.enabled" type="checkbox">Permit exploration when due and funded above the protected balance</label>
                    <p>Choose every allowance explicitly. A scout must answer a named price question and return to verified fuel. Unknown rewards and future sales never fund it.</p>
                    <div class="policy-grid"><label v-for="[key, label] in [['max_cost','Exploration maximum cost in CR'],['max_fuel','Exploration maximum travel fuel'],['max_seconds','Exploration maximum seconds'],['interval_seconds','Minimum seconds between exploration activities']]" :key="key">{{ label }}<input v-model="exploration[key]" type="number" min="0" step="1" required></label></div>
                    <label class="check"><input v-model="acknowledged" type="checkbox" required>I reviewed this one-ship scope and expiry. Execution remains disabled.</label>
                    <PrimaryButton :disabled="!acknowledged || !ship" type="submit">Save policy grant</PrimaryButton>
                </fieldset>
            </form>
        </details>
        <h3>Decision history</h3>
        <p v-if="!decisions.length">No assessments saved. Grant a policy, collect observations if needed, then assess saved evidence.</p>
        <article v-for="decision in decisions" :key="decision.id" class="panel decision" :data-decision="decision.id">
            <h4>Assessment #{{ decision.id }} · {{ decisionStatus(decision) }}</h4>
            <p><time :datetime="decision.created_at">{{ formatLocalTime(decision.created_at) }}</time> · expires <time :datetime="decision.expires_at">{{ formatLocalTime(decision.expires_at) }}</time></p>
            <p>Rule: {{ readableLabel(decision.selection.rule) }}. Selected {{ decision.candidates.find(c => c.id === decision.selection.candidate_id)?.activity }}.</p>
            <p>{{ decision.selection.reason }}</p>
            <details><summary>Score components and evidence</summary><DataDetails :value="{ selection: decision.selection, evidence_hash: decision.evidence_hash, evidence: decision.evidence }" /></details>
            <details open><summary>Immutable candidate choices · {{ decision.candidates.length }}</summary>
                <article v-for="candidate in decision.candidates" :key="candidate.id" class="candidate" :data-candidate="candidate.id">
                    <h5>{{ readableLabel(candidate.activity) }} · {{ readableLabel(candidate.classification) }}{{ candidate.id === decision.selection.candidate_id ? ' · Selected for review' : '' }}</h5>
                    <p v-if="candidate.question">{{ candidate.question }}</p>
                    <p v-for="reason in candidate.reasons" :key="reason">{{ reason }}</p>
                    <dl><dt>Base cash contribution</dt><dd>{{ credit(candidate.base_net) }}</dd><dt>Conservative cash contribution</dt><dd>{{ credit(candidate.conservative_net) }}</dd><dt>Maximum debit / committed capital</dt><dd>{{ credit(candidate.max_debit) }}</dd><dt>Estimated time</dt><dd>{{ candidate.duration_seconds === null ? 'Unknown' : `${candidate.duration_seconds}s` }}</dd><dt>Hard activity limit</dt><dd>{{ candidate.max_seconds }}s</dd></dl>
                    <details><summary>Stable ID, plan hash and exact bounds</summary><DataDetails :value="candidate" /></details>
                </article>
            </details>
        </article>
        <h3>Policy history</h3>
        <details v-for="grant in policies" :key="grant.id" class="panel"><summary>Version {{ grant.version }} · {{ grant.ship_symbol }} · {{ grant.paused_at ? 'Paused' : grant.revoked_at ? 'Replaced' : 'Saved' }}</summary><p>Granted <time :datetime="grant.created_at">{{ formatLocalTime(grant.created_at) }}</time> · expires <time :datetime="grant.expires_at">{{ formatLocalTime(grant.expires_at) }}</time></p><p v-if="grant.paused_at">Paused <time :datetime="grant.paused_at">{{ formatLocalTime(grant.paused_at) }}</time></p><DataDetails :value="grant" /></details>
        <SecondaryButton v-if="(history?.more_decisions && olderDecisions) || (history?.more_policies && olderPolicies)" :disabled="busy" @click="older">Load older adviser history</SecondaryButton>
        <details class="panel"><summary>Unresolved tuning and later enablement</summary><p>Exploration is off by default. Taylor must choose its credit, fuel, time and frequency allowance. The sample threshold, historical worst-case comparison, queue allowance and recovery order need measured validation. Per-activity caps do not establish a cumulative exploration budget.</p><p>Later work owns event triggers, model review, budget reservations, grant revalidation and execution through the shared durable executor. This screen cannot enable those paths.</p></details>
    </section>
</template>

<style scoped>
.adviser-panel { color-scheme: dark; min-width: 0; }
.adviser-panel > * + * { margin-top: 1rem; }
.panel { padding: 1rem; border: 1px solid var(--color-line); border-radius: 16px; background: var(--color-panel); }
.button-row { display: flex; flex-wrap: wrap; gap: .65rem; margin-block: .85rem; }
.panel p + p { margin-top: .65rem; }
.decision > details { margin-top: .8rem; }
.policy-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 15rem), 1fr)); gap: .8rem; margin-block: 1rem; }
fieldset { min-width: 0; }
input[type="number"], select { width: 100%; min-height: 44px; padding: .6rem .75rem; border: 1px solid var(--color-line); border-radius: 10px; background: var(--color-field); color: var(--color-ink); font: inherit; }
label { display: grid; gap: .35rem; margin-block: .6rem; }
.check { display: flex; align-items: center; gap: .7rem; min-height: 44px; }
.check input { width: 1.2rem; height: 1.2rem; flex-shrink: 0; }
.candidate { padding: 1rem 0; border-bottom: 1px solid var(--color-line); overflow-wrap: anywhere; }
h3, h4, h5 { font-weight: 650; margin-block: .7rem; }
dl { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: .4rem .8rem; }
dt { color: var(--color-muted); }
dd { text-align: right; }
summary { min-height: 44px; cursor: pointer; overflow-wrap: anywhere; }
</style>
