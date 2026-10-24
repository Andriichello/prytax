<script setup lang="ts">
import { ref, useId } from 'vue';
import Icon from '@/components/ui/Icon.vue';
import { useOverlay } from '@/composables/useOverlay';
import { t } from '@/i18n';

/**
 * A screen over the whole page, which keeps the page (and its form) as it is underneath: a header with a back or close
 * button, its title and an optional subtitle, its scrolling content, and an optional sticky `footer`. Escape and the
 * header's button close it; the page behind is inert while it is open, and one over another closes first.
 */
const { title, subtitle = undefined, dismiss = 'back' } = defineProps<{
    title: string;
    subtitle?: string;
    /** The header's button: an arrow back (a screen opened from a field) or a cross (a preview). */
    dismiss?: 'back' | 'close';
}>();
const open = defineModel<boolean>('open', { required: true });

const titleId = useId();
const screen = ref<HTMLElement | null>(null);

function close(): void {
    open.value = false;
}

useOverlay(open, close, screen);
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-from-class="translate-x-full"
            leave-to-class="translate-x-full"
            enter-active-class="transition-transform duration-200 ease-out"
            leave-active-class="transition-transform duration-150 ease-in"
        >
            <section
                v-if="open"
                ref="screen"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                tabindex="-1"
                class="fixed inset-0 z-50 flex flex-col bg-paper outline-none"
            >
                <div class="mx-auto flex w-full max-w-120 min-h-0 flex-1 flex-col">
                    <header class="flex items-center gap-1 px-2 pt-2">
                        <button
                            type="button"
                            :aria-label="dismiss === 'close' ? t('shell.ui.close') : t('shell.nav.back')"
                            class="flex size-11 shrink-0 items-center justify-center text-ink"
                            @click="close"
                        >
                            <Icon :name="dismiss === 'close' ? 'close' : 'back'" :size="22" />
                        </button>
                        <div class="flex min-w-0 flex-1 flex-col">
                            <h1 :id="titleId" class="m-0 truncate text-17 font-semibold">{{ title }}</h1>
                            <span v-if="subtitle" class="truncate text-12 text-ink-3">{{ subtitle }}</span>
                        </div>
                    </header>
                    <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 pt-3 pb-8">
                        <slot />
                    </div>
                    <footer
                        v-if="$slots.footer"
                        class="border-t border-line bg-surface px-4 pt-3 pb-[calc(env(safe-area-inset-bottom)+12px)]"
                    >
                        <slot name="footer" />
                    </footer>
                </div>
            </section>
        </Transition>
    </Teleport>
</template>
