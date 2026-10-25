<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router';
import type { Payment } from '@/api/types';
import VirtualMarker from '@/components/invoice/VirtualMarker.vue';
import { formatDayMonthWords } from '@/utils/dates';
import { formatMoney, formatRate } from '@/utils/money';

/**
 * The head of a receipt card (design.md §16.4): its invoice's number in mono, or the «Без інвойсу» marker, the amount
 * in the receipt's currency on a green pill, the client, the NBU rate, the credit date and the hryvnias, large. With
 * `to`, the card is a link (to the receipt).
 */
const { payment, to = undefined } = defineProps<{
    payment: Pick<Payment, 'amount' | 'currency' | 'nbu_rate' | 'received_at' | 'uah_amount' | 'invoice'>;
    to?: RouteLocationRaw;
}>();
</script>

<template>
    <component
        :is="to ? 'RouterLink' : 'div'"
        :to="to"
        class="grid grid-cols-[1fr_auto] items-baseline gap-x-3 gap-y-1 text-ink no-underline hover:text-ink"
    >
        <VirtualMarker v-if="payment.invoice?.source === 'virtual'" class="self-center justify-self-start" />
        <span v-else class="num truncate text-16 font-semibold">{{ payment.invoice?.number ?? '—' }}</span>
        <span class="num justify-self-end rounded-badge bg-positive-soft px-2 py-0.5 text-15 font-semibold text-positive">
            {{ formatMoney(payment.amount, payment.currency) }}
        </span>
        <span class="min-w-0 truncate text-14 text-ink-2">{{ payment.invoice?.client?.name }}</span>
        <span v-if="payment.currency !== 'UAH'" class="num justify-self-end text-12 text-ink-3">{{ formatRate(payment.nbu_rate, payment.currency) }}</span>
        <span v-else />
        <span class="text-14 text-ink-2">{{ formatDayMonthWords(payment.received_at) }}</span>
        <span class="num justify-self-end text-18 font-semibold">{{ formatMoney(payment.uah_amount, 'UAH') }}</span>
    </component>
</template>
