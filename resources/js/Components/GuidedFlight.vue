<script setup>
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { readableText } from '@/dataPresentation.js';

const props = defineProps({ session: Object, step: Object, storageAvailable: Boolean, busy: Boolean });
const emit = defineEmits(['start', 'pause', 'resume', 'finish', 'advance', 'sync', 'recover', 'clear-expired']);
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
watch([
    () => props.session?.active,
    () => props.step?.target,
    () => props.step?.title,
], () => highlight(), { flush: 'post' });
onUnmounted(clear);
</script>

<template>
    <aside ref="panel" data-guide="guided-coach" :data-step-kind="step?.kind" :data-step-phase="session?.phase" class="guided-coach" :class="{ running: session?.active }" aria-label="Guided first flight">
        <template v-if="!session || !session.active">
            <p class="guided-kicker">HANDS-ON FLIGHT SCHOOL</p>
            <h2>{{ session ? 'Your guided flight is paused' : 'Let’s fly your first trip together' }}</h2>
            <p>Follow one highlighted control at a time. Get exact ship, waypoint, quantity, and price instructions. Game commands advance only after their recorded results are confirmed.</p>
            <div class="guided-buttons">
                <PrimaryButton v-if="!session" type="button" @click="emit('start')">Start guided flight</PrimaryButton>
                <PrimaryButton v-else type="button" @click="emit('resume')">Resume guided flight</PrimaryButton>
            </div>
        </template>
        <template v-else-if="step">
            <div class="guided-header"><p class="guided-kicker">GUIDED FLIGHT · {{ stageLabels[session.phase] }}<span v-if="session.pending"> · #{{ session.pending.id }}</span></p><SecondaryButton v-if="step.kind !== 'complete'" type="button" @click="emit('pause')">Pause guidance</SecondaryButton></div>
            <div aria-live="polite" aria-atomic="true"><h2>{{ readableText(step.title) }}</h2><p id="guided-instruction">{{ readableText(step.body) }}</p></div>
            <p v-if="!storageAvailable" role="status" class="guided-warning">Browser storage is unavailable. Stay on this page to keep your guided progress.</p>
            <div class="guided-buttons">
                <PrimaryButton v-if="step.kind === 'complete'" type="button" @click="emit('finish')">Finish tutorial</PrimaryButton>
                <PrimaryButton v-if="step.kind === 'read' || step.kind === 'plan'" data-guide="continue-guidance" type="button" :disabled="busy || step.canContinue === false" @click="emit('advance')">{{ step.kind === 'plan' ? 'Use this route' : step.next === 'complete' ? 'Finish guided flight' : 'Continue guided flight' }}</PrimaryButton>
                <SecondaryButton v-if="step.kind !== 'complete' && step.target !== 'guided-coach'" type="button" @click="highlight(true)">Show highlighted control</SecondaryButton>
                <SecondaryButton v-if="step.canSync" type="button" :disabled="busy" @click="emit('sync')">Refresh observations</SecondaryButton>
                <SecondaryButton v-if="step.kind === 'recover'" type="button" :disabled="busy" @click="emit('recover')">Restore saved preview</SecondaryButton>
                <SecondaryButton v-if="step.kind === 'expired'" type="button" :disabled="busy" @click="emit('clear-expired')">Clear expired preview tracking</SecondaryButton>
                <SecondaryButton v-if="!confirmingRestart && !session.pending" type="button" @click="confirmingRestart = true">Restart guidance</SecondaryButton>
                <template v-if="confirmingRestart">
                    <span>Restart instructions? Existing commands and cargo stay recorded.</span>
                    <SecondaryButton type="button" @click="confirmingRestart = false; emit('start')">Restart instructions</SecondaryButton><SecondaryButton type="button" @click="confirmingRestart = false">Keep current step</SecondaryButton>
                </template>
            </div>
        </template>
    </aside>
</template>

<style scoped>
.guided-coach { color-scheme: dark; background: #142b35; color: #e9f4fc; border: 2px solid #79d7b1; padding: 18px 22px; margin: 0 0 24px; font: 14px/1.6 var(--font-terminal); overflow-wrap: anywhere; }
.guided-coach.running { position: sticky; top: 0; z-index: 40; box-shadow: 0 8px 24px #02080fe6; }
.guided-coach .guided-kicker { font-size: 11px; letter-spacing: .1em; color: var(--color-accent); margin: 0; }
.guided-coach h2 { border: 0; padding: 0; margin: 6px 0; color: #f1f8ff; font-size: 20px; line-height: 1.3; letter-spacing: 0; }
.guided-coach p { margin: 8px 0; }
.guided-header, .guided-buttons { display: flex; gap: 16px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
.guided-buttons { justify-content: flex-start; margin-top: 16px; }
.guided-coach .guided-warning { color: var(--color-focus); }
@media (max-width: 600px) {
    .guided-coach { padding: 16px; font-size: 13px; }
    .guided-coach h2 { font-size: 17px; }
    .guided-coach.running { max-height: 45vh; overflow-y: auto; }
}
</style>
