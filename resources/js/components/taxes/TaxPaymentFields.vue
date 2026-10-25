<script setup lang="ts">
import { computed, ref } from 'vue';
import { ApiError, api } from '@/api/client';
import type { Account, ApiResponse, CurrentTaxPaymentRequisite, TaxPaymentStatus, TaxType } from '@/api/types';
import DateField from '@/components/form/DateField.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextField from '@/components/form/TextField.vue';
import CopyRow from '@/components/ui/CopyRow.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { t } from '@/i18n';
import { accountLabel } from '@/utils/accounts';
import { errorMessage } from '@/utils/errors';
import { compareAmounts, formatAmount, parseAmount } from '@/utils/money';
import { TAX_PAYMENT_STATUSES, TAX_TYPES } from '@/utils/statuses';

/**
 * What every tax payment records after its amount (design.md §8.4): the date, the status, the account it left, the
 * amount, which left a foreign-currency account (typed by hand, as the bank's rate isn't the NBU's), the budget's
 * requisites with the purpose to copy, and the payment order's number.
 */
export interface TaxPaymentDraft {
    paid_at: string;
    status: TaxPaymentStatus;
    paid_from_account_id: number | null;
    original_amount: string;
    reference: string;
}

const { accounts, amount, requisiteQuery, errors = {} } = defineProps<{
    accounts: Account[];
    /** The amount in UAH, as typed or taken from the receipt. */
    amount: string | null;
    /** Which requisites to show: the tax and its period, on the payment's date. */
    requisiteQuery: { tax_type: TaxType; period_year: number; period_quarter?: number; period_month?: number; date?: string };
    errors?: Partial<Record<'paid_at' | 'paid_from_account_id' | 'original_amount' | 'reference', string | undefined>>;
}>();

const model = defineModel<TaxPaymentDraft>({ required: true });

const { profileId, path, settings } = useCurrentProfile();

const STATUSES: TaxPaymentStatus[] = ['sent', 'paid', 'failed'];
const statusOptions = computed(() => STATUSES.map((value) => ({ value, label: TAX_PAYMENT_STATUSES[value].label })));

/** The accounts of «Сплата податків», the primary one first; an account the payment already names stays listed. */
const accountOptions = computed(() => {
    const taxes = settings.value?.taxes;
    const pays = (account: Account): boolean => account.is_active && (account.currency === 'UAH' || account.kind === 'fop_bank');
    const listed = taxes?.payment_account_ids.length
        ? accounts.filter((account) => taxes.payment_account_ids.includes(account.id))
        : accounts.filter(pays);
    const current = accounts.find((account) => account.id === model.value.paid_from_account_id);
    const shown = current && !listed.includes(current) ? [...listed, current] : listed;

    return [...shown]
        .sort((a, b) => Number(b.id === taxes?.default_account_id) - Number(a.id === taxes?.default_account_id))
        .map((account) => ({
            value: account.id,
            label: account.id === taxes?.default_account_id
                ? `${accountLabel(account.name, account.currency)} · ${t('accounting.taxPayment.primary')}`
                : accountLabel(account.name, account.currency),
        }));
});

const account = computed(() => accounts.find((item) => item.id === model.value.paid_from_account_id) ?? null);
const isForeign = computed(() => !!account.value && account.value.currency !== 'UAH');

/** The bank's rate, which the two amounts imply: hryvnias per unit of the account's currency. */
const bankRate = computed(() => {
    const uah = amount === null ? null : parseAmount(amount);
    const original = parseAmount(model.value.original_amount);

    return uah && original && compareAmounts(original, '0') > 0 ? (Number(uah) / Number(original)).toFixed(4) : null;
});

/* The requisites of the tax and its period, with the purpose filled in */
const requisiteKey = computed(() => JSON.stringify(requisiteQuery));
const requisiteLoader = useLoader(async (signal) => {
    const key = requisiteKey.value;

    try {
        const { data } = await api.get<ApiResponse<CurrentTaxPaymentRequisite>>(path('tax-payment-requisites/current'), requisiteQuery, signal);

        return { key, requisite: data, missing: false };
    } catch (caught) {
        if (caught instanceof ApiError && caught.status === 404) {
            return { key, requisite: null, missing: true };
        }

        throw caught;
    }
}, [profileId, requisiteKey]);
const requisite = computed(() => (requisiteLoader.data.value?.key === requisiteKey.value ? requisiteLoader.data.value.requisite : null));
const requisiteMissing = computed(() => requisiteLoader.data.value?.key === requisiteKey.value && requisiteLoader.data.value.missing);

