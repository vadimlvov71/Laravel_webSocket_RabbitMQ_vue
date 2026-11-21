import { reactive } from 'vue';

// Всплывающие уведомления в стиле push. Одно хранилище на страницу,
// показывает их ToastStack.vue.
// Сами не исчезают: закрываются только кнопкой Close (или Close all).

// Если уведомлений больше, самые старые убираются, чтобы стопка не закрыла страницу.
const MAX_VISIBLE = 5;

export const toasts = reactive([]);
let nextId = 1;

/**
 * @param {{ title: string, variant?: 'info'|'mine', action?: { label: string, run: () => void } }} toast
 */
export function pushToast(toast) {
    const id = nextId++;
    toasts.unshift({ variant: 'info', ...toast, id });
    toasts.splice(MAX_VISIBLE);
    return id;
}

export function dismissToast(id) {
    const index = toasts.findIndex((t) => t.id === id);
    if (index !== -1) toasts.splice(index, 1);
}

export function dismissAllToasts() {
    toasts.splice(0);
}
