<script setup lang="ts">
import type { UntransferredReceipt } from '@/api/types';
import VirtualMarker from '@/components/invoice/VirtualMarker.vue';
import { t } from '@/i18n';
import { formatDayMonth } from '@/utils/dates';
import { formatMoney } from '@/utils/money';

/**
 * The receipts, whose money is still on a platform or a foreign bank, as Головна lists them: the invoice's number (the
 * «Без інвойсу» marker for a payment without one) and what the receipt still holds there; then the day it came, the
 * account and the client; and by when it is to be moved, «прострочено, з …» in red once that's past. A row opens the
 * receipt.
 */
const { receipts } = defineProps<{ receipts: UntransferredReceipt[] }>();

/** The day after the deadline, from which the money is overdue, as the repatriation notification says it. */
function overdueSince(deadline: string): string {
    const date = new Date(`${deadline}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + 1);

    return formatDayMonth(date.toISOString().slice(0, 10));
}
</script>

<template>
    <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
        <RouterLink
            v-for="receipt in receipts"
            :key="`${receipt.payment_id}-${receipt.account.id}`"
            :to="{ name: 'payment', params: { id: receipt.payment_id } }"
            class="flex flex-col gap-1 py-3.5 text-ink no-underline hover:text-ink"
        >
            <span class="flex items-center justify-between gap-3">
                <VirtualMarker v-if="!receipt.invoice || receipt.invoice.source === 'virtual'" />
                <span v-else class="num truncate text-15 font-semibold">{{ receipt.invoice.number }}</span>
                <span class="num shrink-0 text-15 font-semibold">{{ formatMoney(receipt.amount, receipt.currency) }}</span>
            </span>
            <span class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 text-13">
                <span class="min-w-0 truncate text-ink-3">
                    {{ [formatDayMonth(receipt.received_at), receipt.account.name, receipt.invoice?.client].filter(Boolean).join(' · ') }}
                </span>
                <span v-if="receipt.deadline && receipt.overdue" class="shrink-0 font-medium text-negative">
                    {{ t('invoices.home.transferOverdueSince', { date: overdueSince(receipt.deadline) }) }}
                </span>
                <span v-else-if="receipt.deadline" class="shrink-0 text-ink-2">
                    {{ t('invoices.home.transferBy', { date: formatDayMonth(receipt.deadline) }) }}
                </span>
            </span>
        </RouterLink>
    </div>
</template>
