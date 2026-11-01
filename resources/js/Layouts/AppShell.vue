<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

defineProps({
    brand: { type: String, default: 'ST // AGENT COMMAND' },
    skipTo: { type: String, default: 'main-content' },
    skipLabel: { type: String, default: 'Skip to content' },
    guideHref: { type: String, default: null },
    agentId: { type: Number, default: null },
});

const page = usePage();
const signedIn = computed(() => Boolean(page.props.auth?.user));
</script>

<template>
    <div class="app-shell">
        <a :href="`#${skipTo}`" class="skip">{{ skipLabel }}</a>
        <header class="app-topbar">
            <Link :href="signedIn ? route('agents.index') : '/'" class="app-brand">{{ brand }}</Link>
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
                        <Link :href="route('register')" :aria-current="route().current('register') ? 'page' : undefined">Register</Link>
                    </template>
                </slot>
            </nav>
        </header>
        <slot />
    </div>
</template>
