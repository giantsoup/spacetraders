<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    maxWidth: { type: String, default: '2xl' },
    closeable: { type: Boolean, default: true },
    labelledby: { type: String, required: true },
    describedby: { type: String, default: undefined },
});
const emit = defineEmits(['close']);
const dialog = ref(null);
let previousOverflow;

function synchronize() {
    if (props.show && !dialog.value.open) {
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        dialog.value.showModal();
    } else if (!props.show && dialog.value.open) {
        dialog.value.close();
        document.body.style.overflow = previousOverflow;
    }
}
function close() {
    if (props.closeable) emit('close');
}
function closeOnBackdrop(event) {
    const bounds = dialog.value.getBoundingClientRect();
    if (event.clientX < bounds.left || event.clientX > bounds.right
        || event.clientY < bounds.top || event.clientY > bounds.bottom) close();
}

watch(() => props.show, synchronize, { flush: 'post' });
onMounted(synchronize);
onUnmounted(() => {
    if (props.show) document.body.style.overflow = previousOverflow;
});

const maxWidthClass = computed(() => ({
    sm: 'sm:max-w-sm', md: 'sm:max-w-md', lg: 'sm:max-w-lg',
    xl: 'sm:max-w-xl', '2xl': 'sm:max-w-2xl',
})[props.maxWidth]);
</script>

<template>
    <Teleport to="body">
        <dialog
            ref="dialog"
            class="app-dialog"
            :class="maxWidthClass"
            :aria-labelledby="labelledby"
            :aria-describedby="describedby"
            @cancel.prevent="close"
            @click.self="closeOnBackdrop"
        >
            <slot v-if="show" />
        </dialog>
    </Teleport>
</template>
