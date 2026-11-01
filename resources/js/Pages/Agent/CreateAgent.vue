<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import GuidedFlight from '@/Components/GuidedFlight.vue';
import GameConnection from '@/Components/GameConnection.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const form = useForm({
    account_token: '',
    symbol: '',
    faction: '',
    email: '',
});

const formExisting = useForm({
    token: '',
});

const showExistingForm = ref(new URL(window.location.href).searchParams.get('existing') === '1');
const setupGuided = ref(new URL(window.location.href).searchParams.get('guided') === '1');
const setupStage = ref('path');
const setupStep = computed(() => {
    const definitions = {
        path: { target: 'setup-path', title: 'Choose your starting point', body: 'Choose I already have a SpaceTraders agent to import it, or I need a new SpaceTraders agent to register one. Your login to this client is already complete.', kind: 'select' },
        token: { target: showExistingForm.value ? 'agent-token' : 'account-token', title: showExistingForm.value ? 'Paste your agent token' : 'Paste your account token', body: showExistingForm.value ? 'Open the official dashboard, select your existing agent, and generate its agent token. Paste it in the highlighted Agent token field, then Continue. The token stays in this form only.' : 'In the official dashboard open Settings and generate an account token. Paste it in the highlighted Account token field, then Continue. Do not paste an agent token here.', kind: 'read', canContinue: (showExistingForm.value ? formExisting.token : form.account_token).trim().length > 0 },
        name: { target: 'agent-name', title: 'Choose your agent name', body: 'Enter a unique call sign of 3 to 14 characters in Agent name, then Continue. This will identify your fleet in SpaceTraders.', kind: 'read', canContinue: form.symbol.trim().length >= 3 && form.symbol.trim().length <= 14 },
        faction: { target: 'agent-faction', title: 'Enter COSMIC as your faction', body: 'For this starting path, enter COSMIC in Faction, then Continue. The faction determines your starting location. Leave Email empty for this tutorial.', kind: 'read', canContinue: form.faction.trim().toUpperCase() === 'COSMIC' },
        submit: { target: showExistingForm.value ? 'save-agent' : 'register-agent', title: showExistingForm.value ? 'Save this existing agent' : 'Register this agent once', body: 'Review the filled form and click the highlighted submit button. A successful connection opens the flight deck and starts the guided trip. If the request reports an ambiguous registration failure, check the official dashboard before repeating it.', kind: 'submit' },
    };
    return definitions[setupStage.value];
});
function chooseSetup(existing) {
    if (form.processing || formExisting.processing) return;
    if (showExistingForm.value !== existing) toggleForm();
    setupStage.value = 'token';
}
function advanceSetup() {
    if (setupStep.value.canContinue === false) return;
    setupStage.value = setupStage.value === 'token' ? (showExistingForm.value ? 'submit' : 'name') : setupStage.value === 'name' ? 'faction' : 'submit';
}

function createAgent() {
    if (setupGuided.value && setupStage.value !== 'submit') return;
    form.post(route('agents.store', setupGuided.value ? { guided: 1 } : {}), {
        preserveScroll: true,
        onError: () => { if (setupGuided.value) setupStage.value = 'token'; },
        onFinish: () => form.reset('account_token'),
    });
}

function fetchAndSaveAgent() {
    if (setupGuided.value && setupStage.value !== 'submit') return;
    formExisting.post(route('agents.storeExisting', setupGuided.value ? { guided: 1 } : {}), {
        preserveScroll: true,
        onError: () => { if (setupGuided.value) setupStage.value = 'token'; },
        onFinish: () => formExisting.reset('token'),
    });
}

function toggleForm() {
    showExistingForm.value = !showExistingForm.value;
    form.clearErrors();
    formExisting.clearErrors();
    form.reset('account_token');
    formExisting.reset('token');
}
</script>

