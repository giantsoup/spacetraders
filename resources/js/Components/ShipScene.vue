<script setup>
defineProps({ transit: Boolean, paused: Boolean, progress: { type: Number, default: null }, shipName: String });
defineEmits(['report']);
</script>
<template>
    <div class="ship-scene" :class="{ still: paused, transit }">
        <svg v-if="transit" class="scene-route" viewBox="0 0 320 100" preserveAspectRatio="none" aria-hidden="true"><path d="M40 75 L280 25" stroke="#526881" stroke-width="1" stroke-dasharray="2 5"/><path v-if="progress !== null" :d="`M40 75 L${40 + progress * 240} ${75 - progress * 50}`" stroke="#a6f3d0" stroke-width="1"/></svg>
        <svg class="scene-station" viewBox="0 0 32 24" aria-hidden="true" shape-rendering="crispEdges"><path fill="#75869c" d="M14 3h5v18h-5zM5 10h23v5H5z"/><path fill="#b8b1ff" d="M2 5h7v5H2zM2 15h7v5H2zM24 5h6v5h-6zM24 15h6v5h-6z"/><path fill="#dce8f0" d="M12 8h9v9h-9z"/><path fill="#a6f3d0" d="M14 10h5v5h-5z"/></svg>
        <button type="button" class="scene-ship" :aria-label="`${shipName || 'Selected ship'}, open ship report`" @click="$emit('report')">
            <svg viewBox="0 0 32 24" aria-hidden="true" shape-rendering="crispEdges"><g v-if="transit" class="flame"><path fill="#f7aa62" d="M1 10h9v4H1zM4 8h6v8H4z"/><path fill="#f7dc91" d="M5 10h6v4H5z"/></g><path fill="#526881" d="M10 4h6v3h5v3h6v4h-6v3h-5v3h-6v-5H8V9h2z"/><path fill="#dce8f0" d="M12 6h4v3h7v2h5v2h-5v2h-7v3h-4v-5h-2v-2h2z"/><path fill="#a6f3d0" d="M16 10h5v4h-5z"/><path fill="#4d8e8d" d="M17 10h3v2h-3z"/><path fill="#8997be" d="M11 5h3v3h-3zM11 16h3v3h-3z"/></svg>
        </button>
    </div>
</template>
<style scoped>
.ship-scene { position: relative; height: 230px; background: radial-gradient(ellipse at top right, #14274488, transparent 65%); }
.ship-scene::before { content: ''; position: absolute; inset: 0; background-image: radial-gradient(#97a8bc88 .7px, transparent .8px), radial-gradient(#97a8bc66 .7px, transparent .8px); background-size: 43px 37px, 71px 61px; background-position: 12px 7px, 24px 18px; }
.scene-route { position: absolute; inset: 0; width: 100%; height: 100%; }
.scene-station { position: absolute; right: 8%; top: 14%; width: 64px; height: 48px; }
.scene-ship { position: absolute; left: 8%; bottom: 12%; width: 80px; min-height: 60px; border: 0; background: transparent; cursor: pointer; }
.scene-ship svg { width: 80px; height: 60px; animation: ship-bob 4s ease-in-out infinite; }
.still svg { animation-play-state: paused; }
@keyframes ship-bob { 50% { transform: translateY(-5px); } }
@media(max-height:719px) { .ship-scene { height: 128px; } }
@media(max-height:600px) { .ship-scene { height: 72px; } .scene-ship, .scene-ship svg { width: 56px; height: 44px; } .scene-station { width: 48px; height: 36px; } }
@media(prefers-reduced-motion:reduce) { .scene-ship svg { animation: none; } }
</style>
