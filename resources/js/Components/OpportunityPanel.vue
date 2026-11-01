<script setup>
import { computed } from 'vue';
import { formatLocalTime } from '@/dateTime.js';
import { assetName, readableLabel } from '@/dataPresentation.js';
import DataDetails from '@/Components/DataDetails.vue';
import EvidencePanel from '@/Components/EvidencePanel.vue';

const props = defineProps({ report: Object, selected: String });
const opportunities = computed(() => props.report?.opportunities.filter(row => row.ship === props.selected) || []);
const questions = computed(() => props.report?.observation_tasks.filter(row => row.ship === props.selected) || []);
</script>

<template>
    <section v-if="report" id="opportunities" class="panel" aria-labelledby="opportunities-heading">
        <h2 id="opportunities-heading">Local intelligence and opportunities</h2><p v-for="gap in report?.observation_gaps || []" :key="gap.ship" class="warning">{{ gap.ship }} · {{ gap.reason }}</p>
        <p v-if="!report.valid_for_planning" class="warning">Archived world. These observations cannot support new plans.</p>
        <p>{{ report.limits }}</p>
        <p v-if="!report.catalogues.length" class="warning">Catalogue coverage unknown. Synchronize before assessing routes.</p>
        <p v-for="catalogue in report.catalogues" :key="catalogue.symbol" :class="catalogue.data.complete ? 'muted' : 'warning'">
            {{ catalogue.symbol }} · {{ catalogue.data.fetched }} / {{ catalogue.data.total ?? '?' }} known waypoints · {{ catalogue.data.pages }} pages · {{ catalogue.data.reason }}
        </p>
        <p v-if="report.hidden_waypoints.length" class="warning">Unknown traits: {{ report.hidden_waypoints.join(', ') }}.</p>
        <p v-if="report.discovery && !report.discovery.complete" class="warning">Directory coverage partial: {{ report.discovery.observations }} of {{ report.discovery.candidates }} market/shipyard reads fit the discovery budget.</p>
        <div class="table-scroll"><table>
            <caption>Market evidence. Listing freshness and price freshness are independent.</caption>
            <thead><tr><th>Market / goods</th><th>Price state</th><th>Prices observed</th><th>Directory observed</th></tr></thead>
            <tbody><tr v-for="market in report.markets" :key="market.symbol">
                <td>{{ market.symbol }}<br><span class="muted">{{ market.goods.map(assetName).join(', ') || 'No goods listed' }}</span></td>
                <td>{{ readableLabel(market.state) }}</td><td>{{ formatLocalTime(market.price_at, 'Unknown') }}<br>{{ market.observer_ship || 'Observer unknown' }}</td><td>{{ formatLocalTime(market.listing_at) }}</td>
            </tr></tbody>
        </table></div>
        <h3>Candidate complete cycles</h3>
        <p v-if="report.candidate_limit_reached" class="warning">Candidate display reached its bounded limit. This shortlist is not a complete ranking.</p>
        <p>Estimates include empty returns where stated. Unpriced cargo, wear and unmeasured time keep earnings unresolved. Cash margin alone does not rank earnings per hour.</p>
        <p v-if="!opportunities.length" class="muted">No candidates from the current catalogue and loadout.</p>
        <details v-for="(candidate, index) in opportunities" :key="index">
            <summary>{{ readableLabel(candidate.strategy) }} · {{ candidate.site || candidate.source }} → {{ candidate.market }} {{ candidate.good ? assetName(candidate.good) : '' }} · {{ readableLabel(candidate.state) }}</summary>
            <p>{{ candidate.reason }}</p>
            <p v-if="candidate.samples !== undefined">{{ candidate.samples }} extraction samples for this configuration. Base / conservative net credits per hour: {{ candidate.net_credits_per_hour ?? 'Unknown' }} / {{ candidate.conservative_net_per_hour ?? 'Unknown' }}. Forecasts remain separate from realized outcomes.</p>
            <p v-if="candidate.estimated_cash_margin !== undefined">Base cash margin {{ candidate.estimated_cash_margin }} CR · adverse case {{ candidate.conservative_cash_margin }} CR · inventory exposure {{ candidate.inventory_exposure }} CR. Neither is realized income.</p>
            <DataDetails :value="candidate" />
        </details>
        <h3>Questions worth observing</h3>
        <p v-if="!questions.length" class="muted">No missing observations identified for this ship.</p>
        <details v-for="(question, index) in questions" :key="index"><summary>{{ question.destination }} · {{ question.fact }} · {{ question.ready_for_bounded_preview ? 'route candidate' : 'prerequisites unresolved' }}</summary><p>{{ question.purpose }}</p><p>Information benefit: {{ question.benefit }}</p><p>{{ question.stop }}</p><p>{{ question.api_estimate }}</p><DataDetails :value="question.route_options" /></details>
        <h3 v-if="report.cargo_options?.some(row => row.ship === selected)">Plan for owned cargo</h3>
        <details v-for="cargo in report.cargo_options?.filter(row => row.ship === selected) || []" :key="cargo.good"><summary>{{ assetName(cargo.good) }} · {{ cargo.units }} units · retain pending approval</summary><DataDetails :value="cargo.choices" /></details>
        <p class="muted">{{ report.fleet_gate }}</p>
        <EvidencePanel :evidence="report.evidence" :agent-id="report.agent_id" />
    </section>
</template>

<style scoped>
h2 { color: var(--color-accent); font-weight: 800; margin-bottom: 18px; }
h3 { font-weight: 700; margin: 20px 0 8px; }
p { margin: 10px 0; }
.muted { color: var(--color-muted); }
.warning { color: #f8d99b; }
.table-scroll { overflow-x: auto; }
table { min-width: 560px; width: 100%; border-collapse: collapse; font-size: 14px; }
caption { text-align: left; padding: 10px 0; color: var(--color-muted); }
th { text-align: left; background: var(--color-field); color: var(--color-accent); }
td, th { padding: 12px; border-bottom: 1px solid var(--color-line); vertical-align: top; }
details { margin: 12px 0; }
summary { cursor: pointer; color: var(--color-accent); min-height: 44px; }
summary:focus-visible { outline: 3px solid var(--color-focus); outline-offset: 4px; }
</style>
