<script setup lang="ts">
import { computed } from 'vue';
import type { AccountTransfer } from '@/api/types';
import { t } from '@/i18n';
import { accountLabel } from '@/utils/accounts';
import { formatDate } from '@/utils/dates';
import { formatAmount, formatMoney } from '@/utils/money';

/**
 * A transfer between the profile's own accounts: the date, how many receipts it moved (or "продаж валюти"), the
 * route, what was sent and what arrived, and its cost — the fee, or the spread against the NBU for a sale.
 */
const { transfer } = defineProps<{ transfer: AccountTransfer }>();

const isSale = computed(() => transfer.currency_sent !== transfer.currency_received);
const allocations = computed(() => transfer.allocations?.length ?? 0);
</script>

<template>
    <RouterLink
        :to="{ name: 'transfer', params: { id: transfer.id } }"
        class="flex flex-col gap-1 py-3.5 text-ink no-underline hover:text-ink"
    >
        <span class="flex items-baseline justify-between gap-2 text-13 text-ink-3">
            <span class="num">{{ formatDate(transfer.received_at) }}</span>
            <span v-if="isSale" class="font-semibold text-accent-hover">{{ t('money.transfers.currencySale') }}</span>
            <span v-else-if="allocations">{{ t('core.counts.payments', { count: allocations }) }}</span>
        </span>
        <span class="text-16 font-medium">
            {{ accountLabel(transfer.fromAccount?.name, transfer.currency_sent) }} → {{ isSale ? transfer.currency_received : accountLabel(transfer.toAccount?.name, transfer.currency_received) }}
        </span>
        <span class="flex items-baseline justify-between gap-2">
            <span class="num text-15 font-semibold">
                <template v-if="isSale">{{ formatMoney(transfer.amount_sent, transfer.currency_sent) }} → {{ formatMoney(transfer.amount_received, transfer.currency_received) }}</template>
                <template v-else>{{ formatAmount(transfer.amount_sent) }} → {{ formatMoney(transfer.amount_received, transfer.currency_received) }}</template>
            </span>
            <span v-if="!isSale && transfer.fee_amount" class="num text-13 text-ink-3">{{ t('money.flow.feeAmount', { fee: formatAmount(transfer.fee_amount) }) }}</span>
        </span>
        <span v-if="isSale && transfer.effective_rate" class="flex items-baseline justify-between gap-2 text-13 text-ink-3">
            <span class="num">{{ t('money.transfers.vsNbu', { rate: formatAmount(transfer.effective_rate), nbu: formatAmount(transfer.nbu_rate) }) }}</span>
            <span v-if="transfer.fx_spread" class="num">{{ t('money.transfers.spreadAmount', { amount: formatMoney(transfer.fx_spread, 'UAH') }) }}</span>
        </span>
    </RouterLink>
</template>
