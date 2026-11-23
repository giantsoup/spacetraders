<script setup>
import RouteRow from '@/Components/Bridge/RouteRow.vue';
import BridgeLayout from '@/Layouts/BridgeLayout.vue';
import { currentFrom, detailBack } from '@/bridgeFormat.js';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({ bridge: Object, endedRoutes: Array });
const page = usePage();
const agent = computed(() => props.bridge.agent?.symbol);
const back = computed(() => detailBack(currentFrom(page.url), 'routes', agent.value));
</script>

<template>
    <BridgeLayout :bridge="bridge" title="Ended routes" :poll="['endedRoutes']" :back="back">
        <h1 class="bridge-page-title">Ended routes</h1>
        <div v-if="endedRoutes?.length" class="bridge-panel">
            <RouteRow v-for="entry in endedRoutes" :key="entry.key || `${entry.good}-${entry.buyer}`" :entry="entry" :agent="agent" from="ended" />
        </div>
        <p v-else class="bridge-empty">No routes have ended this wipe.</p>
    </BridgeLayout>
</template>
