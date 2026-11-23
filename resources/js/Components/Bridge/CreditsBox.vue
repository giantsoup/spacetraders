<script setup>
import ShortMoney from '@/Components/Bridge/ShortMoney.vue';
import { clock, elapsed, fullTime, timestamp, tone, validNumber } from '@/bridgeFormat.js';
import { computed } from 'vue';

const props = defineProps({ strip: Object, full: Boolean, updatedAt: String, dataAsOf: String, now: Number, ships: Number, routes: Number });
const stale = computed(() => props.now - timestamp(props.updatedAt) > 45000);
const observedAt = computed(() => props.dataAsOf || props.strip?.data_as_of);
const oldData = computed(() => props.now - timestamp(observedAt.value) > 600000);
</script>

<template>
    <div class="bridge-panel bridge-credits" :class="{ 'bridge-credits-full': full }">
        <div class="bridge-credits-inner">
            <div>
                <div class="bridge-detail bridge-credit-label">Credits</div>
                <strong class="bridge-balance"><ShortMoney :value="strip?.credits" /></strong>
            </div>
            <div class="bridge-credit-changes">
                <span>
                    <span :class="tone(strip?.hour_delta)"><span v-if="strip?.hour_delta" aria-hidden="true">{{ strip.hour_delta > 0 ? '▲' : '▼' }} </span><ShortMoney :value="strip?.hour_delta" delta /></span> last hour
                </span>
                <template v-if="full"> · <span><ShortMoney :class="tone(strip?.wipe_delta)" :value="strip?.wipe_delta" delta /> this wipe</span></template>
                <span v-else-if="validNumber(ships) || validNumber(routes)" class="bridge-detail">{{ [validNumber(ships) ? `${ships} ${ships === 1 ? 'ship' : 'ships'}` : '', validNumber(routes) ? `${routes} ${routes === 1 ? 'route' : 'routes'}` : ''].filter(Boolean).join(' · ') }}</span>
            </div>
        </div>
        <div class="bridge-credit-stamp" :class="{ 'bridge-warning': stale }" role="status">
            <template v-if="stale">Not updated since <time :datetime="updatedAt" :title="fullTime(updatedAt)">{{ clock(updatedAt) }}</time> — reconnecting</template>
            <template v-else-if="updatedAt">Updated <time :datetime="updatedAt" :title="fullTime(updatedAt)">{{ clock(updatedAt) }}</time></template>
            <template v-else>Update time unavailable</template>
        </div>
        <p v-if="oldData" class="bridge-credit-stamp bridge-warning">Ship data {{ elapsed(observedAt, now) }} · <time :datetime="observedAt">{{ clock(observedAt) }}</time></p>
        <slot />
    </div>
</template>
