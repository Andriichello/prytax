<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { api } from '@/api/client';
import type { InvoiceLocale, InvoiceStyle } from '@/api/types';
import FullScreenSheet from '@/components/ui/FullScreenSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import PdfPages from '@/components/ui/PdfPages.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { LOCALE_NAMES, t } from '@/i18n';
import { adjacentStyle, INVOICE_STYLES, styleIndex, styleName } from '@/utils/invoiceStyles';

/**
 * Перегляд стилю: the first page of an invoice's PDF in a style, as the API renders it (the draft's, the client's
 * latest invoice or the ФОП's, nothing saved), with the zoom over it (`PdfPages`), which stays as it is from style to
 * style; arrows step through the catalogue, and the language switch changes only the preview. "Обрати" chooses the
 * shown style. On desktop it is a wide dialog: the page, about 760 px wide at 100 %, scrolls on the left, and the
 * controls stay on the right; on a phone the page keeps its A4 frame, inside which a zoomed page scrolls.
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
const blob = ref<Blob | null>(null);
const loading = ref(false);
const failed = ref(false);
const pdfUrl = ref<string | null>(null);

// Synchronous, so opening sets the style and language before the load below runs, once
watch(open, (isOpen) => {
    if (isOpen) {
        shown.value = style;
        language.value = locale;
    } else {
        controller?.abort();
        blob.value = null;
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

function forgetPdf(): void {
    if (pdfUrl.value) {
        URL.revokeObjectURL(pdfUrl.value);
        pdfUrl.value = null;
    }
}

/** Fetch the preview's PDF, which `PdfPages` draws; a newer load (another style or language) cancels this one. */
async function load(): Promise<void> {
    controller?.abort();
    const current = new AbortController();
    controller = current;
    loading.value = true;
    failed.value = false;

    try {
        const fetched = await api.blob(path('invoices/style-preview'), {
            style: shown.value,
            locale: language.value,
            client_id: target.client_id ?? undefined,
            invoice_id: target.invoice_id ?? undefined,
        }, current.signal);

        forgetPdf();
        blob.value = fetched;
        pdfUrl.value = URL.createObjectURL(fetched);
    } catch {
        if (!current.signal.aborted) {
            failed.value = true;
        }
    } finally {
        if (!current.signal.aborted) {
            loading.value = false;
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
    forgetPdf();
});
</script>

<template>
    <FullScreenSheet v-model:open="open" :title="t('invoices.document.previewTitle')" dismiss="close" :size="isDesktop ? 'wide' : 'default'">
        <div :class="isDesktop ? 'grid min-h-0 flex-1 grid-cols-[minmax(0,1fr)_300px]' : 'contents'">
            <PdfPages
                :class="isDesktop ? 'bg-muted' : 'order-3 aspect-[210/297] w-full shrink-0'"
                :inset="isDesktop ? 'px-8 py-6' : ''"
                :max-fit-width="760"
                :max-pages="1"
                :spacer="isDesktop"
                :blob="blob"
                :loading="loading"
                :failed="failed"
                @retry="load"
                @failed="failed = true"
            >
                <template #failed>{{ t('invoices.document.previewError') }}</template>
            </PdfPages>

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
