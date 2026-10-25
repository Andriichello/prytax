<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, newIdempotencyKey } from '@/api/client';
import type { Account, ApiResponse, Payment, TaxPayment, TaxType } from '@/api/types';
import SubScreen from '@/components/layout/SubScreen.vue';
import ReceiptHeader from '@/components/payment/ReceiptHeader.vue';
import TaxPaymentFields, { type TaxPaymentDraft } from '@/components/taxes/TaxPaymentFields.vue';
import ConfirmSheet from '@/components/ui/ConfirmSheet.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { formatAmount, parseAmount } from '@/utils/money';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * Сплата ЄП / ВЗ (M-TaxPaymentNew, design.md §8.4, §16.5): a tax of one receipt. The receipt's head, read-only; the
 * tax, ЄП | ВЗ, whose switch opens the receipt's payment of the other tax; the amount, which the receipt accrued and
 * which can't be changed; then the date, the status, the account, the requisites and the payment order's number. A
 * receipt pays each tax once, so a new payment of a tax, which the receipt has paid already, opens that payment.
 */
type ReceiptTax = Extract<TaxType, 'single_tax' | 'military_levy'>;
const TAXES: ReceiptTax[] = ['single_tax', 'military_levy'];

const route = useRoute();
const router = useRouter();
const { can } = useSession();
const { profileId, path, settings } = useCurrentProfile();

const taxPaymentId = computed(() => (route.params.id ? Number(route.params.id) : null));
const routeKey = computed(() => route.fullPath);

const existing = ref<TaxPayment | null>(null);
const payment = ref<Payment | null>(null);
const accounts = ref<Account[]>([]);
const taxType = ref<ReceiptTax>('single_tax');

const form = ref<TaxPaymentDraft>({ paid_at: today(), status: 'paid', paid_from_account_id: null, original_amount: '', reference: '' });

const { error: loadError, loading, reload } = useLoader(async (signal) => {
    const record = taxPaymentId.value === null
        ? null
        : (await api.get<ApiResponse<TaxPayment>>(path(`tax-payments/${taxPaymentId.value}`), undefined, signal)).data;
    const [receipt, accountList] = await Promise.all([
        api.get<ApiResponse<Payment>>(path(`payments/${record?.payment_id ?? Number(route.query.payment)}`), {
            include: ['invoice.client', 'taxPayments', 'taxRule'],
        }, signal),
        api.all<Account>(path('accounts'), {}, signal),
    ]);
    const wanted = record?.tax_type ?? route.query.tax;

    existing.value = record;
    payment.value = receipt.data;
    accounts.value = accountList;
    taxType.value = TAXES.includes(wanted as ReceiptTax) ? wanted as ReceiptTax : 'single_tax';

    const paid = record === null ? receipt.data.taxPayments?.find((item) => item.tax_type === taxType.value) : undefined;

    if (paid) {
        await router.replace({ name: 'tax-payment', params: { id: paid.id } });

        return true;
    }

    form.value = record
        ? {
            paid_at: record.paid_at ?? '',
            status: record.status,
            paid_from_account_id: record.paid_from_account_id,
            original_amount: record.original_amount ?? '',
            reference: record.reference ?? '',
        }
        : { paid_at: today(), status: 'paid', paid_from_account_id: settings.value?.taxes.default_account_id ?? null, original_amount: '', reference: '' };

    return true;
}, [profileId, routeKey]);

const taxOptions = computed(() => TAXES.map((value) => ({ value, label: TAX_TYPES[value] })));

/** Open the receipt's payment of the other tax: the one recorded, or a new one. */
function switchTax(next: ReceiptTax): void {
    if (!payment.value || next === taxType.value) {
        return;
    }

    const other = payment.value.taxPayments?.find((item) => item.tax_type === next);

    void router.replace(other
        ? { name: 'tax-payment', params: { id: other.id } }
        : { name: 'tax-payment-new', query: { payment: payment.value.id, tax: next } });
}

/** What the receipt accrued of the tax, at the rate of its tax rules. */
const amount = computed(() => (payment.value ? (taxType.value === 'single_tax' ? payment.value.single_tax_uah : payment.value.military_levy_uah) : null));
const ratePercent = computed(() => {
    const rate = taxType.value === 'single_tax' ? payment.value?.taxRule?.single_tax_rate : payment.value?.taxRule?.military_levy_rate;

    return rate ? `${Number((Number(rate) * 100).toFixed(2))} %` : '';
});

const requisiteQuery = computed(() => ({
    tax_type: taxType.value,
    period_year: payment.value?.period_year ?? Number(today().slice(0, 4)),
    period_quarter: payment.value?.period_quarter,
    date: form.value.paid_at || undefined,
}));

const isForeign = computed(() => {
    const account = accounts.value.find((item) => item.id === form.value.paid_from_account_id);

    return !!account && account.currency !== 'UAH';
});

