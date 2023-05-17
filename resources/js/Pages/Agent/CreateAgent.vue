<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
// import { ref } from 'vue';

const form = useForm({
    symbol: '',
    faction: '',
    email: '',
});

const props = defineProps(['errors']);

// const errorKey = ref('');

// console.log(props.errors.value);
// console.log(Object.keys(props.errors).length !== 0);
// console.log(props.errors !== undefined);

// if (props.errors.value !== undefined && Object.keys(props.errors.value).length !== 0) {
//     const keys = Object.keys(props.errors);
//     errorKey.value = keys[0];
// }

function submit() {
    form
        .transform((data) => ({
            url: '/register',
            method: 'post',
            data: {
                ...data,
            },
        }))
        .post(route('agents.store'), {
        errorBag: 'createAgent',
        preserveScroll: true,
    });
}

</script>

<template>
    <Head title="Agents - Register" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Agents</h2>
        </template>


        <div class="py-12">
            <div class="max-w-xl mx-auto sm:px-6 lg:px-8">

                <form @submit.prevent="submit">
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
                        <PrimaryButton type="submit" class="ml-4" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                            Register New Agent
                        </PrimaryButton>
                    </div>
                </form>

                <div class="mt-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                    <pre>{{ errors }}</pre>
<!--                    <div><strong class="font-bold">{{ errorKey }}</strong></div>-->
<!--                    <span class="block sm:inline">{{ errors[errorKey] }}</span>-->
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
