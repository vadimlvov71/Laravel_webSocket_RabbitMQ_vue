import { reactive } from 'vue';

// Подсветка новых комментариев. Класс жёлтого фона ставится сразу (без transition),
// а снимается с transition-colors: так подсветка появляется мгновенно и плавно гаснет.
// При prefers-reduced-motion transition нет, и фон просто исчезает.

const DURATION_MS = 3500;

export const highlighted = reactive(new Set());

export function highlight(id) {
    highlighted.add(id);
    setTimeout(() => highlighted.delete(id), DURATION_MS);
}

/** Классы для фона элемента комментария: base — его обычный фон. */
export function highlightClass(id, base = '') {
    return highlighted.has(id)
        ? 'bg-yellow-100'
        : `${base} motion-safe:transition-colors motion-safe:duration-[1500ms]`;
}

/** Прокрутить к комментарию и подсветить. Элемент должен иметь id="comment-{id}". */
export function reveal(id) {
    const el = document.getElementById(`comment-${id}`);
    if (!el) return false;
    const smooth = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    el.scrollIntoView({ block: 'center', behavior: smooth ? 'smooth' : 'auto' });
    highlight(id);
    return true;
}
