<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError, api } from '@/api/client';
import type { ApiResponse, Payment, PaymentFlow, TaxType } from '@/api/types';
import TextAreaField from '@/components/form/TextAreaField.vue';
import VirtualMarker from '@/components/invoice/VirtualMarker.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import MoneyFlow from '@/components/payment/MoneyFlow.vue';
import ReceiptTaxRow from '@/components/taxes/ReceiptTaxRow.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import ConfirmSheet from '@/components/ui/ConfirmSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import Translated from '@/components/ui/Translated.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { formatDate, monthNameInText, quarterLabel } from '@/utils/dates';
import { errorMessage } from '@/utils/errors';
import { compareAmounts, formatAmount, formatMoney, subtractAmounts } from '@/utils/money';
import { TAX_TYPES, unpaidStatusOf } from '@/utils/statuses';

/**
 * Надходження (M-PaymentDetail): the amount in its currency with hryvnias below, the invoice (or the «Без інвойсу»
 * record), the income date, the NBU rate (only here), ЄП / ВЗ as hints, the way the money went, what it cost and what
 * is left, and «Сплата податків»: ЄП and ВЗ always, each opening its payment (design.md §16.5). Deleting it, or one
 * of its refunds, in a declared quarter asks for a correction note.
 */
const route = useRoute();
const router = useRouter();
const { profileId, path } = useCurrentProfile();
const { can } = useSession();

const id = computed(() => Number(route.params.id));

const { data, error, loading, reload } = useLoader(async (signal) => {
    const [payment, flow] = await Promise.all([
        api.get<ApiResponse<Payment>>(path(`payments/${id.value}`), {
            include: ['invoice.client', 'receivedToAccount', 'taxPayments.paidFromAccount', 'taxRule'],
        }, signal),
        api.get<ApiResponse<PaymentFlow>>(path(`payments/${id.value}/flow`), undefined, signal),
    ]);

    return { payment: payment.data, flow: flow.data };
}, [profileId, id]);

const payment = computed(() => data.value?.payment ?? null);
const flow = computed(() => data.value?.flow ?? null);

const isVirtual = computed(() => payment.value?.invoice?.source === 'virtual');
const ratePercent = (rate: string | undefined): string => (rate ? `${Number((Number(rate) * 100).toFixed(2))} %` : '');

const onPlatform = computed(() => flow.value?.holdings.find((holding) => holding.kind !== 'fop_bank' && compareAmounts(holding.amount, '0') > 0) ?? null);
const landed = computed(() => flow.value?.landed_on_fop_bank.filter((holding) => compareAmounts(holding.amount, '0') > 0) ?? []);
/** ЄП and ВЗ of the receipt: what it accrued, its payment (one per tax), and where a row leads. */
const taxes = computed(() => (['single_tax', 'military_levy'] as const).map((type: Extract<TaxType, 'single_tax' | 'military_levy'>) => {
    const taxPayment = payment.value?.taxPayments?.find((item) => item.tax_type === type) ?? null;

    return {
        type,
        amount: (type === 'single_tax' ? payment.value?.single_tax_uah : payment.value?.military_levy_uah) ?? '0.00',
        taxPayment,
        to: taxPayment
            ? { name: 'tax-payment', params: { id: taxPayment.id } }
            : can('create', 'tax-payments') ? { name: 'tax-payment-new', query: { payment: id.value, tax: type } } : null,
    };
}));

/**
 * The status, to which deleting the receipt returns its paid invoice, as the receipts left no longer cover it (a
 * receipt with refunds can't be deleted, so its whole amount leaves), or null when the invoice stays as it is. A
 * record «Без інвойсу» doesn't return to unpaid: it is deleted with its receipt.
 */
const reopenedStatus = computed(() => {
    const invoice = payment.value?.invoice;

    if (!invoice || invoice.source === 'virtual' || invoice.status !== 'paid'
        || compareAmounts(subtractAmounts(invoice.paid_amount ?? '0', payment.value!.amount), invoice.total) >= 0) {
        return null;
    }

    return unpaidStatusOf(invoice).label;
});

/** Invoices, which saving the receipt couldn't mark paid (the form passes their numbers). */
const unpaid = computed(() => (typeof route.query.unpaid === 'string' ? route.query.unpaid : null));

const menuOpen = ref(false);
const deleteOpen = ref(false);
const busy = ref(false);
const actionError = ref<string | null>(null);

