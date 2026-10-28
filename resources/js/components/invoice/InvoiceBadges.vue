<script setup lang="ts">
import { computed } from 'vue';
import type { Invoice } from '@/api/types';
import Icon from '@/components/ui/Icon.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { t } from '@/i18n';
import { formatDate, formatDayMonth, kyivDate } from '@/utils/dates';
import { INVOICE_STATUSES } from '@/utils/statuses';

/**
 * The badges of an issued invoice (S-InvoiceDetail): its status with the day it changed («Надіслано 15.10»), when it
 * was frozen, and «зовнішній» for an uploaded one. Over the number on a page, under it in the record panel's header.
 */
const { invoice } = defineProps<{ invoice: Invoice }>();

const statusDate = computed(() => {
    const date = { sent: invoice.sent_at, paid: invoice.paid_at, cancelled: invoice.cancelled_at }[invoice.status as 'sent' | 'paid' | 'cancelled'];

    return date ? ` ${formatDayMonth(date)}` : '';
});
</script>

<template>
    <StatusBadge :label="INVOICE_STATUSES[invoice.status].label + statusDate" :tone="INVOICE_STATUSES[invoice.status].tone" />
    <span v-if="invoice.locked_at" class="flex items-center gap-1 text-12 text-ink-3">
        <Icon name="lock" :size="14" />
        {{ t('invoices.detail.frozenOn', { date: formatDate(kyivDate(invoice.locked_at)) }) }}
    </span>
    <span v-if="invoice.source === 'external'" class="rounded-md border border-line-input px-1.5 py-0.5 text-11 font-semibold text-ink-2">
        {{ t('invoices.list.external') }}
    </span>
</template>
