<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
const props = defineProps(['agents']);
</script>

<template>
    <Head title="Agents" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Agents</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div v-for="agent in agents" :key="agent.id" class="p-6 bg-white border-b border-gray-200">
                        <div class="flex justify-between">
                            <span class="text-black px-4">{{ agent.name }}</span>
                            <span class="text-black px-4">{{ agent.account_id }}</span>
                            <span class="text-black px-4">{{ agent.token.substring(0, 5) + " ... " + agent.token.substring(agent.token.length - 5)}}</span>
                            <span class="text-black px-4">{{ agent.email }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
