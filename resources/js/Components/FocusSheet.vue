<script setup>
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
const props = defineProps({ open: Boolean, title: String });
const emit = defineEmits(['close', 'ready']);
const titleId = useId();
const dialog = ref(null);
const heading = ref(null);
let opener;
function containFocus(event) {
    if (event.key !== 'Tab') return;
    const controls = [...dialog.value.querySelectorAll('button, a[href], input, select, textarea, summary, [tabindex]')]
        .filter(el => !el.matches(':disabled') && el.tabIndex >= 0 && el.getClientRects().length && !el.closest('[inert]'));
    const first = controls[0], last = controls.at(-1);
    if (!first) { event.preventDefault(); heading.value.focus(); return; }
    if (event.shiftKey && (document.activeElement === first || !controls.includes(document.activeElement))) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && (document.activeElement === last || !controls.includes(document.activeElement))) { event.preventDefault(); first.focus(); }
}
watch(() => props.open, async open => {
    await nextTick();
    if (open && !dialog.value.open) {
        opener = document.activeElement;
        dialog.value.showModal();
        heading.value.focus();
        emit('ready');
    } else if (!open && dialog.value.open) {
        dialog.value.close();
        if (opener?.isConnected && !opener.closest('[hidden]')) opener.focus();
    }
});
watch(() => props.title, async () => { if (props.open) { await nextTick(); heading.value.focus(); dialog.value.scrollTop = 0; emit('ready'); } });
onBeforeUnmount(() => { if (dialog.value?.open) dialog.value.close(); });
</script>

<template>
    <dialog ref="dialog" class="focus-sheet" :aria-labelledby="titleId" @cancel.prevent="emit('close')" @keydown="containFocus">
        <header class="sheet-header">
            <h2 :id="titleId" ref="heading" tabindex="-1">{{ title }}</h2>
            <SecondaryButton type="button" @click="emit('close')">Done</SecondaryButton>
        </header>
        <div class="sheet-content"><slot /></div>
    </dialog>
</template>

<style>
.focus-sheet { color-scheme: dark; width: min(100% - 16px, 760px); max-height: calc(100dvh - 16px - env(safe-area-inset-top)); margin: auto auto 0; padding: 0 0 env(safe-area-inset-bottom); border: 1px solid var(--color-line); border-radius: 26px 26px 0 0; background: var(--color-panel); color: var(--color-ink); overflow: auto; overscroll-behavior: contain; scroll-padding-top: 100px; font: 16px/1.5 var(--font-interface); overflow-wrap: anywhere; }
.focus-sheet::backdrop { background: #020710cc; }
.sheet-header { position: sticky; top: 0; z-index: 45; display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 16px 20px; background: var(--color-panel); border-bottom: 1px solid var(--color-line); }
.sheet-header h2 { margin: 0 !important; border: 0 !important; padding: 0 !important; color: var(--color-ink) !important; font-size: 22px !important; letter-spacing: 0 !important; }
.sheet-content { padding: 16px 20px 28px; }
@media(min-width:800px) { .focus-sheet { margin: auto; border-radius: 26px; max-height: calc(100dvh - 48px); } }
</style>
