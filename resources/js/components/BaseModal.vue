<script setup>
import { ref, watch, nextTick, useId, onMounted, onBeforeUnmount } from 'vue';
import { fade, pop } from '../transitions';

// Содержимое не размонтируется при закрытии (v-show): виджет reCAPTCHA и введённые
// данные переживают повторное открытие, а скрытые поля не попадают в порядок Tab.
const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
});
const emit = defineEmits(['close']);

const titleId = useId();
const panel = ref(null);
const content = ref(null);
let opener = null;
let saved = null;
let pressedOnBackdrop = false;

const FOCUSABLE = [
    'a[href]', 'button:not([disabled])', 'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])', 'textarea:not([disabled])', 'iframe', '[tabindex]:not([tabindex="-1"])',
].join(',');

const focusables = (root) => [...(root?.querySelectorAll(FOCUSABLE) ?? [])]
    .filter((el) => el.getClientRects().length > 0);

// ---------- блокировка прокрутки ----------
function lockScroll() {
    if (saved) return;
    const html = document.documentElement;
    const body = document.body;
    // Ширину скроллбара меряем до скрытия, иначе контент сдвинется вправо.
    const scrollbar = window.innerWidth - html.clientWidth;
    saved = { html: html.style.overflow, body: body.style.overflow, padding: body.style.paddingRight };
    html.style.overflow = 'hidden';
    body.style.overflow = 'hidden';
    if (scrollbar > 0) {
        body.style.paddingRight = `${parseFloat(getComputedStyle(body).paddingRight) + scrollbar}px`;
    }
}

function unlockScroll() {
    if (!saved) return;
    document.documentElement.style.overflow = saved.html;
    document.body.style.overflow = saved.body;
    document.body.style.paddingRight = saved.padding;
    saved = null;
}

// ---------- открытие/закрытие ----------
watch(() => props.open, async (open) => {
    if (open) {
        opener = document.activeElement;
        lockScroll();
        await nextTick();
        (focusables(content.value)[0] ?? panel.value)?.focus();
    } else {
        unlockScroll();
        if (opener?.isConnected) opener.focus();
        opener = null;
    }
});

// Закрываем, только если и нажатие, и отпускание были на самом фоне:
// выделение текста в поле с отпусканием мыши над фоном окно не закроет.
function onBackdropDown(e) {
    pressedOnBackdrop = e.target === e.currentTarget;
}

function onBackdropClick(e) {
    if (pressedOnBackdrop && e.target === e.currentTarget) emit('close');
    pressedOnBackdrop = false;
}

function onKeydown(e) {
    if (!props.open) return;
    if (e.key === 'Escape' && !e.isComposing) {
        e.preventDefault();
        emit('close');
        return;
    }
    if (e.key !== 'Tab') return;

    // Простое удержание фокуса: с последнего элемента на первый и обратно.
    const items = focusables(panel.value);
    if (!items.length) {
        e.preventDefault();
        panel.value.focus();
        return;
    }
    const first = items[0];
    const last = items.at(-1);
    const active = document.activeElement;
    const outside = !panel.value.contains(active);
    if (e.shiftKey && (active === first || active === panel.value || outside)) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && (active === last || outside)) {
        e.preventDefault();
        first.focus();
    }
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    unlockScroll();
});
</script>

<template>
    <Teleport to="body">
        <Transition v-bind="fade">
            <div v-show="open" class="fixed inset-0 z-40 bg-gray-900/60" aria-hidden="true"></div>
        </Transition>

        <Transition v-bind="pop">
            <div v-show="open" class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4"
                 @pointerdown="onBackdropDown" @click="onBackdropClick">
                <div ref="panel" role="dialog" aria-modal="true" :aria-labelledby="titleId" tabindex="-1"
                     class="flex max-h-[92dvh] w-full flex-col rounded-t-xl bg-white shadow-xl focus:outline-none sm:max-h-[90dvh] sm:max-w-2xl sm:rounded-xl">
                    <header class="flex shrink-0 items-center justify-between gap-4 border-b border-gray-200 px-4 py-3 sm:px-6">
                        <h2 :id="titleId" class="min-w-0 break-words text-lg font-semibold">{{ title }}</h2>
                        <button type="button" aria-label="Close dialog"
                                class="-mr-2 shrink-0 rounded-md p-2 text-2xl leading-none text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                                @click="emit('close')">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </header>
                    <div ref="content"
                         class="min-h-0 overflow-y-auto overscroll-contain px-4 pt-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:px-6 sm:pb-6">
                        <slot />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
