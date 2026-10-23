<script setup lang="ts">
import { computed } from 'vue';
import type { Invoice } from '@/api/types';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { t } from '@/i18n';
import { formatDayMonth, formatPeriod, today } from '@/utils/dates';
import { formatAmount, formatMoney, percentOf } from '@/utils/money';
import { INVOICE_STATUSES } from '@/utils/statuses';

/**
 * An open invoice on Головна: number and status, client and total, the period and when it's due (or since when it's
 * overdue), and how much of it is paid.
 */
const { invoice } = defineProps<{ invoice: Invoice }>();

const paid = computed(() => invoice.paid_amount ?? '0.00');
const percent = computed(() => percentOf(paid.value, invoice.total));
const overdue = computed(() => invoice.due_at !== null && invoice.due_at < today());
</script>

<template>
    <RouterLink
        :to="{ name: 'invoice', params: { id: invoice.id } }"
        class="flex flex-col gap-2 py-3.5 text-ink no-underline hover:text-ink"
    >
        <span class="flex items-center justify-between gap-2">
            <span class="num text-15 font-semibold">{{ invoice.number }}</span>
            <StatusBadge v-bind="INVOICE_STATUSES[invoice.status]" />
        </span>
        <span class="flex justify-between gap-2 text-14">
            <span class="min-w-0 truncate">{{ invoice.client?.name }}</span>
            <span class="num font-semibold">{{ formatMoney(invoice.total, invoice.currency) }}</span>
        </span>
        <span class="flex justify-between gap-2 text-12 text-ink-3">
            <span>{{ t('Період {period}', { period: formatPeriod(invoice.period_start, invoice.period_end) }) }}</span>
            <span v-if="overdue" class="text-negative">{{ t('прострочено з {date}', { date: formatDayMonth(invoice.due_at) }) }}</span>
            <span v-else-if="invoice.due_at">{{ t('сплатити до {date}', { date: formatDayMonth(invoice.due_at) }) }}</span>
        </span>
        <span class="flex items-center gap-2.5">
            <ProgressBar :percent="percent" />
            <span class="num text-12 text-ink-2">{{ formatAmount(paid) }} · {{ percent }} %</span>
        </span>
    </RouterLink>
</template>
