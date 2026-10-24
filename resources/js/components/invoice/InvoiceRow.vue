<script setup lang="ts">
import { computed } from 'vue';
import type { Invoice } from '@/api/types';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { t } from '@/i18n';
import { formatDayMonth, formatPeriod, today } from '@/utils/dates';
import { compareAmounts, formatAmount, formatMoney, percentOf } from '@/utils/money';
import { INVOICE_STATUSES } from '@/utils/statuses';

/**
 * An invoice in Інвойси: number (a generated draft's preview with "наст."; an external one has only its own),
 * status, client and total, and one context line: the period, "Прострочено з …" while money is owed past the due
 * date, or the paid share (handoff §6.6). No "не оплачено" text.
 */
const { invoice } = defineProps<{ invoice: Invoice }>();

const isDraft = computed(() => invoice.status === 'draft');
const isCancelled = computed(() => invoice.status === 'cancelled');
const isOpen = computed(() => invoice.status === 'ready' || invoice.status === 'sent');
const paid = computed(() => invoice.paid_amount ?? '0.00');
const percent = computed(() => percentOf(paid.value, invoice.total));
const overdue = computed(() => isOpen.value && invoice.due_at !== null && invoice.due_at < today()
    && compareAmounts(paid.value, invoice.total) < 0);
const draftNumber = computed(() => {
    if (invoice.source === 'external') {
        return invoice.number ?? t('invoices.list.noNumber');
    }

    // A number typed by hand stays; a generated one is the preview, which follows the draft's dates
    return invoice.number !== null && invoice.sequence_number === null
        ? invoice.number
        : t('invoices.list.nextNumber', { number: invoice.number_preview ?? invoice.number ?? '…' });
});
const partlyPaid = computed(() => isOpen.value && compareAmounts(paid.value, '0') > 0);

const to = computed(() => (isDraft.value
    ? { name: 'invoice-edit', params: { id: invoice.id } }
    : { name: 'invoice', params: { id: invoice.id } }));
</script>

<template>
    <RouterLink :to="to" class="flex flex-col gap-1.5 py-3.5 text-ink no-underline hover:text-ink" :class="isCancelled ? 'text-ink-3 hover:text-ink-3' : ''">
        <span class="flex items-center justify-between gap-2">
            <span class="flex min-w-0 items-center gap-2">
                <span v-if="isDraft" class="truncate text-15 font-medium text-ink-3" :class="invoice.source === 'external' && !invoice.number ? '' : 'num'">
                    {{ draftNumber }}
                </span>
                <span v-else class="num truncate text-15" :class="isCancelled ? 'font-medium line-through' : 'font-semibold'">
                    {{ invoice.number }}
                </span>
                <span
                    v-if="invoice.source === 'external'"
                    class="shrink-0 rounded-md border border-line-input px-1.5 py-0.5 text-11 font-semibold text-ink-2"
                >
                    {{ t('invoices.list.external') }}
                </span>
            </span>
            <StatusBadge v-bind="INVOICE_STATUSES[invoice.status]" />
        </span>
        <span class="flex justify-between gap-2 text-14">
            <span class="min-w-0 truncate">{{ invoice.client?.name }}</span>
            <span class="num" :class="isCancelled ? '' : 'font-semibold'">{{ formatMoney(invoice.total, invoice.currency) }}</span>
        </span>
        <span v-if="overdue" class="text-12 text-negative">
            {{ t('invoices.list.overdueSince', { date: formatDayMonth(invoice.due_at) }) }}<template v-if="invoice.receivingAccount"> · {{ invoice.receivingAccount.name }}</template>
        </span>
        <span v-else-if="partlyPaid" class="mt-0.5 flex items-center gap-2.5">
            <ProgressBar :percent="percent" />
            <span class="num text-12 text-ink-2">{{ formatAmount(paid) }} · {{ percent }} %</span>
        </span>
        <span v-else-if="isDraft || isOpen" class="text-12 text-ink-3">
            {{ t('invoices.home.periodOf', { period: formatPeriod(invoice.period_start, invoice.period_end) }) }}<template v-if="isOpen && invoice.due_at"> · {{ t('invoices.home.dueBy', { date: formatDayMonth(invoice.due_at) }) }}</template>
        </span>
    </RouterLink>
</template>
