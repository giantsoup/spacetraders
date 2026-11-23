<script setup>
defineProps({ items: { type: Array, default: () => [] } });
</script>

<template>
    <details v-if="items.length" class="bridge-panel bridge-watching">
        <summary class="bridge-row"><span class="bridge-grow"><strong>Also watching</strong><span class="bridge-detail">{{ items.join(' · ') }}</span></span></summary>
        <ul class="bridge-watching-list"><li v-for="(item, index) in items" :key="index">{{ item }}</li></ul>
    </details>
</template>
