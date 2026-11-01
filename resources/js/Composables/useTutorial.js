import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { lessons, normalizeProgress, tutorialKey } from '@/tutorial.js';

export function useTutorial(userId, agent) {
    const key = computed(() => tutorialKey(userId(), agent()));
    const progress = ref(normalizeProgress(null));
    const storageAvailable = ref(true);

    function read() {
        try {
            progress.value = normalizeProgress(JSON.parse(window.localStorage.getItem(key.value)));
            storageAvailable.value = true;
        } catch {
            progress.value = normalizeProgress(null);
            storageAvailable.value = false;
        }
    }
    function save(next) {
        progress.value = normalizeProgress(next);
        try {
            window.localStorage.setItem(key.value, JSON.stringify(progress.value));
            storageAvailable.value = true;
        } catch {
            storageAvailable.value = false;
        }
    }
    function receive(event) {
        if (event.key === key.value || event.key === null) read();
    }
    onMounted(() => {
        read();
        window.addEventListener('storage', receive);
    });
    onUnmounted(() => window.removeEventListener('storage', receive));
    watch(key, read);

    const index = computed(() => lessons.findIndex(lesson => lesson.id === progress.value.active));
    const lesson = computed(() => lessons[index.value]);
    const finished = computed(() => progress.value.completed.length === lessons.length);
    const isComplete = computed(() => progress.value.completed.includes(lesson.value.id));
    function select(id) { save({ ...progress.value, active: id }); }
    function toggleComplete() {
        const completed = isComplete.value
            ? progress.value.completed.filter(id => id !== lesson.value.id)
            : [...progress.value.completed, lesson.value.id];
        save({ ...progress.value, completed });
    }
    function reset() { save(normalizeProgress(null)); }
    return { progress, storageAvailable, index, lesson, finished, isComplete, select, toggleComplete, reset };
}
