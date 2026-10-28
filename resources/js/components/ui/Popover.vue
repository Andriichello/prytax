<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

/**
 * A popover anchored to its button (design.md §17.3): no dimming, placed under the anchor (`bottom-start` or
 * `bottom-end`) or over it (`top-start`), flipped when it doesn't fit and shifted to stay 24 px inside the viewport.
 * Escape and a click outside close it, and the focus returns to the anchor. One popover at a time: opening one closes
 * the others. `focus` moves the focus into it (a menu, a list); without it the focus stays where it was, so a field
 * under a date picker can still be typed in.
 */
const { anchor, placement = 'bottom-start', width = 320, label = undefined, focus = true } = defineProps<{
    anchor: HTMLElement | null;
    placement?: 'bottom-start' | 'bottom-end' | 'top-start';
    width?: number;
    label?: string;
    focus?: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const panel = ref<HTMLElement | null>(null);
const position = ref({ top: 0, left: 0, maxHeight: 600 });

const MARGIN = 24;
const GAP = 8;

/** The open popover's closer, so a new one closes it. */
let closeOpen: (() => void) | null = null;

function place(): void {
    if (!anchor || !panel.value) {
        return;
    }

    const box = anchor.getBoundingClientRect();
    const height = panel.value.offsetHeight;
    const below = window.innerHeight - box.bottom - GAP - MARGIN;
    const above = box.top - GAP - MARGIN;
    const up = placement === 'top-start' ? above >= height || above > below : below < height && above > below;
    const left = placement === 'bottom-end' ? box.right - width : box.left;

    position.value = {
        top: up ? Math.max(MARGIN, box.top - GAP - height) : box.bottom + GAP,
        left: Math.min(Math.max(MARGIN, left), window.innerWidth - width - MARGIN),
        maxHeight: Math.max(160, up ? above : below),
    };
}

function close(): void {
    open.value = false;
}

function onPointerdown(event: PointerEvent): void {
    const target = event.target as Node;

    if (!panel.value?.contains(target) && !anchor?.contains(target) && !(target as Element).closest?.('[data-popover-keep]')) {
        close();
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.stopPropagation();
        close();
        anchor?.focus();
    }
}

function release(): void {
    document.removeEventListener('pointerdown', onPointerdown, true);
    document.removeEventListener('keydown', onKeydown, true);
    window.removeEventListener('resize', place);
    window.removeEventListener('scroll', place, true);

    if (closeOpen === close) {
        closeOpen = null;
    }
}

watch(open, async (opening) => {
    if (!opening) {
        release();

        return;
    }

    if (closeOpen && closeOpen !== close) {
        closeOpen();
    }

    closeOpen = close;
    await nextTick();
    place();
    document.addEventListener('pointerdown', onPointerdown, true);
    document.addEventListener('keydown', onKeydown, true);
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);

    if (focus) {
        panel.value?.focus();
    }
}, { immediate: true });

onBeforeUnmount(release);

defineExpose({ place });
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            ref="panel"
            role="dialog"
            :aria-label="label"
            tabindex="-1"
            class="fixed z-70 flex flex-col overflow-y-auto rounded-card border border-line bg-surface shadow-[0_12px_32px_rgb(23_25_31/0.18)] outline-none"
            :style="{ top: `${position.top}px`, left: `${position.left}px`, width: `${width}px`, maxHeight: `${position.maxHeight}px` }"
        >
            <slot :close="close" />
        </div>
    </Teleport>
</template>
