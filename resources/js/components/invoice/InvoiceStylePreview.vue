<script setup lang="ts">
import type { RenderTask } from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { api } from '@/api/client';
import type { InvoiceLocale, InvoiceStyle } from '@/api/types';
import FullScreenSheet from '@/components/ui/FullScreenSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { LOCALE_NAMES, t } from '@/i18n';
import { adjacentStyle, INVOICE_STYLES, styleIndex, styleName } from '@/utils/invoiceStyles';

/**
 * Перегляд стилю: the first page of an invoice's PDF in a style, as the API renders it (the draft's, the client's
 * latest invoice or the ФОП's, nothing saved), drawn with pdf.js; arrows step through the catalogue, and the language
 * switch changes only the preview. "Обрати" chooses the shown style.
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

const shown = ref<InvoiceStyle>(style);
const language = ref<InvoiceLocale>(locale);
const canvas = ref<HTMLCanvasElement | null>(null);
const frame = ref<HTMLElement | null>(null);
const loading = ref(false);
const drawing = ref(false);
const failed = ref(false);
const pdfUrl = ref<string | null>(null);

// Synchronous, so opening sets the style and language before the load below runs, once
watch(open, (isOpen) => {
    if (isOpen) {
        shown.value = style;
        language.value = locale;
    } else {
        controller?.abort();
        rendering?.cancel();
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

function forgetPdf(): void {
    if (pdfUrl.value) {
        URL.revokeObjectURL(pdfUrl.value);
        pdfUrl.value = null;
    }
}

/**
 * Fetch the preview's PDF and draw its first page at the width of the frame; a newer load cancels this one, and its
 * drawing too, as pdf.js draws on a canvas once at a time.
 */
async function load(): Promise<void> {
    controller?.abort();
    rendering?.cancel();
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
        await task.destroy();
    } catch {
        if (!current.signal.aborted) {
            failed.value = true;
            loading.value = false;
            drawing.value = false;
        }
    }
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
    forgetPdf();
});
</script>

<template>
    <FullScreenSheet v-model:open="open" :title="t('invoices.document.previewTitle')" dismiss="close">
        <div class="flex items-center justify-between gap-3">
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

        <SegmentedControl v-model="language" :options="languageOptions" :label="t('invoices.document.language')" />

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

        <p class="m-0 text-15 leading-normal text-ink-2">{{ t('invoices.document.previewCaption') }}</p>
        <button
            type="button"
            class="flex min-h-11 items-center gap-1.5 self-start text-16 font-medium text-accent disabled:opacity-60"
            :disabled="!pdfUrl"
            @click="openPdf"
        >
            {{ t('invoices.document.openPdf') }}
            <Icon name="external" :size="18" />
        </button>

        <template #footer>
            <PrimaryButton @click="choose">{{ t('invoices.document.choose', { name: styleName(shown) }) }}</PrimaryButton>
        </template>
    </FullScreenSheet>
</template>
