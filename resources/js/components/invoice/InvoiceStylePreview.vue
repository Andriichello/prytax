<script setup lang="ts">
import type { PDFDocumentLoadingTask, PDFPageProxy, RenderTask } from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { api } from '@/api/client';
import type { InvoiceLocale, InvoiceStyle } from '@/api/types';
import FullScreenSheet from '@/components/ui/FullScreenSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { LOCALE_NAMES, t } from '@/i18n';
import { adjacentStyle, INVOICE_STYLES, styleIndex, styleName } from '@/utils/invoiceStyles';

/**
 * Перегляд стилю: the first page of an invoice's PDF in a style, as the API renders it (the draft's, the client's
 * latest invoice or the ФОП's, nothing saved), drawn with pdf.js; arrows step through the catalogue, and the language
 * switch changes only the preview. "Обрати" chooses the shown style. On desktop it is a wide dialog: the page, about
 * 760 px wide, scrolls on the left, and the controls stay on the right.
 *
 * Лупа: a click on the page (or the «Лупа» button) turns on a round lens, which follows the pointer and shows the
 * page 1.35× from a sharper drawing of it, made the first time; a click again, or Escape (which leaves the dialog
 * open), turns it off, and so does another style or language. Off the page the lens hides until the pointer is back.
 * On a touch screen a tap turns it on, a drag moves it (and doesn't scroll), and another tap turns it off.
 */
const { style, locale, target } = defineProps<{
    /** The style shown first. */
    style: InvoiceStyle;
    /** The level's invoice language, which the preview starts in. */
    locale: InvoiceLocale;
    /** The invoice to preview, or the client whose latest invoice is previewed. */
    target: { client_id?: number | null; invoice_id?: number | null };
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ choose: [style: InvoiceStyle] }>();

const { path } = useCurrentProfile();
const { isDesktop } = useBreakpoint();

const shown = ref<InvoiceStyle>(style);
const language = ref<InvoiceLocale>(locale);
const canvas = ref<HTMLCanvasElement | null>(null);
const frame = ref<HTMLElement | null>(null);
const lensCanvas = ref<HTMLCanvasElement | null>(null);
const loading = ref(false);
const drawing = ref(false);
const failed = ref(false);
const pdfUrl = ref<string | null>(null);

/** How much the lens magnifies, and the widest drawing it copies from (in canvas pixels). */
const ZOOM = 1.35;
const MAX_ZOOMED_WIDTH = 4096;
/** A press that moves farther than this is a drag (it moves the lens), not a tap (which turns it on or off). */
const TAP_SLOP = 6;

const lensSize = computed(() => (isDesktop.value ? 240 : 180));
const magnifying = ref(false);
/** Where the lens is, in CSS pixels of the page; null while the pointer is off the page. */
const lens = ref<{ x: number; y: number } | null>(null);
const lensStyle = computed(() => (lens.value
    ? {
        width: `${lensSize.value}px`,
        height: `${lensSize.value}px`,
        left: `${lens.value.x - lensSize.value / 2}px`,
        top: `${lens.value.y - lensSize.value / 2}px`,
    }
    : undefined));

// Synchronous, so opening sets the style and language before the load below runs, once
watch(open, (isOpen) => {
    if (isOpen) {
        shown.value = style;
        language.value = locale;
    } else {
        controller?.abort();
        rendering?.cancel();
        stopMagnifying();
        forgetPage();
        forgetPdf();
    }
}, { flush: 'sync' });

watch([open, shown, language], () => {
    if (open.value) {
        void load();
    }
});

const languageOptions = computed(() => (['en', 'uk'] as const).map((code) => ({ value: code, label: LOCALE_NAMES[code] })));

let controller: AbortController | null = null;
let rendering: RenderTask | null = null;

/** The shown page's document, kept open for the lens's sharper drawing, and that drawing once made. */
let shownTask: PDFDocumentLoadingTask | null = null;
let shownPage: PDFPageProxy | null = null;
let zoomed: HTMLCanvasElement | null = null;
let zooming: RenderTask | null = null;

