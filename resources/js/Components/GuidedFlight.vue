<script setup>
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({ session: Object, step: Object, storageAvailable: Boolean, busy: Boolean });
const emit = defineEmits(['start', 'pause', 'resume', 'advance', 'sync', 'recover', 'clear-expired']);
const panel = ref(null);
const confirmingRestart = ref(false);
let highlighted;
let described;
let previousDescription;
const stageLabels = { welcome: '1 / Begin', sync: '2 / Observe', plan: '3 / Review route', briefing: '4 / Check ship', outbound: '5 / Travel and setup', extract: '6 / Extract', inbound: '7 / Return to market', marketSync: '7 / Observe market', sell: '8 / Sell cargo', refuel: '9 / Refill tank', review: 'Trip review', complete: 'Complete', connect: 'Connect your agent' };

function clear() {
    highlighted?.classList.remove('guided-target');
    if (described) {
        if (previousDescription === null) described.removeAttribute('aria-describedby');
        else described.setAttribute('aria-describedby', previousDescription);
    }
    highlighted = described = null;
}
async function highlight(focus = false) {
    clear();
    await nextTick();
    if (!props.session?.active || !props.step || props.step.kind === 'complete') return;
    highlighted = document.querySelector(`[data-guide="${CSS.escape(props.step.target)}"]`);
    if (!highlighted || highlighted === panel.value) return;
    highlighted.classList.add('guided-target');
    described = highlighted.matches('button,input,select,a') ? highlighted : highlighted.querySelector('input,select,button,a');
    if (described) {
        previousDescription = described.getAttribute('aria-describedby');
        described.setAttribute('aria-describedby', [previousDescription, 'guided-instruction'].filter(Boolean).join(' '));
    }
    if (panel.value?.contains(highlighted)) {
        if (focus && described && !described.disabled) described.focus({ preventScroll: true });
        return;
    }
    const offset = (panel.value?.offsetHeight || 0) + 24;
    highlighted.style.setProperty('--guide-offset', `${offset}px`);
    const reveal = props.step.kind === 'approve' && !focus ? highlighted.closest('.approval') || highlighted : highlighted;
    reveal.style.setProperty('--guide-offset', `${offset}px`);
    const bounds = reveal.getBoundingClientRect();
    const editing = document.activeElement?.matches('input,select');
    if (focus || (!editing && (bounds.top < offset || bounds.top > window.innerHeight - 100))) reveal.scrollIntoView({ block: 'start', behavior: 'instant' });
    if (focus && described && !described.disabled) described.focus({ preventScroll: true });
}
onMounted(() => highlight());
watch(() => [props.session?.active, props.step?.target, props.step?.title], () => highlight(), { flush: 'post' });
onUnmounted(clear);
</script>

<template>
    <aside ref="panel" data-guide="guided-coach" :data-step-kind="step?.kind" :data-step-phase="session?.phase" class="guided-coach" :class="{ running: session?.active }" aria-label="Guided first flight">
        <template v-if="!session || !session.active">
            <p class="guided-kicker">HANDS-ON FLIGHT SCHOOL</p>
            <h2>{{ session ? 'Your guided flight is paused' : 'Let’s fly your first trip together' }}</h2>
            <p>Follow one highlighted control at a time. Get exact ship, waypoint, quantity, and price instructions. Game commands advance only after their recorded results are confirmed.</p>
            <div class="guided-buttons">
                <button v-if="!session" type="button" @click="emit('start')">Start guided flight</button>
                <button v-else type="button" @click="emit('resume')">Resume guided flight</button>
            </div>
        </template>
        <template v-else-if="step">
            <div class="guided-header"><p class="guided-kicker">GUIDED FLIGHT · {{ stageLabels[session.phase] }}<span v-if="session.pending"> · #{{ session.pending.id }}</span></p><button type="button" @click="emit('pause')">Pause guidance</button></div>
            <div aria-live="polite" aria-atomic="true"><h2>{{ step.title }}</h2><p id="guided-instruction">{{ step.body }}</p></div>
            <p v-if="!storageAvailable" role="status" class="guided-warning">Browser storage is unavailable. Stay on this page to keep your guided progress.</p>
            <div class="guided-buttons">
                <button v-if="step.kind === 'read' || step.kind === 'plan'" data-guide="continue-guidance" type="button" :disabled="busy || step.canContinue === false" @click="emit('advance')">{{ step.kind === 'plan' ? 'Use this route' : step.next === 'complete' ? 'Finish guided flight' : 'Continue guided flight' }}</button>
                <button v-if="step.kind !== 'complete' && step.target !== 'guided-coach'" type="button" @click="highlight(true)">Show highlighted control</button>
                <button v-if="step.canSync" type="button" :disabled="busy" @click="emit('sync')">Refresh observations</button>
                <button v-if="step.kind === 'recover'" type="button" :disabled="busy" @click="emit('recover')">Restore saved preview</button>
                <button v-if="step.kind === 'expired'" type="button" :disabled="busy" @click="emit('clear-expired')">Clear expired preview tracking</button>
                <button v-if="!confirmingRestart && !session.pending" type="button" @click="confirmingRestart = true">Restart guidance</button>
                <template v-if="confirmingRestart">
                    <span>Restart instructions? Existing commands and cargo stay recorded.</span>
                    <button type="button" @click="confirmingRestart = false; emit('start')">Restart instructions</button><button type="button" @click="confirmingRestart = false">Keep current step</button>
                </template>
            </div>
        </template>
    </aside>
</template>

<style scoped>
.guided-coach{color-scheme:dark;background:#142b35;color:#e9f4fc;border:2px solid #79d7b1;padding:18px 22px;margin:0 0 24px;font:15px/1.55 ui-sans-serif,system-ui,sans-serif;overflow-wrap:anywhere}.guided-coach.running{position:sticky;top:0;z-index:40;box-shadow:0 8px 24px #02080fe6}.guided-coach .guided-kicker{font:11px/1.5 ui-monospace,monospace;letter-spacing:.1em;color:#a6f3d0;margin:0}.guided-coach h2{border:0;padding:0;margin:6px 0;color:#f1f8ff;font-size:20px;line-height:1.3;letter-spacing:0}.guided-coach p{margin:8px 0}.guided-header,.guided-buttons{display:flex;gap:8px 14px;align-items:center;justify-content:space-between;flex-wrap:wrap}.guided-buttons{justify-content:flex-start;margin-top:12px}.guided-coach button{font:13px/1.4 ui-sans-serif,system-ui,sans-serif;min-height:44px;color:#e9f4fc;background:#233e51;border:1px solid #8eb3c6;padding:9px 12px;cursor:pointer}.guided-coach button:focus-visible{outline:3px solid #ffe09a;outline-offset:3px}.guided-coach .guided-warning{color:#ffe09a}.guided-coach button:disabled{opacity:.5;cursor:not-allowed}@media(max-width:600px){.guided-coach{padding:12px;font-size:13px}.guided-coach h2{font-size:17px}.guided-coach .guided-header button{min-height:36px;padding:6px 8px}.guided-buttons{gap:6px}.guided-coach button{font-size:12px}.guided-coach.running{max-height:45vh;overflow-y:auto}}
</style>
