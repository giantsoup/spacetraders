<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button
        :type="type"
        class="app-button app-button-secondary"
    >
        <slot />
    </button>
</template>
