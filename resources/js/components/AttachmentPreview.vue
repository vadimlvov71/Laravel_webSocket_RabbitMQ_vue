<script setup>
// Превью вложения в списке. Кнопка, а не ссылка: открывает лайтбокс,
// и после закрытия BaseModal вернёт фокус именно сюда.
defineProps({ attachment: { type: Object, required: true } });
const emit = defineEmits(['open']);
</script>

<template>
    <button v-if="attachment.type === 'image'" type="button" :aria-label="`View image ${attachment.name}`"
            class="group mt-2 block overflow-hidden rounded-md border border-gray-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
            @click="emit('open', attachment)">
        <img :src="attachment.url" :alt="attachment.name" loading="lazy"
             class="h-20 w-auto max-w-40 object-cover motion-safe:transition motion-safe:duration-300 group-hover:scale-105 group-hover:opacity-90" />
    </button>
    <button v-else type="button" :aria-label="`View text file ${attachment.name}`"
            class="mt-2 inline-flex max-w-full items-center gap-1.5 rounded-md border border-gray-200 bg-gray-50 px-2 py-1 text-xs text-gray-700 hover:bg-gray-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
            @click="emit('open', attachment)">
        <span aria-hidden="true">📄</span>
        <span class="truncate">{{ attachment.name }}</span>
    </button>
</template>
