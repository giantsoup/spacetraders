<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const form = useForm({
    symbol: '',
    faction: '',
    email: '',
});

const formExisting = useForm({
    token: '',
});

const props = defineProps(['errors', 'showRegisterForm']);

const showRegisterForm = ref(false);

// setup empty headers if they are undefined, so we don't error out
if (props.errors.header === undefined) {
    props.errors.header = '';
}

function createAgent() {
    form
        .transform((data) => ({
            url: '/register',
            method: 'post',
            data: {
                ...data,
            },
        }))
        .post(route('agents.store'), {
        preserveScroll: true,
    });
}
function fetchAndSaveAgent() {
  formExisting
        .transform((data) => ({
            url: '/my/agent',
            method: 'get',
            data: {
                ...data,
            },
        }))
        .post(route('agents.storeExisting'), {
        preserveScroll: true,
    });
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
                <template v-if="showRegisterForm">
                    <form @submit.prevent="fetchAndSaveAgent">
                        <div>
                            <InputLabel for="token" value="Agent Token" />

                            <TextInput
                                id="token"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="formExisting.token"
                                required
                            />

                            <InputError class="mt-2" :message="formExisting.errors.token" />
                        </div>

                        <div class="flex items-center justify-end mt-4">
                            <div @click="showRegisterForm = !showRegisterForm" class="cursor-pointer underline">Back to Create New Agent</div>
                            <PrimaryButton type="submit" class="ml-4" :class="{ 'opacity-25': formExisting.processing }" :disabled="formExisting.processing">
                                Save Existing Agent
                            </PrimaryButton>
                        </div>
                    </form>
                </template>
                <template v-else>
                    <form @submit.prevent="createAgent">
                        <div>
                            <InputLabel for="symbol" value="Agent Name" />

                            <TextInput
                                id="symbol"
                                type="text"
                                class="mt-1 block w-full"
                                min="3"
                                max="14"
                                v-model="form.symbol"
                                required
                            />

                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>

                        <div class="mt-4">
                            <InputLabel for="faction" value="Faction" />

                            <TextInput
                                id="faction"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="form.faction"
                                required
                                autocomplete="new-password"
                            />

                            <InputError class="mt-2" :message="form.errors.password" />
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

                        <div class="flex items-center justify-end mt-4">
                            <div @click="showRegisterForm = !showRegisterForm" class="cursor-pointer underline">Already have an Agent?</div>
                            <PrimaryButton type="submit" class="ml-4" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                                Register New Agent
                            </PrimaryButton>
                        </div>
                    </form>
                </template>

                <div v-if="errors.header !== ''" class="mt-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                  <pre>{{ errors }}</pre>
<!--                    <div><strong class="font-bold">{{ errors.header.charAt(0).toUpperCase() + errors.header.slice(1) }}</strong></div>-->
<!--                    <span class="block sm:inline">{{ errors.message }}</span>-->
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
