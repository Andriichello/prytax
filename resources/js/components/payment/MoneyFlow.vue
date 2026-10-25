<script setup lang="ts">
import { computed } from 'vue';
import type { PaymentFlow } from '@/api/types';
import Translated from '@/components/ui/Translated.vue';
import { t } from '@/i18n';
import { formatDayMonth } from '@/utils/dates';
import { compareAmounts, formatAmount, formatMoney } from '@/utils/money';

/**
 * Шлях коштів: the receipt, the transfers that moved its money on, its refunds (which can be cancelled), and where it
 * is now — money still on a platform is marked as not yet moved.
 */
const { flow, canRemoveRefunds = false } = defineProps<{ flow: PaymentFlow; canRemoveRefunds?: boolean }>();
const emit = defineEmits<{ removeRefund: [refundId: number] }>();

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
                <span class="text-12 text-ink-3">{{ t('money.flow.receiptOn', { date: formatDayMonth(step.date) }) }}</span>
                <span class="text-15 font-medium">{{ step.account.account_name }}</span>
                <span class="text-13 text-ink-2">
                    <Translated :text="t('money.flow.receivedAmount')">
                        <template #amount><span class="num font-semibold text-ink">{{ formatMoney(step.amount, step.currency) }}</span></template>
                    </Translated>
                    <template v-if="step.fee && compareAmounts(step.fee, '0') !== 0">
                        · <Translated :text="t('money.flow.feeAmount')"><template #fee><span class="num">{{ formatAmount(step.fee) }}</span></template></Translated>
                    </template>
                </span>
            </span>

            <RouterLink
                v-else-if="step.type === 'transfer'"
                :to="{ name: 'transfer', params: { id: step.transfer_id } }"
                class="flex flex-1 flex-col gap-[3px] pb-4.5 text-ink no-underline hover:text-ink"
            >
                <span class="text-12 text-ink-3">{{ t('money.flow.transferOn', { date: formatDayMonth(step.date) }) }}</span>
                <span class="text-15 font-medium">→ {{ step.to.account_name }}</span>
                <span class="text-13 text-ink-2">
                    <Translated :text="t('money.flow.allocatedArrived')">
                        <template #allocated><span class="num font-semibold text-ink">{{ formatMoney(step.allocated, step.currency_sent) }}</span></template>
                        <template #arrived><span class="num font-semibold text-ink">{{ formatMoney(step.arrived, step.currency_received) }}</span></template>
                    </Translated>
                </span>
                <span v-if="compareAmounts(step.fee_share, '0') !== 0" class="text-12 text-ink-3">
                    <Translated :text="t('money.flow.shareTransferFee')">
                        <template #amount><span class="num">{{ formatMoney(step.fee_share, step.currency_sent) }}</span></template>
                    </Translated>
                </span>
            </RouterLink>

            <span v-else class="flex flex-1 flex-col gap-[3px] pb-4.5">
                <span class="text-12 text-ink-3">{{ t('money.flow.refundOn', { date: formatDayMonth(step.date) }) }}</span>
                <span class="text-15 font-medium">{{ t('money.flow.fromAccount', { account: step.from.account_name }) }}</span>
                <span class="num text-13 font-semibold text-negative">{{ formatMoney(`-${step.amount}`, step.currency) }}</span>
                <button
                    v-if="canRemoveRefunds"
                    type="button"
                    class="flex min-h-11 items-center self-start text-13 font-medium text-negative"
                    @click="emit('removeRefund', step.refund_id)"
                >
                    {{ t('money.flow.deleteRefund') }}
                </button>
            </span>
        </li>

        <li v-for="(holding, index) in now" :key="`now-${holding.account_id}`" class="flex gap-3">
            <span class="flex w-3.5 shrink-0 flex-col items-center">
                <span class="mt-1 size-3 rounded-full border-2" :class="holding.kind === 'fop_bank' ? 'border-positive' : 'border-warning-bar'" />
                <span v-if="index < now.length - 1" class="w-0.5 flex-1 bg-rail" />
            </span>
            <span class="flex flex-1 flex-col gap-[3px]" :class="index < now.length - 1 ? 'pb-4.5' : ''">
                <span class="text-12 text-ink-3">{{ t('money.flow.now') }}</span>
                <span class="text-15 font-medium">{{ holding.kind === 'fop_bank' ? t('money.flow.onAccount', { account: holding.account_name }) : t('money.flow.leftOn', { account: holding.account_name }) }}</span>
                <span class="num text-13 font-semibold" :class="holding.kind === 'fop_bank' ? 'text-positive' : 'text-warning-text'">
                    {{ formatMoney(holding.amount, holding.currency ?? flow.currency) }}<template v-if="holding.kind !== 'fop_bank'">{{ ` ${t('money.flow.notAllocated')}` }}</template>
                </span>
            </span>
        </li>
    </ol>
</template>
