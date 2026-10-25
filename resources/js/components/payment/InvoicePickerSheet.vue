<script setup lang="ts">
import type { Invoice } from '@/api/types';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import VirtualMarker from '@/components/invoice/VirtualMarker.vue';
import Icon from '@/components/ui/Icon.vue';
import Translated from '@/components/ui/Translated.vue';
import { t } from '@/i18n';
import { formatAmount, formatMoney, subtractAmounts } from '@/utils/money';

/**
 * The open invoices to record a receipt for: the number in cobalt, the client on a light second line, and what is
 * still to be received; with `withoutInvoice`, also «Без інвойсу», a payment under the contract (design.md §4.2).
 */
const { invoices, selectedId, title, emptyText, withoutInvoice = false } = defineProps<{
    invoices: Invoice[];
    selectedId: number | null;
    /** "Оберіть інвойс" when not given. */
    title?: string;
    /** What the sheet says without invoices; it explains which invoices take receipts when not given. */
    emptyText?: string;
    /** Offer «Без інвойсу» after the invoices; `without` is emitted when it is chosen. */
    withoutInvoice?: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ select: [invoice: Invoice]; without: [] }>();

function choose(invoice: Invoice): void {
    emit('select', invoice);
    open.value = false;
}

function chooseWithout(): void {
    emit('without');
    open.value = false;
}
</script>

<template>
    <BottomSheet v-model:open="open" :title="title ?? t('money.paymentForm.chooseInvoice')">
        <div v-if="invoices.length" class="flex flex-col divide-y divide-divider overflow-hidden rounded-card border border-line bg-surface">
            <button
                v-for="invoice in invoices"
                :key="invoice.id"
                type="button"
                class="flex items-center gap-3 px-3.5 py-3 text-left"
                :class="invoice.id === selectedId ? 'bg-accent-soft' : ''"
                @click="choose(invoice)"
            >
                <span class="flex min-w-0 flex-1 flex-col gap-[3px]">
                    <span class="num text-16 font-semibold text-accent">{{ invoice.number }}</span>
                    <span class="truncate text-15 font-light">{{ invoice.client?.name }}</span>
                    <span class="text-12 text-ink-3">
                        <Translated :text="t('money.invoicePicker.remainingOfTotal')">
                            <template #remaining><span class="num">{{ formatMoney(subtractAmounts(invoice.total, invoice.paid_amount ?? '0'), invoice.currency) }}</span></template>
                            <template #total><span class="num">{{ formatAmount(invoice.total) }}</span></template>
                        </Translated>
                    </span>
                </span>
                <Icon v-if="invoice.id === selectedId" name="check" :size="22" class="text-accent" />
            </button>
        </div>
        <p v-else class="m-0 text-15 text-ink-2">
            {{ emptyText ?? t('money.invoicePicker.noOpenInvoices') }}
        </p>
        <button
            v-if="withoutInvoice"
            type="button"
            class="mt-3 flex w-full items-start gap-3 rounded-card border border-dashed border-ink-3 bg-surface px-3.5 py-3 text-left"
            @click="chooseWithout"
        >
            <span class="flex min-w-0 flex-1 flex-col gap-1.5">
                <VirtualMarker />
                <span class="text-15 font-semibold text-ink">{{ t('money.invoicePicker.withoutTitle') }}</span>
            </span>
            <Icon name="chevronRight" :size="20" class="mt-0.5 text-ink-3" />
        </button>
    </BottomSheet>
</template>
