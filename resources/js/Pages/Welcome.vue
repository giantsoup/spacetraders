<script setup>
import AppShell from '@/Layouts/AppShell.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({ canLogin: Boolean, canRegister: Boolean });
</script>

<template>
    <Head title="Welcome" />
    <AppShell>
        <main id="main-content" tabindex="-1" class="app-content welcome-content">
            <header class="app-page-heading">
                <p class="app-eyebrow">YOUR FLEET. YOUR NEXT MOVE.</p>
                <h1>SpaceTraders</h1>
                <p class="app-muted">Your command terminal for exploring systems, mining resources, and trading cargo.</p>
            </header>
            <section class="app-panel" aria-labelledby="first-flight-heading">
                <p class="app-eyebrow">FLIGHT SCHOOL</p>
                <h2 id="first-flight-heading" class="mt-2">Start with your first flight</h2>
                <p class="app-muted mt-3">Create a client login or sign in, then follow the new player guide from connecting an agent to selling your first cargo.</p>
                <div class="app-form-actions justify-start">
                    <Link v-if="canLogin" :href="route('login')" class="app-button app-button-primary">Log in</Link>
                    <Link v-if="canRegister" :href="route('register')" class="app-button app-button-secondary">Create client login</Link>
                    <Link :href="route('guide')" class="app-link">New player guide →</Link>
                </div>
            </section>
            <footer class="mt-10 text-sm text-muted">
                <p>Client developed by <a href="https://github.com/giantsoup/spacetraders" class="app-link">Taylor Oyer</a></p>
                <p>Game and API by <a href="https://spacetraders.io" class="app-link">SpaceTraders</a></p>
            </footer>
        </main>
    </AppShell>
</template>

<style scoped>
.welcome-content { max-width: 960px; padding-top: clamp(40px, 10vh, 120px); }
.welcome-content h1 { font-size: clamp(36px, 7vw, 64px); }
</style>
