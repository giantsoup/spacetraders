<script setup>
import FocusSheet from '@/Components/FocusSheet.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { bridgeHref } from '@/bridgeFormat.js';
import { formatLocalTime } from '@/dateTime.js';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({ review: Object });
const emit = defineEmits(['close', 'processing']);
const form = useForm({ agent: null, reset_id: null, intent: null, budget_hold_id: null, expected: {} });
const actions = {
    fleet_pause: 'fleet.pause',
    fleet_resume: 'fleet.resume',
    primary_resume: 'fleet.resume',
    arm_resume: 'arm.resume',
    arm_retire: 'arm.retire',
};
const target = computed(() => {
    const active = props.review;
    if (!active) return null;
    const params = active.grant == null ? {} : { grant: active.grant };
    return bridgeHref(actions[active.kind], active.agent, params);
});
const blockers = computed(() => props.review?.blockers || []);
const destructive = computed(() => ['fleet_pause', 'arm_retire'].includes(props.review?.kind));

watch(() => props.review, review => {
    if (!review) return;
    form.clearErrors();
    form.agent = review.agent;
    form.reset_id = review.reset_id ?? null;
    form.intent = review.intent ?? null;
    form.budget_hold_id = review.budget_hold_id ?? null;
    form.expected = review.expected ?? {};
});
watch(() => form.processing, processing => emit('processing', processing));

function close() {
    if (!form.processing) emit('close');
}

function submit() {
    if (!target.value || blockers.value.length || form.processing || form.hasErrors) return;
    const failed = () => {
        form.setError('bridge', 'Could not confirm the action. Close this review and check the current fleet status.');
        return false;
    };
    form.post(target.value, {
        preserveScroll: true,
        onSuccess: () => emit('close', true),
        onNetworkError: failed,
        onHttpException: failed,
    });
}
</script>

<template>
    <FocusSheet class="bridge-sheet bridge-review" :class="{ 'bridge-review-working': form.processing }" :open="Boolean(review)" :title="review?.title || 'Review'" @close="close">
        <form v-if="review" :aria-busy="form.processing" @submit.prevent="submit">
            <p>{{ review.description }}</p>
            <ul v-if="review.facts?.length" class="bridge-review-facts">
                <li v-for="(fact, index) in review.facts" :key="index">{{ fact.text }}<template v-if="fact.at"> at <time :datetime="fact.at">{{ formatLocalTime(fact.at) }}</time>.</template></li>
            </ul>
            <div v-if="blockers.length" class="bridge-review-blockers">
                <h3>Before you can continue</h3>
                <ul>
                    <li v-for="(blocker, index) in blockers" :key="`${blocker.code}-${index}`">
                        {{ blocker.message }}
                        <Link v-if="blocker.href" class="bridge-link" :href="blocker.href"> {{ blocker.code === 'fleet_review' ? 'Review on Bridge' : 'Open flight deck' }} ›</Link>
                    </li>
                </ul>
            </div>
            <p v-if="form.errors.bridge" class="bridge-negative" role="alert">{{ form.errors.bridge }}</p>
            <p v-else-if="form.hasErrors" class="bridge-negative" role="alert">{{ Object.values(form.errors).flat().join(' ') }}</p>
            <div class="bridge-review-buttons">
                <SecondaryButton :disabled="form.processing" @click="close">{{ form.processing ? 'Working…' : review.cancel }}</SecondaryButton>
                <component :is="destructive ? DangerButton : PrimaryButton" type="submit" :disabled="form.processing || form.hasErrors || blockers.length > 0">{{ form.processing ? 'Working…' : review.confirm }}</component>
            </div>
        </form>
    </FocusSheet>
</template>
