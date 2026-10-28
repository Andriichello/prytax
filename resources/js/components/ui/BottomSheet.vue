<script setup lang="ts">
import { ref, useId } from 'vue';
import Icon from '@/components/ui/Icon.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { useOverlay } from '@/composables/useOverlay';
import { t } from '@/i18n';

/**
 * A sheet that slides up over the page: a handle, a title with a close button (or the `action` slot), its content,
 * and an optional sticky `footer` for its buttons. Escape, the close button and a tap on the dimmed page close it.
 * The page behind is inert while it is open, and a sheet over another one closes first: it and its backdrop lie
 * over the sheet under it, which a tap outside the top sheet doesn't reach. On desktop it is a centred dialog, 520 px
 * wide (design.md §17.3), over the page or the record panel.
 */
const { title } = defineProps<{ title: string }>();
const open = defineModel<boolean>('open', { required: true });

const titleId = useId();
const sheet = ref<HTMLElement | null>(null);

function close(): void {
    open.value = false;
}

const { layer } = useOverlay(open, close, sheet);
const { isDesktop } = useBreakpoint();
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
            enter-active-class="transition-opacity duration-200"
            leave-active-class="transition-opacity duration-150"
        >
            <div v-if="open" class="fixed inset-0 bg-overlay" :style="{ zIndex: 40 + layer * 10 }" aria-hidden="true" @click="close" />
        </Transition>
        <Transition
            :enter-from-class="isDesktop ? 'opacity-0 scale-95' : 'translate-y-full'"
            :leave-to-class="isDesktop ? 'opacity-0 scale-95' : 'translate-y-full'"
            :enter-active-class="isDesktop ? 'transition duration-150 ease-out' : 'transition-transform duration-200 ease-out'"
            :leave-active-class="isDesktop ? 'transition duration-100 ease-in' : 'transition-transform duration-150 ease-in'"
        >
            <section
                v-if="open"
                ref="sheet"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                tabindex="-1"
                class="fixed flex flex-col overflow-hidden bg-paper outline-none"
                :class="isDesktop
                    ? 'inset-x-0 top-1/2 mx-auto max-h-[85dvh] w-130 -translate-y-1/2 rounded-3xl shadow-[0_24px_64px_rgb(23_25_31/0.28)]'
                    : 'inset-x-0 bottom-0 mx-auto max-h-[90dvh] w-full max-w-120 rounded-t-3xl'"
                :style="{ zIndex: 50 + layer * 10 }"
            >
                <div
                    class="flex flex-col gap-3.5 overflow-y-auto"
                    :class="[$slots.footer ? 'pb-4' : 'pb-[calc(env(safe-area-inset-bottom)+28px)]', isDesktop ? 'px-6 pt-5' : 'px-4 pt-2.5']"
                >
                    <div v-if="!isDesktop" class="h-1.25 w-10 shrink-0 self-center rounded-full bg-handle" aria-hidden="true" />
                    <slot name="lead" />
                    <div class="flex items-center justify-between">
                        <h2 :id="titleId" class="m-0 text-22 font-semibold tracking-[-0.02em]">{{ title }}</h2>
                        <slot name="action">
                            <button
                                type="button"
                                :aria-label="t('shell.ui.close')"
                                class="flex size-11 items-center justify-center text-ink"
                                @click="close"
                            >
                                <Icon name="close" :size="22" />
                            </button>
                        </slot>
                    </div>
                    <slot />
                </div>
                <div v-if="$slots.footer" class="border-t border-line bg-surface pt-3" :class="isDesktop ? 'px-6 pb-4' : 'px-4 pb-[calc(env(safe-area-inset-bottom)+16px)]'">
                    <slot name="footer" />
                </div>
            </section>
        </Transition>
    </Teleport>
</template>
