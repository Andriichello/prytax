<script setup lang="ts">
import type { PDFDocumentLoadingTask, PDFDocumentProxy, RenderTask } from 'pdfjs-dist';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';
import { loadPdf } from '@/utils/pdf';
import { anchoredScroll, clampZoom, MAX_ZOOM, MIN_ZOOM, stepZoom } from '@/utils/zoom';

/**
 * The pages of a PDF (an invoice's, a style's preview), drawn with pdf.js, which scroll both ways inside it, with the
 * zoom floating over their bottom: − and + step through the levels, and the percentage goes back to 100 %, the width
 * the pages fit at (the scroller's, up to `maxFitWidth`). Ctrl+wheel (a touchpad's pinch) and a pinch on a touch
 * screen zoom around the fingers or the pointer. A changed zoom stretches the drawn pages at once, and draws them
 * sharp again when it settles, swapping each page's drawing in whole, so nothing blinks. The parent fetches the PDF
 * (`blob`) and says while it loads or failed; `failed` is emitted when pdf.js can't draw it.
 */
const { blob, loading = false, failed = false, maxPages = Infinity, maxFitWidth = Infinity, inset = '', spacer = true } = defineProps<{
    blob: Blob | null;
    loading?: boolean;
    failed?: boolean;
    /** How many pages to draw, from the first. */
    maxPages?: number;
    /** The widest the pages are at 100 %, in CSS pixels. */
    maxFitWidth?: number;
    /** The scroller's padding classes. */
    inset?: string;
    /** Room under the last page, so the zoom doesn't cover its end. */
    spacer?: boolean;
}>();
const emit = defineEmits<{ retry: []; failed: [] }>();

/** The widest drawing of a page, in canvas pixels, beyond which a page is stretched rather than drawn (memory). */
const MAX_PIXEL_WIDTH = 3072;
/** How long the zoom must stay still before the pages are drawn sharp at it. */
const SETTLE_MS = 150;

const scroller = ref<HTMLElement | null>(null);
const zoom = ref(1);
const fitWidth = ref(0);
/** Each page's height to its width, known before it is drawn. */
const ratios = ref<number[]>([]);

const percent = computed(() => Math.round(zoom.value * 100));
/** While the parent fetches the PDF, or pdf.js opens it, the page's place pulses. */
const waiting = computed(() => loading || (blob !== null && !failed && ratios.value.length === 0));
const pagesStyle = computed(() => (fitWidth.value ? { width: `${fitWidth.value * zoom.value}px` } : undefined));

/** The open PDF, and its loading task, which closes it. */
let pdf: PDFDocumentProxy | null = null;
let task: PDFDocumentLoadingTask | null = null;
/** Which PDF is the latest one opened, and which drawing the latest one started: an older one stops. */
let openings = 0;
let drawings = 0;
let rendering: RenderTask | null = null;
let settle: ReturnType<typeof setTimeout> | null = null;
let resizes: ResizeObserver | null = null;

function measure(): void {
    const element = scroller.value;

    if (!element) {
        return;
    }

    const style = getComputedStyle(element);
    const width = element.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);

    fitWidth.value = Math.max(Math.min(width, maxFitWidth), 0);
}

function forget(): void {
    openings++;
    drawings++;
    rendering?.cancel();
    rendering = null;
    void task?.destroy();
    task = null;
    pdf = null;
    ratios.value = [];
}

watch(() => blob, async (current) => {
    forget();

    if (!current) {
        return;
    }

    const opening = openings;

    try {
        const loadingTask = await loadPdf(current);
        const opened = await loadingTask.promise;

        if (opening !== openings) {
            await loadingTask.destroy();

            return;
        }

        task = loadingTask;
        pdf = opened;
        const sizes: number[] = [];

        for (let number = 1; number <= Math.min(opened.numPages, maxPages); number++) {
            const viewport = (await opened.getPage(number)).getViewport({ scale: 1 });
            sizes.push(viewport.height / viewport.width);
        }

        if (opening !== openings) {
            return;
        }

        ratios.value = sizes;
        await nextTick();
        await draw();
    } catch {
        if (opening === openings) {
            emit('failed');
        }
    }
}, { immediate: true });