function forgetPdf(): void {
    if (pdfUrl.value) {
        URL.revokeObjectURL(pdfUrl.value);
        pdfUrl.value = null;
    }
}

function forgetPage(): void {
    zooming?.cancel();
    zooming = null;
    zoomed = null;
    shownPage = null;
    void shownTask?.destroy();
    shownTask = null;
}

/**
 * Fetch the preview's PDF and draw its first page at the width of the frame; a newer load cancels this one, and its
 * drawing too, as pdf.js draws on a canvas once at a time.
 */
async function load(): Promise<void> {
    controller?.abort();
    rendering?.cancel();
    stopMagnifying();
    const current = new AbortController();
    controller = current;
    loading.value = true;
    drawing.value = false;
    failed.value = false;

    try {
        const blob = await api.blob(path('invoices/style-preview'), {
            style: shown.value,
            locale: language.value,
            client_id: target.client_id ?? undefined,
            invoice_id: target.invoice_id ?? undefined,
        }, current.signal);
        const pdfjs = await import('pdfjs-dist');
        pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

        const task = pdfjs.getDocument({ data: new Uint8Array(await blob.arrayBuffer()) });
        const page = await (await task.promise).getPage(1);

        if (current.signal.aborted) {
            await task.destroy();

            return;
        }

        forgetPage();
        shownTask = task;
        shownPage = page;
        forgetPdf();
        pdfUrl.value = URL.createObjectURL(blob);
        loading.value = false;
        drawing.value = true;
        await nextTick();

        const drawn = canvas.value;
        const width = frame.value?.clientWidth ?? 358;

        if (drawn) {
            const scale = (width / page.getViewport({ scale: 1 }).width) * (window.devicePixelRatio || 1);
            const viewport = page.getViewport({ scale });
            drawn.width = Math.floor(viewport.width);
            drawn.height = Math.floor(viewport.height);
            rendering = page.render({ canvas: drawn, viewport });
            await rendering.promise;
        }

        drawing.value = false;
    } catch {
        if (!current.signal.aborted) {
            failed.value = true;
            loading.value = false;
            drawing.value = false;
        }
    }
}

/** Draw the shown page at the lens's magnification, once; until then the lens enlarges the page as drawn. */
async function drawZoomed(): Promise<void> {
    const page = shownPage;
    const width = frame.value?.clientWidth;

    if (!page || !width || zoomed || zooming) {
        return;
    }

    const pageWidth = page.getViewport({ scale: 1 }).width;
    const viewport = page.getViewport({ scale: Math.min(width * ZOOM * (window.devicePixelRatio || 1), MAX_ZOOMED_WIDTH) / pageWidth });
    const sharp = document.createElement('canvas');
    sharp.width = Math.floor(viewport.width);
    sharp.height = Math.floor(viewport.height);
    zooming = page.render({ canvas: sharp, viewport });

    try {
        await zooming.promise;

        if (page === shownPage) {
            zoomed = sharp;
            drawLens();
        }
    } catch {
        // Cancelled by another page; the lens keeps enlarging the page as drawn
    } finally {
        zooming = null;
    }
}

/** Copy the part of the page under the lens into it, magnified. */
function drawLens(): void {
    const target = lensCanvas.value;
    const position = lens.value;
    const page = canvas.value;
    const width = frame.value?.clientWidth;
    const context = target?.getContext('2d');

    if (!target || !context || !position || !page || !width) {
        return;
    }

    const source = zoomed ?? page;
    const pixels = Math.round(lensSize.value * (window.devicePixelRatio || 1));
    const perCssPixel = source.width / width;
    const region = (lensSize.value / ZOOM) * perCssPixel;

    if (target.width !== pixels) {
        target.width = pixels;
        target.height = pixels;
    }

    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, pixels, pixels);
    context.drawImage(source, position.x * perCssPixel - region / 2, position.y * perCssPixel - region / 2, region, region, 0, 0, pixels, pixels);
}

function startMagnifying(at: { x: number; y: number }): void {
    magnifying.value = true;
    lens.value = at;
    window.addEventListener('keydown', onKeydown, true);
    void nextTick(drawLens);
    void drawZoomed();
}

