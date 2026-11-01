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

                <section class="mb-8 rounded-lg border border-slate-600 bg-slate-900 p-6 text-slate-100" aria-labelledby="new-player-heading">
                    <p class="text-xs font-semibold tracking-widest text-emerald-300">FLIGHT SCHOOL</p>
                    <h1 id="new-player-heading" class="mt-2 text-2xl font-semibold">New to SpaceTraders?</h1>
                    <p class="mt-2 max-w-2xl text-slate-300">Learn how to connect an agent, fly a mining ship, sell your first cargo, and refuel. Follow the tutorial at your own pace alongside the flight deck.</p>
                    <Link :href="route('guide')" class="mt-4 inline-flex min-h-11 items-center rounded-sm bg-emerald-200 px-4 py-2 font-semibold text-slate-950 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-amber-200">Start the new player guide →</Link>
                </section>

                <div class="agents-wrapper overflow-hidden shadow-xs sm:rounded-lg">

                    <Link :href="route('agents.show', agent.id)" v-for="agent in agents" :key="agent.id"
                         class="agents mt-6 p-6 bg-white border-b border-gray-400 rounded-md hover:bg-gray-200 hover:cursor-pointer">
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            <span class="text-black px-4 break-words">{{ agent.name }}</span>
                            <span class="text-black px-4 break-words">{{ agent.account_id }}</span>
                            <span class="text-black px-4 break-words">
                                {{ agent.connection_state || 'Open flight deck' }}
                            </span>
                            <span class="text-black px-4 break-words">{{ agent.email }}</span>
                        </div>
                    </Link>

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
