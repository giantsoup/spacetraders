<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

</script>

<template>
    <Head title="Profile" />

    <AuthenticatedLayout>
        <template #header>
            <p class="app-eyebrow">CLIENT SETTINGS</p>
            <h1>Profile</h1>
            <p class="app-muted">Manage your client login and account details.</p>
        </template>

        <div>
            <div class="app-stack">
                <div class="app-panel">
                    <UpdateProfileInformationForm
                        class="max-w-xl"
                    />
                </div>

                <div class="app-panel">
                    <UpdatePasswordForm class="max-w-xl" />
                </div>

                <div class="app-panel">
                    <DeleteUserForm class="max-w-xl" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