function stopMagnifying(): void {
    magnifying.value = false;
    lens.value = null;
    window.removeEventListener('keydown', onKeydown, true);
}

/** Escape turns the lens off before it reaches the dialog, which stays open. */
function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.stopPropagation();
        stopMagnifying();
    }
}

/** A pointer's place on the page, kept inside it. */
function pointOf(event: PointerEvent): { x: number; y: number } | null {
    const box = frame.value?.getBoundingClientRect();

    if (!box) {
        return null;
    }

    return {
        x: Math.min(Math.max(event.clientX - box.left, 0), box.width),
        y: Math.min(Math.max(event.clientY - box.top, 0), box.height),
    };
}

const ready = computed(() => !loading.value && !failed.value && Boolean(pdfUrl.value));
let press: { x: number; y: number; moved: boolean } | null = null;

function onPointerdown(event: PointerEvent): void {
    if (!ready.value || (event.pointerType === 'mouse' && event.button !== 0)) {
        return;
    }

    press = { x: event.clientX, y: event.clientY, moved: false };

    if (magnifying.value && event.pointerType !== 'mouse') {
        (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
    }
}

function onPointermove(event: PointerEvent): void {
    if (press && Math.hypot(event.clientX - press.x, event.clientY - press.y) > TAP_SLOP) {
        press.moved = true;
    }

    if (magnifying.value && (event.pointerType === 'mouse' || press)) {
        lens.value = pointOf(event);
        drawLens();
    }
}

function onPointerup(event: PointerEvent): void {
    const tapped = press !== null && !press.moved;
    press = null;

    if (!tapped) {
        return;
    }

    if (magnifying.value) {
        stopMagnifying();
    } else {
        const at = pointOf(event);

        if (at) {
            startMagnifying(at);
        }
    }
}

function onPointerleave(event: PointerEvent): void {
    if (event.pointerType === 'mouse') {
        lens.value = null;
    }
}

/** «Лупа» from the keyboard or a click: the lens starts in the middle of the page's visible part. */
function toggleMagnifier(): void {
    const box = frame.value?.getBoundingClientRect();

    if (magnifying.value || !box) {
        stopMagnifying();

        return;
    }

    const top = Math.max(box.top, 0);
    const bottom = Math.min(box.bottom, window.innerHeight);
    startMagnifying({ x: box.width / 2, y: Math.min(Math.max((top + bottom) / 2 - box.top, 0), box.height) });
}

function step(direction: 1 | -1): void {
    shown.value = adjacentStyle(shown.value, direction);
}

function openPdf(): void {
    if (pdfUrl.value) {
        window.open(pdfUrl.value, '_blank', 'noopener');
    }
}

function choose(): void {
    emit('choose', shown.value);
    open.value = false;
}

onBeforeUnmount(() => {
    controller?.abort();
    rendering?.cancel();
    stopMagnifying();
    forgetPage();
    forgetPdf();
});
</script>

<template>
    <FullScreenSheet v-model:open="open" :title="t('invoices.document.previewTitle')" dismiss="close" :size="isDesktop ? 'wide' : 'default'">
        <div :class="isDesktop ? 'grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)_300px]' : 'contents'">
            <div :class="isDesktop ? 'scrollbar-thin min-h-0 overflow-y-auto bg-muted px-8 py-6' : 'contents'">
                <div
                    class="relative mx-auto w-full select-none"
                    :class="[isDesktop ? 'max-w-190' : 'order-3', !ready ? '' : magnifying ? 'cursor-none touch-none' : 'cursor-zoom-in']"
                    @pointerdown="onPointerdown"
                    @pointermove="onPointermove"
                    @pointerup="onPointerup"
                    @pointercancel="press = null"
                    @pointerleave="onPointerleave"
                >
                    <div
                        ref="frame"
                        :aria-busy="loading || drawing ? 'true' : 'false'"
                        class="relative aspect-[210/297] w-full overflow-hidden rounded-[4px] bg-white shadow-[0_2px_12px_rgb(23_25_31/0.12)]"
                    >
                        <div v-if="loading" class="absolute inset-0 animate-pulse bg-muted" role="status" :aria-label="t('core.common.loading')" />
                        <div v-else-if="failed" class="absolute inset-0 flex flex-col items-center justify-center gap-3 p-6 text-center">
                            <span class="text-15 text-ink-2">{{ t('invoices.document.previewError') }}</span>
                            <button type="button" class="min-h-11 px-3 text-15 font-medium text-accent" @click="load">{{ t('core.common.retry') }}</button>
                        </div>
                        <canvas v-show="!loading && !failed" ref="canvas" class="block h-full w-full" />
                    </div>
                    <canvas
                        v-show="magnifying && lens"
                        ref="lensCanvas"
                        aria-hidden="true"
                        class="pointer-events-none absolute rounded-full border-2 border-white bg-white shadow-[0_0_0_1px_var(--color-line-input),0_12px_32px_rgb(23_25_31/0.3)]"
                        :style="lensStyle"
                    />
                </div>
            </div>

            <div :class="isDesktop ? 'scrollbar-thin flex min-h-0 flex-col gap-5 overflow-y-auto border-l border-line py-5 pr-4 pl-6' : 'contents'">
                <div class="flex items-center justify-between gap-3" :class="isDesktop ? '' : 'order-1'">
                    <button
                        type="button"
                        :aria-label="t('invoices.document.previousStyle')"
                        class="flex size-11 shrink-0 items-center justify-center rounded-full border border-line-input bg-surface text-ink"
                        @click="step(-1)"
                    >
                        <Icon name="chevronLeft" :size="20" />
                    </button>
                    <div class="flex min-w-0 flex-col items-center">
                        <span class="truncate text-20 font-semibold">{{ styleName(shown) }}</span>
                        <span class="num text-13 text-ink-3">{{ styleIndex(shown) + 1 }} / {{ INVOICE_STYLES.length }}</span>
                    </div>
                    <button
                        type="button"
                        :aria-label="t('invoices.document.nextStyle')"
                        class="flex size-11 shrink-0 items-center justify-center rounded-full border border-line-input bg-surface text-ink"
                        @click="step(1)"
                    >
                        <Icon name="chevronRight" :size="20" />
                    </button>
                </div>

                <SegmentedControl v-model="language" :class="isDesktop ? '' : 'order-2'" :options="languageOptions" :label="t('invoices.document.language')" />

                <div class="flex flex-col items-start gap-1.5" :class="isDesktop ? '' : 'order-4'">
                    <button
                        type="button"
                        :aria-pressed="magnifying ? 'true' : 'false'"
                        :disabled="!ready"
                        class="flex min-h-11 items-center gap-2 rounded-full border px-4 text-15 font-medium disabled:opacity-60"
                        :class="magnifying ? 'border-accent bg-accent-soft text-accent' : 'border-line-input bg-surface text-ink'"
                        @click="toggleMagnifier"
                    >
                        <Icon name="search" :size="18" />
                        {{ t('invoices.document.magnify') }}
                    </button>
                    <span class="text-13 leading-[1.45] text-ink-3">{{ isDesktop ? t('invoices.document.magnifyHint') : t('invoices.document.magnifyHintTouch') }}</span>
                </div>

                <p class="m-0 text-15 leading-normal text-ink-2" :class="isDesktop ? '' : 'order-5'">{{ t('invoices.document.previewCaption') }}</p>
                <button
                    type="button"
                    class="flex min-h-11 items-center gap-1.5 self-start text-16 font-medium text-accent disabled:opacity-60"
                    :class="isDesktop ? '' : 'order-6'"
                    :disabled="!pdfUrl"
                    @click="openPdf"
                >
                    {{ t('invoices.document.openPdf') }}
                    <Icon name="external" :size="18" />
                </button>
            </div>
        </div>

        <template #footer>
            <div :class="isDesktop ? 'ml-auto w-75' : ''">
                <PrimaryButton @click="choose">{{ t('invoices.document.choose', { name: styleName(shown) }) }}</PrimaryButton>
            </div>
        </template>
    </FullScreenSheet>
</template>
