<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppShell from '@/Layouts/AppShell.vue';
import LocationInformation from '@/Components/SystemMap/LocationInformation.vue';
import LocationCard from '@/Components/SystemMap/LocationCard.vue';
import WaypointSprite from '@/Components/SystemMap/WaypointSprite.vue';
import ObservationStamp from '@/Components/SystemMap/ObservationStamp.vue';
import MapIcon from '@/Components/SystemMap/MapIcon.vue';
import { assetName } from '@/dataPresentation.js';
import { clamp, closeUpZoom, fitCamera, isPlotted, mapLabels, markerGroups, markerSize, panCamera, project, wheelZoomFactor, zoomCamera } from '@/systemMap.js';
import { shipSelectionKey } from '@/shipSelection.js';
const props = defineProps({ agent: Object, ships: Array, map: Object, freshnessSeconds: Number, selectedLocation: String });
const viewport = ref(null), canvas = ref(null), selected = ref(props.selectedLocation || null), directory = ref(null), knowledge = ref(null), knowledgeOpen = ref(false);
const size = reactive({ width: 1000, height: 600 });
const camera = ref({ x: 0, y: 0, scale: 1 });
const now = ref(Date.now());
const informationOpen = ref(false);
const page = usePage();
const shipSymbol = computed(() => props.map.ship?.symbol || '');
const plotted = computed(() => props.map.locations.filter(isPlotted));
const unplotted = computed(() => props.map.locations.filter(p => !isPlotted(p)));
const fitScale = computed(() => fitCamera(plotted.value, size).scale);
const maxZoom = computed(() => closeUpZoom(fitScale.value));
const diameter = computed(() => markerSize(camera.value.scale, fitScale.value));
const atCloseUp = computed(() => camera.value.scale >= maxZoom.value);
const groups = computed(() => mapLabels(markerGroups(plotted.value, camera.value, size, diameter.value), size, diameter.value));
watch(maxZoom, limit => { if (camera.value.scale > limit) camera.value = { ...camera.value, scale: limit }; });
const point = computed(() => props.map.locations.find(p => p.symbol === selected.value));
const nearby = computed(() => groups.value.find(g => g.members.some(m => m.point.symbol === selected.value))?.members.map(m => m.point) || (point.value ? [point.value] : []));
const position = computed(() => plotted.value.find(p => p.symbol === props.map.ship?.position));
const shipScreen = computed(() => position.value ? project(position.value, camera.value, size) : null);
const selectedScreen = computed(() => point.value && isPlotted(point.value) ? project(point.value, camera.value, size) : null);
const cardStyle = computed(() => {
    const anchor = selectedScreen.value || { x: size.width - 430, y: 150 };
    return { left: `${clamp(anchor.x + diameter.value / 2 + 14, 12, size.width - 392)}px`, top: `${clamp(anchor.y - 60, 12, Math.max(12, size.height - Math.min(560, size.height - 24) - 12))}px` };
});
const grid = computed(() => { const desired = 80 / camera.value.scale, base = 10 ** Math.floor(Math.log10(desired)); const step = [1, 2, 5, 10].find(n => n * base >= desired) * base; return { step, pixels: step * camera.value.scale, origin: project({ x: 0, y: 0 }, camera.value, size) }; });
function fit() { camera.value = fitCamera(plotted.value, size); }
function zoom(factor, anchor = { x: size.width / 2, y: size.height / 2 }) { camera.value = zoomCamera(camera.value, factor, anchor, size, maxZoom.value); }
function centerShip() { if (position.value) camera.value = { ...camera.value, x: position.value.x, y: position.value.y }; }
function chooseShip(event) { router.get(route('agents.map', props.agent.id), { ship: event.target.value }); }
let returnFocus, observer, timer;
async function select(symbol, event) {
    const opener = event?.currentTarget;
    const withinCard = Boolean(opener?.closest('.location-card'));
    if (!withinCard) returnFocus = opener?.closest('.map-directory')?.querySelector('summary') || opener || returnFocus;
    selected.value = symbol;
    rememberLocation(symbol);

    knowledgeOpen.value = false;
    if (directory.value) directory.value.open = false;
    await nextTick();
    if (withinCard) opener.scrollIntoView({ block: 'nearest' });
    else viewport.value?.querySelector('.map-close')?.focus({ preventScroll: true });
}
function rememberLocation(symbol) {
    const address = new URL(window.location.href);
    if (symbol) address.searchParams.set('location', symbol);
    else address.searchParams.delete('location');
    router.replace({ url: address.pathname + address.search + address.hash, props: current => ({ ...current, selectedLocation: symbol }), preserveState: true, preserveScroll: true });
}
function toggleKnowledge() {
    knowledgeOpen.value = !knowledgeOpen.value;
    if (knowledgeOpen.value && directory.value) directory.value.open = false;
}
function directoryToggled() { if (directory.value?.open) knowledgeOpen.value = false; }
function outside(event) {
    const mapGesture = event.target.closest('.system-canvas') && (dragged || multiTouch);
    if (knowledgeOpen.value && !event.target.closest('.map-knowledge')) knowledgeOpen.value = false;
    if (point.value && !mapGesture && !event.target.closest('.location-card, .map-marker, .map-directory, .map-controls, .map-header, .map-knowledge')) close(false);
}
function close(restore = true) {
    if (!selected.value) return;
    selected.value = null;
    rememberLocation(null);
    if (!restore) return;
    if (returnFocus?.isConnected) returnFocus.focus({ preventScroll: true });
    else canvas.value?.focus({ preventScroll: true });
}
function escape(event) {
    if (event.target.closest('dialog[open]')) return;
    if (knowledgeOpen.value) { event.preventDefault(); event.stopPropagation(); knowledgeOpen.value = false; knowledge.value?.querySelector('.map-knowledge-toggle')?.focus({ preventScroll: true }); }
    else if (point.value) { event.preventDefault(); event.stopPropagation(); close(); }
}
const pointers = new Map();
let gesture = null, start = null, dragged = false, multiTouch = false;
function local(event) { const bounds = canvas.value.getBoundingClientRect(); return { x: event.clientX - bounds.left, y: event.clientY - bounds.top }; }
function pointerDown(event) {
    if (event.button && event.pointerType !== 'touch') return;
    event.preventDefault();
    if (!pointers.size) { dragged = false; multiTouch = false; } else multiTouch = true;
    pointers.set(event.pointerId, local(event));
    if (!event.target.closest('.map-marker')) { canvas.value.focus({ preventScroll: true }); canvas.value.setPointerCapture(event.pointerId); }
    gesture = [...pointers.values()];
    start = gesture[0];
}
function pointerMove(event) {
    if (!pointers.has(event.pointerId)) return;
    pointers.set(event.pointerId, local(event));
    const current = [...pointers.values()];
    if (!dragged && current.length === 1 && Math.hypot(current[0].x - start.x, current[0].y - start.y) < 4) return;
    if (!canvas.value.hasPointerCapture(event.pointerId)) canvas.value.setPointerCapture(event.pointerId);
    if (current.length >= 2 && gesture.length >= 2) {
        const midpoint = list => ({ x: (list[0].x + list[1].x) / 2, y: (list[0].y + list[1].y) / 2 });
        const a = midpoint(gesture), b = midpoint(current);
        const distance = list => Math.hypot(list[0].x - list[1].x, list[0].y - list[1].y);
        if (distance(gesture) > 0) zoom(distance(current) / distance(gesture), a);
        camera.value = panCamera(camera.value, b.x - a.x, b.y - a.y);
        dragged = true;
    } else if (current.length === 1 && gesture.length === 1) {
        const dx = current[0].x - gesture[0].x, dy = current[0].y - gesture[0].y;
        if (Math.abs(dx) + Math.abs(dy) > 0) { camera.value = panCamera(camera.value, dx, dy); dragged = true; }
    }
    gesture = current;
}
function pointerUp(event) {
    if (!pointers.has(event.pointerId)) return;
    pointers.delete(event.pointerId);
    gesture = [...pointers.values()];
    if (!pointers.size && !dragged && !multiTouch && event.type === 'pointerup' && !event.target.closest('.map-marker')) close(false);
}
function pointerLost(event) { if (event.target === canvas.value) pointerUp(event); }
function wheel(event) { zoom(wheelZoomFactor(event.deltaY, event.deltaMode, size.height), local(event)); }
function keydown(event) {
    if (event.target !== canvas.value || event.ctrlKey || event.metaKey || event.altKey) return;
    const moves = { ArrowLeft: [70, 0], ArrowRight: [-70, 0], ArrowUp: [0, 70], ArrowDown: [0, -70] };
    if (moves[event.key]) camera.value = panCamera(camera.value, ...moves[event.key]);
    else if (['+', '='].includes(event.key)) zoom(1.4);
    else if (event.key === '-') zoom(1 / 1.4);
    else if (event.key.toLowerCase() === 'f') fit();
    else if (event.key.toLowerCase() === 'c') centerShip();
    else return;
    event.preventDefault();
}
onMounted(() => {
    const key = shipSelectionKey(page.props.auth.user.id, props.agent);
    try {
        const saved = sessionStorage.getItem(key);
        if (!new URLSearchParams(window.location.search).has('ship') && saved && saved !== shipSymbol.value && props.ships.some(s => s.symbol === saved)) {
            router.get(route('agents.map', props.agent.id), { ship: saved }, { replace: true });
        } else if (shipSymbol.value) sessionStorage.setItem(key, shipSymbol.value);
    } catch { /* Map selection still works without browser storage. */ }
    let initial = true;
    observer = new ResizeObserver(entries => {
        const bounds = entries[0].contentRect;
        size.width = bounds.width; size.height = bounds.height;
        if (initial) { fit(); initial = false; }
    });
    observer.observe(viewport.value);
    timer = window.setInterval(() => { now.value = Date.now(); }, 10000);
});
onBeforeUnmount(() => { observer?.disconnect(); clearInterval(timer); });
</script>

