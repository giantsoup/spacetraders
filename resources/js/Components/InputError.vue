<script setup>
defineProps({
    message: {
        type: String,
    },
});
</script>

<template>
    <div v-show="message" aria-live="polite">
        <p class="app-error">
            {{ message }}
        </p>
    </div>
</template>