const copiedAll = ref(false);

async function copyAll(): Promise<void> {
    if (!requisite.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText([
            `${t('core.common.recipient')}: ${requisite.value.recipient_name}`,
            `IBAN: ${requisite.value.iban}`,
            `${t('settings.taxes.recipientCode')}: ${requisite.value.recipient_code}`,
            `${t('settings.taxes.paymentPurpose')}: ${requisite.value.purpose}`,
            `${t('accounting.taxPayment.amount')}: ${amount === null ? '' : parseAmount(amount) ?? amount}`,
        ].join('\n'));
        copiedAll.value = true;
        setTimeout(() => (copiedAll.value = false), 1500);
    } catch {
        copiedAll.value = false;
    }
}
</script>

<template>
    <DateField v-model="model.paid_at" :label="t('accounting.taxPayment.paidOn')" :error="errors.paid_at" />

    <div class="flex flex-col gap-1.5">
        <span class="text-13 font-medium text-ink-2">{{ t('core.common.status') }}</span>
        <SegmentedControl v-model="model.status" :label="t('core.common.status')" size="lg" :options="statusOptions" />
        <span class="text-12 text-ink-3">{{ t('accounting.taxPayment.sentIsPending') }}</span>
    </div>

    <SelectField
        v-model="model.paid_from_account_id"
        :label="t('accounting.taxPayment.paidFromAccount')"
        :placeholder="t('accounting.taxPayment.notSpecified')"
        clearable
        :options="accountOptions"
        :hint="t('accounting.taxPayment.primaryHint')"
        :error="errors.paid_from_account_id"
    />
    <TextField
        v-if="isForeign && account"
        v-model="model.original_amount"
        :label="t('accounting.taxPayment.writtenOff', { currency: account.currency })"
        numeric
        :hint="bankRate
            ? t('accounting.taxPayment.bankRate', { currency: account.currency, rate: formatAmount(bankRate) })
            : t('accounting.taxPayment.writtenOffHint')"
        :error="errors.original_amount"
    />

    <section v-if="requisite" class="flex flex-col rounded-card border border-line bg-surface pr-1 pl-4">
        <div class="flex items-center justify-between py-2">
            <SectionTitle as="span">{{ t('accounting.taxPayment.paymentDetails', { tax: TAX_TYPES[requisiteQuery.tax_type] }) }}</SectionTitle>
            <button type="button" class="flex min-h-11 items-center px-3 text-15 font-medium text-accent" @click="copyAll">
                {{ copiedAll ? t('shell.ui.copied') : t('accounting.taxPayment.copyAll') }}
            </button>
        </div>
        <div class="flex flex-col divide-y divide-divider border-t border-divider">
            <CopyRow :label="t('core.common.recipient')" :value="requisite.recipient_name" :figure="false" />
            <CopyRow label="IBAN" :value="requisite.iban" />
            <CopyRow :label="t('settings.taxes.recipientCode')" :value="requisite.recipient_code" />
            <CopyRow :label="t('settings.taxes.paymentPurpose')" :value="requisite.purpose" wrap />
        </div>
    </section>
    <p v-else-if="requisiteMissing" class="m-0 rounded-card border border-line bg-surface p-4 text-14 text-ink-2">
        {{ t('accounting.taxPayment.noPaymentDetails', { tax: TAX_TYPES[requisiteQuery.tax_type] }) }}
    </p>
    <p v-else-if="requisiteLoader.error.value" class="m-0 flex flex-wrap items-center gap-x-2 text-14 text-negative" role="alert">
        {{ errorMessage(requisiteLoader.error.value) }}
        <button type="button" class="min-h-11 text-14 font-medium text-accent" @click="requisiteLoader.reload">{{ t('core.common.retry') }}</button>
    </p>

    <TextField
        v-model="model.reference"
        :label="t('accounting.taxPayment.paymentOrderNumber')"
        :placeholder="t('core.common.optional')"
        :error="errors.reference"
    />
</template>