<template>
    <Head :title="showExistingForm ? 'Import agent' : 'Register agent'" />

    <AuthenticatedLayout>
        <template #header>
            <p class="app-eyebrow">FLEET ACCESS</p>
            <h1>{{ showExistingForm ? 'Import existing agent' : 'Register new agent' }}</h1>
            <p class="app-muted">Connect your SpaceTraders agent to this command terminal.</p>
        </template>

        <div class="max-w-3xl mx-auto">
            <div>
                <GameConnection />
                <GuidedFlight v-if="setupGuided" :session="{ active: true, phase: 'connect' }" :step="setupStep" :storage-available="true" :busy="form.processing || formExisting.processing" @pause="setupGuided = false" @advance="advanceSetup" @start="setupStage = 'path'" />
                <div v-if="setupGuided" data-guide="setup-path" class="mb-6 grid gap-3">
                    <button type="button" :disabled="form.processing || formExisting.processing" class="app-button app-button-secondary" @click="chooseSetup(true)">I already have a SpaceTraders agent</button>
                    <button type="button" :disabled="form.processing || formExisting.processing" class="app-button app-button-secondary" @click="chooseSetup(false)">I need a new SpaceTraders agent</button>
                    <a href="https://my.spacetraders.io" target="_blank" rel="noopener noreferrer" class="app-link">Open official account dashboard<span class="sr-only"> in a new tab</span></a>
                </div>
                <aside class="app-panel mb-6">
                    <h2>Your client login and game agent are separate</h2>
                    <p class="mt-2 text-sm text-muted">Use an account token to register a new agent, or import an existing agent with its agent token.</p>
                    <Link :href="route('guide')" class="app-link mt-3">Read the step-by-step player guide →</Link>
                </aside>
                <form class="app-panel" v-if="showExistingForm" @submit.prevent="fetchAndSaveAgent">
                    <div>
                        <InputLabel for="token" value="Agent token" />

                        <TextInput
                            :aria-invalid="Boolean(formExisting.errors.token)"
                            :aria-describedby="formExisting.errors.token ? 'token-error' : undefined"
                            id="token" data-guide="agent-token"
                            type="password"
                            class="mt-1 block w-full"
                            v-model="formExisting.token"
                            autocomplete="off"
                            required
                        />

                        <InputError id="token-error" class="mt-2" :message="formExisting.errors.token" />
                    </div>

                    <InputError class="mt-4" :message="formExisting.errors.api" role="alert" />

                    <div class="app-form-actions">
                        <button type="button" @click="toggleForm" class="app-link text-sm" :disabled="formExisting.processing || setupGuided">
                            Back to Create New Agent
                        </button>
                        <PrimaryButton data-guide="save-agent" type="submit" :class="{ 'opacity-25': formExisting.processing }" :disabled="formExisting.processing || (setupGuided && setupStage !== 'submit')">
                            Save Existing Agent
                        </PrimaryButton>
                    </div>
                </form>
                <form class="app-panel" v-else @submit.prevent="createAgent">
                    <div>
                        <InputLabel for="account_token" value="Account token" />

                        <TextInput
                            :aria-invalid="Boolean(form.errors.account_token)"
                            :aria-describedby="'account-token-help' + (form.errors.account_token ? ' account_token-error' : '')"
                            id="account_token" data-guide="account-token"
                            type="password"
                            class="mt-1 block w-full"
                            v-model="form.account_token"
                            autocomplete="off"
                            required
                        />

                        <p id="account-token-help" class="mt-2 text-sm text-muted">
                            Use your SpaceTraders account token to register a new agent.
                            Your account token is used for this request and is not saved.
                        </p>
                        <InputError id="account_token-error" class="mt-2" :message="form.errors.account_token" />
                    </div>

                    <div class="mt-4">
                        <InputLabel for="symbol" value="Agent name" />

                        <TextInput
                            :aria-invalid="Boolean(form.errors.symbol)"
                            :aria-describedby="form.errors.symbol ? 'symbol-error' : undefined"
                            id="symbol" data-guide="agent-name"
                            type="text"
                            class="mt-1 block w-full"
                            minlength="3"
                            maxlength="14"
                            v-model="form.symbol"
                            required
                        />

                        <InputError id="symbol-error" class="mt-2" :message="form.errors.symbol" />
                    </div>

                    <div class="mt-4">
                        <InputLabel for="faction" value="Faction" />

                        <TextInput
                            :aria-invalid="Boolean(form.errors.faction)"
                            :aria-describedby="form.errors.faction ? 'faction-error' : undefined"
                            id="faction" data-guide="agent-faction"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="form.faction"
                            required
                        />

                        <InputError id="faction-error" class="mt-2" :message="form.errors.faction" />
                    </div>

                    <div class="mt-4">
                        <InputLabel for="email" value="Email" />

                        <TextInput
                            :aria-invalid="Boolean(form.errors.email)"
                            :aria-describedby="form.errors.email ? 'email-error' : undefined"
                            id="email"
                            type="email"
                            class="mt-1 block w-full"
                            v-model="form.email"
                        />

                        <InputError id="email-error" class="mt-2" :message="form.errors.email" />
                    </div>

                    <InputError class="mt-4" :message="form.errors.api" role="alert" />

                    <div class="app-form-actions">
                        <button type="button" @click="toggleForm" class="app-link text-sm" :disabled="form.processing || setupGuided">
                            Already have an Agent?
                        </button>
                        <PrimaryButton data-guide="register-agent" type="submit" :class="{ 'opacity-25': form.processing }" :disabled="form.processing || (setupGuided && setupStage !== 'submit')">
                            Register New Agent
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
