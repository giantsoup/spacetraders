<script setup>
import { computed, ref, watch } from 'vue';
import { assetName, readableLabel, readableText, readableValue } from '@/dataPresentation.js';
import WaypointSprite from './WaypointSprite.vue';
import ObservationStamp from './ObservationStamp.vue';
const props = defineProps({ point: Object, nearby: Array, reset: String, now: Number, seconds: Number });
defineEmits(['close', 'select']);
const details = ref(null);
watch(() => props.point.symbol, () => { if (details.value) details.value.scrollTop = 0; }, { flush: 'post' });
const directory = computed(() => props.point.market.directory?.data);
const prices = computed(() => props.point.market.prices?.data);
const offers = computed(() => props.point.shipyard.offers?.data);
const presence = value => ({ observed: 'Observed', absent: 'Not present in observed traits', unknown: 'Unknown' }[value]);
const shipDescriptions = computed(() => offers.value?.ships?.filter(offer => offer.description) || []);
</script>
<template>
    <section class="location-card" role="dialog" aria-modal="false" aria-labelledby="location-title" @keydown.esc.stop="$emit('close')">
        <header class="location-heading"><WaypointSprite :point="point" :reset="reset" /><div><p>{{ assetName(point.type) }}</p><h2 id="location-title">{{ point.symbol }}</h2></div><button type="button" class="map-close" aria-label="Close location information" @click="$emit('close')">×</button></header>
        <div ref="details" class="location-scroll" tabindex="0" aria-label="Stored location details">
            <details v-if="nearby.length > 1" class="nearby-locations"><summary>{{ nearby.length }} nearby locations · choose another</summary><div class="nearby-options"><button v-for="other in nearby" :key="other.symbol" type="button" :aria-pressed="point.symbol === other.symbol" @click="$emit('select', other.symbol, $event)">{{ other.symbol }}</button></div></details>
            <p class="location-coordinates">{{ point.x === null || point.y === null ? 'Coordinates Unknown · Unplotted' : `x ${point.x} · y ${point.y}` }}<span v-if="point.orbits"> · Orbits {{ point.orbits }}</span></p>
            <p class="location-faction">Faction: {{ point.faction ? assetName(point.faction.symbol) : 'Unknown' }}</p>
            <section class="location-prices"><h3>Stored prices</h3><p>Marketplace: {{ presence(point.market.presence) }}</p>
                <ObservationStamp v-if="point.market.prices" :at="point.market.prices.observed_at" :now="now" :seconds="seconds" /><p v-if="prices?.invalidated_by_action" class="stale">Invalidated by later activity. These prices are historical.</p>
                <p v-if="!prices?.tradeGoods?.length">Unknown · No stored quotes. An empty quote response does not confirm no goods.</p>
                <div v-else class="map-price-table"><table><thead><tr><th>Good</th><th>Buy</th><th>Sell</th></tr></thead><tbody><tr v-for="good in prices.tradeGoods" :key="good.symbol"><th scope="row">{{ assetName(good) }}<small>{{ assetName(good.supply) }} · {{ assetName(good.activity) }}</small></th><td>{{ readableValue(good.purchasePrice, 'purchasePrice') }}</td><td>{{ readableValue(good.sellPrice, 'sellPrice') }}</td></tr></tbody></table></div>
            </section>
            <section><h3>Shipyard</h3><p>{{ presence(point.shipyard.presence) }}</p>
                <template v-if="offers"><ObservationStamp :at="point.shipyard.offers.observed_at" :now="now" :seconds="seconds" /><ul v-if="offers.ships?.length" class="map-traits"><li v-for="(offer, index) in offers.ships" :key="index">{{ offer.name || assetName(offer.type) }} · {{ readableValue(offer.purchasePrice, 'purchasePrice') }}</li></ul><p v-else>Detailed offers and prices: Unknown</p><p>{{ !Array.isArray(offers.shipTypes) ? 'Available types: Unknown' : offers.shipTypes.length ? offers.shipTypes.map(type => assetName(type.type)).join(', ') : 'Confirmed empty ship type list' }}</p></template><p v-else>Offers: Unknown</p>
            </section>
            <section><h3>Market goods</h3>
                <template v-if="directory"><ObservationStamp :at="point.market.directory.observed_at" :now="now" :seconds="seconds" /><div v-for="kind in ['imports', 'exports', 'exchange']" :key="kind" class="map-goods"><strong>{{ readableLabel(kind) }}</strong><p>{{ !Array.isArray(directory[kind]) ? 'Unknown' : directory[kind].length ? directory[kind].map(good => assetName(good)).join(', ') : 'Confirmed empty' }}</p></div></template><p v-else>Goods directory: Unknown</p>
            </section>
            <section><h3>Other observations</h3><p>Construction: {{ readableValue(point.isUnderConstruction) }}</p><p>Orbitals: {{ point.orbitals === null ? 'Unknown' : point.orbitals.length ? point.orbitals.map(orbital => orbital.symbol).join(', ') : 'Confirmed empty' }}</p><p v-if="point.modifiers === null">Modifiers: Unknown</p><p v-else-if="!point.modifiers.length">Modifiers: Confirmed empty</p><ul v-else class="map-traits"><li v-for="modifier in point.modifiers" :key="modifier.symbol">{{ modifier.name || assetName(modifier.symbol) }}<p v-if="modifier.description">{{ readableText(modifier.description) }}</p></li></ul></section>
            <section><h3>Traits & terrain</h3><p v-if="point.traits === null">Unknown</p><p v-else-if="!point.traits.length">Confirmed empty trait list</p><ul v-else class="map-traits"><li v-for="trait in point.traits" :key="trait.symbol"><strong>{{ trait.name || assetName(trait.symbol) }}</strong><p v-if="trait.description">{{ readableText(trait.description) }}</p></li></ul><ObservationStamp v-if="point.traits !== null" :at="point.traits_observed_at" :now="now" :seconds="seconds" /></section>
            <section v-if="point.description || point.faction?.description || shipDescriptions.length"><h3>Descriptions & lore</h3><p v-if="point.description">{{ readableText(point.description) }}</p><p v-if="point.faction?.description">{{ readableText(point.faction.description) }}</p><ul v-if="shipDescriptions.length" class="map-traits"><li v-for="(offer, index) in shipDescriptions" :key="index"><strong>{{ offer.name || assetName(offer.type) }}</strong><p>{{ readableText(offer.description) }}</p></li></ul></section>
            <section><h3>Observation details</h3><ObservationStamp :at="point.observed_at" :now="now" :seconds="seconds" /><p class="map-provenance">Source: {{ readableLabel(point.source) }} · Stored observation</p></section>
            <p class="map-footnote">Artwork uses known type and terrain. Pixel patterns are cosmetic. This card never refreshes or executes gameplay.</p>
        </div>
    </section>
</template>
