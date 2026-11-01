<script setup>
import AppShell from '@/Layouts/AppShell.vue';
import { formatLocalTime } from '@/dateTime.js';
import { Head, Link } from '@inertiajs/vue3';
import TutorialCourse from '@/Components/TutorialCourse.vue';
import GameConnection from '@/Components/GameConnection.vue';
import { glossary } from '@/tutorial.js';

defineProps({ agent: { type: Object, default: null }, agents: { type: Array, required: true } });
</script>

<template>
    <Head title="New player guide" />
    <AppShell brand="ST // FLIGHT SCHOOL" skip-to="course" skip-label="Skip to tutorial" :agent-id="agent?.id" class="guide-page">
        <main>
            <GameConnection />
            <div class="intro">
                <p class="eyebrow">NEW PLAYER GUIDE</p>
                <h1>Your first flight. Your first sale.</h1>
                <p>A guided route through the early-game basics, from connecting your agent to bringing a mining ship home ready for its next trip.</p>
                <div class="scope"><span>10 lessons</span><span>At your own pace</span><span>Real flight-deck controls</span></div>
            </div>
            <section class="agent-context" aria-labelledby="context-heading">
                <h2 id="context-heading">{{ agent ? `Flying with ${agent.name}` : 'Start here, then choose your agent' }}</h2>
                <p>{{ agent ? `Checklist for ${agent.name} · Reset ${formatLocalTime(agent.reset_id, agent.reset_id || 'not observed yet')}. Select another agent to switch checklists.` : 'You can read every lesson before connecting an agent. Preparation progress is separate from each agent’s checklist.' }}</p>
                <nav v-if="agents.length" aria-label="Tutorial agent" class="agent-links">
                    <Link v-for="entry in agents" :key="entry.id" :href="route('agents.guide', entry.id)" :aria-current="entry.id === agent?.id ? 'page' : undefined">{{ entry.name }}</Link>
                </nav>
                <Link v-else :href="route('agents.create')">Connect your first agent →</Link>
            </section>
            <section class="agent-context" aria-labelledby="guided-heading">
                <h2 id="guided-heading">Play with step-by-step guidance</h2>
                <p>Follow the highlighted buttons and fields in an established order. The guided flight names your exact ship, waypoints, quantities, and totals, and waits for each command to be confirmed.</p>
                <Link :href="agent ? route('agents.show', { agent: agent.id, guided: 1 }) : route('agents.create', { guided: 1 })" class="guided-launch">{{ agent ? 'Start guided flight' : 'Begin guided agent setup' }} →</Link>
                <p>The lessons below remain available as a reference handbook.</p>
            </section>
            <div id="course" tabindex="-1"><TutorialCourse :key="agent?.id || 'preparation'" :agent="agent" /></div>
            <section class="reference" aria-labelledby="reference-heading">
                <h2 id="reference-heading">Keep these within reach</h2>
                <details>
                    <summary>Flight-deck glossary</summary>
                    <dl><template v-for="[term, definition] in glossary" :key="term"><dt>{{ term }}</dt><dd>{{ definition }}</dd></template></dl>
                </details>
                <details>
                    <summary>When a command does not move forward</summary>
                    <p>Preview means awaiting your approval. Pending means saved for the local worker. Sending means the upstream request may be in flight. Confirmed means the action was accepted. Failed or blocked needs the displayed reason resolved. Uncertain means inspect and reconcile before more writes.</p>
                    <p>Run the queue worker from this project’s folder if approved commands stay pending. Optional mining automation also needs the scheduler and SPACETRADERS_AUTOMATION_ENABLED=true in the local environment. Leave automation disabled until you are ready for a bounded trip.</p>
                    <pre>php artisan queue:work database --queue=spacetraders --sleep=1 --tries=1 --timeout=120</pre>
                    <pre>php artisan schedule:work</pre>
                    <p>Use separate terminals for these processes. Stop automation in the flight deck and let in-flight work settle before stopping the processes. Closing a tab does not stop them. Mac sleep pauses execution. A stale heartbeat can also mean an idle worker, so inspect the action status.</p>
                </details>
                <details>
                    <summary>What this client supports, and what comes later</summary>
                    <p>Available now: agent registration and import, local observation, same-system navigation, docking, orbit, mining, cargo trades, refueling, one-time complete-miner setup, and bounded mining automation.</p>
                    <p>Later work: mid-game and late-game courses, contracts, surveys, equipment management, repairs, intersystem travel, and general fleet expansion. Some official quickstart steps use those features and cannot be followed in this client yet.</p>
                    <p>World resets invalidate old agent credentials. Import current credentials and synchronize. Checklists are kept separately for each agent and observed reset. Clearing browser data removes checklists; they are not account backups.</p>
                </details>
                <p class="sources">Game concepts checked against the <a href="https://docs.spacetraders.io/quickstart/new-game" target="_blank" rel="noopener noreferrer">official new-game guide<span class="sr-only">, opens in a new tab</span></a> and <a href="https://docs.spacetraders.io/openapi" target="_blank" rel="noopener noreferrer">SpaceTraders API documentation<span class="sr-only">, opens in a new tab</span></a>. Instructions follow the controls available in this client.</p>
            </section>
        </main>
        <footer>ST // FLIGHT SCHOOL · Early-game course · Mid-game and late-game lessons coming later</footer>
    </AppShell>
