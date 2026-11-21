<script setup>
import { listEnter } from '../transitions';
import AttachmentPreview from './AttachmentPreview.vue';
import { highlightClass } from '../highlight';

defineProps({
    comment: { type: Object, required: true },
    depth: { type: Number, default: 1 },
});
const emit = defineEmits(['reply', 'preview']);
const fmt = (iso) => new Date(iso).toLocaleString();
</script>

<template>
    <div :class="depth < 6 ? 'border-l-2 border-gray-300 pl-3' : ''">
        <div :id="`comment-${comment.id}`" class="scroll-mt-24 rounded-md border border-gray-200 p-3"
             :class="highlightClass(comment.id, 'bg-white')">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                <span class="font-semibold text-gray-800">{{ comment.user_name }}</span>
                <span class="break-all">{{ comment.email }}</span>
                <a v-if="comment.home_page" :href="comment.home_page" target="_blank"
                   rel="nofollow noopener" class="break-all text-blue-600 hover:underline">{{ comment.home_page }}</a>
                <time>{{ fmt(comment.created_at) }}</time>
            </div>
            <div class="comment-text mt-2 text-sm text-gray-800" v-html="comment.text"></div>
            <AttachmentPreview v-if="comment.attachment" :attachment="comment.attachment" @open="emit('preview', $event)" />
            <button type="button" :aria-label="`Reply to ${comment.user_name}`"
                    class="mt-2 rounded text-xs font-medium text-blue-600 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                    @click="emit('reply', comment)">Reply</button>
        </div>

        <TransitionGroup v-if="comment.replies.length" v-bind="listEnter" appear tag="div" class="mt-2 space-y-2">
            <CommentNode v-for="r in comment.replies" :key="r.id" :comment="r"
                         :depth="depth + 1" @reply="emit('reply', $event)" @preview="emit('preview', $event)" />
        </TransitionGroup>
    </div>
</template>