/* A delete in a declared quarter answers 409: it then needs a correction note */
const correctionNote = ref('');
const needsNote = ref(false);

/**
 * Remember why a delete failed: a declared quarter asks for the correction note.
 */
function failed(caught: unknown): void {
    needsNote.value ||= caught instanceof ApiError && caught.status === 409;
    actionError.value = errorMessage(caught);
}

const noteQuery = () => (correctionNote.value.trim() ? { correction_note: correctionNote.value.trim() } : undefined);

function openDelete(): void {
    menuOpen.value = false;
    actionError.value = null;
    needsNote.value = false;
    correctionNote.value = '';
    deleteOpen.value = true;
}

async function acknowledge(): Promise<void> {
    menuOpen.value = false;
    actionError.value = null;

    try {
        await api.post(path(`payments/${id.value}/acknowledge-repatriation`), {});
        await reload();
    } catch (caught) {
        actionError.value = errorMessage(caught);
    }
}

async function remove(): Promise<void> {
    busy.value = true;
    actionError.value = null;

    try {
        // A payment without an invoice takes its record along
        const invoiceId = isVirtual.value ? null : payment.value?.invoice_id;

        await api.delete(path(`payments/${id.value}`), noteQuery());
        deleteOpen.value = false;
        await router.replace(invoiceId ? { name: 'invoice', params: { id: invoiceId } } : { name: 'accounting' });
    } catch (caught) {
        failed(caught);
    } finally {
        busy.value = false;
    }
}

/* Deleting a refund */
const refundToRemove = ref<number | null>(null);
const refundOpen = computed({
    get: () => refundToRemove.value !== null,
    set: (isOpen: boolean) => {
        if (!isOpen) {
            refundToRemove.value = null;
        }
    },
});

function openRefundRemoval(refundId: number): void {
    actionError.value = null;
    needsNote.value = false;
    correctionNote.value = '';
    refundToRemove.value = refundId;
}