/** Back where the form was opened from: Податки, Облік, the receipt or its record. */
function leave(): void {
    if (window.history.state?.back) {
        router.back();
    } else {
        void router.replace(payment.value ? { name: 'payment', params: { id: payment.value.id } } : { name: 'taxes' });
    }
}

/* Saving */
const idempotencyKey = newIdempotencyKey();
const saving = ref(false);
const saveError = ref<unknown>(null);

async function save(): Promise<void> {
    saving.value = true;
    saveError.value = null;

    const body = {
        tax_type: taxType.value,
        payment_id: payment.value?.id,
        status: form.value.status,
        paid_at: form.value.paid_at || null,
        paid_from_account_id: form.value.paid_from_account_id,
        original_amount: isForeign.value ? parseAmount(form.value.original_amount) ?? form.value.original_amount : null,
        reference: form.value.reference.trim() || null,
    };

    try {
        if (existing.value) {
            await api.patch(path(`tax-payments/${existing.value.id}`), body);
        } else {
            await api.post(path('tax-payments'), body, { 'Idempotency-Key': idempotencyKey });
        }

        leave();
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

const fieldErrors = computed(() => ({
    paid_at: fieldError(saveError.value, 'paid_at'),
    paid_from_account_id: fieldError(saveError.value, 'paid_from_account_id'),
    original_amount: fieldError(saveError.value, 'original_amount'),
    reference: fieldError(saveError.value, 'reference'),
}));
const generalError = computed(() => (saveError.value && !Object.values(fieldErrors.value).some(Boolean) ? errorMessage(saveError.value) : null));

/* Deleting */
const deleteOpen = ref(false);
const deleting = ref(false);
const deleteError = ref<string | null>(null);

async function remove(): Promise<void> {
    deleting.value = true;
    deleteError.value = null;

    try {
        await api.delete(path(`tax-payments/${existing.value!.id}`));
        deleteOpen.value = false;
        leave();
    } catch (caught) {
        deleteError.value = errorMessage(caught);
    } finally {
        deleting.value = false;
    }
}
</script>

<template>
    <SubScreen :title="t('accounting.taxPayment.title')" :back="{ name: 'taxes' }">
        <LoadState :loading="loading && !payment" :error="loadError" @retry="reload">
            <template v-if="payment">
                <div class="flex flex-col gap-1.5">
                    <span class="text-13 font-medium text-ink-2">{{ t('core.common.receipt') }}</span>
                    <ReceiptHeader :payment="payment" class="rounded-card border border-line bg-surface px-4 py-3.5" />
                </div>

                <div class="flex flex-col gap-1.5">
                    <span class="text-13 font-medium text-ink-2">{{ t('core.common.tax') }}</span>
                    <SegmentedControl :model-value="taxType" :label="t('core.common.tax')" size="lg" :options="taxOptions" @update:model-value="switchTax" />
                    <span class="text-12 text-ink-3">{{ t('accounting.taxPayment.receiptOnly') }}</span>
                </div>

                <div class="flex flex-col gap-1.5">
                    <span class="text-13 font-medium text-ink-2">{{ t('accounting.taxPayment.amountUah') }}</span>
                    <div class="flex items-center justify-between gap-3 rounded-control border border-line bg-muted px-4 py-3">
                        <span class="num text-20 font-semibold">{{ formatAmount(amount) }}</span>
                        <span class="flex flex-col items-end text-right text-12 text-ink-2">
                            <span>{{ t('accounting.taxPayment.accruedFrom', { rate: ratePercent, base: formatAmount(payment.uah_amount) }) }}</span>
                            <span>{{ t('accounting.taxPayment.accruedFromReceipt') }}</span>
                        </span>
                    </div>
                </div>

                <TaxPaymentFields
                    v-model="form"
                    :accounts="accounts"
                    :amount="amount"
                    :requisite-query="requisiteQuery"
                    :errors="fieldErrors"
                />

                <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>

                <button
                    v-if="existing && can('delete', 'tax-payments')"
                    type="button"
                    class="flex min-h-11 items-center self-center text-15 font-medium text-negative"
                    @click="deleteOpen = true"
                >
                    {{ t('accounting.taxPayment.deletePayment') }}
                </button>
            </template>
        </LoadState>

        <template v-if="payment && can(existing ? 'update' : 'create', 'tax-payments')" #footer>
            <PrimaryButton :busy="saving" :disabled="loading" @click="save">
                {{ t('accounting.taxPayment.savePayment') }}
            </PrimaryButton>
        </template>

        <ConfirmSheet
            v-model:open="deleteOpen"
            :title="t('accounting.taxPayment.deleteQuestion')"
            :confirm-label="t('core.common.delete')"
            destructive
            :busy="deleting"
            :error="deleteError"
            @confirm="remove"
        >
            <p class="m-0">{{ t('accounting.taxPayment.deleteExplained') }}</p>
        </ConfirmSheet>
    </SubScreen>
</template>
