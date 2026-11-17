<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import CommentForm from './components/CommentForm.vue';
import CommentNode from './components/CommentNode.vue';

const columns = [
    { key: 'user_name', label: 'User Name' },
    { key: 'email', label: 'E-mail' },
    { key: 'created_at', label: 'Date' },
];

const comments = ref([]);
const meta = reactive({ current_page: 1, last_page: 1, total: 0 });
const sort = ref('created_at');
const dir = ref('desc'); // по умолчанию LIFO
const loading = ref(false);
const replyTo = ref(null);
const formRef = ref(null);

const fmt = (iso) => new Date(iso).toLocaleString();

async function load(page = meta.current_page) {
    loading.value = true;
    try {
        const { data } = await axios.get('/comments', { params: { sort: sort.value, dir: dir.value, page } });
        comments.value = data.data;
        Object.assign(meta, data.meta);
    } finally {
        loading.value = false;
    }
}

function sortBy(field) {
    if (sort.value === field) {
        dir.value = dir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sort.value = field;
        dir.value = field === 'created_at' ? 'desc' : 'asc';
    }
    load(1);
}

const pages = computed(() => {
    const from = Math.max(1, meta.current_page - 2);
    const to = Math.min(meta.last_page, meta.current_page + 2);
    return Array.from({ length: to - from + 1 }, (_, i) => from + i);
});

function startReply(comment) {
    replyTo.value = comment;
    formRef.value?.$el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function onCreated() {
    replyTo.value = null;
    load();
}

onMounted(() => {
    load(1);
    // WebSocket (Reverb): обновляем список, когда кто-то добавил комментарий
    window.Echo?.channel('comments').listen('.comment.created', () => load());
});

onBeforeUnmount(() => window.Echo?.leave('comments'));
</script>

<template>
    <div class="mx-auto max-w-5xl space-y-6 px-4 py-8">
        <h1 class="text-2xl font-bold">Comments</h1>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm" :class="{ 'opacity-60': loading }">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-gray-50 text-gray-600">
                    <tr>
                        <th v-for="col in columns" :key="col.key" class="whitespace-nowrap px-4 py-3 font-semibold">
                            <button type="button" class="inline-flex items-center gap-1 hover:text-blue-600" @click="sortBy(col.key)">
                                {{ col.label }}
                                <span class="text-xs">{{ sort === col.key ? (dir === 'asc' ? '▲' : '▼') : '↕' }}</span>
                            </button>
                        </th>
                        <th class="px-4 py-3 font-semibold">Text</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <template v-for="c in comments" :key="c.id">
                        <tr class="align-top">
                            <td class="px-4 py-3 font-medium">
                                {{ c.user_name }}
                                <a v-if="c.home_page" :href="c.home_page" target="_blank" rel="nofollow noopener"
                                   class="block break-all text-xs font-normal text-blue-600 hover:underline">{{ c.home_page }}</a>
                            </td>
                            <td class="break-all px-4 py-3">{{ c.email }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-gray-500">{{ fmt(c.created_at) }}</td>
                            <td class="min-w-64 px-4 py-3">
                                <div class="comment-text" v-html="c.text"></div>
                                <button type="button" class="mt-2 text-xs font-medium text-blue-600 hover:underline"
                                        @click="startReply(c)">Reply</button>
                            </td>
                        </tr>
                        <tr v-if="c.replies.length">
                            <td colspan="4" class="bg-gray-50 px-4 py-3">
                                <div class="space-y-2">
                                    <CommentNode v-for="r in c.replies" :key="r.id" :comment="r" :depth="1" @reply="startReply" />
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!comments.length && !loading">
                        <td colspan="4" class="px-4 py-8 text-center text-gray-500">No comments yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="meta.last_page > 1" class="flex flex-wrap items-center justify-center gap-1 text-sm">
            <button class="rounded border bg-white px-3 py-1 disabled:opacity-40" :disabled="meta.current_page === 1"
                    @click="load(meta.current_page - 1)">‹ Prev</button>
            <button v-if="pages[0] > 1" class="rounded border bg-white px-3 py-1" @click="load(1)">1 …</button>
            <button v-for="p in pages" :key="p" class="rounded border px-3 py-1"
                    :class="p === meta.current_page ? 'border-blue-600 bg-blue-600 text-white' : 'bg-white'"
                    @click="load(p)">{{ p }}</button>
            <button v-if="pages.at(-1) < meta.last_page" class="rounded border bg-white px-3 py-1"
                    @click="load(meta.last_page)">… {{ meta.last_page }}</button>
            <button class="rounded border bg-white px-3 py-1 disabled:opacity-40"
                    :disabled="meta.current_page === meta.last_page" @click="load(meta.current_page + 1)">Next ›</button>
        </nav>

        <CommentForm ref="formRef" :reply-to="replyTo" @created="onCreated" @cancel-reply="replyTo = null" />
    </div>
</template>

<style>
.comment-text { white-space: pre-wrap; overflow-wrap: anywhere; }
.comment-text a { color: #2563eb; text-decoration: underline; }
.comment-text code { background: #f3f4f6; padding: 0 .25rem; border-radius: .25rem; font-family: ui-monospace, monospace; }
</style>