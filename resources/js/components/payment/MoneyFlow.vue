<script setup lang="ts">
import { computed } from 'vue';
import type { PaymentFlow } from '@/api/types';
import { formatDayMonth } from '@/utils/dates';
import { compareAmounts, formatAmount, formatMoney } from '@/utils/money';

/**
 * Шлях коштів: the receipt, the transfers that moved its money on, its refunds, and where it is now — money still on
 * a platform is marked as not yet moved.
 */
const { flow } = defineProps<{ flow: PaymentFlow }>();

const now = computed(() => flow.holdings.filter((holding) => compareAmounts(holding.amount, '0') > 0));
</script>

<template>
    <ol class="m-0 flex list-none flex-col rounded-card border border-line bg-surface p-4">
        <li v-for="(step, index) in flow.steps" :key="`${step.type}-${index}`" class="flex gap-3">
            <span class="flex w-3.5 shrink-0 flex-col items-center">
                <span class="mt-1 size-3 rounded-full" :class="step.type === 'refund' ? 'bg-negative' : 'bg-accent'" />
                <span v-if="index < flow.steps.length - 1 || now.length" class="w-0.5 flex-1 bg-rail" />
            </span>

            <span v-if="step.type === 'receipt'" class="flex flex-1 flex-col gap-[3px] pb-4.5">
                <span class="text-12 text-ink-3">{{ formatDayMonth(step.date) }} · надходження</span>
                <span class="text-15 font-medium">{{ step.account.account_name }}</span>
                <span class="text-13 text-ink-2">
                    Отримано <span class="num font-semibold text-ink">{{ formatMoney(step.amount, step.currency) }}</span>
                    <template v-if="step.fee && compareAmounts(step.fee, '0') !== 0"> · комісія <span class="num">{{ formatAmount(step.fee) }}</span></template>
                </span>
            </span>

            <RouterLink
                v-else-if="step.type === 'transfer'"
                :to="{ name: 'transfer', params: { id: step.transfer_id } }"
                class="flex flex-1 flex-col gap-[3px] pb-4.5 text-ink no-underline hover:text-ink"
            >
                <span class="text-12 text-ink-3">{{ formatDayMonth(step.date) }} · переказ</span>
                <span class="text-15 font-medium">→ {{ step.to.account_name }}</span>
                <span class="text-13 text-ink-2">
                    Розподілено <span class="num font-semibold text-ink">{{ formatMoney(step.allocated, step.currency_sent) }}</span>
                    → прибуло <span class="num font-semibold text-ink">{{ formatMoney(step.arrived, step.currency_received) }}</span>
                </span>
                <span v-if="compareAmounts(step.fee_share, '0') !== 0" class="text-12 text-ink-3">
                    Частка комісії переказу <span class="num">{{ formatMoney(step.fee_share, step.currency_sent) }}</span>
                </span>
            </RouterLink>

            <span v-else class="flex flex-1 flex-col gap-[3px] pb-4.5">
                <span class="text-12 text-ink-3">{{ formatDayMonth(step.date) }} · повернення клієнту</span>
                <span class="text-15 font-medium">з {{ step.from.account_name }}</span>
                <span class="num text-13 font-semibold text-negative">{{ formatMoney(`-${step.amount}`, step.currency) }}</span>
            </span>
        </li>

        <li v-for="(holding, index) in now" :key="`now-${holding.account_id}`" class="flex gap-3">
            <span class="flex w-3.5 shrink-0 flex-col items-center">
                <span class="mt-1 size-3 rounded-full border-2" :class="holding.kind === 'fop_bank' ? 'border-positive' : 'border-warning-bar'" />
                <span v-if="index < now.length - 1" class="w-0.5 flex-1 bg-rail" />
            </span>
            <span class="flex flex-1 flex-col gap-[3px]" :class="index < now.length - 1 ? 'pb-4.5' : ''">
                <span class="text-12 text-ink-3">зараз</span>
                <span class="text-15 font-medium">{{ holding.kind === 'fop_bank' ? 'На' : 'Залишок на' }} {{ holding.account_name }}</span>
                <span class="num text-13 font-semibold" :class="holding.kind === 'fop_bank' ? 'text-positive' : 'text-warning-text'">
                    {{ formatMoney(holding.amount, holding.currency ?? flow.currency) }}<template v-if="holding.kind !== 'fop_bank'"> не розподілено</template>
                </span>
            </span>
        </li>
    </ol>
</template>
