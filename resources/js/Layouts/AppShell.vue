<script setup>
import FocusSheet from '@/Components/FocusSheet.vue';
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

defineProps({
    compact: Boolean,
    brand: { type: String, default: 'ST // AGENT COMMAND' },
    skipTo: { type: String, default: 'main-content' },
    skipLabel: { type: String, default: 'Skip to content' },
    guideHref: { type: String, default: null },
    agentId: { type: Number, default: null },
});

const menuOpen = ref(false);
const page = usePage();
const signedIn = computed(() => Boolean(page.props.auth?.user));
</script>

<template>
    <div class="app-shell" :class="{ 'compact-shell': compact }">
        <a :href="`#${skipTo}`" class="skip">{{ skipLabel }}</a>
        <header class="app-topbar">
            <Link :href="signedIn ? route('agents.index') : '/'" class="app-brand">{{ brand }}</Link>
            <button v-if="compact" class="shell-menu app-button app-button-secondary" type="button" @click="menuOpen = true">Menu</button>
            <component :is="compact ? FocusSheet : 'div'" :open="menuOpen" title="Navigation" @close="menuOpen = false">
            <nav aria-label="Main navigation">
                <slot name="navigation">
                    <template v-if="signedIn">
                        <Link :href="route('agents.index')" :aria-current="route().current('agents.index') ? 'page' : undefined">Agents</Link>
                        <Link v-if="agentId" :href="route('agents.show', agentId)" :aria-current="route().current('agents.show') ? 'page' : undefined">Flight deck</Link>
                        <Link :href="guideHref || route('guide')" :aria-current="route().current('guide') || route().current('agents.guide') ? 'page' : undefined">Player guide</Link>
                        <Link :href="route('profile.edit')" :aria-current="route().current('profile.edit') ? 'page' : undefined">Profile</Link>
                        <Link :href="route('logout')" method="post" as="button">Log out</Link>
                    </template>
                    <template v-else>
                        <Link :href="route('login')" :aria-current="route().current('login') ? 'page' : undefined">Log in</Link>
                    </template>
                </slot>
            </nav>
            </component>
        </header>
        <slot />
    </div>
</template>

<style>
.compact-shell .app-topbar { padding: calc(4px + env(safe-area-inset-top)) max(16px, env(safe-area-inset-right)) 4px max(16px, env(safe-area-inset-left)); gap: 8px; border: 0; background: var(--color-space); flex-wrap: nowrap; }
.compact-shell .app-brand { font-size: 13px; letter-spacing: .08em; }
.compact-shell .app-topbar .focus-sheet nav { display: grid; }
.compact-shell .shell-menu { padding: 8px 12px; }
</style>