watch([zoom, fitWidth], () => {
    if (settle) {
        clearTimeout(settle);
    }

    settle = setTimeout(() => void draw(), SETTLE_MS);
});

/**
 * Draw every page at the zoom: each into a canvas of its own, which then replaces the shown drawing; a newer drawing
 * (another zoom, another PDF) stops this one.
 */
async function draw(): Promise<void> {
    const shownPdf = pdf;
    const canvases = [...(scroller.value?.querySelectorAll<HTMLCanvasElement>('canvas[data-page]') ?? [])];

    if (!shownPdf || !fitWidth.value) {
        return;
    }

    rendering?.cancel();
    const drawing = ++drawings;
    const pixelWidth = Math.min(fitWidth.value * zoom.value * (window.devicePixelRatio || 1), MAX_PIXEL_WIDTH);

    try {
        for (const [index, shown] of canvases.entries()) {
            const page = await shownPdf.getPage(index + 1);
            const viewport = page.getViewport({ scale: pixelWidth / page.getViewport({ scale: 1 }).width });
            const sharp = document.createElement('canvas');
            sharp.width = Math.floor(viewport.width);
            sharp.height = Math.floor(viewport.height);

            if (drawing !== drawings) {
                return;
            }

            rendering = page.render({ canvas: sharp, viewport });
            await rendering.promise;

            if (drawing !== drawings) {
                return;
            }

            shown.width = sharp.width;
            shown.height = sharp.height;
            shown.getContext('2d')?.drawImage(sharp, 0, 0);
        }
    } catch {
        // Cancelled by a newer drawing
    } finally {
        if (drawing === drawings) {
            rendering = null;
        }
    }
}

/**
 * Zoom to the level, keeping the point under `anchor` (from the scroller's top left; its middle by default) in place.
 */
async function zoomTo(level: number, anchor?: { x: number; y: number }): Promise<void> {
    const element = scroller.value;
    const next = clampZoom(level);
    const ratio = next / zoom.value;

    if (!element || ratio === 1) {
        return;
    }

    const at = anchor ?? { x: element.clientWidth / 2, y: element.clientHeight / 2 };
    const left = element.scrollLeft;
    const top = element.scrollTop;
    zoom.value = next;
    await nextTick();
    element.scrollLeft = anchoredScroll(left, at.x, ratio);
    element.scrollTop = anchoredScroll(top, at.y, ratio);
}

function pointIn(clientX: number, clientY: number): { x: number; y: number } | undefined {
    const box = scroller.value?.getBoundingClientRect();

    return box ? { x: clientX - box.left, y: clientY - box.top } : undefined;
}

/** Ctrl+wheel, which a touchpad's pinch sends too: about 28 % a notch of a mouse wheel, smoothly on a touchpad. */
function onWheel(event: WheelEvent): void {
    if (!event.ctrlKey || !ratios.value.length) {
        return;
    }

    event.preventDefault();
    const pixels = event.deltaMode === 1 ? event.deltaY * 16 : event.deltaY;
    void zoomTo(zoom.value * 1.0025 ** -pixels, pointIn(event.clientX, event.clientY));
}

/** A pinch of two fingers; one finger scrolls as usual. */
let pinch: { distance: number; zoom: number } | null = null;

function fingers(event: TouchEvent): { distance: number; middle: { x: number; y: number } | undefined } {
    const [first, second] = [event.touches[0], event.touches[1]];

    return {
        distance: Math.hypot(second.clientX - first.clientX, second.clientY - first.clientY),
        middle: pointIn((first.clientX + second.clientX) / 2, (first.clientY + second.clientY) / 2),
    };
}

function onTouchstart(event: TouchEvent): void {
    pinch = event.touches.length === 2 && ratios.value.length ? { distance: fingers(event).distance, zoom: zoom.value } : null;
}

