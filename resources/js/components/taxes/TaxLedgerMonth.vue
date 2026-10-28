<script setup lang="ts">
import { computed } from 'vue';
import type { TaxLedger } from '@/api/types';
import TaxFigures from '@/components/taxes/TaxFigures.vue';
import TaxLedgerRow from '@/components/taxes/TaxLedgerRow.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { formatMonthYear } from '@/utils/dates';

/**
 * A month of Податки's ledger (design.md §16.5): a dark header with the month and the three columns of its rows (the
 * failed ones left out), then the rows, unpaid first, then failed, sent and paid. On desktop the columns sit on the
 * header's right.
 */
const { month, canPay } = defineProps<{
    month: TaxLedger['months'][number];
    canPay: boolean;
}>();

const { isDesktop } = useBreakpoint();

/** «Жовтень 2026» */
const title = computed(() => {
    const [year, number] = month.month.split('-').map(Number);

    return formatMonthYear(year, number);
});
</script>

<template>
    <section class="flex flex-col overflow-hidden rounded-card border border-line bg-surface" :aria-label="title">
        <header class="-mb-px flex bg-ink py-3.5 text-white" :class="isDesktop ? 'items-center justify-between gap-6 px-5' : 'flex-col gap-2.5 px-4'">
            <h3 class="m-0 text-17 font-semibold">{{ title }}</h3>
            <TaxFigures :figures="month" dark :class="isDesktop ? 'w-110' : ''" />
        </header>
        <TaxLedgerRow v-for="item in month.items" :key="`${item.kind}:${item.tax_payment_id ?? ''}:${item.receipt?.id ?? ''}:${item.tax_type}:${item.period_month ?? ''}`" :item="item" :can-pay="canPay" />
    </section>
</template>
