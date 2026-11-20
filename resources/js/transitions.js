// Наборы классов для <Transition>/<TransitionGroup> (v-bind="fade").
// Анимация включается только под motion-safe:, поэтому при prefers-reduced-motion
// у элемента нет transition-duration и Vue переключает состояние мгновенно.

// Затемнение под модальным окном.
export const fade = {
    enterActiveClass: 'motion-safe:transition-opacity motion-safe:duration-200 motion-safe:ease-out',
    leaveActiveClass: 'motion-safe:transition-opacity motion-safe:duration-150 motion-safe:ease-in',
    enterFromClass: 'opacity-0',
    leaveToClass: 'opacity-0',
};

// Само окно: на телефоне выезжает снизу, на sm+ слегка увеличивается.
export const pop = {
    enterActiveClass: 'motion-safe:transition motion-safe:duration-200 motion-safe:ease-out',
    leaveActiveClass: 'motion-safe:transition motion-safe:duration-150 motion-safe:ease-in',
    enterFromClass: 'opacity-0 translate-y-8 sm:translate-y-0 sm:scale-95',
    leaveToClass: 'opacity-0 translate-y-8 sm:translate-y-0 sm:scale-95',
};

// Появление новых комментариев. Уход без анимации: строка таблицы,
// исчезающая с задержкой, заставила бы список прыгать после её удаления.
export const listEnter = {
    enterActiveClass: 'motion-safe:transition motion-safe:duration-500 motion-safe:ease-out',
    enterFromClass: 'opacity-0 -translate-y-2',
};
