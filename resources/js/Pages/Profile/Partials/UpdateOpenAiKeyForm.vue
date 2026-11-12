<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatLocalTime } from '@/dateTime.js';
import { router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

const props = defineProps({
    openAiKey: {
        type: Object,
        required: true,
    },
});

// One key per instance: once saved, the field stays hidden until the operator chooses Replace.
const replacing = ref(false);
const showKey = ref(false);
const forgetting = ref(false);
const keyInput = ref(null);
const replaceButton = ref(null);
const form = useForm({ openai_api_key: '' }).dontRemember('openai_api_key');
const editing = computed(() => !props.openAiKey.saved || replacing.value);

const clearKey = () => {
    form.defaults('openai_api_key', '');
    form.reset('openai_api_key');
    showKey.value = false;
};

// The field and the saved-state row swap places, so move focus to whichever one remains.
const focusField = async () => {
    await nextTick();
    keyInput.value?.focus();
};

const focusReplace = async () => {
    await nextTick();
    replaceButton.value?.$el?.focus();
};

const startReplacing = () => {
    replacing.value = true;
    focusField();
};

const cancelReplacing = () => {
    clearKey();
    form.clearErrors();
    replacing.value = false;
    focusReplace();
};

const updateKey = () => {
    form.put(route('profile.openai-key.update'), {
        preserveScroll: true,
        onSuccess: () => {
            replacing.value = false;
            focusReplace();
        },
        // The rejected key is cleared on purpose so a secret never lingers in the field.
        onError: focusField,
        onFinish: clearKey,
    });
};

const forgetKey = () => {
    router.delete(route('profile.openai-key.destroy'), {
        preserveScroll: true,
        onStart: () => { forgetting.value = true; },
        onFinish: () => {
            clearKey();
            form.clearErrors();
            replacing.value = false;
            forgetting.value = false;
            // A failed Forget leaves the key saved, so the field never appears; return to Replace instead.
            if (props.openAiKey.saved) focusReplace();
            else focusField();
        },
    });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-ink">OpenAI API key</h2>

            <p id="openai-key-help" class="mt-1 text-sm text-muted">
                Used only by the adviser on this server. Stored encrypted and never shown again. Use a key from an OpenAI project with a spending limit.
            </p>
        </header>

        <p class="mt-2 text-sm text-muted" role="status">
            <template v-if="openAiKey.saved">Key ending in …{{ openAiKey.hint }}, saved {{ formatLocalTime(openAiKey.savedAt) }}.</template>
            <template v-else>No key saved.</template>
        </p>

        <div v-if="openAiKey.saved && !replacing" class="mt-6 flex items-center gap-4">
            <SecondaryButton ref="replaceButton" :disabled="forgetting" @click="startReplacing">Replace key</SecondaryButton>
            <button type="button" class="app-link text-sm" :disabled="forgetting" @click="forgetKey">Forget key</button>

            <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" class="transition ease-in-out">
                <p v-if="form.recentlySuccessful" class="text-sm text-muted">Saved.</p>
            </Transition>
        </div>

        <form v-if="editing" @submit.prevent="updateKey" class="mt-6 space-y-6">
            <div>
                <InputLabel for="openai_api_key" :value="openAiKey.saved ? 'New API key' : 'API key'" />

                <TextInput
                    ref="keyInput"
                    :aria-invalid="Boolean(form.errors.openai_api_key)"
                    :aria-describedby="'openai-key-help' + (form.errors.openai_api_key ? ' openai_api_key-error' : '')"
                    id="openai_api_key"
                    :type="showKey ? 'text' : 'password'"
                    class="mt-1 block w-full"
                    data-1p-ignore
                    data-lpignore="true"
                    data-bwignore
                    data-form-type="other"
                    spellcheck="false"
                    autocapitalize="off"
                    v-model="form.openai_api_key"
                    autocomplete="new-password"
                    required
                />

                <button type="button" class="app-link mt-2 text-sm" :aria-pressed="showKey" aria-controls="openai_api_key" :aria-label="showKey ? 'Hide API key' : 'Show API key'" @click="showKey = !showKey">
                    {{ showKey ? 'Hide' : 'Show' }}
                </button>

                <InputError id="openai_api_key-error" :message="form.errors.openai_api_key" class="mt-2" />
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">{{ openAiKey.saved ? 'Save new key' : 'Save key' }}</PrimaryButton>
                <SecondaryButton v-if="openAiKey.saved" :disabled="form.processing" @click="cancelReplacing">Cancel</SecondaryButton>
            </div>
        </form>
    </section>
</template>
