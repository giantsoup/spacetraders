<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatLocalTime } from '@/dateTime.js';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    openAiKey: {
        type: Object,
        required: true,
    },
});

const showKey = ref(false);
const forgetting = ref(false);
const form = useForm({ openai_api_key: '' }).dontRemember('openai_api_key');

const clearKey = () => {
    form.defaults('openai_api_key', '');
    form.reset('openai_api_key');
    showKey.value = false;
};

const updateKey = () => {
    form.put(route('profile.openai-key.update'), {
        preserveScroll: true,
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
            forgetting.value = false;
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

        <p class="mt-2 text-sm text-muted">
            <template v-if="openAiKey.saved">Key ending in …{{ openAiKey.hint }}, saved {{ formatLocalTime(openAiKey.savedAt) }}.</template>
            <template v-else>No key saved.</template>
        </p>

        <form @submit.prevent="updateKey" class="mt-6 space-y-6">
            <div>
                <InputLabel for="openai_api_key" :value="openAiKey.saved ? 'New API key' : 'API key'" />

                <TextInput
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
                <button v-if="openAiKey.saved" type="button" class="app-link ml-4 mt-2 text-sm" :disabled="form.processing || forgetting" @click="forgetKey">
                    Forget key
                </button>

                <InputError id="openai_api_key-error" :message="form.errors.openai_api_key" class="mt-2" />
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing || forgetting">{{ openAiKey.saved ? 'Replace key' : 'Save key' }}</PrimaryButton>

                <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" class="transition ease-in-out">
                    <p v-if="form.recentlySuccessful" class="text-sm text-muted">Saved.</p>
                </Transition>
            </div>
        </form>
    </section>
</template>
