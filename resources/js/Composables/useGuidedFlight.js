import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { flightStep, guidedKey, newFlight, restoreFlight, settleFlight } from '@/guidedFlight.js';

export function useGuidedFlight(userId, context) {
    const session = ref(null);
    const storageAvailable = ref(true);
    const now = ref(Date.now());
    const key = computed(() => guidedKey(userId(), context().agent));
    const step = computed(() => flightStep(session.value, context(), now.value));
    const finished = computed(() => session.value?.phase === 'complete' && !session.value.active);
    let timer;
    function read() {
        try { session.value = restoreFlight(JSON.parse(window.localStorage.getItem(key.value))); }
        catch { session.value = null; storageAvailable.value = false; }
    }
    function update(value) {
        session.value = value;
        try { window.localStorage.setItem(key.value, JSON.stringify(value)); storageAvailable.value = true; }
        catch { storageAvailable.value = false; }
    }
    function receive(event) { if (event.key === key.value || event.key === null) read(); }
    onMounted(() => {
        read();
        timer = window.setInterval(() => { now.value = Date.now(); }, 1000);
        window.addEventListener('storage', receive);
        if (new URL(window.location.href).searchParams.get('guided') === '1' && !session.value) update(newFlight(context().agent.credits));
    });
    onUnmounted(() => { window.clearInterval(timer); window.removeEventListener('storage', receive); });
    watch(key, (next, previous) => {
        if (previous.endsWith(':unknown') && session.value?.phase === 'sync') update(session.value);
        else read();
    });
    watch([() => session.value?.pending?.id, () => context().actions], () => {
        if (!session.value?.pending) return;
        const action = context().actions.find(action => action.id === session.value.pending.id);
        if (!action) return;
        const next = settleFlight(session.value, action);
        if (next !== session.value) update(next);
    });
    function start() { update(newFlight(context().agent.credits)); }
    function pause() { if (session.value) update({ ...session.value, active: false }); }
    function resume() { if (session.value) update({ ...session.value, active: true }); }
    function finish() {
        if (session.value?.phase === 'complete') update({ ...session.value, active: false });
    }
    function advance() {
        if (step.value?.kind === 'plan') update({ ...session.value, plan: step.value.plan, reserveFloor: Math.max(session.value.reserveFloor || 0, step.value.plan.reserve), phase: 'briefing' });
        else if (step.value?.kind === 'read') update({ ...session.value, phase: step.value.next });
    }
    function synchronized() {
        if (!session.value?.active || session.value.pending) return;
        const phase = session.value.phase;
        if (phase === 'sync') update({ ...session.value, phase: 'plan' });
        else if (phase === 'outbound' && session.value.plan.kind === 'scout') {
            const ship = context().ships.find(ship => ship.symbol === session.value.plan.ship)?.snapshot;
            if (ship?.nav.waypointSymbol === session.value.plan.site && ship.nav.status !== 'IN_TRANSIT') update({ ...session.value, scouted: [...session.value.scouted, session.value.plan.site], plan: null, phase: 'plan' });
        } else if (phase === 'marketSync' || phase === 'inbound') {
            const ship = context().ships.find(ship => ship.symbol === session.value.plan.ship)?.snapshot;
            if (ship?.nav.waypointSymbol === session.value.plan.market && ship.nav.status === 'DOCKED') update({ ...session.value, phase: 'sell' });
        }
    }
    function trackApproval(action, intent) {
        if (!session.value?.active || !intent || action.command !== intent.command || action.ship_symbol !== session.value.plan?.ship) return;
        update({ ...session.value, pending: { id: action.id, command: intent.command, args: intent.args, next: intent.next } });
    }
    function clearExpired() {
        if (step.value?.kind === 'expired') update({ ...session.value, pending: null });
    }
    return { session, step, storageAvailable, finished, active: computed(() => !!session.value?.active), start, pause, resume, finish, advance, synchronized, trackApproval, clearExpired };
}
