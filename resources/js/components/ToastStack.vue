<script setup>
import { toasts, dismissToast, dismissAllToasts } from '../toasts';
import { toast } from '../transitions';

const button = 'rounded-md px-2.5 py-1 text-xs font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600';
</script>

<template>
    <Teleport to="body">
        <!-- Над модальным окном (z-50): уведомление может прийти, пока пишут ответ.
             Регион всегда в DOM и polite: диктор дочитает текущую фразу. -->
        <div aria-live="polite" aria-relevant="additions"
             class="pointer-events-none fixed inset-x-4 top-[max(1rem,env(safe-area-inset-top))] z-[60] flex max-h-[calc(100dvh-2rem)] flex-col items-stretch gap-2 overflow-y-auto sm:inset-x-auto sm:right-4 sm:w-96">
            <button v-if="toasts.length > 1" type="button"
                    class="pointer-events-auto self-end rounded-md bg-gray-800/90 px-2.5 py-1 text-xs font-semibold text-white shadow hover:bg-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                    @click="dismissAllToasts">Close all ({{ toasts.length }})</button>

            <TransitionGroup v-bind="toast">
                <div v-for="t in toasts" :key="t.id"
                     class="pointer-events-auto flex items-start gap-3 rounded-lg border bg-white p-3 shadow-lg"
                     :class="t.variant === 'mine' ? 'border-amber-400 ring-2 ring-amber-300' : 'border-gray-200'">
                    <span aria-hidden="true" class="text-lg leading-6">{{ t.variant === 'mine' ? '↩️' : '💬' }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="break-words text-sm font-medium text-gray-900">{{ t.title }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <!-- Show не закрывает уведомление: закрывается оно только кнопкой Close -->
                            <button v-if="t.action" type="button" :class="[button, 'bg-blue-600 text-white hover:bg-blue-700']"
                                    @click="t.action.run()">{{ t.action.label }}</button>
                            <button type="button" :class="[button, 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-100']"
                                    :aria-label="`Close notification: ${t.title}`"
                                    @click="dismissToast(t.id)">Close</button>
                        </div>
                    </div>
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>
