<script setup>
defineProps({
    comment: { type: Object, required: true },
    depth: { type: Number, default: 1 },
});
const emit = defineEmits(['reply']);
const fmt = (iso) => new Date(iso).toLocaleString();
</script>

<template>
    <div :class="depth < 6 ? 'border-l-2 border-gray-300 pl-3' : ''">
        <div class="rounded-md border border-gray-200 bg-white p-3">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                <span class="font-semibold text-gray-800">{{ comment.user_name }}</span>
                <span class="break-all">{{ comment.email }}</span>
                <a v-if="comment.home_page" :href="comment.home_page" target="_blank"
                   rel="nofollow noopener" class="break-all text-blue-600 hover:underline">{{ comment.home_page }}</a>
                <time>{{ fmt(comment.created_at) }}</time>
            </div>
            <div class="comment-text mt-2 text-sm text-gray-800" v-html="comment.text"></div>
            <button type="button" class="mt-2 text-xs font-medium text-blue-600 hover:underline"
                    @click="emit('reply', comment)">Reply</button>
        </div>

        <div v-if="comment.replies.length" class="mt-2 space-y-2">
            <CommentNode v-for="r in comment.replies" :key="r.id" :comment="r"
                         :depth="depth + 1" @reply="emit('reply', $event)" />
        </div>
    </div>
</template>