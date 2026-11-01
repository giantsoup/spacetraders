<script setup>
import { computed } from 'vue';
import DataDetails from '@/Components/DataDetails.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({ evidence: Object });
const number = value => value === null || value === undefined ? 'Unknown' : Number(value).toLocaleString(undefined, { maximumFractionDigits: 3 });
const routes = computed(() => (props.evidence?.travel || []).slice(-100));
const plotted = computed(() => routes.value.filter(row => row.distance !== null && row.fuel_error_mean !== null));
const maxDistance = computed(() => Math.max(1, ...plotted.value.map(row => row.distance)));
const maxError = computed(() => Math.max(1, ...plotted.value.map(row => Math.abs(row.fuel_error_mean))));
const pointX = row => 45 + row.distance / maxDistance.value * 610;
const pointY = row => 100 - row.fuel_error_mean / maxError.value * 65;
function download() {
    const url = URL.createObjectURL(new Blob([JSON.stringify(props.evidence, null, 2)], { type: 'application/json' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `spacetraders-evidence-${String(props.evidence.reset_id).replace(/[^a-zA-Z0-9-]/g, '_')}.json`;
    link.click();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <section v-if="evidence" class="evidence" aria-labelledby="evidence-heading">
        <h3 id="evidence-heading">Our travel and mining evidence</h3>
        <p>{{ evidence.policy }}</p>
        <p>{{ evidence.travel_samples_in_window }} of {{ evidence.total_travel_samples }} travel samples and {{ evidence.mining_runs_in_window }} of {{ evidence.total_mining_runs }} mining runs are in this analysis window. Older raw evidence remains saved.</p>
        <SecondaryButton @click="download">Download evidence summary</SecondaryButton>
        <details><summary>Travel calibration · {{ evidence.travel.length }} route/configuration groups</summary>
            <p>{{ evidence.travel_limits }}</p>
            <p v-if="!routes.length">No confirmed travel samples yet. Approved manual and automated travel will collect them.</p>
            <template v-else>
                <div v-if="plotted.length" class="chart-scroll" tabindex="0" aria-label="Scrollable travel fuel error chart"><svg viewBox="0 0 700 200" role="img" aria-label="Fuel prediction error by distance. Positive errors exceed the prediction; negative errors use less fuel. Compare matching ship configurations.">
                    <line x1="45" y1="100" x2="655" y2="100" stroke="#8ba5be" stroke-dasharray="4" />
                    <line x1="45" y1="25" x2="45" y2="170" stroke="#8ba5be" />
                    <text x="50" y="20">Actual minus predicted fuel · tank units</text><text x="45" y="190">0</text><text x="500" y="190">Distance → {{ number(maxDistance) }}</text>
                    <circle v-for="row in plotted" :key="`${row.configuration}:${row.from}:${row.to}`" :cx="pointX(row)" :cy="pointY(row)" r="5" :fill="row.fuel_error_mean > 0 ? '#f4b37d' : '#9ae6c6'">
                        <title>{{ row.from }} → {{ row.to }} · {{ row.configuration.slice(0, 8) }} · {{ row.sample_count }} samples · mean error {{ number(row.fuel_error_mean) }}</title>
                    </circle>
                </svg></div>
                <p>Positive error means the prediction was exceeded. Compare matching configurations and modes; these points do not establish a formula. Displaying {{ routes.length }} of {{ evidence.travel.length }} groups.</p>
                <div class="table-scroll"><table><thead><tr><th>Route / configuration</th><th>Samples / overruns</th><th>Actual fuel min / mean / max</th><th>Mean fuel error</th><th>Server duration / error</th></tr></thead><tbody>
                    <tr v-for="row in routes" :key="`${row.configuration}:${row.from}:${row.to}`"><td>{{ row.from }} → {{ row.to }}<br>{{ row.mode }} · {{ row.configuration.slice(0, 8) }}<br>Distance {{ number(row.distance) }} · speed {{ number(row.engine_speed) }}</td><td>{{ row.sample_count }} / {{ row.fuel_overruns }}<br v-if="row.invalid_fuel_samples"><span v-if="row.invalid_fuel_samples">{{ row.invalid_fuel_samples }} invalid fuel samples excluded</span></td><td>{{ number(row.actual_fuel_min) }} / {{ number(row.actual_fuel_mean) }} / {{ number(row.actual_fuel_max) }}</td><td>{{ number(row.fuel_error_mean) }}</td><td>{{ number(row.server_duration_mean) }}s / {{ number(row.duration_error_mean) }}s<br>{{ row.server_duration_samples }} timed samples</td></tr>
                </tbody></table></div>
            </template>
        </details>
        <details><summary>Mining departure policies · {{ evidence.mining_loads.length }} comparison groups</summary>
            <p>{{ evidence.mining_limits }}</p>
            <p v-if="!evidence.mining_loads.length">No approved mining runs have started yet. One extraction remains the default.</p>
            <details v-for="(load, index) in evidence.mining_loads.slice(-50)" :key="index"><summary>{{ load.site }} → {{ load.market }} · up to {{ load.max_extractions }} extractions · {{ load.runs }} runs</summary><DataDetails :value="load" /></details>
        </details>
    </section>
</template>

<style scoped>
.evidence { border-top: 1px solid var(--color-line); margin-top: 22px; padding-top: 12px; }
h3 { font-weight: 700; margin: 12px 0; }
p { margin: 12px 0; color: var(--color-muted); }
details { margin: 16px 0; }
summary { color: var(--color-accent); cursor: pointer; min-height: 40px; }
summary:focus-visible { outline: 3px solid var(--color-focus); outline-offset: 4px; }
.chart-scroll { overflow-x: auto; }
.chart-scroll:focus-visible { outline: 3px solid var(--color-focus); outline-offset: 4px; }
svg { min-width: 600px; width: 100%; background: var(--color-field); margin: 12px 0; }
svg text { fill: var(--color-ink); font-size: 12px; }
.table-scroll { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { text-align: left; vertical-align: top; border-bottom: 1px solid var(--color-line); padding: 12px; }
th { color: var(--color-accent); background: var(--color-field); }
</style>
