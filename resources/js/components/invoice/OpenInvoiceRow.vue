<script setup lang="ts">
import { computed } from 'vue';
import type { Invoice } from '@/api/types';
import VirtualMarker from '@/components/invoice/VirtualMarker.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { t } from '@/i18n';
import { formatDayMonth, formatPeriod, today } from '@/utils/dates';
import { compareAmounts, formatAmount, formatMoney, percentOf } from '@/utils/money';
import { datedInvoiceStatus } from '@/utils/statuses';

/**
 * An invoice on Головна (M-Home, design.md §16.5), of any status: the number («наст. …» on a draft, the «Без інвойсу»
 * marker on a payment without an invoice) and the status with its date; the client and the total; the period with
 * «сплатити до …», or «прострочено, до …» in red while it is unpaid past due. Only a sent invoice shows its paid bar.
 */
const { invoice } = defineProps<{ invoice: Invoice }>();

const isDraft = computed(() => invoice.status === 'draft');
const isCancelled = computed(() => invoice.status === 'cancelled');
const isVirtual = computed(() => invoice.source === 'virtual');
const paid = computed(() => invoice.paid_amount ?? '0.00');
const percent = computed(() => percentOf(paid.value, invoice.total));
const isOpen = computed(() => invoice.status === 'ready' || invoice.status === 'sent');
const overdue = computed(() => isOpen.value && invoice.due_at !== null && invoice.due_at < today() && compareAmounts(paid.value, invoice.total) < 0);
const number = computed(() => (isDraft.value
    ? t('invoices.list.nextNumber', { number: invoice.number_preview ?? invoice.number ?? '…' })
    : invoice.number));

const to = computed(() => (isDraft.value
    ? { name: 'invoice-edit', params: { id: invoice.id } }
    : { name: 'invoice', params: { id: invoice.id } }));
</script>

<template>
    <RouterLink :to="to" class="flex flex-col gap-1.5 py-3.5 text-ink no-underline hover:text-ink" :class="isCancelled ? 'text-ink-3 hover:text-ink-3' : ''">
        <span class="flex items-center justify-between gap-2">
            <span class="flex min-w-0 items-center gap-2">
                <VirtualMarker v-if="isVirtual" />
                <span
                    v-else
                    class="num truncate text-15"
                    :class="isDraft ? 'font-medium text-ink-3' : isCancelled ? 'font-medium line-through' : 'font-semibold'"
                >
                    {{ number }}
                </span>
                <span
                    v-if="invoice.source === 'external'"
                    class="shrink-0 rounded-md border border-line-input px-1.5 py-0.5 text-11 font-semibold text-ink-2"
                >
                    {{ t('invoices.list.external') }}
                </span>
            </span>
            <StatusBadge v-bind="datedInvoiceStatus(invoice)" />
        </span>
        <span class="flex justify-between gap-2 text-14">
            <span class="min-w-0 truncate">{{ invoice.client?.name }}</span>
            <span class="num" :class="isCancelled ? '' : 'font-semibold'">{{ formatMoney(invoice.total, invoice.currency) }}</span>
        </span>
        <span class="flex justify-between gap-2 text-12 text-ink-3">
            <span>{{ t('invoices.home.periodOf', { period: formatPeriod(invoice.period_start, invoice.period_end) }) }}</span>
            <span v-if="overdue" class="text-negative">{{ t('invoices.home.overdueBy', { date: formatDayMonth(invoice.due_at) }) }}</span>
            <span v-else-if="invoice.due_at && !isVirtual">{{ t('invoices.home.dueBy', { date: formatDayMonth(invoice.due_at) }) }}</span>
        </span>
        <span v-if="invoice.status === 'sent'" class="flex items-center gap-2.5">
            <ProgressBar :percent="percent" />
            <span class="num text-12 text-ink-2">{{ formatAmount(paid) }} · {{ percent }} %</span>
        </span>
    </RouterLink>
</template>
