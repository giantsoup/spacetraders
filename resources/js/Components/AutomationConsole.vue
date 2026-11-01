<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import Checkbox from '@/Components/Checkbox.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import DataDetails from '@/Components/DataDetails.vue';
import { formatLocalTime, localTimeZone } from '@/dateTime.js';
import { assetName, cargoSummary, readableLabel, readableText } from '@/dataPresentation.js';

const props = defineProps({ agent: Object, ships: Array, engine: Object, operator: Boolean, investigations: Array, drafts: Array, runs: Array, accounting: Object, enabled: Boolean, observations: { type: Array, default: () => [] } });
const emit = defineEmits(['refresh']);
const strategy = ref(props.drafts[0]?.candidate.strategy || props.investigations[0]?.strategy || 'mining');
const system = ref(props.ships[0]?.snapshot.nav.systemSymbol || '');
const sort = ref('rate');
const chosen = ref('');
const draftId = ref(props.drafts[0]?.id || null);
const investigationId = ref(props.investigations.find(i => i.strategy === strategy.value)?.id || null);
const investigation = computed(() => props.investigations.find(i => i.id === investigationId.value) || props.investigations.find(i => i.strategy === strategy.value));
const draft = computed(() => props.drafts.find(d => d.id === draftId.value));
const preview = computed(() => props.runs.find(r => r.state === 'preview' && r.plan_version === 2 && r.draft_id === draft.value?.id));
const active = computed(() => props.runs.find(r => r.active_agent_id));
const outcome = computed(() => active.value || props.runs.find(r => r.state !== 'preview'));
const runShip = computed(() => props.ships.find(s => s.symbol === outcome.value?.ship_symbol)?.snapshot);
const candidates = computed(() => [...(investigation.value?.candidates || [])].sort((a, b) => sort.value === 'net' ? ((b.estimated_net ?? -Infinity) - (a.estimated_net ?? -Infinity) || (a.working_capital ?? Infinity) - (b.working_capital ?? Infinity) || (a.duration_seconds ?? Infinity) - (b.duration_seconds ?? Infinity) || a.id.localeCompare(b.id)) : 0));
const limits = ref({ reserve: 2000, max_spend: 100, max_loss: 100, minutes: 60, cycles: 1, delivery_limit: 10, purchase_limit: 40, max_extractions: 1, extraction_headroom: 40, site_seconds: 300, survey_signature: '' });
watch(() => draft.value?.id, () => { if (draft.value) limits.value = { max_extractions: 1, extraction_headroom: 40, site_seconds: 300, survey_signature: '', ...draft.value.limits }; }, { immediate: true });
const dirty = computed(() => draft.value && Object.keys(limits.value).some(key => (key === 'survey_signature' ? (limits.value[key] || '') !== (draft.value.limits[key] || '') : Number(limits.value[key]) !== Number(draft.value.limits[key] ?? ({ max_extractions: 1, extraction_headroom: 40, site_seconds: 300 }[key])))));
const staleDraft = computed(() => draft.value && Date.now() - Date.parse(draft.value.updated_at) > 180000);
const engineHealthy = computed(() => props.engine?.state === 'running' && props.engine.gate_open);
const busy = ref(false);
const investigating = ref(false);
const cancelled = ref(false);
const notice = ref('');
const error = ref(false);
const errors = ref({});
const messagePanel = ref(null);
const draftPanel = ref(null);
const reviewPanel = ref(null);
const acceptContract = ref(false);
const forceAcknowledged = ref(false);
const forceAvailable = computed(() => props.engine?.state === 'faulted' && props.engine.stop_requested_at && Date.now() - Date.parse(props.engine.stop_requested_at) >= 40000);
const balanceNote = ref('');
const startingBalance = ref('');
const credit = value => value === null || value === undefined ? 'Unknown' : `${Number(value).toLocaleString()} CR`;
const classifications = { ready: 'Ready to stage', needs_preparation: 'Needs preparation', needs_information: 'Needs information', unsupported: 'Unsupported' };
const utc = value => formatLocalTime(value, 'Unknown');
const hasError = key => !!errors.value[`limits.${key}`];
const numericLimits = () => Object.fromEntries(Object.entries(limits.value).map(([key, value]) => [key, key === 'survey_signature' ? value : Number(value)]));
watch(() => preview.value?.id, () => { acceptContract.value = false; });
onBeforeUnmount(() => { cancelled.value = true; });
async function refresh() {
    await new Promise(resolve => emit('refresh', resolve));
}
async function post(path, data = {}, concurrent = false) {
    if (busy.value && !concurrent) return null;
    if (!concurrent) busy.value = true;
    errors.value = {};
    error.value = false;
    notice.value = '';
    try {
        const response = await window.axios.post(`/agents/${props.agent.id}/${path}`, data, { headers: { Accept: 'application/json' } });
        await refresh();
        return response.data;
    } catch (failure) {
        errors.value = failure.response?.data?.errors || {};
        notice.value = Object.values(errors.value).flat().join(' ') || failure.response?.data?.message || 'Local request failed. Reload and inspect saved state before retrying.';
        error.value = true;
        await refresh();
        await nextTick();
        messagePanel.value?.focus();
        return null;
    } finally {
        if (!concurrent) busy.value = false;
    }
}
async function investigate() {
    const record = await post('investigations', { strategy: strategy.value, system: system.value });
    if (!record) return;
    investigationId.value = record.id;
    chosen.value = '';
    await continueInvestigation(record.id);
}
async function continueInvestigation(id = investigation.value?.id) {
    if (!id || investigating.value) return;
    investigating.value = true;
    cancelled.value = false;
    try {
        for (let step = 0; step < 40 && !cancelled.value; step++) {
            const record = await post(`investigations/${id}/advance`);
            if (!record || record.status !== 'investigating') break;
        }
    } finally { investigating.value = false; }
}
async function cancelInvestigation() {
    cancelled.value = true;
    await post(`investigations/${investigation.value.id}/cancel`, {}, true);
    notice.value = 'Investigation cancelled. No further reads requested. Partial observations remain saved.';
}
function selectCandidate(candidate) {
    chosen.value = candidate.id;
    draftId.value = null;
    limits.value = { reserve: Math.max(2000, candidate.minimum_reserve || 0), max_spend: Math.max(100, candidate.working_capital || 0), max_loss: Math.max(100, candidate.working_capital || 0), minutes: 60, cycles: 1, delivery_limit: 10, purchase_limit: 40, max_extractions: 1, extraction_headroom: props.ships.find(ship => ship.symbol === candidate.ship_symbol)?.snapshot.cargo.capacity || 40, site_seconds: 300, survey_signature: '' };
}
async function stage(update = false) {
    const record = await post(update ? `drafts/${draft.value.id}/update` : 'drafts', {
        investigation_id: update ? draft.value.investigation_id : investigation.value.id,
        candidate_id: update ? draft.value.candidate.id : chosen.value, limits: numericLimits(), ...(update ? { revision: draft.value.revision } : {}),
    });
    if (!record) return;
    draftId.value = record.id;
    chosen.value = '';
    notice.value = `Draft revision ${record.revision} saved. No gameplay queued.`;
    await nextTick(); draftPanel.value?.focus();
}
async function previewPlan() {
    const record = await post(`drafts/${draft.value.id}/preview`, { revision: draft.value.revision });
    if (record) { await nextTick(); reviewPanel.value?.focus(); }
}
async function approve() {
    const result = await post(`runs/${preview.value.id}/start`, { accept_contract: acceptContract.value });
    if (result) notice.value = 'Exact preview approved once. Follow the saved run and action history.';
}
async function engineOperation(operation) {
    const result = await post(`engine/${operation}`, operation === 'force-stop' ? { acknowledge: forceAcknowledged.value } : {});
    if (result) notice.value = result.message;
}
async function reviewBalance() {
    const result = await post('accounting/review', { observed_balance: props.agent.credits, starting_balance: startingBalance.value === '' ? null : Number(startingBalance.value), note: balanceNote.value, acknowledge: true });
    if (result) { balanceNote.value = ''; notice.value = 'Balance review recorded separately from confirmed operating receipts.'; }
}
</script>

