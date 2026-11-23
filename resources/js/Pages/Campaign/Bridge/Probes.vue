<script setup>
import ShipRow from '@/Components/Bridge/ShipRow.vue';
import BridgeLayout from '@/Layouts/BridgeLayout.vue';
import { currentFrom, detailBack } from '@/bridgeFormat.js';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({ bridge: Object, probes: Array });
const page = usePage();
const agent = computed(() => props.bridge.agent?.symbol);
const groups = computed(() => props.probes?.filter(group => group.count) || []);
const back = computed(() => detailBack(currentFrom(page.url), 'fleet', agent.value));
</script>

<template>
    <BridgeLayout :bridge="bridge" title="Probes" :poll="['probes']" :back="back">
        <template #default="{ now }">
            <h1 class="bridge-page-title">Probes</h1>
            <p v-if="!groups.length" class="bridge-empty">No probes to show for this wipe.</p>
            <section v-for="group in groups" :key="group.name">
                <div class="bridge-section-head"><h2>{{ group.name }} · {{ group.count }}</h2></div>
                <div class="bridge-panel">
                    <ShipRow v-for="entry in group.ships" :key="entry.symbol" :ship="entry" :agent="agent" from="probes" :now="now" />
                </div>
            </section>
        </template>
    </BridgeLayout>
</template>
