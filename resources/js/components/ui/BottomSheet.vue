<script lang="ts">
/** The open sheets, the topmost last: one page lock for all of them, and Escape closes only the topmost. */
const openSheets: symbol[] = [];

/**
 * Lock the page behind the sheets (no scrolling, no focus or taps reaching it) while any sheet is open.
 */
function lockPage(): void {
    const locked = openSheets.length > 0;

    document.body.style.overflow = locked ? 'hidden' : '';
    document.getElementById('app')?.toggleAttribute('inert', locked);
}
</script>

<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import Icon from '@/components/ui/Icon.vue';

/**
 * A sheet that slides up over the page: a handle, a title with a close button (or the `action` slot), its content,
 * and an optional sticky `footer` for its buttons. Escape, the close button and a tap on the dimmed page close it.
 * The page behind is inert while it is open, and a sheet over another one closes first.
 */
const { title } = defineProps<{ title: string }>();
const open = defineModel<boolean>('open', { required: true });

const titleId = useId();
const sheet = ref<HTMLElement | null>(null);
const token = Symbol('sheet');
let returnFocus: HTMLElement | null = null;

function close(): void {
    open.value = false;
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && openSheets[openSheets.length - 1] === token) {
        close();
    }
}

function release(): void {
    const index = openSheets.indexOf(token);

    if (index !== -1) {
        openSheets.splice(index, 1);
        lockPage();
    }

    document.removeEventListener('keydown', onKeydown);
}

watch(open, async (isOpen) => {
    if (isOpen) {
        returnFocus = document.activeElement as HTMLElement | null;
        openSheets.push(token);
        lockPage();
        document.addEventListener('keydown', onKeydown);
        await nextTick();
        sheet.value?.focus();
    } else if (openSheets.includes(token)) {
        release();
        returnFocus?.focus();
    }
}, { immediate: true });

onBeforeUnmount(release);
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-from-class="opacity-0"
            leave-to-class="opacity-0"
            enter-active-class="transition-opacity duration-200"
            leave-active-class="transition-opacity duration-150"
        >
            <div v-if="open" class="fixed inset-0 z-40 bg-overlay" aria-hidden="true" @click="close" />
        </Transition>
        <Transition
            enter-from-class="translate-y-full"
            leave-to-class="translate-y-full"
            enter-active-class="transition-transform duration-200 ease-out"
            leave-active-class="transition-transform duration-150 ease-in"
        >
            <section
                v-if="open"
                ref="sheet"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                tabindex="-1"
                class="fixed inset-x-0 bottom-0 z-50 mx-auto flex max-h-[90dvh] w-full max-w-120 flex-col overflow-hidden rounded-t-3xl bg-paper outline-none"
            >
                <div
                    class="flex flex-col gap-3.5 overflow-y-auto px-4 pt-2.5"
                    :class="$slots.footer ? 'pb-4' : 'pb-[calc(env(safe-area-inset-bottom)+28px)]'"
                >
                    <div class="h-1.25 w-10 shrink-0 self-center rounded-full bg-handle" aria-hidden="true" />
                    <slot name="lead" />
                    <div class="flex items-center justify-between">
                        <h2 :id="titleId" class="m-0 text-22 font-semibold tracking-[-0.02em]">{{ title }}</h2>
                        <slot name="action">
                            <button
                                type="button"
                                aria-label="Закрити"
                                class="flex size-11 items-center justify-center text-ink"
                                @click="close"
                            >
                                <Icon name="close" :size="22" />
                            </button>
                        </slot>
                    </div>
                    <slot />
                </div>
                <div v-if="$slots.footer" class="border-t border-line bg-surface px-4 pt-3 pb-[calc(env(safe-area-inset-bottom)+16px)]">
                    <slot name="footer" />
                </div>
            </section>
        </Transition>
    </Teleport>
</template>
