<script setup>
import AppShell from '@/Layouts/AppShell.vue';
import { Head, Link } from '@inertiajs/vue3';
import GameConnection from '@/Components/GameConnection.vue';
import { formatLocalTime, localTimeZone } from '@/dateTime.js';
import { computed } from 'vue';

const props = defineProps({
    agents: { type: Array, required: true },
    archivedAgents: { type: Array, required: true },
    resetCheckError: { type: String, default: null },
});

const groups = computed(() => [
    { id: 'current-agents', title: 'Current agents', agents: props.agents, archived: false },
    { id: 'archived-agents', title: 'Archived agents', agents: props.archivedAgents, archived: true },
]);

const connectionStates = {
    connected: { label: 'Synchronized', tone: 'good', help: 'Open the flight deck to review your fleet and synchronize the latest game state.' },
    unsynchronized: { label: 'Not synchronized', tone: 'neutral', help: 'Open the flight deck and synchronize to discover your fleet and balance.' },
    reset: { label: 'Archived · World reset', tone: 'neutral', help: 'This agent belongs to a previous world. Its saved snapshot and activity are retained for reference.' },
    invalid_credential: { label: 'Token needs attention', tone: 'warning', help: 'Import a current agent token to restore access to this agent.', reconnect: true },
};
const unknownConnection = { label: 'Connection not verified', tone: 'neutral', help: 'Open the flight deck to check this agent’s connection.' };
const connection = agent => connectionStates[agent.connection_state] || unknownConnection;
const credits = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });
</script>

<template>
    <Head title="Agents" />

    <AppShell skip-to="agents" skip-label="Skip to agents" class="agents-page">

        <main id="agents" tabindex="-1">
            <div class="page-heading">
                <div>
                    <p class="eyebrow">FLEET ACCESS</p>
                    <h1>Agents</h1>
                    <p class="muted">Choose an agent to open its flight deck and manage your fleet.</p>
                </div>
                <div class="actions">
                    <Link :href="route('agents.create', { existing: 1 })" class="action secondary">Import existing agent</Link>
                    <Link :href="route('agents.create')" class="action primary">Register new agent <span aria-hidden="true">→</span></Link>
                </div>
            </div>

            <GameConnection />
            <p v-if="resetCheckError" class="reset-warning" role="status">{{ resetCheckError }}</p>

            <section class="flight-school" aria-labelledby="new-player-heading">
                <div>
                    <p class="eyebrow">FLIGHT SCHOOL</p>
                    <h2 id="new-player-heading">New to SpaceTraders?</h2>
                    <p class="muted">Connect an agent, fly a mining ship, sell your first cargo, and refuel with step-by-step guidance.</p>
                </div>
                <Link :href="route('guide')" class="action secondary">Start the new player guide <span aria-hidden="true">→</span></Link>
            </section>

            <section v-for="group in groups" :id="group.id" :key="group.id" class="agent-section" :aria-labelledby="`${group.id}-heading`">
                <div class="section-heading">
                    <h2 :id="`${group.id}-heading`">{{ group.title }} <span class="count">{{ group.agents.length }}</span></h2>
                    <p class="muted">Saved snapshots · Times shown in {{ localTimeZone }}</p>
                </div>
                <p v-if="group.archived" class="archive-help muted">Agents from previous worlds are archived automatically. Their snapshots and activity remain available, but they cannot play in the current world.</p>

                <ul v-if="group.agents.length" class="agent-grid">
                    <li v-for="agent in group.agents" :key="agent.id" class="agent-card" :class="{ archived: group.archived }">
                        <div class="card-heading">
                            <h3>{{ agent.name }}</h3>
                            <span class="status" :class="connection(agent).tone">{{ connection(agent).label }}</span>
                        </div>
                        <dl>
                            <div><dt>Faction</dt><dd>{{ agent.faction || 'Unknown' }}</dd></div>
                            <div><dt>Last saved balance</dt><dd class="balance">{{ agent.synced_at && agent.credits !== null ? `${credits.format(agent.credits)} CR` : 'Not synchronized' }}</dd></div>
                            <div><dt>World reset</dt><dd>{{ formatLocalTime(agent.reset_id, agent.reset_id || 'Unknown') }}</dd></div>
                            <div><dt>Last synchronized</dt><dd><time v-if="agent.synced_at" :datetime="agent.synced_at">{{ formatLocalTime(agent.synced_at) }}</time><span v-else>Never</span></dd></div>
                        </dl>
                        <p class="connection-help" :class="{ warning: connection(agent).reconnect }">{{ connection(agent).help }}</p>
                        <div class="card-actions">
                            <Link :href="route('agents.show', agent.id)" class="action secondary" :aria-label="group.archived ? `View archived record for ${agent.name} from ${agent.reset_id || 'unknown world'}` : `Open flight deck for ${agent.name}`">{{ group.archived ? 'View archived record' : 'Open flight deck' }} <span aria-hidden="true">→</span></Link>
                            <Link v-if="connection(agent).reconnect" :href="route('agents.create', { existing: 1 })" class="reconnect" :aria-label="`Import current token for ${agent.name}`">Import current token</Link>
                        </div>
                    </li>
                </ul>

                <div v-else-if="!group.archived" class="empty-state">
                    <p class="eyebrow">READY FOR YOUR FIRST FLIGHT</p>
                    <h3>{{ archivedAgents.length ? 'No current agents' : 'No agents connected yet' }}</h3>
                    <p class="muted">{{ archivedAgents.length ? 'Your previous agents are archived below. Register or import an agent for the current world to start a new fleet.' : 'Your client login is ready. Import an existing SpaceTraders agent or register a new one to start building your fleet.' }}</p>
                    <Link :href="route('agents.create', { guided: 1 })" class="action primary">Begin guided agent setup <span aria-hidden="true">→</span></Link>
                </div>
                <p v-else class="empty-archive muted">No archived agents. Agents will appear here after a world reset is detected.</p>
            </section>
        </main>
    </AppShell>
