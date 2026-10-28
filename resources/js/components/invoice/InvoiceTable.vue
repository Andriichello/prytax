<script setup lang="ts">
import type { Invoice } from '@/api/types';
import VirtualMarker from '@/components/invoice/VirtualMarker.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { t } from '@/i18n';
import { formatDayMonth, formatPeriod } from '@/utils/dates';
import { invoiceRowLink, invoiceRowNumber, isOverdue } from '@/utils/invoiceRows';
import { formatAmount, formatMoney, percentOf } from '@/utils/money';
import { datedInvoiceStatus } from '@/utils/statuses';

/**
 * The invoices of a quarter as the desktop tables list them (D-Home, D-Invoices; design.md §17.4). `list` (Інвойси):
 * Номер · Клієнт · Період · Сума · Оплата («до 14.11», «прострочено, до 19.10» in red, or «—») · Статус. `home`
 * (Головна): Номер · the client with the period and the due date under it · Сума (with «3 100.00 · 80 %» under a sent
 * one's) · Статус. A row opens the invoice (its panel), a draft its editor.
 */
const { invoices, variant = 'list' } = defineProps<{
    invoices: Invoice[];
    variant?: 'list' | 'home';
}>();

const columns = variant === 'list'
    ? 'grid-cols-[190px_minmax(0,1fr)_170px_150px_170px_150px]'
    : 'grid-cols-[180px_minmax(0,1fr)_150px_140px]';
</script>

<template>
    <section class="overflow-hidden rounded-card border border-line bg-surface">
        <div class="grid gap-4 border-b border-line px-5 py-2.5 text-12 text-ink-3" :class="columns">
            <span>{{ t('invoices.table.number') }}</span>
            <span>{{ variant === 'list' ? t('core.common.client') : t('invoices.table.clientPeriod') }}</span>
            <span v-if="variant === 'list'">{{ t('core.common.period') }}</span>
            <span class="text-right">{{ t('invoices.table.amount') }}</span>
            <span v-if="variant === 'list'">{{ t('invoices.table.payment') }}</span>
            <span class="text-right">{{ t('core.common.status') }}</span>
        </div>
        <RouterLink
            v-for="(invoice, index) in invoices"
            :key="invoice.id"
            :to="invoiceRowLink(invoice)"
            class="grid items-center gap-4 px-5 py-3.5 text-ink no-underline hover:bg-paper-light hover:text-ink"
            :class="[columns, index > 0 ? 'border-t border-divider' : '', invoice.status === 'cancelled' ? 'text-ink-3 hover:text-ink-3' : '']"
        >
            <span class="flex min-w-0 items-center gap-2 whitespace-nowrap">
                <VirtualMarker v-if="invoice.source === 'virtual'" />
                <span
                    v-else
                    class="truncate text-14"
                    :class="[
                        invoice.status === 'draft' ? 'text-ink-3' : invoice.status === 'cancelled' ? 'line-through' : 'font-semibold',
                        invoice.source === 'external' && invoice.status === 'draft' && !invoice.number ? '' : 'num',
                    ]"
                >{{ invoiceRowNumber(invoice) }}</span>
                <span v-if="invoice.source === 'external'" class="shrink-0 rounded-md border border-line-input px-1.5 py-0.5 text-11 font-semibold text-ink-2">
                    {{ t('invoices.list.external') }}
                </span>
            </span>
            <template v-if="variant === 'list'">
                <span class="truncate text-14">{{ invoice.client?.name }}</span>
                <span class="num text-13 whitespace-nowrap text-ink-2">{{ formatPeriod(invoice.period_start, invoice.period_end) }}</span>
            </template>
            <span v-else class="flex min-w-0 flex-col gap-0.5">
                <span class="truncate text-14">{{ invoice.client?.name }}</span>
                <span class="num text-12 text-ink-3">{{ formatPeriod(invoice.period_start, invoice.period_end) }}</span>
                <span v-if="isOverdue(invoice)" class="text-12 text-negative">{{ t('invoices.home.overdueBy', { date: formatDayMonth(invoice.due_at) }) }}</span>
                <span v-else-if="invoice.due_at && invoice.source !== 'virtual'" class="text-12 text-ink-3">{{ t('invoices.home.dueBy', { date: formatDayMonth(invoice.due_at) }) }}</span>
            </span>
            <span class="flex flex-col items-end">
                <span class="num text-14" :class="invoice.status === 'cancelled' ? '' : 'font-semibold'">{{ formatMoney(invoice.total, invoice.currency) }}</span>
                <span v-if="variant === 'home' && invoice.status === 'sent'" class="num text-11 text-ink-3">
                    {{ formatAmount(invoice.paid_amount ?? '0.00') }} · {{ percentOf(invoice.paid_amount ?? '0.00', invoice.total) }} %
                </span>
            </span>
            <span v-if="variant === 'list'" class="text-13 whitespace-nowrap" :class="isOverdue(invoice) ? 'text-negative' : 'text-ink-2'">
                <template v-if="isOverdue(invoice)">{{ t('invoices.home.overdueBy', { date: formatDayMonth(invoice.due_at) }) }}</template>
                <template v-else-if="invoice.due_at && invoice.source !== 'virtual' && invoice.status !== 'paid' && invoice.status !== 'cancelled'">
                    {{ t('invoices.table.dueShort', { date: formatDayMonth(invoice.due_at) }) }}
                </template>
                <template v-else>—</template>
            </span>
            <span class="flex justify-end"><StatusBadge v-bind="datedInvoiceStatus(invoice)" /></span>
        </RouterLink>
    </section>
</template>
