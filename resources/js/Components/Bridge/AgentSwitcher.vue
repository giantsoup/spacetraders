<script setup>
import FocusSheet from '@/Components/FocusSheet.vue';
import ShortMoney from '@/Components/Bridge/ShortMoney.vue';
import { bridgeHref, label } from '@/bridgeFormat.js';
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({ agent: String, agents: { type: Array, default: () => [] }, tab: String });
const open = ref(false);
const stopped = computed(() => props.agents.filter(entry => entry.needs_you > 0));
const ordered = computed(() => [...stopped.value, ...props.agents.filter(entry => !(entry.needs_you > 0))]);
const otherCount = computed(() => stopped.value.filter(entry => entry.symbol !== props.agent).length);
const accessibleName = computed(() => {
    const badge = otherCount.value === 1 ? '1 other agent needs you. ' : `${otherCount.value} other agents need you. `;
    return `Agent ${props.agent}. ${otherCount.value ? badge : ''}Switch agent`;
});
</script>

<template>
    <template v-if="agent">
        <button v-if="agents.length > 1" type="button" class="bridge-agent" aria-haspopup="dialog" :aria-label="accessibleName" @click="open = true">
            {{ agent }} <span aria-hidden="true">▾</span>
            <span v-if="otherCount" class="bridge-badge" aria-hidden="true">{{ otherCount }}</span>
        </button>
        <span v-else class="bridge-agent">{{ agent }}</span>
        <FocusSheet class="bridge-sheet" title="Agents" :open="open" @close="open = false">
            <div class="bridge-panel">
                <Link v-for="entry in ordered" :key="entry.symbol" class="bridge-row" :href="bridgeHref(tab, entry.symbol)" :aria-current="entry.symbol === agent ? 'true' : undefined" @click="open = false">
                    <span class="bridge-grow"><strong>{{ entry.symbol }}</strong><span class="bridge-detail">{{ label(entry.state) }}<template v-if="entry.needs_you"> · <span class="bridge-negative">{{ entry.needs_you }} needs you</span></template></span></span>
                    <ShortMoney :value="entry.credits" />
                    <span v-if="entry.symbol === agent" class="bridge-positive" aria-hidden="true">✓</span>
                </Link>
            </div>
        </FocusSheet>
    </template>
</template>