<template>
    <Head :title="`System map · ${map.system || agent.name}`" />
    <AppShell compact brand="SPACETRADERS" :agent-id="agent.id" :ship-symbol="shipSymbol" class="system-map-shell" @keydown.esc.capture="escape">
        <main id="main-content" class="system-map-page" @click="outside">
            <header class="map-header">
                <div class="map-title"><p class="map-eyebrow">{{ agent.name }} / STORED<span class="map-eyebrow-extra"> EXPLORATION</span></p><h1>{{ map.system || 'System Unknown' }} <span class="map-subtitle"><span class="map-page-name">System map</span> <span v-if="map.system_info?.type" class="map-system-type">{{ assetName(map.system_info.type) }}</span></span></h1></div>
                <div class="map-header-actions"><label v-if="ships.length" class="map-ship-picker"><span class="map-ship-label" aria-hidden="true">Ship</span><span class="map-sr">Selected ship</span><select aria-label="Selected ship" :value="shipSymbol" @change="chooseShip"><option v-for="ship in ships" :key="ship.symbol" :value="ship.symbol">{{ ship.symbol }}</option></select></label><Link class="map-button map-deck-link" :href="route('agents.show', { agent: agent.id, ship: shipSymbol })"><span class="map-label">Flight deck</span><MapIcon name="deck" /></Link></div>
            </header>
            <div class="map-stage">
                <div ref="knowledge" class="map-knowledge" :class="{ open: knowledgeOpen }">
                    <button type="button" class="map-knowledge-toggle" :aria-expanded="knowledgeOpen" aria-controls="map-knowledge-detail" @click="toggleKnowledge"><span class="map-status-dot" /><span class="map-knowledge-summary"><span class="map-sr">Stored knowledge only · </span>{{ map.catalogue?.complete ? 'Complete' : 'Partial' }}<span class="map-sr"> catalogue</span> · {{ plotted.length }}<template v-if="unplotted.length"> of {{ map.locations.length }}</template> plotted</span><ObservationStamp v-if="map.catalogue" :at="map.catalogue.observed_at" :now="now" :seconds="freshnessSeconds" /><MapIcon name="info" /></button>
                    <div id="map-knowledge-detail" class="map-knowledge-detail"><span class="map-status-dot" /><span>Stored knowledge while browsing</span><span>· {{ map.catalogue?.complete ? 'Complete catalogue when observed' : 'Partial catalogue · coverage unconfirmed' }}</span><span>· {{ plotted.length }} plotted<span v-if="unplotted.length"> / {{ unplotted.length }} unplotted</span></span><ObservationStamp v-if="map.catalogue" :at="map.catalogue.observed_at" :now="now" :seconds="freshnessSeconds" /><p class="map-knowledge-help">Drag to pan · Pinch to zoom · Dots mark exact coordinates · Art patterns are cosmetic. New information and commands require an explicit action.</p></div>
                </div>
            <div ref="viewport" class="map-viewport">
                <svg ref="canvas" class="system-canvas" :viewBox="`0 0 ${size.width} ${size.height}`" tabindex="0" role="group" aria-label="Interactive system map" aria-describedby="map-help" :data-camera="JSON.stringify(camera)" :data-max-zoom="maxZoom" :data-marker-size="diameter" @pointerdown="pointerDown" @pointermove="pointerMove" @pointerup="pointerUp" @pointercancel="pointerUp" @lostpointercapture="pointerLost" @wheel.prevent="wheel" @keydown="keydown">
                    <defs><pattern id="map-grid" :width="grid.pixels" :height="grid.pixels" patternUnits="userSpaceOnUse" :x="grid.origin.x" :y="grid.origin.y"><path :d="`M ${grid.pixels} 0 L 0 0 0 ${grid.pixels}`" fill="none" stroke="#263347" stroke-width=".6" /></pattern><pattern id="map-stars" width="177" height="149" patternUnits="userSpaceOnUse"><circle cx="25" cy="38" r=".8" fill="#52617a" /><circle cx="120" cy="102" r=".6" fill="#77839a" /></pattern></defs>
                    <rect width="100%" height="100%" fill="#0b1220" /><rect width="100%" height="100%" fill="url(#map-stars)" /><rect width="100%" height="100%" fill="url(#map-grid)" />
                    <path :d="`M ${grid.origin.x - 8} ${grid.origin.y} h 16 M ${grid.origin.x} ${grid.origin.y - 8} v 16`" stroke="#596983" fill="none" />
                    <g v-for="group in groups" :key="group.key" class="map-group">
                        <g v-for="member in group.members" :key="member.point.symbol"><line :x1="member.x" :y1="member.y" :x2="group.x" :y2="group.y" stroke="#6b7c95" stroke-dasharray="2 3" /><circle :cx="member.x" :cy="member.y" r="2.5" fill="#c5d7e9"><title>{{ member.point.symbol }} · x {{ member.point.x }} · y {{ member.point.y }}</title></circle></g>
                        <foreignObject :x="group.x - diameter / 2" :y="group.y - diameter / 2" :width="diameter" :height="diameter"><button xmlns="http://www.w3.org/1999/xhtml" type="button" class="map-marker" :class="{ selected: group.members.some(m => m.point.symbol === selected) }" :aria-label="group.members.length > 1 ? `${group.members.length} locations near ${group.members[0].point.symbol}` : `Inspect ${group.members[0].point.symbol}`" @click.stop="(!$event.detail || (!dragged && !multiTouch)) && select(group.members[0].point.symbol, $event)"><WaypointSprite :point="group.members[0].point" :reset="agent.reset_id" /><span v-if="group.members.length > 1" class="marker-count">{{ group.members.length }}</span></button></foreignObject>
                        <text v-if="group.label" :x="group.x" :y="group.labelY" text-anchor="middle" class="marker-label">{{ group.label }}</text>
                    </g>
                    <g v-if="shipScreen" :transform="`translate(${shipScreen.x},${shipScreen.y})`" class="map-ship-indicator" aria-hidden="true"><circle :r="diameter / 2 + 5" fill="none" stroke="#7dd3cb" :stroke-dasharray="map.ship.in_transit ? '4 5' : undefined" /><path :d="`M -6 ${-diameter / 2 - 11} L 0 ${-diameter / 2 - 20} L 6 ${-diameter / 2 - 11} Z`" fill="#a3f0e4" /></g>
                </svg>
                <div v-if="!plotted.length" class="map-empty"><p class="map-eyebrow">NO PLOTTED OBSERVATIONS</p><h2>{{ ships.length ? 'This system is a blank page.' : 'Your fleet is empty.' }}</h2><p>{{ ships.length ? 'Stored locations will appear here when coordinates are known. Map browsing never requests new information.' : 'Choose a ship on the flight deck when one is available.' }}</p></div>
                <div class="map-controls" role="group" aria-label="Map controls"><button type="button" class="map-tool map-tool-square" aria-label="Zoom in" :disabled="atCloseUp" :title="atCloseUp ? 'Close-up limit reached' : 'Zoom in'" @click="zoom(1.5)"><MapIcon name="plus" /></button><button type="button" class="map-tool map-tool-square" aria-label="Zoom out" title="Zoom out" @click="zoom(1 / 1.5)"><MapIcon name="minus" /></button><button type="button" class="map-tool" title="Fit system" @click="fit"><MapIcon name="fit" /><span class="map-label">Fit system</span></button><button type="button" class="map-tool" :title="map.ship?.in_transit ? 'Center departure' : 'Center on ship'" :disabled="!position" @click="centerShip"><MapIcon name="ship" /><span class="map-label">{{ map.ship?.in_transit ? 'Center departure' : 'Center on ship' }}</span></button></div>
                <details ref="directory" class="map-directory" @toggle="directoryToggled"><summary><MapIcon name="list" /><span class="map-label">Locations</span><span class="map-count">{{ map.locations.length }}</span></summary><div class="map-directory-list"><p v-if="!map.locations.length">No stored locations</p><h3>Plotted locations</h3><button v-for="location in plotted" :key="location.symbol" type="button" @click="select(location.symbol, $event)"><span>{{ location.symbol }}</span><small>{{ assetName(location.type) }} · {{ location.x }}, {{ location.y }}</small></button><h3 v-if="unplotted.length">Unplotted · coordinates Unknown</h3><button v-for="location in unplotted" :key="location.symbol" type="button" @click="select(location.symbol, $event)"><span>{{ location.symbol }}</span><small>{{ assetName(location.type) }} · Unknown coordinates</small></button></div></details>
                <div v-if="map.ship" class="map-ship-note"><span class="map-status-dot" />{{ shipSymbol }} · {{ map.ship.in_transit ? 'In transit · departure shown, arrival unconfirmed' : `Last observed at ${map.ship.waypoint || 'Unknown'}` }}<span v-if="!position"> · Position Unknown</span><ObservationStamp :at="map.ship.observed_at" :now="now" :seconds="freshnessSeconds" /><span v-if="agent.connection_state === 'reset'">Archived world</span></div>
                <LocationCard :ship="map.ship" :archived="agent.connection_state === 'reset'" :agent-id="agent.id" @information="informationOpen = true" v-if="point" :point="point" :nearby="nearby" :reset="agent.reset_id" :now="now" :seconds="freshnessSeconds" :style="cardStyle" @select="select" @close="close" />
                <div class="map-scale" aria-hidden="true"><span :style="{ width: `${grid.pixels}px` }" />{{ grid.step }} coordinate units · +y ↑</div>
            </div>
            </div>
            <footer id="map-help" class="map-help"><span>Drag to pan · Scroll / pinch to zoom</span><span>Keyboard: arrows pan · + / − zoom · F fit · C ship · Esc close</span><span>Dots mark exact coordinates · Art patterns are cosmetic</span></footer>
        </main>
        <LocationInformation v-if="point" :open="informationOpen" :agent="agent" :ship="map.ship" :point="point" :system="map.system" @close="informationOpen = false" />
    </AppShell>
</template>

<style src="@/systemMap.css"></style>
