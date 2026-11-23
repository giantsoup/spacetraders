<script setup>
import AppShell from '@/Layouts/AppShell.vue';
import AgentSwitcher from '@/Components/Bridge/AgentSwitcher.vue';
import BridgeTabs from '@/Components/Bridge/BridgeTabs.vue';
import CreditsBox from '@/Components/Bridge/CreditsBox.vue';
import ReviewSheet from '@/Components/Bridge/ReviewSheet.vue';
import { bridgeHref, clock, elapsed, label, reviewFor, strategyHref, timestamp } from '@/bridgeFormat.js';
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, shallowRef } from 'vue';

const props = defineProps({
    bridge: { type: Object, required: true },
    title: { type: String, required: true },
    tab: String,
    poll: { type: Array, required: true },
    back: Object,
    ships: Number,
    routes: Number,
    pollingPaused: Boolean,
});
const page = usePage();
const agent = computed(() => props.bridge.agent?.symbol);
const tab = computed(() => props.tab || props.bridge.tab);
const phase = computed(() => /^[a-z][a-z0-9_-]*$/i.test(props.bridge.phase || '') ? label(props.bridge.phase) : '');
const brief = computed(() => props.bridge.tab === 'brief');
const strip = computed(() => props.bridge.creditsStrip);
const now = ref(Date.now());
const selectedReview = shallowRef(null);
const processing = ref(false);
const root = ref(null);
const flash = ref(null);
const main = ref(null);
const loadingReview = ref(false);
const reviewNotice = ref(null);
function reviewLoadFailed() {
    reviewNotice.value = 'Could not load the review. Try again.';
    return false;
}
let tick;
onMounted(() => { tick = setInterval(() => { now.value = Date.now(); }, 1000); });
onUnmounted(() => clearInterval(tick));

usePoll(15000, () => ({
    only: ['bridge', ...props.poll],
    onBefore: () => !processing.value && !props.pollingPaused && !loadingReview.value,
    onNetworkError: () => false,
    onHttpException: () => false,
}));

function showReview(kind, grant, opener) {
    const symbol = agent.value;
    if (!symbol || loadingReview.value) return;
    const targetGrant = kind.startsWith('arm_') ? grant : undefined;
    reviewNotice.value = null;
    const open = reviews => {
        if (symbol !== agent.value) return;
        const review = reviewFor(reviews, kind, targetGrant);
        if (!review) {
            reviewNotice.value = kind === 'fleet_pause'
                ? 'No fleet pause is available for this agent.'
                : 'This review is no longer available. Check the current fleet status.';
            return;
        }

        opener?.focus();
        selectedReview.value = {
            ...review,
            expected: JSON.parse(JSON.stringify(review.expected ?? {})),
            agent: symbol,
            kind,
            grant: targetGrant,
        };
    };

    loadingReview.value = true;
    router.get(page.url, { agent: symbol, review: kind, review_grant: targetGrant }, {
        only: ['reviews'],
        preserveState: true,
        preserveScroll: true,
        preserveUrl: true,
        async: true,
        onSuccess: page => open(page.props.reviews),
        onNetworkError: reviewLoadFailed,
        onHttpException: reviewLoadFailed,
        onFinish: () => { loadingReview.value = false; },
    });
}

async function closeReview(saved) {
    selectedReview.value = null;
    if (!saved) return;
    await nextTick();
    await nextTick();
    if (document.activeElement === document.body) {
        (flash.value || main.value?.querySelector('.bridge-flash[role="status"]') || main.value)?.focus();
    }
}

async function pauseFromMenu(close) {
    close();
    await nextTick();
    await nextTick();
    showReview('fleet_pause', null, root.value?.closest('.bridge-shell')?.querySelector('.shell-menu'));
}
</script>

<template>
    <Head :title="brief ? 'Bridge' : `${title} · Bridge`" />
    <AppShell compact bridge brand="ST //" class="bridge-shell">
        <template v-if="back" #topbar-leading>
            <Link class="bridge-back bridge-link" :href="back.href"><span aria-hidden="true">‹</span> {{ back.label }}</Link>
        </template>
        <template #topbar-center>
            <BridgeTabs :tab="tab" :agent="agent" :needs-you="bridge.needsYouCount" />
        </template>
        <template #topbar-agent>
            <div ref="root" class="bridge-agent-slot">
                <AgentSwitcher :agent="agent" :agents="bridge.campaignAgents" :tab="tab" />
            </div>
        </template>
        <template #navigation="{ close }">
            <Link v-if="agent" :href="strategyHref(agent)">How C0 decides</Link>
            <Link :href="route('campaign.show', { agent })">Classic status</Link>
            <Link v-if="bridge.flightDeckHref" :href="bridge.flightDeckHref">Flight deck</Link>
            <Link :href="route('agents.index')">Agents</Link>
            <Link :href="route('guide')">Player guide</Link>
            <Link :href="route('profile.edit')">Profile</Link>
            <Link :href="route('logout')" method="post" as="button">Log out</Link>
            <button v-if="agent" type="button" class="bridge-negative bridge-danger-quiet" @click="pauseFromMenu(close)">Pause the whole fleet…</button>
        </template>

        <main ref="main" id="main-content" tabindex="-1" class="bridge-main" :class="{ 'bridge-brief': brief }">
            <component :is="brief ? 'h1' : 'p'" class="bridge-kicker">
                Bridge<template v-if="strip?.day && strip?.days"> · Day {{ strip.day }} of {{ strip.days }}</template><template v-if="phase"> · {{ phase }} phase</template>
            </component>
            <div v-if="agent && bridge.agent.reset_id && !bridge.resetUnconfirmed && !brief" class="bridge-strip">
                <CreditsBox :strip="strip" :updated-at="bridge.updatedAt" :data-as-of="bridge.dataAsOf" :now="now" :ships="ships ?? bridge.ship_count" :routes="routes" />
            </div>
            <p v-else-if="agent && !brief" class="bridge-empty" :class="{ 'bridge-warning': now - timestamp(bridge.updatedAt) > 45000 }" role="status">
                <template v-if="now - timestamp(bridge.updatedAt) > 45000">Not updated since <time :datetime="bridge.updatedAt">{{ clock(bridge.updatedAt) }}</time> — reconnecting</template>
                <template v-else-if="bridge.updatedAt">Updated <time :datetime="bridge.updatedAt">{{ clock(bridge.updatedAt) }}</time></template>
            </p>
            <p v-if="!brief && bridge.status" ref="flash" class="bridge-flash" role="status" tabindex="-1">{{ bridge.status }}</p>
            <p v-if="loadingReview" class="bridge-flash" role="status">Loading review…</p>
            <p v-if="reviewNotice" class="bridge-negative" role="status">{{ reviewNotice }}</p>
            <slot :now="now" :show-review="showReview" />
            <p v-if="bridge.engine" class="bridge-engine">
                <template v-if="bridge.engine.heartbeat_stale">Engine isn’t responding</template>
                <template v-else>Engine {{ label(bridge.engine.state)?.toLowerCase() || 'state unavailable' }}</template>
                <template v-if="bridge.engine.heartbeat_at"> · {{ bridge.engine.heartbeat_stale ? 'last heartbeat' : 'heartbeat' }} {{ elapsed(bridge.engine.heartbeat_at, now) }}<span class="sr-only">, {{ clock(bridge.engine.heartbeat_at) }}</span></template>
                <template v-else> · no heartbeat recorded</template>
            </p>
            <slot name="footer" :show-review="showReview" />
        </main>
        <ReviewSheet :review="selectedReview" @close="closeReview" @processing="processing = $event" />
    </AppShell>
</template>
