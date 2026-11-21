<script setup>
import { ref, computed, watch, useId, onBeforeUnmount } from 'vue';
import axios from 'axios';
import { fade } from '../transitions';

// Поиск по комментариям (GET /comments/search, Elasticsearch).
// Combobox по WAI-ARIA: фокус остаётся в поле, стрелки двигают активный вариант
// (aria-activedescendant), Enter выбирает, Esc закрывает список.
const emit = defineEmits(['select']);

const MIN_LENGTH = 2; // как SearchCommentsRequest::MIN_LENGTH
const DEBOUNCE_MS = 300;

const listId = useId();
const query = ref('');
const results = ref([]);
const status = ref('idle'); // idle | loading | done | unavailable | error
const open = ref(false);
const active = ref(-1);
const root = ref(null);
let timer = null;
let controller = null;

const fmt = (iso) => new Date(iso).toLocaleString();
const optionId = (i) => `${listId}-option-${i}`;

const message = computed(() => {
    if (status.value === 'loading' && !results.value.length) return 'Searching…';
    if (status.value === 'done' && !results.value.length) return 'Nothing found.';
    if (status.value === 'unavailable') return 'Search is temporarily unavailable.';
    if (status.value === 'error') return 'Search failed. Please try again.';
    return '';
});

watch(query, (q) => {
    clearTimeout(timer);
    active.value = -1;
    if (q.trim().length < MIN_LENGTH) {
        controller?.abort();
        results.value = [];
        status.value = 'idle';
        open.value = false;
        return;
    }
    timer = setTimeout(() => run(q.trim()), DEBOUNCE_MS);
});

async function run(q) {
    // ответ на старый запрос не должен перезаписать результаты нового
    controller?.abort();
    controller = new AbortController();
    status.value = 'loading';
    open.value = true;
    try {
        const { data } = await axios.get('/comments/search', { params: { q }, signal: controller.signal });
        results.value = data.data;
        status.value = 'done';
    } catch (e) {
        if (axios.isCancel(e)) return;
        results.value = [];
        status.value = e.response?.status === 503 ? 'unavailable' : 'error';
    }
}

function choose(hit) {
    open.value = false;
    active.value = -1;
    emit('select', hit.id);
}

function onKeydown(e) {
    if (e.key === 'Escape') {
        if (open.value) {
            e.preventDefault();
            open.value = false;
        } else {
            query.value = '';
        }
        return;
    }
    if (!results.value.length) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        open.value = true;
        const step = e.key === 'ArrowDown' ? 1 : -1;
        active.value = (active.value + step + results.value.length) % results.value.length;
        document.getElementById(optionId(active.value))?.scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter' && open.value && active.value >= 0) {
        e.preventDefault();
        choose(results.value[active.value]);
    }
}

// клик вне поля и списка закрывает список, фокус при этом не трогаем
function onFocusOut(e) {
    if (!root.value?.contains(e.relatedTarget)) open.value = false;
}

onBeforeUnmount(() => {
    clearTimeout(timer);
    controller?.abort();
});
</script>

<template>
    <div ref="root" class="relative w-full sm:w-80" @focusout="onFocusOut">
        <label :for="`${listId}-input`" class="sr-only">Search comments</label>
        <input :id="`${listId}-input`" v-model="query" type="search" autocomplete="off" placeholder="Search comments…"
               role="combobox" aria-autocomplete="list" :aria-expanded="open" :aria-controls="listId"
               :aria-activedescendant="active >= 0 ? optionId(active) : undefined"
               class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
               @keydown="onKeydown" @focus="open = results.length > 0 || !!message" />

        <Transition v-bind="fade">
            <div v-show="open && (results.length || message)"
                 class="absolute inset-x-0 top-full z-30 mt-1 max-h-96 overflow-y-auto rounded-md border border-gray-200 bg-white shadow-lg sm:left-auto sm:w-[28rem]">
                <ul :id="listId" role="listbox" aria-label="Search results">
                    <!-- mousedown.prevent: поле не теряет фокус до click, список не закрывается раньше выбора -->
                    <li v-for="(hit, i) in results" :id="optionId(i)" :key="hit.id" role="option" :aria-selected="i === active"
                        class="cursor-pointer border-b border-gray-100 px-3 py-2 text-sm last:border-0"
                        :class="i === active ? 'bg-blue-50' : 'hover:bg-gray-50'"
                        @mousedown.prevent @mouseenter="active = i" @click="choose(hit)">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-2 text-xs text-gray-500">
                            <span class="font-semibold text-gray-800">{{ hit.user_name }}</span>
                            <span>{{ hit.parent_id ? 'reply · ' : '' }}{{ fmt(hit.created_at) }}</span>
                        </div>
                        <!-- snippet экранирован Elasticsearch (encoder: html), теги в нём только <mark> -->
                        <p class="search-snippet mt-0.5 break-words text-gray-700" v-html="hit.snippet"></p>
                    </li>
                </ul>
                <p v-if="message" role="status" class="px-3 py-2 text-sm"
                   :class="status === 'unavailable' || status === 'error' ? 'text-red-600' : 'text-gray-500'">{{ message }}</p>
            </div>
        </Transition>
    </div>
</template>

<style>
.search-snippet mark { background: #fef08a; color: inherit; border-radius: .125rem; padding: 0 .1rem; }
</style>
