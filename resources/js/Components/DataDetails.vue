<script setup>
import { readableLabel, readableValue } from '@/dataPresentation.js';
import { formatLocalTime } from '@/dateTime.js';

const props = defineProps({
    value: { required: true },
    field: { type: String, default: '' },
    context: { type: String, default: '' },
    path: { type: String, default: '' },
});
const structured = value => value !== null && typeof value === 'object';
const childPath = key => props.path ? props.path + '.' + key : key;
const fieldLabel = key => {
    if (props.context !== 'ship') return readableLabel(key);
    if (childPath(key) === 'nav.waypointSymbol') return 'Current waypoint';
    if (key === 'waypointSymbol') return 'Waypoint symbol';
    if (key === 'symbol') return 'Symbol';
    if (key === 'units') return 'Units';
    if (key === 'wages') return 'Wages per crew member per hour';
    if (key === 'morale') return 'Morale (0–100)';
    return readableLabel(key);
};
const fieldValue = value => {
    if (props.context !== 'ship') return readableValue(value, props.field);
    if (value === null || value === undefined) return 'Not observed';
    if (typeof value === 'number') {
        if (!Number.isFinite(value)) return 'Unknown';
        if (['condition', 'integrity'].includes(props.field)) return Math.round(value * 100) + '%';
        if (props.path === 'crew.wages') return value + ' CR per crew member per hour';
        return String(value);
    }
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    return value === '' ? 'Not provided' : value;
};
</script>

<template>
    <div v-if="structured(value) && !Object.keys(value).length" class="data-empty">None recorded</div>
    <ol v-else-if="Array.isArray(value)" class="data-list">
        <li v-for="(item, index) in value" :key="index"><DataDetails :value="item" :field="field" :context="context" :path="path + '[' + index + ']'" /></li>
    </ol>
    <dl v-else-if="structured(value)" class="data-details">
        <div v-for="(item, key) in value" :key="key" class="data-row" :class="{ 'data-group': structured(item) }">
            <dt>{{ fieldLabel(key) }}</dt>
            <dd><DataDetails :value="item" :field="key" :context="context" :path="childPath(key)" /></dd>
        </div>
    </dl>
    <time v-else-if="formatLocalTime(value, null)" :datetime="value">{{ fieldValue(value) }}</time>
    <span v-else class="data-value">{{ fieldValue(value) }}</span>
</template>

<style scoped>
.data-details { display: block; margin: 0; font-size: 13px; }
.data-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--color-line); }
.data-row:last-child { border-bottom: 0; }
.data-row > dt { color: var(--color-muted); }
.data-row > dd { margin: 0; text-align: right; min-width: 0; }
.data-group { grid-template-columns: minmax(0, 1fr); gap: 4px; }
.data-group > dt { color: var(--color-accent); font-weight: 700; }
.data-group > dd { padding-left: 16px; border-left: 2px solid var(--color-line); text-align: left; }
.data-list { display: grid; gap: 12px; margin: 0; padding-left: 20px; list-style: decimal; }
.data-value, .data-empty { overflow-wrap: anywhere; white-space: pre-line; }
.data-empty { color: var(--color-muted); }
@media (max-width: 480px) {
    .data-row { grid-template-columns: minmax(0, 1fr); gap: 4px; }
    .data-row > dd { text-align: left; }
    .data-group > dd { padding-left: 10px; }
}
</style>
