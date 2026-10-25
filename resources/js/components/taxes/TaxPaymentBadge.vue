<script setup lang="ts">
import { computed } from 'vue';
import type { TaxPaymentStatus } from '@/api/types';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { t } from '@/i18n';
import { formatDayMonth } from '@/utils/dates';
import { TAX_PAYMENT_STATUSES } from '@/utils/statuses';

/**
 * A tax payment's status (design.md §16.2): «Надіслано» (amber), «Сплачено» (green), «Не пройшов» (red), or, without a
 * payment, «Не сплачено», muted with a dashed border. With `dated`, the short form of the lists: «сплачено 12.10».
 */
const { status, paidAt = null, dated = false } = defineProps<{
    status: TaxPaymentStatus | null;
    paidAt?: string | null;
    dated?: boolean;
}>();

const label = computed(() => {
    if (!dated) {
        return status === null ? t('core.taxPaymentStatus.unpaid') : TAX_PAYMENT_STATUSES[status].label;
    }

    const date = formatDayMonth(paidAt);

    switch (status) {
        case 'sent':
            return t('core.taxPaymentStatus.sentOn', { date });
        case 'paid':
            return t('core.taxPaymentStatus.paidOn', { date });
        case 'failed':
            return t('core.taxPaymentStatus.failedShort');
        default:
            return t('core.taxPaymentStatus.unpaidShort');
    }
});
</script>

<template>
    <span
        v-if="status === null"
        class="shrink-0 rounded-badge border border-dashed border-line-input px-2 py-[2px] text-12 font-semibold whitespace-nowrap text-ink-3"
    >
        {{ label }}
    </span>
    <StatusBadge v-else :label="label" :tone="TAX_PAYMENT_STATUSES[status].tone" />
</template>