</template>

<style scoped>
.guide-page{font-family:ui-sans-serif,system-ui,sans-serif;line-height:1.7}.guide-page main a{color:var(--color-accent);text-underline-offset:4px}.guide-page :is(a,summary):focus-visible{outline:3px solid var(--color-focus);outline-offset:4px}.guide-page main{max-width:1200px;margin:auto;padding:48px 24px}.intro{max-width:780px;margin-bottom:32px}.eyebrow{color:var(--color-accent);font:12px ui-monospace,monospace;letter-spacing:.15em}.guide-page h1{font-size:clamp(30px,4vw,46px);font-weight:750;line-height:1.15;margin:16px 0 20px;letter-spacing:-.025em}.intro>p:not(.eyebrow){font-size:18px;color:#bdcfe2}.scope{display:flex;flex-wrap:wrap;gap:12px 24px;margin-top:24px;font:12px ui-monospace,monospace;color:var(--color-accent)}.agent-context{border-left:3px solid #7ccbac;padding:16px 22px;background:#13232e;margin-bottom:24px}.guide-page .guided-launch{display:inline-flex;align-items:center;min-height:44px;background:var(--color-accent);color:#0a241d;padding:10px 16px;margin:10px 0;text-decoration:none;font-weight:700}.guide-page h2{font-weight:700;font-size:18px;margin:0 0 8px}.guide-page p{margin:8px 0}.agent-context p{font-size:13px;color:#bacbde}.agent-links{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}.agent-links a{padding:8px 14px;min-height:44px;border:1px solid #58708b;border-radius:4px;text-decoration:none}.agent-links a[aria-current]{border-color:var(--color-accent);background:#1c3b34;color:#e0fff2}.reference{margin-top:36px}.reference h2{margin-bottom:16px}.reference details{border-bottom:1px solid #344b65;padding:16px 0}.reference summary{cursor:pointer;color:#b5ebd8;min-height:44px;padding:8px 0;font-weight:600}.reference p,.reference dl{max-width:850px;color:#bacbde}.reference dt{font-weight:700;color:var(--color-ink);margin-top:16px}.reference dd{margin-top:4px}.reference pre{overflow-x:auto;white-space:pre-wrap;overflow-wrap:anywhere;background:var(--color-panel);border:1px solid #344b65;padding:16px;font:12px/1.7 ui-monospace,monospace}.reference .sources{font-size:12px;margin-top:24px}.guide-page footer{max-width:1200px;margin:auto;padding:0 24px 32px;color:#a9bed3;font:11px/1.8 ui-monospace,monospace}@media(max-width:600px){.guide-page main{padding:28px 14px}.agent-context{padding:14px 18px}.intro>p:not(.eyebrow){font-size:16px}}
</style>
