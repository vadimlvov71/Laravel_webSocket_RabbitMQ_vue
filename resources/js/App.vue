<script setup>
import { ref, reactive, computed, nextTick, onMounted, onBeforeUnmount } from 'vue';
import axios from 'axios';
import AttachmentLightbox from './components/AttachmentLightbox.vue';
import AttachmentPreview from './components/AttachmentPreview.vue';
import BaseModal from './components/BaseModal.vue';
import CommentForm from './components/CommentForm.vue';
import CommentNode from './components/CommentNode.vue';
import ToastStack from './components/ToastStack.vue';
import { listEnter } from './transitions';
import { pushToast } from './toasts';
import { rememberMine, isMine } from './myComments';
import { highlight, highlightClass, reveal } from './highlight';
import { countUnread, startUnreadTitle, stopUnreadTitle } from './unreadTitle';

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
const loadError = ref(false);
const replyTo = ref(null);
const modalOpen = ref(false);
const notice = ref('');
// вложение не сбрасываем при закрытии: содержимое нужно до конца анимации ухода
const preview = ref(null);
const previewOpen = ref(false);
let noticeTimer = null;

const modalTitle = computed(() => (replyTo.value ? `Reply to ${replyTo.value.user_name}` : 'Add comment'));

const fmt = (iso) => new Date(iso).toLocaleString();

async function load(page = meta.current_page) {
    loading.value = true;
    try {
        const { data } = await axios.get('/comments', { params: { sort: sort.value, dir: dir.value, page } });
        comments.value = data.data;
        Object.assign(meta, data.meta);
        loadError.value = false;
    } catch {
        loadError.value = true;
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

function openForm(comment = null) {
    replyTo.value = comment;
    modalOpen.value = true;
}

function openPreview(attachment) {
    preview.value = attachment;
    previewOpen.value = true;
}

// ---------- новые комментарии ----------
const containsId = (list, id) => list.some((c) => c.id === id || containsId(c.replies ?? [], id));

/**
 * Показать комментарий: если его ветки нет на текущей странице, спрашиваем
 * у сервера номер страницы при текущей сортировке и открываем её.
 */
async function showComment(id) {
    if (!containsId(comments.value, id)) {
        try {
            const { data } = await axios.get(`/comments/${id}/page`, { params: { sort: sort.value, dir: dir.value } });
            await load(data.page);
        } catch {
            return;
        }
    }
    await nextTick();
    reveal(id);
}

async function onCreated(id) {
    // replyTo не сбрасываем: заголовок окна не должен меняться во время анимации закрытия
    modalOpen.value = false;
    notice.value = 'Your comment has been sent';
    clearTimeout(noticeTimer);
    noticeTimer = setTimeout(() => (notice.value = ''), 5000);
    rememberMine(id);
    // своё событие из WebSocket не придёт (toOthers), поэтому показываем сами
    await load();
    await showComment(id);
}

// WebSocket (Reverb): событие приходит во все вкладки, кроме вкладки автора
async function onRemoteComment(e) {
    countUnread();
    await load();

    const visible = containsId(comments.value, e.id);
    if (visible) highlight(e.id);

    const toMe = isMine(e.parent_id);
    let title = `New comment from ${e.user_name}`;
    if (toMe) title = `${e.user_name} replied to your comment`;
    else if (e.parent_id) title = `${e.user_name} replied to ${e.parent_user_name ?? 'a comment'}`;

    pushToast({
        title,
        variant: toMe ? 'mine' : 'info',
        action: { label: visible ? 'Show' : 'Show (other page)', run: () => showComment(e.id) },
    });
}

onMounted(() => {
    load(1);
    startUnreadTitle();
    window.Echo?.channel('comments').listen('.comment.created', onRemoteComment);
});

onBeforeUnmount(() => {
    window.Echo?.leave('comments');
    stopUnreadTitle();
    clearTimeout(noticeTimer);
});
</script>

<template>
    <div class="mx-auto max-w-5xl space-y-6 px-4 py-8">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">Comments</h1>
            <button type="button" @click="openForm()"
                    class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600">Add comment</button>
        </div>

        <!-- Регион всегда в DOM, чтобы скринридер объявил появившийся текст -->
        <p role="status" class="text-sm font-medium text-green-700" :class="{ 'sr-only': !notice }">
            <span v-if="notice">✓ {{ notice }}</span>
        </p>

        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm" :class="{ 'opacity-60': loading }"
             :aria-busy="loading">
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
                <!-- Один tbody на комментарий: TransitionGroup нужен один ключевой элемент на запись -->
                <TransitionGroup v-bind="listEnter">
                    <tbody v-for="c in comments" :key="c.id" class="divide-y divide-gray-100 border-t border-gray-100">
                        <tr :id="`comment-${c.id}`" class="scroll-mt-24 align-top" :class="highlightClass(c.id)">
                            <td class="px-4 py-3 font-medium">
                                {{ c.user_name }}
                                <a v-if="c.home_page" :href="c.home_page" target="_blank" rel="nofollow noopener"
                                   class="block break-all text-xs font-normal text-blue-600 hover:underline">{{ c.home_page }}</a>
                            </td>
                            <td class="break-all px-4 py-3">{{ c.email }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-gray-500">{{ fmt(c.created_at) }}</td>
                            <td class="min-w-64 px-4 py-3">
                                <div class="comment-text" v-html="c.text"></div>
                                <AttachmentPreview v-if="c.attachment" :attachment="c.attachment" @open="openPreview" />
                                <button type="button" :aria-label="`Reply to ${c.user_name}`"
                                        class="mt-2 rounded text-xs font-medium text-blue-600 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                                        @click="openForm(c)">Reply</button>
                            </td>
                        </tr>
                        <tr v-if="c.replies.length">
                            <td colspan="4" class="bg-gray-50 px-4 py-3">
                                <TransitionGroup v-bind="listEnter" appear tag="div" class="space-y-2">
                                    <CommentNode v-for="r in c.replies" :key="r.id" :comment="r" :depth="1"
                                                 @reply="openForm" @preview="openPreview" />
                                </TransitionGroup>
                            </td>
                        </tr>
                    </tbody>
                </TransitionGroup>
                <tbody v-if="!comments.length && !loadError" class="border-t border-gray-100">
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-gray-500">
                            {{ loading ? 'Loading comments…' : 'No comments yet.' }}
                        </td>
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

        <p v-if="loadError" role="alert" class="text-sm text-red-600">
            Could not load comments.
            <button type="button" class="rounded font-medium underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600" @click="load()">Retry</button>
        </p>

        <BaseModal :open="modalOpen" :title="modalTitle" @close="modalOpen = false">
            <CommentForm :reply-to="replyTo" @created="onCreated" />
        </BaseModal>

        <AttachmentLightbox :open="previewOpen" :attachment="preview" @close="previewOpen = false" />
        <ToastStack />
    </div>
</template>

<style>
.comment-text { white-space: pre-wrap; overflow-wrap: anywhere; }
.comment-text a { color: #2563eb; text-decoration: underline; }
.comment-text code { background: #f3f4f6; padding: 0 .25rem; border-radius: .25rem; font-family: ui-monospace, monospace; }
</style>