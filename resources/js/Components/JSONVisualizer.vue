<script setup>
import { ref, reactive, computed } from 'vue';

const props = defineProps(['json']);
const jsonObject = ref(props.json.value);
// const jsonObject = ref(JSON.parse(props.json));
// console.log(jsonObject.value['data']);

const resultObject = computed(() => {
    for (const key in jsonObject) {
        if (jsonObject.hasOwnProperty(key)) {
            const keyName = key;
            const keyType = typeof key;
            const value = jsonObject[key];
            const valueType = typeof value;

            resultObject[key] = {
                keyName,
                keyType,
                value,
                valueType,
            };
        }
    }

    console.log('resultObject', resultObject.value);
    return resultObject
});
</script>

<template>
    <div class="ml-4 bg-white rounded-lg overflow-hidden shadow-md">
        <div class="px-6 py-4 border-b rounded-lg hover:bg-gray-100 hover:cursor-pointer">
            <h3 class="text-xl font-semibold">{{ resultObject['data']['keyName'] }} <small class="font-normal ml-2">{{ resultObject['data']['keyType'] }}</small></h3>
        </div>
        <div class="px-6 py-4">
            <small>{{ resultObject[0]['valueType'] }}</small>
            <template v-if="resultObject[0]['valueType'] === 'object' || resultObject[0]['valueType'] === 'array'">
                <JSONVisualizer :json="JSON.stringify(resultObject[0]['value'])" />
            </template>
            <template v-if="resultObject[0]['valueType'] !== 'object' && resultObject[0]['valueType'] !== 'array'">
                <div>{{ resultObject[0]['value'] }}</div>
            </template>
        </div>
    </div>
</template>

<style scoped>

</style>