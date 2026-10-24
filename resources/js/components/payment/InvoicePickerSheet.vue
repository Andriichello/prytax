<script setup lang="ts">
import type { Invoice } from '@/api/types';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import Translated from '@/components/ui/Translated.vue';
import { t } from '@/i18n';
import { formatAmount, formatMoney, subtractAmounts } from '@/utils/money';

/**
 * The open invoices to record a receipt for: the number in cobalt, the client on a light second line, and what is
 * still to be received.
 */
const { invoices, selectedId, title, emptyText } = defineProps<{
    invoices: Invoice[];
    selectedId: number | null;
    /** "Оберіть інвойс" when not given. */
    title?: string;
    /** What the sheet says without invoices; it explains which invoices take receipts when not given. */
    emptyText?: string;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ select: [invoice: Invoice] }>();

function choose(invoice: Invoice): void {
    emit('select', invoice);
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
    </BottomSheet>
</template>
