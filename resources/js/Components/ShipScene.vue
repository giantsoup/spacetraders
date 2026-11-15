<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import WaypointSprite from '@/Components/SystemMap/WaypointSprite.vue';
import { waypointSprite } from '@/waypointSprites.js';

const props = defineProps({
    location: { type: Object, required: true }, reset: String, transit: Boolean, paused: Boolean,
    pose: { type: String, default: 'unknown' }, progress: { type: Number, default: null }, shipName: String,
    compact: Boolean, interactive: { type: Boolean, default: true },
});
defineEmits(['report']);
const scene = ref(null);
const bounds = ref({ width: 0, height: 230 });
let observer;
onMounted(() => {
    observer = new ResizeObserver(([entry]) => { bounds.value = entry.contentRect; });
    observer.observe(scene.value);
});
onUnmounted(() => observer?.disconnect());
const geometry = computed(() => {
    const { width, height } = bounds.value;
    const tiny = height <= 72;
    const centred = props.pose === 'docked' || props.pose === 'orbit';
    const scale = centred ? tiny ? 3 : height <= 128 ? 5 : 9 : (tiny ? 48 : height <= 128 ? 80 : 112) / 24;
    const shipScale = tiny ? 1 : centred && height > 128 ? 4 : 2;
    const pixels = waypointSprite(props.location, props.reset).pixels.filter(pixel => pixel.y >= 10 && pixel.y <= 13);
    const minX = pixels.length ? Math.min(...pixels.map(pixel => pixel.x)) : 6;
    const maxX = pixels.length ? Math.max(...pixels.map(pixel => pixel.x)) : 17;
    const centreY = height / 2;
    const groupWidth = 23 * shipScale + (maxX + 1 - minX) * scale;
    const port = width / 2 - groupWidth / 2 + 23 * shipScale;
    const radiusX = (maxX + 1 - minX) * scale / 2 + 4.6 * scale;
    const radiusY = 4.4 * scale;
    const rotation = -14 * Math.PI / 180;
    const ellipseX = radiusX * Math.cos(150 * Math.PI / 180);
    const ellipseY = radiusY * Math.sin(150 * Math.PI / 180);
    const orbitX = Math.round(ellipseX * Math.cos(rotation) - ellipseY * Math.sin(rotation));
    const orbitY = Math.round(ellipseX * Math.sin(rotation) + ellipseY * Math.cos(rotation));
    const locationLeft = props.pose === 'docked' ? port - minX * scale : width * (centred ? .5 : .78125) - 12 * scale;
    const locationTop = centred ? centreY - 12 * scale : height * .04;
    const routeStart = { x: width * .125, y: height * .75 };
    const routeEnd = { x: locationLeft + minX * scale - 32, y: locationTop + 12 * scale };
    const progress = props.pose === 'arriving' ? 1 : props.progress ?? 0;
    const shipX = props.pose === 'docked' ? width / 2 - groupWidth / 2 - 8 * shipScale
        : props.pose === 'orbit' ? width / 2 + orbitX - 16 * shipScale
        : props.pose === 'transit' || props.pose === 'arriving' ? routeStart.x + progress * (routeEnd.x - routeStart.x) - 16 * shipScale : width * .08;
    const shipY = props.pose === 'docked' ? centreY : props.pose === 'orbit' ? centreY + orbitY
        : props.pose === 'transit' || props.pose === 'arriving' ? routeStart.y + progress * (routeEnd.y - routeStart.y) : height * .88 - 12 * shipScale;
    const controlHeight = Math.max(44, (props.pose === 'orbit' ? 16 : 24) * shipScale);
    return {
        scale, shipScale, controlHeight, centreY, port, radiusX, radiusY, locationLeft, locationTop,
        shipLeft: Math.max(0, Math.min(width - 32 * shipScale, shipX)),
        shipTop: tiny ? 4 : Math.max(5, Math.min(height - controlHeight, shipY - controlHeight / 2)),
        route: 'M' + routeStart.x + ' ' + routeStart.y + ' L' + routeEnd.x + ' ' + routeEnd.y,
        trail: 'M' + routeStart.x + ' ' + routeStart.y + ' L' + (routeStart.x + progress * (routeEnd.x - routeStart.x)) + ' ' + (routeStart.y + progress * (routeEnd.y - routeStart.y)),
        routeStart,
    };
});
const position = (left, top) => ({ left: left + 'px', top: top + 'px' });
const ringPath = front => 'M' + -geometry.value.radiusX + ' 0 A' + geometry.value.radiusX + ' ' + geometry.value.radiusY + ' 0 0 ' + (front ? 0 : 1) + ' ' + geometry.value.radiusX + ' 0';
const ringTransform = computed(() => 'translate(' + bounds.value.width / 2 + ' ' + geometry.value.centreY + ') rotate(-14)');
</script>
<template>
    <div ref="scene" class="ship-scene" :class="{ still: paused, transit, compact }" :data-pose="pose" :style="{ '--s': geometry.scale + 'px', '--k': geometry.shipScale + 'px', '--control-height': geometry.controlHeight + 'px' }">
        <svg v-if="pose === 'orbit'" class="scene-ring back" aria-hidden="true"><path :transform="ringTransform" :d="ringPath(false)" /></svg>
        <svg v-if="pose === 'transit' || pose === 'arriving'" class="scene-route" aria-hidden="true">
            <path :d="geometry.route" stroke="#526881" stroke-width="1.5" stroke-dasharray="3 6" />
            <path v-if="progress !== null || pose === 'arriving'" :d="geometry.trail" stroke="#a6f3d0" stroke-width="1.5" />
            <rect :x="geometry.routeStart.x - 3" :y="geometry.routeStart.y - 3" width="6" height="6" fill="#526881" />
        </svg>
        <div class="scene-location" aria-hidden="true" :data-location-symbol="location.symbol || ''" :style="position(geometry.locationLeft, geometry.locationTop)"><WaypointSprite :point="location" :reset="reset" /></div>
        <svg v-if="pose === 'orbit'" class="scene-ring front" aria-hidden="true"><path :transform="ringTransform" :d="ringPath(true)" /></svg>
        <template v-if="pose === 'docked'">
            <div class="scene-clamp" aria-hidden="true" :style="position(geometry.port - 3 * geometry.shipScale, geometry.centreY - 2.5 * geometry.shipScale)" />
            <div class="scene-port" aria-hidden="true" :style="position(geometry.port + geometry.scale / 2 - geometry.shipScale, geometry.centreY - geometry.shipScale)" />
        </template>
        <component :is="interactive ? 'button' : 'div'" :type="interactive ? 'button' : undefined" class="scene-ship" :aria-hidden="interactive ? undefined : true" :aria-label="interactive ? (shipName || 'Selected ship') + ', open ship report' : undefined" :style="position(geometry.shipLeft, geometry.shipTop)" @click="interactive && $emit('report')">
            <svg viewBox="0 0 32 24" aria-hidden="true" shape-rendering="crispEdges"><g v-if="pose === 'transit'" class="flame"><path fill="#f7aa62" d="M1 10h9v4H1zM4 8h6v8H4z"/><path fill="#f7dc91" d="M5 10h6v4H5z"/></g><path fill="#526881" d="M10 4h6v3h5v3h6v4h-6v3h-5v3h-6v-5H8V9h2z"/><path fill="#dce8f0" d="M12 6h4v3h7v2h5v2h-5v2h-7v3h-4v-5h-2v-2h2z"/><path fill="#a6f3d0" d="M16 10h5v4h-5z"/><path fill="#4d8e8d" d="M17 10h3v2h-3z"/><path fill="#8997be" d="M11 5h3v3h-3zM11 16h3v3h-3z"/></svg>
        </component>
        <span class="scene-location-label" :class="{ corner: pose === 'docked' || pose === 'orbit' }" aria-hidden="true" :title="location.symbol" :style="position(geometry.locationLeft + 12 * geometry.scale - 56, geometry.locationTop + 24 * geometry.scale + 4)"><span class="scene-location-context">{{ transit ? 'Destination' : 'Observed location' }}</span>{{ location.symbol || 'Location unknown' }}</span>
    </div>
