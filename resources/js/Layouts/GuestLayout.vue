<script setup>
import AppShell from '@/Layouts/AppShell.vue';

defineProps({ title: { type: String, required: true } });
</script>

<template>
    <AppShell>
        <main id="main-content" tabindex="-1" class="app-content app-auth">
            <header class="app-page-heading">
                <p class="app-eyebrow">CLIENT ACCESS</p>
                <h1>{{ title }}</h1>
                <p class="app-muted">Your login to this client is separate from your SpaceTraders agent.</p>
            </header>
            <div class="app-panel">
                <slot />
            </div>
        </main>
    </AppShell>
</template>