<template>
    <div class="automation-console">
        <section class="panel engine-panel" aria-labelledby="engine-heading">
            <div class="section-title"><h2 id="engine-heading">Local engine</h2><span class="state-tag" :class="{ healthy: engineHealthy }">{{ readableLabel(engine?.state || 'off') }}</span></div>
            <p>{{ engine?.message || 'Local engine is off.' }}</p>
            <p class="muted">Engine controls are separate from run approval. Start authorizes no gameplay. Closing this page leaves the engine running. Sleep can miss contract deadlines.</p>
            <div v-if="operator" class="button-row">
                <PrimaryButton :disabled="busy || ['running','starting','stopping'].includes(engine?.state)" @click="engineOperation('start')">Start local engine</PrimaryButton>
                <DangerButton :disabled="busy || engine?.state === 'off'" @click="engineOperation('end')">End session</DangerButton>
            </div>
            <p v-else class="muted">Only the configured local operator can control the engine.</p>
            <div v-if="operator && forceAvailable">
                <label class="check-row"><Checkbox v-model:checked="forceAcknowledged" />I understand force stop may interrupt an upstream request; uncertain writes require reconciliation.</label>
                <DangerButton :disabled="busy || !forceAcknowledged" @click="engineOperation('force-stop')">Force stop owned engine</DangerButton>
            </div>
            <p class="muted">Heartbeat <time :datetime="engine?.heartbeat_at">{{ utc(engine?.heartbeat_at) }}</time> · {{ localTimeZone }}</p>
        </section>

        <p v-if="notice" ref="messagePanel" tabindex="-1" :role="error ? 'alert' : 'status'" class="automation-notice" :class="{ fault: error }">{{ readableText(notice) }}</p>

        <section class="panel" aria-labelledby="new-run-heading">
            <h2 id="new-run-heading">New automated run</h2>
            <p class="muted">Investigate, stage a draft, preview fresh state, then approve that exact plan once. Investigation only reads game state.</p>
            <fieldset :disabled="busy || investigating || !!active"><legend>Choose a strategy</legend>
                <div class="strategy-options"><label><input v-model="strategy" type="radio" value="mining" name="automation-strategy" @change="chosen = ''; investigationId = null">Mining</label><label><input v-model="strategy" type="radio" value="contracts" name="automation-strategy" @change="chosen = ''; investigationId = null">Contracts</label></div>
                <label>Investigation system<select v-model="system"><option value="">Choose an owned system</option><option v-for="s in [...new Set(ships.map(s => s.snapshot.nav.systemSymbol))]" :key="s" :value="s">{{ s }}</option></select></label>
                <div class="button-row"><PrimaryButton :disabled="!system" @click="investigate">Investigate opportunities</PrimaryButton></div>
            </fieldset>
            <div v-if="investigation" class="investigation-progress" aria-live="polite">
                <p>Investigation #{{ investigation.id }} · {{ readableLabel(investigation.status) }} · {{ investigation.system_symbol }} · {{ investigation.coverage.requests }}/{{ investigation.coverage.request_limit }} requests · {{ investigation.coverage.elapsed_seconds || 0 }}/{{ investigation.coverage.seconds_limit }}s elapsed time</p>
                <p>Observed <time :datetime="investigation.observations.observed_at">{{ utc(investigation.observations.observed_at) }}</time>. Page limit 10; market limit 24; candidate limit 200. Search covers only this observed system.</p>
                <p v-for="reason in investigation.coverage.limits_reached" :key="reason" class="warning">{{ readableText(reason) }}</p>
                <div v-if="investigation.status === 'investigating'" class="button-row"><SecondaryButton v-if="!investigating" :disabled="busy" @click="continueInvestigation()">Continue investigation</SecondaryButton><DangerButton @click="cancelInvestigation">Cancel investigation</DangerButton></div>
            </div>
            <template v-if="candidates.length">
                <label>Opportunity order<select v-model="sort"><option value="rate">Estimated credits per hour</option><option value="net">Estimated total net credits</option></select></label>
                <div class="candidate-list">
                    <article v-for="candidate in candidates" :key="candidate.id" class="candidate" :class="{ selected: chosen === candidate.id }">
                        <p v-if="candidate.recommended || candidate.suggested_trial" class="recommendation">{{ candidate.trial_required ? 'Bounded trial available' : 'Best observed opportunity' }}</p>
                        <h3>{{ candidate.ship_symbol }} · {{ candidate.contract_id || candidate.site }}<template v-if="candidate.market"> → {{ candidate.market }}</template></h3>
                        <p class="state-tag">{{ classifications[candidate.classification] }}<template v-if="candidate.trial_required"> · Trial required</template></p>
                        <p v-if="candidate.strategy === 'contracts'">{{ candidate.use_existing_cargo ? 'Includes explicitly selected existing cargo; remainder uses verified purchases' : 'Verified purchases; existing cargo stays protected' }}</p>
                        <dl><dt>Estimated net / hourly</dt><dd>{{ credit(candidate.estimated_net) }} / {{ credit(candidate.estimated_per_hour) }}</dd><dt>Estimated duration</dt><dd>{{ candidate.duration_seconds === null ? 'Unknown' : `${candidate.duration_seconds}s including allowance` }}</dd><dt>Working capital / setup</dt><dd>{{ credit(candidate.working_capital) }} / {{ credit(candidate.setup_cost) }}</dd><dt>Fuel use</dt><dd>{{ candidate.fuel_estimate ?? 'Unknown' }} tank units</dd><dt>Required equipment</dt><dd>{{ candidate.required_equipment.join(', ') }}</dd></dl>
                        <ul class="reasons"><li v-for="reason in candidate.reasons" :key="reason">{{ readableText(reason) }}</li></ul>
                        <p v-if="candidate.classification !== 'ready'" class="muted">Use the manual controls below for a separate scouting, setup or refueling preview. Investigate again after preparation.</p>
                        <details v-if="candidate.contract_plan"><summary>Remaining contract, payments and itinerary</summary><DataDetails :value="candidate.contract_plan" /></details>
                        <details v-if="candidate.yield_history"><summary>Yield evidence</summary><DataDetails :value="candidate.yield_history" /></details>
                        <SecondaryButton :disabled="busy || candidate.classification !== 'ready' || !!active" :aria-pressed="chosen === candidate.id" @click="selectCandidate(candidate)">Select {{ candidate.ship_symbol }} {{ candidate.contract_id || candidate.site }}{{ candidate.use_existing_cargo ? ' with existing cargo' : '' }}{{ candidate.market ? ` via ${candidate.market}` : '' }}</SecondaryButton>
                    </article>
                </div>
            </template>
            <p v-else-if="investigation?.status === 'complete'" class="warning">No candidates observed in this scope. No gameplay has been authorized.</p>
            <p v-if="chosen" class="muted">Selection is local. Review limits below, then Stage plan.</p>
        </section>

        <section v-if="chosen || draft" ref="draftPanel" tabindex="-1" class="panel" aria-labelledby="draft-heading">
            <h2 id="draft-heading">Stage plan</h2>
            <p v-if="draft">Saved draft #{{ draft.id }} · Revision {{ draft.revision }} · {{ readableLabel(draft.candidate.strategy) }} · {{ draft.candidate.ship_symbol }}</p>
            <p v-if="staleDraft" class="warning">Draft observations may be stale. Preview refreshes the exact selected opportunity and may reject changed prerequisites.</p>
            <fieldset :disabled="busy || !!active"><legend>Explicit run bounds</legend>
                <div class="form-grid">
                    <label>Automation protected reserve<input v-model="limits.reserve" type="number" min="1" step="1" :aria-invalid="hasError('reserve')"></label>
                    <label>Automation maximum spending<input v-model="limits.max_spend" type="number" min="0" step="1" :aria-invalid="hasError('max_spend')"></label>
                    <label>Automation maximum loss<input v-model="limits.max_loss" type="number" min="0" step="1" :aria-invalid="hasError('max_loss')"></label>
                    <label>Automation duration in minutes<input v-model="limits.minutes" type="number" min="1" max="60" step="1" :aria-invalid="hasError('minutes')"></label>
                    <label>Mining trip limit<input v-model="limits.cycles" type="number" min="1" max="10" step="1" :aria-invalid="hasError('cycles')"></label>
                    <template v-if="(draft?.candidate?.strategy || investigation?.strategy) === 'mining'">
                        <label>Maximum extractions per load<input v-model="limits.max_extractions" type="number" min="1" max="10"></label>
                        <label>Extraction headroom in cargo units<input v-model="limits.extraction_headroom" type="number" min="1"></label>
                        <label>Maximum extraction-site seconds<input v-model="limits.site_seconds" type="number" min="1" max="900"></label>
                        <label>Signed survey for staged mining<select v-model="limits.survey_signature" aria-label="Signed survey for staged mining"><option value="">Extract without a survey</option><option v-for="survey in observations.filter(row => row.kind === 'survey' && row.data.signed.symbol === draft?.candidate?.site && !row.data.exhausted && !row.data.unavailable_reason && Date.parse(row.data.signed.expiration) > Date.now())" :key="survey.id" :value="survey.symbol">{{ survey.symbol }} · {{ utc(survey.data.signed.expiration) }}</option></select></label>
                    </template>
                    <label>Contract delivery limit<input v-model="limits.delivery_limit" type="number" min="1" max="10" step="1" :aria-invalid="hasError('delivery_limit')"></label>
                    <label>Contract purchase batch limit<input v-model="limits.purchase_limit" type="number" min="0" max="40" step="1" :aria-invalid="hasError('purchase_limit')"></label>
                </div>
                <div class="button-row"><PrimaryButton v-if="chosen" @click="stage()">Stage plan</PrimaryButton><SecondaryButton v-if="draft" @click="stage(true)">Save draft changes</SecondaryButton><PrimaryButton v-if="draft" :disabled="dirty" @click="previewPlan">Preview automation</PrimaryButton></div>
                <p v-if="dirty" class="warning">Save changed limits before previewing. An earlier preview cannot approve a new draft revision.</p>
            </fieldset>
        </section>

        <section v-if="preview" ref="reviewPanel" tabindex="-1" class="panel automation-review" aria-labelledby="automation-review-heading">
            <h2 id="automation-review-heading">Review automation preview</h2>
            <p>{{ readableLabel(preview.strategy) }} · {{ preview.ship_symbol }} · Draft revision {{ preview.draft_revision }} · Expires <time :datetime="preview.expires_at">{{ utc(preview.expires_at) }}</time></p>
            <dl><dt>Starting location / fuel</dt><dd>{{ preview.plan.initial_waypoint }} / {{ preview.plan.initial_fuel }}</dd><dt>Starting cargo</dt><dd>{{ cargoSummary(preview.plan.initial_inventory) }}</dd><dt>Estimated net credits</dt><dd>{{ credit(preview.plan.estimated_net) }}</dd><dt>Reserve / spending / loss</dt><dd>{{ credit(preview.plan.reserve) }} / {{ credit(preview.plan.max_spend) }} / {{ credit(preview.plan.max_loss) }}</dd><dt>Duration / trips</dt><dd>{{ preview.plan.minutes }} min / {{ preview.plan.cycles }}</dd></dl>
            <template v-if="preview.strategy === 'contracts'">
                <dl><dt>Ending location / estimated fuel</dt><dd>{{ preview.plan.contract_plan.ending_location }} / {{ preview.plan.contract_plan.ending_fuel_estimate }}</dd><dt>Ending cargo</dt><dd>{{ cargoSummary(preview.plan.contract_plan.ending_inventory) }}</dd><dt>Included existing cargo</dt><dd>{{ cargoSummary(preview.plan.contract_plan.approved_existing_cargo) }}</dd><dt>Existing cargo direct-sale alternative</dt><dd>{{ credit(preview.plan.contract_plan.direct_sale_alternative) }}</dd><dt>Future / previously paid receipts</dt><dd>{{ credit(preview.plan.contract_plan.future_receipts) }} / {{ credit(preview.plan.contract_plan.already_paid_acceptance) }}</dd><dt>Acquisition / estimated fuel consumption</dt><dd>{{ credit(preview.plan.contract_plan.purchase_cost) }} / {{ credit(preview.plan.contract_plan.fuel_adjustment_estimate) }}</dd><dt>Scheduling allowance</dt><dd>{{ preview.plan.contract_plan.scheduling_allowance_seconds }}s</dd><dt>Acceptance deadline</dt><dd>{{ utc(preview.plan.contract_plan.contract.deadlineToAccept || preview.plan.contract_plan.contract.expiration) }}</dd><dt>Delivery deadline</dt><dd>{{ utc(preview.plan.contract_plan.contract.terms.deadline) }}</dd></dl>
                <h3>Ordered actions</h3><ol class="ordered-actions"><li v-for="(step, index) in preview.plan.contract_plan.actions" :key="index">{{ readableLabel(step.command) }}<template v-if="step.arguments.symbol"> · {{ step.arguments.units }} {{ assetName(step.arguments.symbol) }}</template><template v-if="step.arguments.waypointSymbol"> · {{ step.arguments.waypointSymbol }}</template><template v-if="step.arguments.maxTotal !== undefined"> · up to {{ credit(step.arguments.maxTotal) }}</template></li></ol>
                <p class="warning">Stopping does not remove an accepted contract’s game obligation. The Mac must stay awake and the engine healthy; completion before the deadline is not guaranteed.</p>
                <label v-if="!preview.plan.contract_plan.contract.accepted" class="check-row"><Checkbox v-model:checked="acceptContract" />I explicitly approve accepting this contract and its continuing delivery obligation.</label>
            </template>
            <template v-else><p class="warning">Mining continues within approved extraction bounds when buyer prices age in transit. Sale and refuel require fresh prices at the buyer; cargo is retained if the sale is blocked.</p><p v-if="preview.plan.trial_required" class="warning">Trial required. Yield and earnings are unknown. Up to {{ preview.plan.max_extractions || 1 }} {{ (preview.plan.max_extractions || 1) === 1 ? 'extraction' : 'extractions' }} per trip within the approved headroom and site-time bounds.</p><ol class="ordered-actions"><li v-for="step in preview.plan.ordered_actions" :key="step">{{ step }}</li></ol><p>Ending location {{ preview.plan.market }}. Unsold or unknown cargo stays aboard and blocks execution. Refueling targets a full tank within the spending cap.</p></template>
            <p class="warning">Stop on approved budget, loss, duration, equipment condition, unexplained state, uncertain write, reset or no progress. Prices are local estimates. Cash contribution excludes wear, time and unpriced inventory.</p>
            <p v-if="!engineHealthy" class="warning">Start a healthy engine, then create a fresh preview before approval.</p>
            <div class="button-row"><PrimaryButton :disabled="busy || !enabled || !engineHealthy || dirty || (preview.strategy === 'contracts' && !preview.plan.contract_plan.contract.accepted && !acceptContract)" @click="approve">Approve and start</PrimaryButton><SecondaryButton :disabled="busy" @click="previewPlan">Refresh preview</SecondaryButton></div>
        </section>

        <section v-if="outcome" class="panel" aria-labelledby="run-monitor-heading">
            <h2 id="run-monitor-heading">Run monitor and outcome</h2>
            <p>Run #{{ outcome.id }} · {{ readableLabel(outcome.strategy) }} · <strong>{{ readableLabel(outcome.state) }}</strong></p>
            <p v-if="outcome.blocked_reason" class="warning">{{ readableText(outcome.blocked_reason) }}</p>
            <p v-if="outcome.strategy === 'contracts'">Current / next action {{ outcome.active_agent_id ? readableLabel(outcome.plan.contract_plan?.actions[outcome.totals.step_index]?.command || 'settled') : 'No further actions' }} · Confirmed deliveries {{ outcome.totals.deliveries || 0 }}</p>
            <dl><dt>Next action time</dt><dd>{{ utc(outcome.next_action_at) }}</dd><dt>Started</dt><dd>{{ utc(outcome.started_at) }}</dd><dt>Confirmed receipts</dt><dd>{{ credit(outcome.totals.receipts ?? outcome.totals.sale_proceeds) }}</dd><dt>Confirmed spending</dt><dd>{{ credit(outcome.totals.spending ?? outcome.totals.fuel_cost) }}</dd><dt>Confirmed operating cash contribution</dt><dd>{{ credit(outcome.totals.cash_contribution ?? outcome.totals.credit_delta) }}</dd><dt>Remaining spending limit</dt><dd>{{ credit(outcome.plan.max_spend - (outcome.totals.spending ?? outcome.totals.fuel_cost ?? 0)) }}</dd><dt>Starting / ending fuel</dt><dd>{{ outcome.plan.initial_fuel }} / {{ outcome.totals.ending_fuel ?? runShip?.fuel.current ?? 'Unknown' }}</dd><dt>Remaining cargo</dt><dd>{{ cargoSummary(outcome.totals.unsold ?? outcome.plan.initial_inventory) }}</dd><dt>Elapsed time</dt><dd>{{ outcome.totals.elapsed_seconds ?? (outcome.started_at ? Math.max(0, Math.floor((Date.now() - Date.parse(outcome.started_at)) / 1000)) : 'Unknown') }} seconds</dd></dl>
            <p class="muted">Operating cash contribution is not full economic profit. Fuel inventory, wear, unpriced cargo and time remain separate.</p>
            <SecondaryButton v-if="!active" :disabled="busy" @click="investigate">Investigate again</SecondaryButton>
        </section>

        <section class="panel" aria-labelledby="credit-accounting-heading">
            <h2 id="credit-accounting-heading">Credit accounting</h2>
            <dl><dt>First observed local balance</dt><dd>{{ credit(accounting?.baseline?.credits) }}</dd><dt>First observed at</dt><dd>{{ utc(accounting?.baseline?.observed_at) }}</dd><dt>User-supplied starting balance</dt><dd>{{ credit(accounting?.baseline?.user_starting_credits) }}</dd><dt>Total change from identified starting balance</dt><dd>{{ credit(accounting?.total_balance_change) }}</dd><dt>Confirmed local changes</dt><dd>{{ credit(accounting?.confirmed_change) }}</dd><dt>Unrelated manual commands</dt><dd>{{ credit(accounting?.manual_change) }}</dd><dt>Reviewed external adjustments</dt><dd>{{ credit(accounting?.reviewed_adjustments) }}</dd><dt>Unexplained changes</dt><dd>{{ credit(accounting?.unexplained_change) }}</dd></dl>
            <p v-if="accounting?.unexplained_change" class="warning">Unexplained balance changes block new approval and attribution until reviewed.</p>
            <details><summary>Review an external change or identify an earlier starting balance</summary><p class="muted">Stop and settle all work first. This records your review; it does not change the game or turn an adjustment into confirmed earnings. The original local observation stays recorded.</p><label>Earlier starting balance, optional<input v-model="startingBalance" type="number" min="0" max="1000000000" step="1"></label><label>Balance review note<input v-model="balanceNote" type="text" minlength="10" maxlength="500"></label><SecondaryButton :disabled="busy || !!active || balanceNote.length < 10" @click="reviewBalance">Acknowledge and record balance review</SecondaryButton></details>
        </section>
    </div>
