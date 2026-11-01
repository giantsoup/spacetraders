<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const connection = computed(() => page.props.gameConnection);
</script>

<template>
    <aside v-if="connection" class="game-connection" :class="{ offline: connection.mode === 'offline' }" aria-label="Game connection">
        <strong>{{ connection.mode === 'offline' ? 'Offline practice' : 'Live SpaceTraders API' }}</strong>
        <p v-if="connection.mode === 'offline'">This environment uses synthetic game responses. Tutorial actions here do not affect your live agent.</p>
        <p v-else>The tutorial plays your real agent. Synchronize reads the live game; approval queues a real action for execution against SpaceTraders. Check Activity history for confirmation.</p>
        <span v-if="connection.mode === 'live'">{{ connection.endpoint }}</span>
    </aside>
</template>

<style scoped>
.game-connection{color-scheme:dark;background:#123029;color:#defbed;border:1px solid #78c6a4;padding:14px 18px;margin-bottom:22px;font:14px/1.6 ui-sans-serif,system-ui,sans-serif;overflow-wrap:anywhere}.game-connection strong{display:block;font-weight:700;color:var(--color-accent)}.game-connection p{margin:5px 0}.game-connection span{color:#b7d6c8;font:12px/1.6 ui-monospace,monospace}.game-connection.offline{background:#302819;border-color:#bd9e61;color:#fff0d0}.game-connection.offline strong{color:#ffe09a}
</style>
