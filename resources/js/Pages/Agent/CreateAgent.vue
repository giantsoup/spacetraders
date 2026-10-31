<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const form = useForm({
    account_token: '',
    symbol: '',
    faction: '',
    email: '',
});

const formExisting = useForm({
    token: '',
});

const showExistingForm = ref(false);

function createAgent() {
    form.post(route('agents.store'), {
        preserveScroll: true,
        onFinish: () => form.reset('account_token'),
    });
}

function fetchAndSaveAgent() {
    formExisting.post(route('agents.storeExisting'), {
        preserveScroll: true,
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
    <Head title="Agents - Register" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Agents | Create</h2>
        </template>

        <div class="py-12">
            <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
                <form v-if="showExistingForm" @submit.prevent="fetchAndSaveAgent">
                    <div>
                        <InputLabel for="token" value="Agent token" />

                        <TextInput
                            id="token"
                            type="password"
                            class="mt-1 block w-full"
                            v-model="formExisting.token"
                            autocomplete="off"
                            required
                        />

                        <InputError class="mt-2" :message="formExisting.errors.token" />
                    </div>

                    <InputError class="mt-4" :message="formExisting.errors.api" role="alert" />

                    <div class="flex items-center justify-end mt-4">
                        <button type="button" @click="toggleForm" class="underline" :disabled="formExisting.processing">
                            Back to Create New Agent
                        </button>
                        <PrimaryButton type="submit" class="ml-4" :class="{ 'opacity-25': formExisting.processing }" :disabled="formExisting.processing">
                            Save Existing Agent
                        </PrimaryButton>
                    </div>
                </form>
                <form v-else @submit.prevent="createAgent">
                    <div>
                        <InputLabel for="account_token" value="Account token" />

                        <TextInput
                            id="account_token"
                            type="password"
                            class="mt-1 block w-full"
                            v-model="form.account_token"
                            autocomplete="off"
                            required
                        />

                        <p class="mt-2 text-sm text-gray-600">
                            Use your SpaceTraders account token to register a new agent.
                            Your account token is used for this request and is not saved.
                        </p>
                        <InputError class="mt-2" :message="form.errors.account_token" />
                    </div>

                    <div class="mt-4">
                        <InputLabel for="symbol" value="Agent name" />

                        <TextInput
                            id="symbol"
                            type="text"
                            class="mt-1 block w-full"
                            minlength="3"
                            maxlength="14"
                            v-model="form.symbol"
                            required
                        />

                        <InputError class="mt-2" :message="form.errors.symbol" />
                    </div>

                    <div class="mt-4">
                        <InputLabel for="faction" value="Faction" />

                        <TextInput
                            id="faction"
                            type="text"
                            class="mt-1 block w-full"
                            v-model="form.faction"
                            required
                        />

                        <InputError class="mt-2" :message="form.errors.faction" />
                    </div>

                    <div class="mt-4">
                        <InputLabel for="email" value="Email" />

                        <TextInput
                            id="email"
                            type="email"
                            class="mt-1 block w-full"
                            v-model="form.email"
                        />

                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <InputError class="mt-4" :message="form.errors.api" role="alert" />

                    <div class="flex items-center justify-end mt-4">
                        <button type="button" @click="toggleForm" class="underline" :disabled="form.processing">
                            Already have an Agent?
                        </button>
                        <PrimaryButton type="submit" class="ml-4" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                            Register New Agent
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
