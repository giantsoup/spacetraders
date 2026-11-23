<script setup>
import ShipName from '@/Components/Bridge/ShipName.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { identity, label } from '@/bridgeFormat.js';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({ card: { type: Object, required: true }, primary: Boolean, agent: String, ship: [Object, String] });
defineEmits(['review']);
const scope = computed(() => ({
    engine: 'Engine stopped',
    agent: 'Fleet stopped',
    primary: 'Main campaign stopped',
    "main campaign": 'Main campaign stopped',
    "one ship": 'Ship stopped',
    ship: 'Ship stopped',
    main_campaign: 'Main campaign stopped',
    arm: 'Ship stopped',
    one_ship: 'Ship stopped',
}[props.card.scope] || `${label(props.card.scope)} · Stopped`));
const displayShip = computed(() => props.ship || props.card.ship);
const canReview = computed(() => Boolean(props.card.review));
const retire = computed(() => props.card.review === 'arm_resume' && props.card.code === 'director_error');
const linkLabel = computed(() => props.card.link_label || ({
    engine_heartbeat_stale: 'Check engine',
    unexplained_change: 'Review balance',
    uncertain_action: 'Reconcile on flight deck',
    no_cargo_buyer: 'See cargo',
    recovery_stalled: 'See cargo',
    registration_unknown: 'Open Agents',
    registration_requires_review: 'Open Agents',
    account_token_missing: 'Open Agents',
}[props.card.code] || 'Open details'));
const reviewLabel = computed(() => {
    if (props.card.review_label) return props.card.review_label;
    if (props.card.code === 'operator_pause') return 'Review and resume';
    if (props.card.review?.startsWith('arm_')) return `Review ${identity(displayShip.value).name || 'ship'}`;
    if (props.card.review === 'primary_resume') return 'Review main campaign';
    return `Review ${props.agent || 'the fleet'}`;
});
const linkFirst = computed(() => ['unexplained_change', 'uncertain_action', 'no_cargo_buyer', 'recovery_stalled'].includes(props.card.code));
</script>

<template>
    <article class="bridge-attention">
        <p class="bridge-severity"><span aria-hidden="true">⛔</span> {{ scope }}</p>
        <h3>{{ card.title }}</h3>
        <p v-if="displayShip" class="bridge-detail"><ShipName :ship="displayShip" /><template v-if="displayShip.owner || card.arm_label"> · arm {{ displayShip.owner || card.arm_label }}</template></p>
        <p v-if="card.detail" class="bridge-attention-detail">{{ card.detail }}</p>
        <div v-if="canReview || card.href" class="bridge-card-actions">
            <Link v-if="card.href && linkFirst" class="app-button" :class="primary ? 'app-button-primary' : 'app-button-secondary'" :href="card.href">{{ linkLabel }}</Link>
            <component v-if="canReview" :is="primary && !(linkFirst && card.href) ? PrimaryButton : SecondaryButton" type="button" aria-haspopup="dialog" @click="$emit('review', card.review, card.grant_id, $event.currentTarget)">{{ reviewLabel }}</component>
            <SecondaryButton v-if="retire" aria-haspopup="dialog" @click="$emit('review', 'arm_retire', card.grant_id, $event.currentTarget)">Review retirement</SecondaryButton>
            <Link v-if="card.href && !linkFirst" class="app-button" :class="primary && !canReview ? 'app-button-primary' : 'app-button-secondary'" :href="card.href">{{ linkLabel }}</Link>
        </div>
    </article>
</template>
