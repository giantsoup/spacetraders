<script setup>
import WaypointSprite from '@/Components/SystemMap/WaypointSprite.vue';
defineProps({ location: Object, reset: String, transit: Boolean, paused: Boolean, progress: { type: Number, default: null }, shipName: String });
defineEmits(['report']);
</script>
<template>
    <div class="ship-scene" :class="{ still: paused, transit }">
        <svg v-if="transit" class="scene-route" viewBox="0 0 320 100" preserveAspectRatio="none" aria-hidden="true"><path d="M40 75 L250 30" stroke="#526881" stroke-width="1" stroke-dasharray="2 5"/><path v-if="progress !== null" :d="`M40 75 L${40 + progress * 210} ${75 - progress * 45}`" stroke="#a6f3d0" stroke-width="1"/></svg>
        <div class="scene-location" aria-hidden="true" :data-location-symbol="location.symbol || ''">
            <WaypointSprite :point="location" :reset="reset" />
            <span class="scene-location-label" :title="location.symbol"><span class="scene-location-context">{{ transit ? 'Destination' : 'Observed location' }}</span>{{ location.symbol || 'Location unknown' }}</span>
        </div>
        <button type="button" class="scene-ship" :aria-label="`${shipName || 'Selected ship'}, open ship report`" @click="$emit('report')">
            <svg viewBox="0 0 32 24" aria-hidden="true" shape-rendering="crispEdges"><g v-if="transit" class="flame"><path fill="#f7aa62" d="M1 10h9v4H1zM4 8h6v8H4z"/><path fill="#f7dc91" d="M5 10h6v4H5z"/></g><path fill="#526881" d="M10 4h6v3h5v3h6v4h-6v3h-5v3h-6v-5H8V9h2z"/><path fill="#dce8f0" d="M12 6h4v3h7v2h5v2h-5v2h-7v3h-4v-5h-2v-2h2z"/><path fill="#a6f3d0" d="M16 10h5v4h-5z"/><path fill="#4d8e8d" d="M17 10h3v2h-3z"/><path fill="#8997be" d="M11 5h3v3h-3zM11 16h3v3h-3z"/></svg>
        </button>
    </div>
</template>
<style scoped>
.ship-scene { position: relative; height: 230px; background: radial-gradient(ellipse at top right, #14274488, transparent 65%); }
.ship-scene::before { content: ''; position: absolute; inset: 0; background-image: radial-gradient(#97a8bc88 .7px, transparent .8px), radial-gradient(#97a8bc66 .7px, transparent .8px); background-size: 43px 37px, 71px 61px; background-position: 12px 7px, 24px 18px; }
.scene-route { position: absolute; inset: 0; width: 100%; height: 100%; }
.scene-location { position: absolute; left: 78.125%; top: 4%; width: 112px; transform: translateX(-50%); pointer-events: none; text-align: center; }
.scene-location > svg { display: block; width: 112px; height: 112px; margin: 0 auto; }
.scene-location-label { display: block; margin-top: 4px; color: #c4d5e8; font: 10px/1.4 ui-monospace, monospace; overflow-wrap: anywhere; }
.scene-location-context { display: block; color: #91a8c1; font: 10px/1.4 var(--font-interface); }
.scene-ship { position: absolute; left: 8%; bottom: 12%; width: 80px; min-height: 60px; border: 0; background: transparent; cursor: pointer; }
.scene-ship svg { width: 80px; height: 60px; animation: ship-bob 4s ease-in-out infinite; }
.still svg { animation-play-state: paused; }
@keyframes ship-bob { 50% { transform: translateY(-5px); } }
@media(max-height:719px) { .ship-scene { height: 128px; } .scene-location > svg { width: 80px; } .scene-location > svg { height: 80px; } }
@media(max-height:600px) { .ship-scene { height: 72px; } .scene-ship, .scene-ship svg { width: 56px; height: 44px; } .scene-location > svg { width: 48px; } .scene-location > svg { height: 48px; } .scene-location-context { display: none; } .scene-location-label { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; } }
@media(prefers-reduced-motion:reduce) { .scene-ship svg { animation: none; } }
</style>
