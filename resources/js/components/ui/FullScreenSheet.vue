<script setup lang="ts">
import { ref, useId } from 'vue';
import Icon from '@/components/ui/Icon.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { useOverlay } from '@/composables/useOverlay';
import { t } from '@/i18n';

/**
 * A screen over the whole page, which keeps the page (and its form) as it is underneath: a header with its title and
 * an optional subtitle, and a back arrow before them or a ✕ after them (the modal header, design.md §16.6, rule 7),
 * its scrolling content, and an optional sticky `footer`. On desktop it is a centred dialog with rounded corners and
 * its backdrop (design.md §17.3), 560 px wide, or `wide` (up to 1120 × 960 px, for a document's preview, whose
 * content lays itself out and scrolls by itself); the header, the content and the footer span the dialog, so the
 * footer fills its rounded bottom and the scrollbar keeps to its edge. Escape and the header's button close it; the
 * page behind is inert while it is open, and one over another closes first.
 */
const { title, subtitle = undefined, dismiss = 'back', size = 'default' } = defineProps<{
    title: string;
    subtitle?: string;
    /** The header's button: an arrow back (a screen opened from a field) or a cross (a preview). */
    dismiss?: 'back' | 'close';
    /** The desktop dialog's size. */
    size?: 'default' | 'wide';
}>();
const open = defineModel<boolean>('open', { required: true });

const titleId = useId();
const screen = ref<HTMLElement | null>(null);

function close(): void {
    open.value = false;
}

const { layer } = useOverlay(open, close, screen);
const { isDesktop } = useBreakpoint();
</script>

<template>
    <Teleport to="body">
        <div v-if="open && isDesktop" class="fixed inset-0 bg-overlay" :style="{ zIndex: 40 + layer * 10 }" aria-hidden="true" @click="close" />
        <Transition
            :enter-from-class="isDesktop ? 'opacity-0' : 'translate-x-full'"
            :leave-to-class="isDesktop ? 'opacity-0' : 'translate-x-full'"
            :enter-active-class="isDesktop ? 'transition-opacity duration-150' : 'transition-transform duration-200 ease-out'"
            :leave-active-class="isDesktop ? 'transition-opacity duration-100' : 'transition-transform duration-150 ease-in'"
        >
            <section
                v-if="open"
                ref="screen"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                tabindex="-1"
                class="fixed flex flex-col bg-paper outline-none"
                :class="isDesktop
                    ? ['inset-x-0 top-1/2 mx-auto -translate-y-1/2 overflow-hidden rounded-3xl shadow-[0_24px_64px_rgb(23_25_31/0.28)]', size === 'wide' ? 'h-[min(960px,calc(100dvh-64px))] w-[min(1120px,calc(100vw-64px))]' : 'max-h-[88dvh] w-140']
                    : 'inset-0'"
                :style="{ zIndex: 50 + layer * 10 }"
            >
                <div class="flex min-h-0 w-full flex-1 flex-col" :class="isDesktop ? '' : 'mx-auto max-w-120'">
                    <header
                        class="flex shrink-0 items-center gap-1"
                        :class="[isDesktop ? 'border-b border-line pt-4 pb-3' : 'pt-2', dismiss === 'close' ? (isDesktop ? 'pr-4 pl-6' : 'pr-2 pl-4') : (isDesktop ? 'pr-6 pl-3' : 'px-2')]"
                    >
                        <button
                            v-if="dismiss === 'back'"
                            type="button"
                            :aria-label="t('shell.nav.back')"
                            class="flex size-11 shrink-0 items-center justify-center text-ink"
                            @click="close"
                        >
                            <Icon name="back" :size="22" />
                        </button>
                        <div class="flex min-w-0 flex-1 flex-col">
                            <h1 :id="titleId" class="m-0 truncate text-17 font-semibold">{{ title }}</h1>
                            <span v-if="subtitle" class="truncate text-12 text-ink-3">{{ subtitle }}</span>
                        </div>
                        <button
                            v-if="dismiss === 'close'"
                            type="button"
                            :aria-label="t('shell.ui.close')"
                            class="flex size-11 shrink-0 items-center justify-center text-ink"
                            @click="close"
                        >
                            <Icon name="close" :size="22" />
                        </button>
                    </header>
                    <div v-if="isDesktop && size === 'wide'" class="flex min-h-0 flex-1 flex-col">
                        <slot />
                    </div>
                    <div v-else class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto" :class="isDesktop ? 'scrollbar-thin py-5 pr-4 pl-6' : 'px-4 pt-3 pb-8'">
                        <slot />
                    </div>
                    <footer
                        v-if="$slots.footer"
                        class="shrink-0 border-t border-line bg-surface"
                        :class="isDesktop ? 'px-6 py-4' : 'px-4 pt-3 pb-[calc(env(safe-area-inset-bottom)+12px)]'"
                    >
                        <slot name="footer" />
                    </footer>
                </div>
            </section>
        </Transition>
    </Teleport>
</template>