async function removeRefund(): Promise<void> {
    busy.value = true;
    actionError.value = null;

    try {
        await api.delete(path(`payments/${id.value}/refunds/${refundToRemove.value}`), noteQuery());
        refundToRemove.value = null;
        await reload();
    } catch (caught) {
        failed(caught);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <SubScreen :title="t('core.common.receipt')" muted :back="payment?.invoice_id ? { name: 'invoice', params: { id: payment.invoice_id } } : { name: 'accounting' }">
        <template #action>
            <button
                v-if="payment && can('update', 'payments')"
                type="button"
                :aria-label="t('core.common.moreActions')"
                class="flex size-11 items-center justify-center text-ink"
                @click="menuOpen = true"
            >
                <Icon name="more" :size="22" />
            </button>
        </template>

        <LoadState :loading="loading && !data" :error="error" @retry="reload">
            <template v-if="payment && flow">
                <p v-if="unpaid" class="m-0 rounded-button bg-warning-soft p-3.5 text-14 leading-[1.45] text-warning" role="alert">
                    <Translated :text="t('money.payment.markPaidFailed')">
                        <template #number><span class="num">{{ unpaid }}</span></template>
                    </Translated>
                </p>
                <div class="flex flex-col gap-1.5">
                    <span class="num text-32 font-semibold tracking-[-0.03em]">{{ formatMoney(payment.amount, payment.currency) }}</span>
                    <span v-if="payment.currency !== 'UAH'" class="num text-16 font-light text-ink-2">{{ formatMoney(payment.uah_amount, 'UAH') }}</span>
                    <div class="mt-1 flex gap-2">
                        <StatusBadge :label="`${quarterLabel(payment.period_quarter)} ${payment.period_year} · ${monthNameInText(payment.period_month)}`" tone="info" />
                    </div>
                </div>

                <div class="mt-2.5 mb-0.5 border-t border-dashed border-line-input" />

                <RouterLink
                    v-if="payment.invoice"
                    :to="{ name: 'invoice', params: { id: payment.invoice.id } }"
                    class="-my-1 flex min-h-14 items-center gap-3 py-1 text-ink no-underline hover:text-ink"
                >
                    <span class="flex min-w-0 flex-1 flex-col items-start gap-1">
                        <VirtualMarker v-if="isVirtual" />
                        <span v-else class="num text-17 font-semibold text-accent">{{ payment.invoice.number }}</span>
                        <span class="max-w-full truncate text-16 font-light">{{ payment.invoice.client?.name }}</span>
                    </span>
                    <Icon name="chevronRight" :size="20" class="shrink-0 text-accent" />
                </RouterLink>

                <section class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4 py-1 text-14">
                    <div class="flex justify-between gap-3 py-3">
                        <span class="text-ink-3">{{ t('money.payment.incomeDate') }}</span>
                        <span class="num">{{ formatDate(payment.received_at) }}</span>
                    </div>
                    <div v-if="payment.currency !== 'UAH'" class="flex justify-between gap-3 py-3">
                        <span class="text-ink-3">{{ payment.rate_is_manual ? t('money.payment.manualRate') : t('money.payment.nbuRate') }}</span>
                        <span class="num">{{ formatAmount(payment.nbu_rate) }} · {{ formatDate(payment.nbu_rate_date) }}</span>
                    </div>
                    <div class="flex justify-between gap-3 py-3">
                        <span class="text-ink-3">{{ t('money.payment.singleTaxEstimate', { rate: ratePercent(payment.taxRule?.single_tax_rate) }) }}</span>
                        <span class="num font-semibold">{{ formatAmount(payment.single_tax_uah) }}</span>
                    </div>
                    <div class="flex justify-between gap-3 py-3">
                        <span class="text-ink-3">{{ t('money.payment.militaryLevyEstimate', { rate: ratePercent(payment.taxRule?.military_levy_rate) }) }}</span>
                        <span class="num font-semibold">{{ formatAmount(payment.military_levy_uah) }}</span>
                    </div>
                    <div v-if="payment.taxRule" class="flex justify-between gap-3 py-3">
                        <span class="text-ink-3">{{ t('money.payment.taxRules') }}</span>
                        <span>{{ t('core.common.fromDate', { date: formatDate(payment.taxRule.effective_from) }) }}</span>
                    </div>
                </section>

                <section class="flex flex-col gap-2.5">
                    <SectionTitle>{{ t('money.payment.moneyFlow') }}</SectionTitle>
                    <MoneyFlow :flow="flow" :can-remove-refunds="can('delete', 'refunds')" @remove-refund="openRefundRemoval" />
                    <RouterLink
                        v-if="onPlatform && can('create', 'account-transfers')"
                        :to="{ name: 'transfer-new', query: { from: onPlatform.account_id ?? undefined } }"
                        class="flex h-12 items-center justify-center gap-2 rounded-control border border-line-input bg-surface text-15 font-medium text-ink no-underline hover:text-ink"
                    >
                        <Icon name="transfer" :size="20" />
                        {{ t('money.payment.withdrawBalance') }}
                    </RouterLink>
                </section>

                <section class="grid grid-cols-2 gap-2" :aria-label="t('money.payment.summary')">
                    <div class="flex flex-col gap-1 rounded-button border border-line bg-surface p-3">
                        <span class="text-12 text-ink-3">{{ t('money.payment.lostFees') }}</span>
                        <span class="num text-16 font-semibold">{{ flow.fees !== null ? formatMoney(flow.fees, flow.currency) : '—' }}</span>
                        <span v-if="flow.transfer_fee_share && compareAmounts(flow.transfer_fee_share, '0') !== 0" class="num text-11 text-ink-3">
                            {{ formatAmount(flow.receipt_fee ?? '0') }} + {{ formatAmount(flow.transfer_fee_share) }}
                        </span>
                        <span v-else class="text-11 text-ink-3">{{ t('money.payment.onReceipt') }}</span>
                    </div>
                    <div class="flex flex-col gap-1 rounded-button border border-line bg-surface p-3">
                        <span class="text-12 text-ink-3">{{ t('money.payment.reachedFopAccount') }}</span>
                        <template v-if="landed.length">
                            <span v-for="holding in landed" :key="holding.account_id ?? 0" class="num text-16 font-semibold">
                                {{ formatMoney(holding.amount, holding.currency ?? flow.currency) }}
                            </span>
                            <span class="text-11 text-ink-3">{{ landed.map((holding) => holding.account_name).join(', ') }}</span>
                        </template>
                        <template v-else>
                            <span class="num text-16 font-semibold">{{ formatMoney('0.00', flow.currency) }}</span>
                            <span class="text-11 text-ink-3">{{ t('money.payment.notTransferredYet') }}</span>
                        </template>
                    </div>
                    <div class="flex flex-col gap-1 rounded-button border border-line bg-surface p-3">
                        <span class="text-12 text-ink-3">{{ t('money.payment.stillPlatform') }}</span>
                        <span class="num text-16 font-semibold">{{ formatMoney(flow.still_on_platform, flow.currency) }}</span>
                        <span class="text-11 text-ink-3">{{ t('money.payment.balanceNotLoss') }}</span>
                    </div>
                    <div class="flex flex-col gap-1 rounded-button border border-line bg-surface p-3">
                        <span class="text-12 text-ink-3">{{ t('money.payment.netIncome') }}</span>
                        <span class="num text-16 font-semibold">{{ flow.net_income !== null ? formatMoney(flow.net_income, flow.currency) : '—' }}</span>
                        <span class="text-11 text-ink-3">{{ t('money.payment.netIncomeNote') }}</span>
                    </div>
                </section>

                <section v-if="payment.kind === 'invoice'" class="flex flex-col gap-2.5">
                    <SectionTitle>{{ t('settings.taxes.payingTaxes') }}</SectionTitle>
                    <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                        <ReceiptTaxRow
                            v-for="tax in taxes"
                            :key="tax.type"
                            :label="TAX_TYPES[tax.type]"
                            :amount="tax.amount"
                            :tax-payment="tax.taxPayment"
                            :to="tax.to"
                        />
                    </div>
                    <p class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('money.payment.taxesCaption') }}</p>
                </section>

                <p v-if="actionError" class="m-0 text-14 text-negative" role="alert">{{ actionError }}</p>
            </template>
        </LoadState>

        <BottomSheet v-model:open="menuOpen" :title="t('money.payment.receiptActions')">
            <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                <RouterLink
                    v-if="payment?.kind !== 'fx_gain'"
                    :to="{ name: 'payment-edit', params: { id } }"
                    class="flex min-h-13 items-center gap-3 text-15 text-ink no-underline hover:text-ink"
                >
                    <Icon name="invoice" :size="20" /> {{ t('money.payment.edit') }}
                </RouterLink>
                <button
                    v-if="onPlatform && !payment?.repatriation_ack_at"
                    type="button"
                    class="flex min-h-13 items-center gap-3 text-left text-15"
                    @click="acknowledge"
                >
                    <Icon name="bell" :size="20" /> {{ t('money.payment.dontRemindPlatform') }}
                </button>
                <button
                    v-if="can('delete', 'payments') && payment?.kind !== 'fx_gain'"
                    type="button"
                    class="flex min-h-13 items-center gap-3 text-left text-15 text-negative"
                    @click="openDelete"
                >
                    <Icon name="close" :size="20" /> {{ t('money.payment.deleteReceipt') }}
                </button>
            </div>
        </BottomSheet>

        <ConfirmSheet
            v-model:open="deleteOpen"
            :title="t('money.payment.deleteReceiptQuestion')"
            :confirm-label="t('core.common.delete')"
            destructive
            :busy="busy"
            :error="actionError"
            @confirm="remove"
        >
            <p class="m-0">{{ t('money.payment.deleteReceiptExplained') }}</p>
            <p v-if="reopenedStatus" class="m-0 text-warning">
                <Translated :text="t('money.payment.invoiceWillReturn', { status: reopenedStatus })">
                    <template #number><span class="num">{{ payment?.invoice?.number }}</span></template>
                </Translated>
            </p>
            <p v-if="payment?.invoice?.source === 'virtual'" class="m-0">{{ t('money.payment.recordGoesToo') }}</p>
            <TextAreaField v-if="needsNote" v-model="correctionNote" :label="t('money.payment.correctionQuestion')" :rows="2" />
        </ConfirmSheet>

        <ConfirmSheet
            v-model:open="refundOpen"
            :title="t('money.payment.deleteRefundQuestion')"
            :confirm-label="t('core.common.delete')"
            destructive
            :busy="busy"
            :error="actionError"
            @confirm="removeRefund"
        >
            <p class="m-0">{{ t('money.payment.deleteRefundExplained') }}</p>
            <TextAreaField v-if="needsNote" v-model="correctionNote" :label="t('money.payment.correctionQuestion')" :rows="2" />
        </ConfirmSheet>
    </SubScreen>
</template>