</template>
<style scoped>
.ship-scene { position: relative; height: 230px; overflow: hidden; background: radial-gradient(ellipse at top right, #14274488, transparent 65%); }
.ship-scene::before { content: ''; position: absolute; inset: 0; background-image: radial-gradient(#97a8bc88 .7px, transparent .8px), radial-gradient(#97a8bc66 .7px, transparent .8px); background-size: 43px 37px, 71px 61px; background-position: 12px 7px, 24px 18px; pointer-events: none; }
.scene-route, .scene-ring { position: absolute; inset: 0; width: 100%; height: 100%; fill: none; pointer-events: none; }
.scene-ring path { stroke-width: max(1.5px, calc(var(--k) / 2)); stroke-dasharray: calc(var(--k) * 1.5) calc(var(--k) * 3); animation: ring-drift 3s linear infinite; }
.scene-ring.back { stroke: #526881; }
.scene-ring.front { stroke: #a6f3d0; }
.scene-location { position: absolute; width: calc(24 * var(--s)); height: calc(24 * var(--s)); pointer-events: none; }
.scene-location > svg { display: block; width: 100%; height: 100%; }
.scene-location-label { position: absolute; width: 112px; max-width: 100%; color: #c4d5e8; font: 10px/1.4 ui-monospace, monospace; overflow-wrap: anywhere; text-align: center; pointer-events: none; }
.scene-location-context { display: block; color: #91a8c1; font: 10px/1.4 var(--font-interface); }
.scene-location-label.corner { left: 2px !important; top: auto !important; bottom: 0; width: auto; max-width: calc(100% - 4px); text-align: left; }
.scene-ship { position: absolute; display: flex; align-items: center; justify-content: center; width: calc(32 * var(--k)); height: var(--control-height); min-height: 44px; padding: 0; border: 0; background: transparent; }
button.scene-ship { cursor: pointer; }
.scene-ship svg { display: block; flex-shrink: 0; width: calc(32 * var(--k)); height: calc(24 * var(--k)); }
[data-pose="orbit"] .scene-ship svg, [data-pose="transit"] .scene-ship svg, [data-pose="unknown"] .scene-ship svg { animation: ship-bob 4s ease-in-out infinite; }
[data-pose="unknown"] .scene-ship { opacity: .45; }
.flame { animation: flame-flicker .35s steps(2, end) infinite; }
.scene-clamp { position: absolute; width: calc(3 * var(--k)); height: calc(5 * var(--k)); border-block: var(--k) solid #8997be; pointer-events: none; }
.scene-port { position: absolute; width: calc(2 * var(--k)); height: calc(2 * var(--k)); background: #a6f3d0; box-shadow: 0 0 8px #a6f3d0; animation: port-blink 2.4s steps(1, end) infinite; pointer-events: none; }
.still *, .still *::before, .still *::after { animation-play-state: paused !important; }
@keyframes ship-bob { 50% { transform: translateY(-4px); } }
@keyframes flame-flicker { 50% { opacity: .55; } }
@keyframes ring-drift { to { stroke-dashoffset: calc(-4.5 * var(--k)); } }
@keyframes port-blink { 50% { opacity: .35; } }
@media(max-height:639px) { .ship-scene:not(.compact) { height: 128px; } }
@media(max-height:600px) { .ship-scene:not(.compact) { height: 72px; } .ship-scene:not(.compact) .scene-location-label { left: 12px !important; top: auto !important; bottom: 4px; width: auto; max-width: calc(100% - 24px); text-align: left; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; } .ship-scene:not(.compact) .scene-location-context { display: none; } .ship-scene:not(.compact) .scene-ship svg { animation: none; } }
.ship-scene.compact { height: 128px; }
@media(prefers-reduced-motion:reduce) { .ship-scene *, .ship-scene *::before, .ship-scene *::after { animation: none !important; } }
</style>