</template>

<style scoped>

.agents-page main { max-width: 1280px; margin: auto; padding: 40px 24px 64px; }
.page-heading, .section-heading, .card-heading { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; }
.page-heading { margin-bottom: 28px; gap: 24px; }
.eyebrow { color: var(--color-accent); font-size: 12px; letter-spacing: .12em; }
.agents-page h1 { font-size: clamp(30px, 4vw, 40px); line-height: 1.3; font-weight: 800; margin: 6px 0 10px; }
.agents-page h2 { font-size: 18px; font-weight: 750; }
.agents-page h3 { font-size: 21px; font-weight: 750; }
.muted, dt { color: var(--color-muted); }
.actions, .card-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.action { display: inline-flex; align-items: center; justify-content: center; gap: 12px; min-height: 44px; padding: 10px 16px; border: 1px solid; font-size: 13px; font-weight: 700; text-align: center; }
.primary { background: var(--color-accent); border-color: var(--color-accent); color: #0a241d; }
.primary:hover { background: #c4ffe4; }
.secondary { background: #243c54; border-color: #7593ac; color: #f0f5ff; }
.secondary:hover { background: #365574; }
.agents-page :is(a, button):focus-visible { outline: 3px solid var(--color-focus); outline-offset: 4px; }

.flight-school { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 24px; padding: 24px; margin-bottom: 36px; border: 1px solid #456b65; border-left: 3px solid var(--color-accent); background: #13232e; }
.flight-school > div { flex: 1 1 460px; }
.flight-school h2 { margin: 6px 0 8px; }
.section-heading { margin-bottom: 18px; }
.agent-section + .agent-section { margin-top: 40px; padding-top: 32px; border-top: 1px solid var(--color-line); }
.agent-section { scroll-margin-top: 24px; }
.archive-help { margin-bottom: 20px; font-size: 13px; max-width: 850px; }
.empty-archive { padding: 20px; border: 1px dashed var(--color-line); font-size: 13px; }
.reset-warning { margin-bottom: 24px; padding: 16px; background: #302819; border: 1px solid #bd9e61; color: #ffe09a; }
.agent-card.archived { box-shadow: none; border-style: dashed; }
.section-heading p { font-size: 12px; }
.count { display: inline-block; margin-left: 8px; padding: 0 8px; border: 1px solid var(--color-line); color: var(--color-muted); font-size: 13px; }
.agent-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px; list-style: none; padding: 0; }
.agent-card { display: flex; flex-direction: column; min-width: 0; padding: 24px; border: 2px solid var(--color-line); background: var(--color-panel); box-shadow: 5px 5px 0 #05090f; }
.card-heading { align-items: start; gap: 12px; }
.status { padding: 4px 8px; border: 1px solid #58708b; font-size: 12px; }
.good { background: #123029; color: var(--color-accent); border-color: #78c6a4; }
.neutral { background: #18283b; color: #c9d7e8; }
.status.warning { background: #302819; border-color: #bd9e61; }
.warning { color: #ffe09a; }
.agent-card dl { display: grid; gap: 14px; margin: 24px 0; font-size: 13px; }
.agent-card dl > div { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.25fr); gap: 12px; }
.agent-card dd { text-align: right; }
.balance { color: #fce4a6; }
.connection-help { font-size: 13px; color: var(--color-muted); margin-bottom: 22px; }
.connection-help.warning { color: #ffe09a; }
.card-actions { margin-top: auto; padding-top: 18px; border-top: 1px solid var(--color-line); }
.reconnect { display: inline-flex; align-items: center; min-height: 44px; color: var(--color-accent); font-size: 13px; text-decoration: underline; text-underline-offset: 4px; }
.empty-state { padding: 40px 24px; border: 2px dashed var(--color-line); background: var(--color-panel); text-align: center; }
.empty-state h3 { margin: 10px 0; }
.empty-state > .muted { max-width: 620px; margin: 0 auto 24px; }

@media (max-width: 800px) {
    .agent-grid { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 480px) {

    .agents-page main { padding: 28px 16px 48px; }
    .flight-school, .agent-card { padding: 18px; }
    .page-heading .actions { width: 100%; }
    .page-heading .action { width: 100%; }
    .card-actions { align-items: stretch; flex-direction: column; }
    .reconnect { justify-content: center; }
}
</style>
