<script setup>
import LogRow from '@/Components/Bridge/LogRow.vue';
import BridgeLayout from '@/Layouts/BridgeLayout.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { bridgeHref, commodity, localDay, routeParts, waypoint } from '@/bridgeFormat.js';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, shallowRef, watch } from 'vue';

const props = defineProps({ bridge: Object, log: Object });
const page = usePage();
const agent = computed(() => props.bridge.agent?.symbol);
const filters = ['all', 'problems', 'routes', 'plan'];
const initialCursor = computed(() => new URL(page.url, 'http://bridge.local').searchParams.get('before'));
const scope = computed(() => [agent.value, props.bridge.agent?.reset_id, props.log?.filter, props.log?.route, initialCursor.value].join('|'));
const head = shallowRef(props.log?.rows || []);
const older = shallowRef(new Map());
const loadedCursor = ref(undefined);
const loading = ref(false);
const error = ref(null);

watch(scope, () => {
    head.value = props.log?.rows || [];
    older.value = new Map();
    loadedCursor.value = undefined;
    error.value = null;
});
watch(() => props.log, log => {
    if (loading.value) return;
    if (loadedCursor.value !== undefined) append(head.value);
    head.value = log?.rows || [];
});

const next = computed(() => loadedCursor.value === undefined ? props.log?.next_before : loadedCursor.value);
const resultRows = computed(() => [...new Map([...older.value, ...head.value.map(row => [row.id, row])]).values()]
    .sort((first, second) => second.id - first.id));
const selectedRoute = computed(() => routeParts(props.log?.route));
const filter = computed(() => props.log?.filter || 'all');

function filteredHref(filter, routeKey = null, before = null) {
    return bridgeHref('log', agent.value, {
        filter: filter === 'all' ? undefined : filter,
        route: routeKey || undefined,
        before: before || undefined,
    });
}

function groups(now) {
    const days = new Map();
    for (const row of resultRows.value) {
        const day = localDay(row.at, now);
        if (!days.has(day)) days.set(day, []);
        days.get(day).push(row);
    }
    return days;
}

function append(rows) {
    older.value = new Map([...older.value, ...rows.map(row => [row.id, row])]);
}

function showEarlier() {
    if (loading.value || !next.value) return;
    const requestedScope = scope.value;
    loading.value = true;
    error.value = null;
    const failed = () => {
        error.value = 'Could not load earlier events. Try again.';
        return false;
    };
    router.get(filteredHref(filter.value, props.log?.route, next.value), {}, {
        only: ['log'],
        preserveState: true,
        preserveScroll: true,
        preserveUrl: true,
        async: true,
        onSuccess: response => {
            if (scope.value !== requestedScope || !response.props.log) return;
            append(response.props.log.rows);
            loadedCursor.value = response.props.log.next_before ?? null;
        },
        onError: failed,
        onNetworkError: failed,
        onHttpException: failed,
        onFinish: () => { loading.value = false; },
    });
}
</script>

<template>
    <BridgeLayout :bridge="bridge" title="Log" :poll="initialCursor ? [] : ['log']" :polling-paused="loading">
        <template #default="{ now }">
            <h1 class="bridge-page-title">Log</h1>
            <nav class="bridge-filters" aria-label="Log filters">
                <Link v-for="item in filters" :key="item" :href="filteredHref(item)" :aria-current="filter === item ? 'page' : undefined">{{ item[0].toUpperCase() + item.slice(1) }}</Link>
            </nav>
            <p v-if="selectedRoute" class="bridge-route-filter">
                <span>{{ commodity(selectedRoute.good) }} · {{ waypoint(selectedRoute.source) }} → {{ waypoint(selectedRoute.buyer) }}</span>
                <Link class="bridge-link" :href="filteredHref('routes')">Clear route filter</Link>
            </p>
            <section v-for="[day, rows] in groups(now)" :key="day">
                <div class="bridge-section-head"><h2>{{ day }}</h2></div>
                <div class="bridge-panel">
                    <LogRow v-for="entry in rows" :key="entry.id" :entry="entry" />
                </div>
            </section>
            <p v-if="!resultRows.length" class="bridge-empty">{{ filter === 'problems' ? 'No problems logged this wipe.' : selectedRoute ? 'No loads logged for this route.' : 'No events logged for this filter.' }}</p>
            <p v-if="error" class="bridge-negative" role="alert">{{ error }}</p>
            <SecondaryButton v-if="next" class="bridge-show-earlier" :disabled="loading" @click="showEarlier">{{ loading ? 'Loading earlier events…' : 'Show earlier' }}</SecondaryButton>
        </template>
    </BridgeLayout>
</template>
