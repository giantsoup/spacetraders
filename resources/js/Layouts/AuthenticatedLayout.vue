<script setup>
import AppShell from '@/Layouts/AppShell.vue';
</script>

<template>
    <AppShell>
        <main id="main-content" tabindex="-1" class="app-content">
            <header v-if="$slots.header" class="app-page-heading">
                <slot name="header" />
            </header>
            <slot />
        </main>
    </AppShell>
</template>
