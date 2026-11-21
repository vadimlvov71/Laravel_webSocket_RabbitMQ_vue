<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import BaseModal from './BaseModal.vue';
import { zoom } from '../transitions';

// Лайтбокс поверх BaseModal: Esc, клик по фону, блокировка прокрутки и возврат
// фокуса уже есть там. Здесь только содержимое и эффект появления картинки.
const props = defineProps({
    open: { type: Boolean, default: false },
    attachment: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const text = ref('');
const loading = ref(false);
const failed = ref(false);

watch(() => [props.open, props.attachment], async ([open, file]) => {
    if (!open || file?.type !== 'text') return;
    text.value = '';
    failed.value = false;
    loading.value = true;
    try {
        // без transformResponse axios попытался бы разобрать файл как JSON
        const { data } = await axios.get(file.url, { responseType: 'text', transformResponse: [(d) => d] });
        text.value = data;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <BaseModal :open="open" :title="attachment?.name ?? ''" @close="emit('close')">
        <template v-if="attachment?.type === 'image'">
            <Transition v-bind="zoom" appear mode="out-in">
                <img v-if="open" :key="attachment.url" :src="attachment.url" :alt="attachment.name"
                     class="mx-auto block h-auto w-full max-w-[640px] rounded-md bg-gray-100 shadow-lg [image-rendering:auto]" />
            </Transition>
            <a :href="attachment.url" target="_blank" rel="noopener"
               class="mt-3 inline-block text-sm text-blue-600 hover:underline">Open original in a new tab</a>
        </template>

        <template v-else-if="attachment">
            <p v-if="loading" class="text-sm text-gray-500">Loading…</p>
            <p v-else-if="failed" role="alert" class="text-sm text-red-600">Could not load the file.</p>
            <!-- только {{ }}: содержимое файла никогда не попадает в разметку как HTML -->
            <pre v-else class="max-h-[60dvh] overflow-auto whitespace-pre-wrap break-words rounded-md bg-gray-50 p-3 font-mono text-xs text-gray-800">{{ text }}</pre>
        </template>
    </BaseModal>
</template>
