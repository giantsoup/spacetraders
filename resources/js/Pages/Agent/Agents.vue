<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {Head, Link} from '@inertiajs/vue3';
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

                <div class="agents-wrapper overflow-hidden shadow-xs sm:rounded-lg">

                    <div v-for="agent in agents" :key="agent.id"
                         class="agents mt-6 p-6 bg-white border-b border-gray-400 rounded-md hover:bg-gray-200 hover:cursor-pointer">
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            <span class="text-black px-4 break-words">{{ agent.name }}</span>
                            <span class="text-black px-4 break-words">{{ agent.account_id }}</span>
                            <span class="text-black px-4 break-words">
                                {{ agent.token_preview }}
                            </span>
                            <span class="text-black px-4 break-words">{{ agent.email }}</span>
                        </div>
                    </div>

                    <div class="mt-10">
                        <Link :href="route('agents.create')">
                            <button class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 px-4 rounded-l">
                                Register New Agent
                            </button>
                        </Link>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.agents:first-child {
    margin-top: 0;
}
</style>
