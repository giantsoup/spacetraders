<script setup>
import { computed, nextTick, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { lessons } from '@/tutorial.js';
import { useTutorial } from '@/Composables/useTutorial.js';

const props = defineProps({ agent: { type: Object, default: null }, compact: Boolean });
const page = usePage();
const { progress, storageAvailable, index, lesson, finished, isComplete, select, toggleComplete, reset } = useTutorial(() => page.props.auth.user.id, () => props.agent);
const expanded = ref(false);
const confirmingReset = ref(false);
const heading = ref(null);
const destinations = {
    fleet: ['fleet', 'Open fleet'], setup: ['setup', 'Open mining setup'],
    waypoints: ['waypoints', 'Open local waypoints'], manual: ['manual', 'Open manual commands'],
    market: ['market', 'Open cargo and market'], activity: ['activity', 'Open activity history'],
};
const action = computed(() => {
    if (!props.agent) return { href: route('agents.create'), label: 'Connect an agent' };
    if (!lesson.value.target || lesson.value.target === 'connect') return { href: route('agents.show', props.agent.id), label: 'Open flight deck' };
    const [anchor, label] = destinations[lesson.value.target];
    return { href: `${route('agents.show', props.agent.id)}#${anchor}`, label };
});
async function go(id) {
    select(id);
    await nextTick();
    heading.value?.focus();
}
async function restart() {
    reset();
    confirmingReset.value = false;
    await nextTick();
    heading.value?.focus();
}
</script>

<template>
    <section class="tutorial" :class="{ compact }" aria-label="Early-game tutorial">
        <div class="course-header">
            <div>
                <p class="kicker">FLIGHT SCHOOL / EARLY GAME</p>
                <h2 v-if="compact">{{ finished ? 'Early-game checklist complete' : 'Early-game tutorial' }}</h2>
                <p v-if="compact" class="current-lesson">Current lesson: {{ lesson.title }}</p>
                <p class="progress-label" role="status">{{ progress.completed.length }} of {{ lessons.length }} lessons checked off</p>
            </div>
            <div v-if="compact" class="course-actions">
                <button type="button" :aria-expanded="expanded" aria-controls="deck-lesson" @click="expanded = !expanded">{{ expanded ? 'Hide lesson' : 'Show lesson' }}</button>
                <Link :href="route('agents.guide', agent.id)">Full player guide</Link>
            </div>
        </div>
        <progress :value="progress.completed.length" :max="lessons.length" aria-label="Lessons checked off">{{ progress.completed.length }} / {{ lessons.length }}</progress>
        <p class="save-note">Player-confirmed checklist. Saved only in this browser, {{ agent ? 'for this agent and world reset' : 'as preparation until you select an agent' }}. Game actions still need preview and approval.</p>
        <p v-if="!storageAvailable" class="storage-warning" role="status">Browser storage is unavailable or unreadable. You can continue, but progress may be lost when you leave.</p>
        <div v-show="!compact || expanded" :id="compact ? 'deck-lesson' : undefined" class="course-body">
            <nav v-if="!compact" aria-label="Tutorial lessons" class="lesson-nav">
                <ol>
                    <li v-for="(entry, position) in lessons" :key="entry.id">
                        <button type="button" :aria-current="lesson.id === entry.id ? 'step' : undefined" @click="go(entry.id)">
                            <span class="step-number" aria-hidden="true">{{ progress.completed.includes(entry.id) ? '✓' : String(position + 1).padStart(2, '0') }}</span>
                            <span>{{ entry.title }}<span v-if="progress.completed.includes(entry.id)" class="sr-only">, checked off</span></span>
                        </button>
                    </li>
                </ol>
            </nav>
            <article class="lesson">
                <p class="kicker">{{ lesson.label }} · LESSON {{ index + 1 }} OF {{ lessons.length }}</p>
                <h2 ref="heading" tabindex="-1" class="lesson-heading">{{ lesson.title }}</h2>
                <p class="goal">{{ lesson.goal }}</p>
                <p>{{ lesson.explanation }}</p>
                <p v-if="agent && lesson.id === 'agent'" class="goal">{{ agent.name }} is already saved. Open its flight deck and continue with the next lesson. You can check off this connection step without registering again.</p>
                <h3>Do this step by step</h3>
                <ol class="instructions"><li v-for="step in lesson.steps" :key="step">{{ step }}</li></ol>
                <div class="lesson-links">
                    <a v-if="compact && lesson.target && lesson.target !== 'connect'" :href="`#${destinations[lesson.target][0]}`" class="primary-link">{{ action.label }} <span aria-hidden="true">↓</span></a>
                    <Link v-else :href="action.href" class="primary-link">{{ action.label }} <span aria-hidden="true">↗</span></Link>
                    <a v-if="lesson.id === 'agent'" href="https://my.spacetraders.io" target="_blank" rel="noopener noreferrer">Official account dashboard <span class="sr-only">opens in a new tab</span></a>
                </div>
                <aside class="recovery"><h3>If you get stuck</h3><p>{{ lesson.recovery }}</p></aside>
                <label class="checkpoint">
                    <input type="checkbox" :checked="isComplete" @change="toggleComplete">
                    <span>{{ lesson.checkpoint }}</span>
                </label>
                <p class="check-note">Check this after doing the steps. This does not verify or run game commands.</p>
                <div v-if="finished" class="completion" role="status">
                    <h3>Early-game checklist complete</h3>
                    <p>You have checked off the manual basics. Keep using small trips and reviewing outcomes. Mid-game and late-game lessons will come later.</p>
                    <Link v-if="agent" :href="route('agents.show', agent.id)">Return to your flight deck</Link>
                </div>
                <div class="lesson-pagination">
                    <button type="button" :disabled="index === 0" @click="go(lessons[index - 1].id)">Previous lesson</button>
                    <button v-if="index < lessons.length - 1" type="button" @click="go(lessons[index + 1].id)">Next lesson <span aria-hidden="true">→</span></button>
                    <button v-else-if="!finished" type="button" @click="go(lessons.find(entry => !progress.completed.includes(entry.id)).id)">Review unchecked lessons</button>
                </div>
            </article>
        </div>
        <div v-if="!compact" class="restart">
            <button v-if="!confirmingReset" type="button" @click="confirmingReset = true">Reset checklist</button>
            <template v-else>
                <p>Clear this checklist and return to the first lesson? Your game is unaffected.</p>
                <button type="button" @click="restart">Clear checklist</button>
                <button type="button" @click="confirmingReset = false">Keep progress</button>
            </template>
        </div>
    </section>
</template>

<style scoped>
.tutorial{color-scheme:dark;color:#e4edf7;background:#101b2b;border:1px solid #344b65;padding:24px;min-width:0;font:15px/1.65 ui-sans-serif,system-ui,sans-serif;overflow-wrap:anywhere}.tutorial *{box-sizing:border-box}.course-header,.course-actions,.lesson-links,.lesson-pagination{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}.course-actions{justify-content:flex-start}.tutorial .kicker{color:#a6f3d0;font:11px/1.5 ui-monospace,monospace;letter-spacing:.12em;margin:0 0 8px}.tutorial h2{font-size:24px;line-height:1.3;font-weight:750;letter-spacing:0;border:0;color:#eef5ff;margin:0 0 12px;padding:0}.tutorial h3{font-size:15px;font-weight:700;color:#eef5ff;margin:22px 0 8px}.tutorial p{margin:10px 0}.tutorial .progress-label{font-size:13px;color:#bbccdf;margin:0}.tutorial progress{display:block;width:100%;height:6px;appearance:none;border:0;background:#24374c;margin:14px 0}.tutorial progress::-webkit-progress-bar{background:#24374c}.tutorial progress::-webkit-progress-value{background:#a6f3d0}.tutorial progress::-moz-progress-bar{background:#a6f3d0}.tutorial .save-note,.tutorial .check-note{font-size:12px;color:#b4c5d9;line-height:1.6}.tutorial .storage-warning{color:#ffe0a3;border-left:3px solid #c7a66e;padding:10px;background:#29271f}.course-body{display:grid;grid-template-columns:245px minmax(0,1fr);gap:30px;margin-top:28px}.lesson-nav{border-right:1px solid #344b65;padding-right:20px}.lesson-nav ol{list-style:none;margin:0;padding:0;display:grid;gap:6px}.tutorial button{font:inherit;min-height:44px;padding:10px 14px;background:#20354a;color:#edf5ff;border:1px solid #6f8da8;border-radius:4px;cursor:pointer;white-space:normal;line-height:1.5}.tutorial button:hover:not(:disabled){background:#304c67}.tutorial button:disabled{opacity:.45;cursor:not-allowed}.tutorial a{color:#a6f3d0;text-decoration:underline;text-underline-offset:4px}.tutorial :is(button,a,input):focus-visible,.lesson-heading:focus{outline:3px solid #f7dc91;outline-offset:4px}.tutorial .lesson-nav button{display:flex;gap:12px;align-items:baseline;width:100%;text-align:left;border:1px solid transparent;background:transparent;color:#bacbde;font-size:13px;padding:11px}.tutorial .lesson-nav button[aria-current]{border-color:#87d9bb;background:#193832;color:#dcfff1}.step-number{font:12px ui-monospace,monospace;min-width:20px}.lesson{min-width:0;max-width:800px}.tutorial .goal{color:#b4ebd8;font-size:17px;line-height:1.6}.instructions{padding-left:24px;list-style:decimal;margin:16px 0;display:grid;gap:15px}.instructions li{padding-left:6px}.instructions li::marker{color:#a6f3d0;font-weight:700}.lesson-links{justify-content:flex-start;margin:24px 0}.tutorial .primary-link{display:inline-flex;align-items:center;gap:16px;min-height:44px;background:#a6f3d0;color:#0a241d;border:1px solid #a6f3d0;padding:10px 16px;border-radius:4px;text-decoration:none;font-weight:700}.tutorial .recovery{background:#1c2735;border-left:3px solid #c7a66e;padding:12px 18px;color:#d4deeb;font-size:14px}.tutorial .recovery h3{margin:0 0 6px;color:#ffe0a3}.tutorial .recovery p{margin:0}.tutorial .checkpoint{display:flex;gap:12px;align-items:start;padding:18px;background:#132d29;border:1px solid #4e8a77;margin-top:24px;color:#d9fff0;font-size:14px;cursor:pointer}.tutorial .checkpoint input{appearance:auto;accent-color:#a6f3d0;width:20px;height:20px;min-height:20px;flex:0 0 20px;margin:2px 0 0;padding:0}.lesson-pagination{border-top:1px solid #344b65;padding-top:20px;margin-top:24px}.tutorial .completion{border:1px solid #7ccbac;background:#183b31;padding:18px;margin-top:20px}.tutorial .completion h3{margin:0}.restart{border-top:1px solid #344b65;margin-top:24px;padding-top:18px;font-size:13px}.restart button+button{margin-left:10px}.compact{margin:0 0 24px;border-color:#4e8a77}.compact .course-body{grid-template-columns:minmax(0,1fr);margin-top:20px}.compact .lesson{max-width:none}.compact h2{font-size:19px}.compact .course-actions{font-size:13px}@media(max-width:850px){.course-body{grid-template-columns:minmax(0,1fr);gap:24px}.lesson-nav{border-right:0;border-bottom:1px solid #344b65;padding:0 0 20px}.lesson-nav ol{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:520px){.tutorial{padding:18px}.lesson-nav ol{grid-template-columns:minmax(0,1fr)}.tutorial h2{font-size:22px}.course-actions{width:100%}.lesson-pagination{align-items:stretch}.lesson-pagination button{flex:1}.tutorial .checkpoint{padding:14px}}
</style>