</template>

<style scoped>
.panel{border:2px solid var(--color-line);background:var(--color-panel);padding:22px;box-shadow:5px 5px 0 #05090f;min-width:0}.panel h2{font-size:17px;letter-spacing:.06em;font-weight:800;color:var(--color-accent);margin-bottom:18px;border-bottom:1px solid var(--color-line);padding-bottom:12px}.panel h3{font-weight:700;color:#cfdbeb;margin:20px 0 8px}.muted{color:var(--color-muted)}.warning{color:#f8d99b}.panel p{margin:8px 0}.panel label{display:grid;gap:7px;font-size:13px;color:#c9d7e8}.panel input:not([type=checkbox]):not([type=radio]),.panel select{display:block;border:1px solid #67829f;background:var(--color-field);color:#f0f5ff;padding:10px;width:100%;min-height:44px;border-radius:0;font:inherit}.panel input[aria-invalid="true"]{border-color:var(--color-fault)}.panel fieldset{min-width:0}.panel legend{font-size:13px;color:var(--color-muted)}.panel fieldset:disabled{opacity:.65}.panel dl{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:6px 16px;font-size:13px}.panel dt{color:var(--color-muted)}.panel dd{text-align:right;overflow-wrap:anywhere}.panel details{margin:12px 0}.panel summary{color:var(--color-accent);cursor:pointer}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:20px 0}.button-row{display:flex;flex-wrap:wrap;align-items:center;gap:16px;margin:18px 0}.automation-console{display:grid;gap:22px;min-width:0}.section-title{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:12px}.section-title h2{margin:0}.state-tag{display:inline-block;color:#f8d99b;font-size:12px}.state-tag.healthy,.recommendation{color:var(--color-accent)}.strategy-options{display:flex;flex-wrap:wrap;gap:24px;margin:16px 0}.strategy-options label,.check-row{display:flex!important;align-items:center;gap:12px!important}.strategy-options input,.check-row input{appearance:auto;accent-color:var(--color-accent);padding:0;width:20px!important;height:20px;min-height:20px!important;flex-shrink:0}.candidate-list{display:grid;gap:16px;margin-top:18px}.candidate{padding:16px;border:1px solid var(--color-line);background:var(--color-field);min-width:0}.candidate.selected{border-color:var(--color-accent)}.candidate h3{margin:6px 0!important;overflow-wrap:anywhere}.reasons,.ordered-actions{padding-left:24px;font-size:13px}.reasons{list-style:square}.ordered-actions{list-style:decimal}.ordered-actions li{padding:4px 0}.automation-review{border-color:#d3b77c!important;background:#202635!important;scroll-margin-top:100px}.automation-notice{padding:16px;border:2px solid var(--color-accent);background:#123429;overflow-wrap:anywhere}.automation-notice.fault{background:#421f2b;border-color:#f1a4b4;color:#ffdde4}.investigation-progress{margin:18px 0;padding:12px;border-left:3px solid #58708b}.check-row{margin:20px 0;font-size:13px}.automation-console summary{min-height:44px!important}.automation-console button{margin-top:4px}.recommendation{font-size:12px;letter-spacing:.03em}@media(max-width:900px){.form-grid{grid-template-columns:1fr}}@media(max-width:600px){.panel{padding:16px}.candidate{padding:12px}.automation-console{gap:16px}}
</style>
