import { reactive } from 'vue';

// Всплывающие уведомления в стиле push. Одно хранилище на страницу,
// показывает их ToastStack.vue.

const LIFETIME_MS = 7000;
const MAX_VISIBLE = 4;

export const toasts = reactive([]);
const timers = new Map();
let nextId = 1;

/**
 * @param {{ title: string, variant?: 'info'|'mine', action?: { label: string, run: () => void } }} toast
 */
export function pushToast(toast) {
    const id = nextId++;
    toasts.unshift({ variant: 'info', ...toast, id });
    // старые уходят первыми, чтобы стопка не закрывала страницу
    toasts.slice(MAX_VISIBLE).forEach((t) => dismissToast(t.id));
    startTimer(id);
    return id;
}

export function dismissToast(id) {
    clearTimeout(timers.get(id));
    timers.delete(id);
    const index = toasts.findIndex((t) => t.id === id);
    if (index !== -1) toasts.splice(index, 1);
}

// Пока курсор или фокус на уведомлении, оно не исчезает: иначе кнопка
// «Показать» может пропасть прямо под курсором.
export function pauseToast(id) {
    clearTimeout(timers.get(id));
}

export function resumeToast(id) {
    startTimer(id);
}

function startTimer(id) {
    clearTimeout(timers.get(id));
    timers.set(id, setTimeout(() => dismissToast(id), LIFETIME_MS));
}
