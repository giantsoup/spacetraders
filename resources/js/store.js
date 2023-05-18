import { defineStore } from 'pinia';

const useStore = defineStore('elementsStore', {
    state() {
        return {
            activeAgentAccountID: null,
        };
    },
    persist: true,
    actions: {
        log() {
            console.log('stored elements', this.elements);
        },
    },
});

export default useStore;