function onTouchmove(event: TouchEvent): void {
    if (!pinch || event.touches.length !== 2) {
        return;
    }

    event.preventDefault();
    const { distance, middle } = fingers(event);
    void zoomTo(pinch.zoom * (distance / pinch.distance), middle);
}

function onTouchend(event: TouchEvent): void {
    if (event.touches.length < 2) {
        pinch = null;
    }
}

onMounted(() => {
    measure();

    if (scroller.value && typeof ResizeObserver !== 'undefined') {
        resizes = new ResizeObserver(measure);
        resizes.observe(scroller.value);
    }
});

onBeforeUnmount(() => {
    forget();
    resizes?.disconnect();

    if (settle) {
        clearTimeout(settle);
    }
});
</script>

<template>
    <div class="relative flex min-h-0 flex-col">
        <div
            ref="scroller"
            class="scrollbar-thin min-h-0 flex-1 touch-pan-x touch-pan-y overflow-auto"
            :class="inset"
            :aria-busy="waiting ? 'true' : 'false'"
            @wheel="onWheel"
            @touchstart="onTouchstart"
            @touchmove="onTouchmove"
            @touchend="onTouchend"
            @touchcancel="onTouchend"
        >
            <slot />

            <div class="mx-auto flex flex-col gap-4" :class="pagesStyle ? '' : 'w-full'" :style="pagesStyle">
                <div v-if="waiting" class="aspect-[210/297] w-full animate-pulse rounded-[4px] bg-muted" role="status" :aria-label="t('core.common.loading')" />
                <div v-else-if="failed" class="flex aspect-[210/297] w-full flex-col items-center justify-center gap-3 rounded-[4px] bg-surface p-6 text-center">
                    <span class="text-15 text-ink-2"><slot name="failed" /></span>
                    <button type="button" class="min-h-11 px-3 text-15 font-medium text-accent" @click="emit('retry')">{{ t('core.common.retry') }}</button>
                </div>
                <div
                    v-for="(ratio, index) in ratios"
                    v-else
                    :key="index"
                    class="w-full shrink-0 overflow-hidden rounded-[4px] bg-white shadow-[0_2px_12px_rgb(23_25_31/0.12)]"
                    :style="{ aspectRatio: `1 / ${ratio}` }"
                >
                    <canvas :data-page="index + 1" class="block h-full w-full" />
                </div>
            </div>

            <div v-if="spacer" class="h-16" aria-hidden="true" />
        </div>

        <div v-if="!waiting && !failed && ratios.length" class="pointer-events-none absolute inset-x-0 bottom-4 flex justify-center">
            <div
                role="group"
                :aria-label="t('shell.ui.zoom')"
                class="pointer-events-auto flex items-center gap-0.5 rounded-full border border-line bg-surface p-1 shadow-[0_6px_20px_rgb(23_25_31/0.18)]"
            >
                <button
                    type="button"
                    :aria-label="t('shell.ui.zoomOut')"
                    :disabled="zoom <= MIN_ZOOM"
                    class="flex size-10 items-center justify-center rounded-full text-ink disabled:opacity-40"
                    @click="zoomTo(stepZoom(zoom, -1))"
                >
                    <Icon name="minus" :size="20" />
                </button>
                <button
                    type="button"
                    :aria-label="t('shell.ui.zoomReset', { percent })"
                    class="num h-10 min-w-16 rounded-full px-2 text-14 font-medium text-ink"
                    @click="zoomTo(1)"
                >
                    {{ percent }} %
                </button>
                <button
                    type="button"
                    :aria-label="t('shell.ui.zoomIn')"
                    :disabled="zoom >= MAX_ZOOM"
                    class="flex size-10 items-center justify-center rounded-full text-ink disabled:opacity-40"
                    @click="zoomTo(stepZoom(zoom, 1))"
                >
                    <Icon name="plus" :size="20" />
                </button>
            </div>
        </div>
    </div>
</template>
