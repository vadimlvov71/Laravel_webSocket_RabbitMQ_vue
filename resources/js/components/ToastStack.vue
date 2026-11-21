<script setup>
import { toasts, dismissToast, pauseToast, resumeToast } from '../toasts';
import { toast } from '../transitions';

function run(t) {
    dismissToast(t.id);
    t.action.run();
}
</script>

<template>
    <Teleport to="body">
        <!-- Над модальным окном (z-50): уведомление может прийти, пока пишут ответ.
             Регион всегда в DOM и polite: диктор дочитает текущую фразу. -->
        <div aria-live="polite" aria-relevant="additions"
             class="pointer-events-none fixed inset-x-4 top-[max(1rem,env(safe-area-inset-top))] z-[60] flex flex-col items-stretch gap-2 sm:inset-x-auto sm:right-4 sm:w-96">
            <TransitionGroup v-bind="toast">
                <div v-for="t in toasts" :key="t.id"
                     class="pointer-events-auto flex items-start gap-3 rounded-lg border bg-white p-3 shadow-lg"
                     :class="t.variant === 'mine' ? 'border-amber-400 ring-2 ring-amber-300' : 'border-gray-200'"
                     @mouseenter="pauseToast(t.id)" @mouseleave="resumeToast(t.id)"
                     @focusin="pauseToast(t.id)" @focusout="resumeToast(t.id)">
                    <span aria-hidden="true" class="text-lg leading-6">{{ t.variant === 'mine' ? '↩️' : '💬' }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="break-words text-sm font-medium text-gray-900">{{ t.title }}</p>
                        <button v-if="t.action" type="button"
                                class="mt-1 rounded text-sm font-semibold text-blue-600 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                                @click="run(t)">{{ t.action.label }}</button>
                    </div>
                    <button type="button" aria-label="Dismiss notification"
                            class="-m-1 rounded p-1 text-xl leading-none text-gray-400 hover:text-gray-700 focus-visible:outline-2 focus-visible:outline-blue-600"
                            @click="dismissToast(t.id)">&times;</button>
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
