<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError, api, newIdempotencyKey } from '@/api/client';
import type {
    Account,
    ApiResponse,
    CurrentTaxPaymentRequisite,
    Payment,
    QuarterTaxPayments,
    TaxAllocation,
    TaxMonths,
    TaxPaymentStatus,
    TaxReconciliation,
    TaxType,
} from '@/api/types';
import DateField from '@/components/form/DateField.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextField from '@/components/form/TextField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import CopyRow from '@/components/ui/CopyRow.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { currentQuarter, dateParts, formatDayMonth, monthName, monthNameInText, quarterLabel, quarterOf, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { compareAmounts, formatAmount, formatMoney, parseAmount, subtractAmounts, sumAmounts } from '@/utils/money';
import { TAX_PAYMENT_STATUSES, TAX_TYPES } from '@/utils/statuses';
import { amountsToPay, isToPay } from '@/utils/taxes';

/**
 * Сплата податку (S-TaxPaymentNew): which tax, what it pays (a receipt, a month or a quarter), how much is left,
 * the amount and date, its status, the UAH account it left, and the budget requisites with the payment's purpose.
 *
 * Without a period in the link, the form opens on the earliest quarter, which is due and not paid. The amount follows
 * what is left of the chosen tax and period until it is typed by hand.
 */
const TAXES: TaxType[] = ['single_tax', 'military_levy', 'social_contribution'];
const STATUSES: TaxPaymentStatus[] = ['sent', 'paid', 'failed'];
const taxOptions = computed(() => TAXES.map((type) => ({ value: type, label: TAX_TYPES[type] })));
const statusOptions = computed(() => STATUSES.map((value) => ({ value, label: TAX_PAYMENT_STATUSES[value].label })));
const monthOptions = computed(() => Array.from({ length: 12 }, (_, index) => ({ value: index + 1, label: monthName(index + 1) })));
const quarterOptions = computed(() => [1, 2, 3, 4].map((value) => ({ value, label: quarterLabel(value) })));

const route = useRoute();
const router = useRouter();
const { can } = useSession();
const { profileId, path, settings } = useCurrentProfile();

const now = currentQuarter();
const query = route.query;
const queryQuarter = Number(query.quarter) >= 1 && Number(query.quarter) <= 4 ? Number(query.quarter) : null;
const queryPayment = Number(query.payment) || null;
const queryAllocation = ['payment', 'month', 'quarter'].includes(query.allocation as string) ? query.allocation as TaxAllocation : null;

const form = reactive({
    tax_type: (TAXES.includes(query.tax as TaxType) ? query.tax : 'single_tax') as TaxType,
    allocation: queryAllocation ?? (queryPayment ? 'payment' : 'quarter') as TaxAllocation,
    payment_id: queryPayment as number | null,
    period_year: Number(query.year) || now.year,
    period_quarter: queryQuarter ?? now.quarter,
    period_month: Number(query.month) || (queryQuarter ? queryQuarter * 3 : dateParts(today()).month),
    amount: typeof query.amount === 'string' ? query.amount : '',
    paid_at: today(),
    status: 'paid' as TaxPaymentStatus,
    paid_from_account_id: null as number | null,
    reference: '',
});

/** Typed by hand: the amount then stays as it is when the tax or the period changes. */
const amountTouched = ref(false);

/** Chosen by hand (or given by the link): the period then isn't moved to the earliest due quarter. */
const periodTouched = ref(queryQuarter !== null || Number(query.month) > 0 || queryPayment !== null);

if (form.tax_type === 'social_contribution' && form.allocation === 'payment') {
    form.allocation = 'quarter';
}

/* The receipt of the link, opened without its quarter: the quarter is taken from the receipt, before its receipts load */
const isPeriodPending = ref(queryPayment !== null && queryQuarter === null);

if (isPeriodPending.value) {
    void api.get<ApiResponse<Payment>>(path(`payments/${queryPayment}`)).then(({ data }) => {
        form.period_year = data.period_year;
        form.period_quarter = data.period_quarter;
    }).catch(() => undefined).finally(() => {
        isPeriodPending.value = false;
    });
}

/* Reference data, loaded as the choice changes; an answer for an earlier choice is never shown */
const accountsLoader = useLoader((signal) => api.all<Account>(path('accounts'), {}, signal), [profileId]);
const accounts = computed(() => accountsLoader.data.value ?? []);

watch(accountsLoader.data, (list) => {
    if (list && form.paid_from_account_id === null) {
        form.paid_from_account_id = settings.value?.taxes.default_account_id
            ?? list.find((account) => account.currency === 'UAH' && account.is_active && account.kind === 'fop_bank')?.id
            ?? null;
    }
}, { once: true });

const taxesKey = computed(() => `${form.tax_type}:${form.period_year}`);
const taxesLoader = useLoader(async (signal) => {
    const key = taxesKey.value;
    const [loaded, monthly] = await Promise.all([
        api.get<ApiResponse<TaxReconciliation>>(path(`taxes/${form.period_year}`), { tax_type: form.tax_type }, signal),
        api.get<ApiResponse<TaxMonths>>(path(`taxes/${form.period_year}/months`), undefined, signal),
    ]);

    return { key, reconciliation: loaded.data, months: monthly.data };
}, [profileId, taxesKey]);
const reconciliation = computed(() => (taxesLoader.data.value?.key === taxesKey.value ? taxesLoader.data.value.reconciliation : null));
const months = computed(() => (taxesLoader.data.value?.key === taxesKey.value ? taxesLoader.data.value.months : null));
const toPay = computed(() => (reconciliation.value ? amountsToPay(reconciliation.value) : null));

const receiptsKey = computed(() => (form.allocation === 'payment' && !isPeriodPending.value ? `${form.period_year}:${form.period_quarter}` : null));
const receiptsLoader = useLoader(async (signal) => {
    const key = receiptsKey.value;

    if (key === null) {
        return null;
    }

    const { data } = await api.get<ApiResponse<QuarterTaxPayments>>(path(`taxes/${form.period_year}/${form.period_quarter}/payments`), undefined, signal);

    return { key, data };
}, [profileId, receiptsKey]);
const receipts = computed(() => (receiptsLoader.data.value && receiptsLoader.data.value.key === receiptsKey.value ? receiptsLoader.data.value.data : null));

watch(receipts, (data) => {
    if (data && !data.rows.some((row) => row.type === 'payment' && row.payment_id === form.payment_id)) {
        form.payment_id = data.rows.find((row) => row.type === 'payment')?.payment_id ?? null;
    }
});

const receipt = computed(() => receipts.value?.rows.find((row) => row.type === 'payment' && row.payment_id === form.payment_id) ?? null);

/* Opening on the earliest quarter, which is due and not paid (last year's IV кв. in its first quarter) */
async function chooseDefaultPeriod(): Promise<void> {
    if (periodTouched.value || form.allocation !== 'quarter') {
        return;
    }

    const taxType = form.tax_type;
    const years = Number(query.year) ? [form.period_year] : (now.quarter === 1 ? [now.year - 1, now.year] : [now.year]);

    try {
        for (const year of years) {
            const { data } = await api.get<ApiResponse<TaxReconciliation>>(path(`taxes/${year}`), { tax_type: taxType });
            const amounts = amountsToPay(data);
            const due = [...data.quarters].sort((a, b) => a.quarter - b.quarter).find((quarter) => isToPay(quarter, amounts.get(quarter.quarter)));

            if (due) {
                if (!periodTouched.value && form.tax_type === taxType && form.allocation === 'quarter') {
                    form.period_year = year;
                    form.period_quarter = due.quarter;
                }

                return;
            }
        }
    } catch {
        // The period stays as it is; the balances' own loading reports what failed
    }
}

void chooseDefaultPeriod();
watch(() => form.tax_type, () => void chooseDefaultPeriod());

/* What is left to pay for the chosen period or receipt */
const remaining = computed<string | null>(() => {
    if (form.allocation === 'quarter') {
        return toPay.value?.get(form.period_quarter) ?? null;
    }

    if (form.allocation === 'month') {
        const month = months.value?.months.find((item) => item.month === form.period_month);

        if (!month) {
            return null;
        }

        const figures = month[form.tax_type];
        const left = subtractAmounts(figures.owed, figures.paid);

        return compareAmounts(left, '0') > 0 ? left : '0.00';
    }

    if (receipt.value?.type === 'payment' && form.tax_type !== 'social_contribution') {
        const tax = receipt.value[form.tax_type];
        const paid = sumAmounts(...tax.payments.filter((payment) => payment.status === 'paid').map((payment) => payment.amount));
        const left = subtractAmounts(tax.hint, paid);

        return compareAmounts(left, '0') > 0 ? left : '0.00';
    }

    return null;
});

watch(remaining, (value) => {
    if (!amountTouched.value && value !== null) {
        form.amount = compareAmounts(value, '0') > 0 ? value : '';
    }
});

const periodLabel = computed(() => {
    if (form.allocation === 'month') {
        return `${monthNameInText(form.period_month)} ${form.period_year}`;
    }

    if (form.allocation === 'payment') {
        return receipt.value ? t('надходження {date}', { date: formatDayMonth(receipt.value.received_at) }) : t('надходження');
    }

    return `${quarterLabel(form.period_quarter)}`;
});

/*
 * The budget's requisites for the tax and period, with the purpose filled in. ЄП and ВЗ are declared per quarter, so
 * their purpose names the quarter, also when a payment is allocated to a month; ЄСВ names the month.
 */
const canPay = computed(() => can('create', 'tax-payments'));

const requisiteQuery = computed(() => {
    const byMonth = form.allocation === 'month' && form.tax_type === 'social_contribution';
    const quarter = form.allocation === 'payment' && receipt.value
        ? quarterOf(receipt.value.received_at)
        : form.allocation === 'month' ? quarterOf(form.period_month) : form.period_quarter;

    return {
        tax_type: form.tax_type,
        period_year: form.period_year,
        period_quarter: byMonth ? undefined : quarter,
        period_month: byMonth ? form.period_month : undefined,
        date: form.paid_at || undefined,
    };
});
const requisiteKey = computed(() => JSON.stringify(requisiteQuery.value));
const requisiteLoader = useLoader(async (signal) => {
    const key = requisiteKey.value;

    if (!canPay.value) {
        return { key, requisite: null, missing: false };
    }

    try {
        const { data } = await api.get<ApiResponse<CurrentTaxPaymentRequisite>>(path('tax-payment-requisites/current'), requisiteQuery.value, signal);

        return { key, requisite: data, missing: false };
    } catch (caught) {
        if (caught instanceof ApiError && caught.status === 404) {
            return { key, requisite: null, missing: true };
        }

        throw caught;
    }
}, [profileId, requisiteKey, canPay]);
const requisite = computed(() => (requisiteLoader.data.value?.key === requisiteKey.value ? requisiteLoader.data.value.requisite : null));
const requisiteMissing = computed(() => requisiteLoader.data.value?.key === requisiteKey.value && requisiteLoader.data.value.missing);

/** Why the balances, receipts, accounts or requisites didn't load. */
const loadError = computed(() => {
    const failed = [accountsLoader.error.value, taxesLoader.error.value, receiptsLoader.error.value, requisiteLoader.error.value].find(Boolean);

    return failed ? errorMessage(failed) : null;
});

function retryLoading(): void {
    for (const loader of [accountsLoader, taxesLoader, receiptsLoader, requisiteLoader]) {
        if (loader.error.value) {
            void loader.reload();
        }
    }
}

const allRequisites = computed(() => (requisite.value
    ? [
        `${t('Отримувач')}: ${requisite.value.recipient_name}`,
        `IBAN: ${requisite.value.iban}`,
        `${t('Код отримувача')}: ${requisite.value.recipient_code}`,
        `${t('Призначення платежу')}: ${requisite.value.purpose}`,
        `${t('Сума')}: ${parseAmount(form.amount) ?? form.amount}`,
    ].join('\n')
    : ''));

const copiedAll = ref(false);

async function copyAll(): Promise<void> {
    try {
        await navigator.clipboard.writeText(allRequisites.value);
        copiedAll.value = true;
        setTimeout(() => (copiedAll.value = false), 1500);
    } catch {
        copiedAll.value = false;
    }
}

const accountOptions = computed(() => accounts.value
    .filter((account) => account.currency === 'UAH' && account.is_active)
    .map((account) => ({ value: account.id, label: account.kind === 'personal' ? t('{name} · особистий', { name: account.name }) : account.name })));

const receiptOptions = computed(() => (receipts.value?.rows ?? [])
    .filter((row) => row.type === 'payment')
    .map((row) => ({
        value: row.payment_id,
        label: `${formatDayMonth(row.received_at)} · ${row.invoice_number ?? row.description ?? ''} · ${formatAmount(row.uah_amount)} ₴`,
    })));

const allocationOptions = computed<{ value: TaxAllocation; label: string }[]>(() => (form.tax_type === 'social_contribution'
    ? [{ value: 'month', label: t('Місяць') }, { value: 'quarter', label: t('Квартал') }]
    : [{ value: 'payment', label: t('Надходження||одне') }, { value: 'month', label: t('Місяць') }, { value: 'quarter', label: t('Квартал') }]));

const years = computed(() => [now.year, now.year - 1, now.year - 2].map((value) => ({ value, label: String(value) })));

function onTaxChange(taxType: TaxType): void {
    if (taxType === 'social_contribution' && form.allocation === 'payment') {
        form.allocation = 'quarter';
    }
}

/** Where the form returns: the view of Податки, which shows what was paid. */
const returnTo = computed(() => ({
    name: 'taxes',
    query: {
        tax: form.tax_type,
        year: form.period_year,
        ...(form.allocation === 'payment'
            ? { view: 'receipts', quarter: form.period_quarter }
            : { view: form.allocation === 'month' ? 'months' : 'quarters' }),
    },
}));

/* Saving */
const idempotencyKey = newIdempotencyKey();
const saving = ref(false);
const saveError = ref<unknown>(null);

async function save(): Promise<void> {
    saving.value = true;
    saveError.value = null;

    try {
        await api.post(path('tax-payments'), {
            tax_type: form.tax_type,
            allocation: form.allocation,
            payment_id: form.allocation === 'payment' ? form.payment_id : null,
            period_year: form.allocation === 'payment' ? null : form.period_year,
            period_quarter: form.allocation === 'quarter' ? form.period_quarter : null,
            period_month: form.allocation === 'month' ? form.period_month : null,
            amount: parseAmount(form.amount) ?? form.amount,
            status: form.status,
            paid_at: form.paid_at || null,
            paid_from_account_id: form.paid_from_account_id,
            reference: form.reference.trim() || null,
        }, { 'Idempotency-Key': idempotencyKey });

        await router.replace(returnTo.value);
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

const generalError = computed(() => {
    const fields = ['amount', 'paid_at', 'paid_from_account_id', 'payment_id', 'period_quarter', 'period_month', 'period_year'];

    return saveError.value && !fields.some((field) => fieldError(saveError.value, field)) ? errorMessage(saveError.value) : null;
});
</script>

<template>
    <SubScreen :title="t('Сплата податку')" :back="returnTo">
        <div class="flex flex-col gap-1.5">
            <span class="text-13 font-medium text-ink-2">{{ t('Податок') }}</span>
            <SegmentedControl v-model="form.tax_type" :label="t('Податок')" size="lg" :options="taxOptions" @update:model-value="onTaxChange" />
        </div>

        <div class="flex flex-col gap-1.5">
            <span class="text-13 font-medium text-ink-2">{{ t('Зарахувати на') }}</span>
            <SegmentedControl v-model="form.allocation" :label="t('Зарахувати на')" size="lg" :options="allocationOptions" />
        </div>

        <div class="grid grid-cols-[2fr_1fr] gap-2.5">
            <SelectField
                v-if="form.allocation === 'month'"
                v-model="form.period_month"
                :label="t('Місяць')"
                :options="monthOptions"
                :error="fieldError(saveError, 'period_month')"
                @update:model-value="periodTouched = true"
            />
            <SelectField
                v-else
                v-model="form.period_quarter"
                :label="t('Квартал')"
                :options="quarterOptions"
                :error="fieldError(saveError, 'period_quarter')"
                @update:model-value="periodTouched = true"
            />
            <SelectField v-model="form.period_year" :label="t('Рік')" :options="years" @update:model-value="periodTouched = true" />
        </div>

        <SelectField
            v-if="form.allocation === 'payment'"
            v-model="form.payment_id"
            :label="t('Надходження||одне')"
            :placeholder="t('Надходжень у кварталі немає')"
            :options="receiptOptions"
            :error="fieldError(saveError, 'payment_id')"
        />

        <div v-if="remaining !== null" class="flex items-center justify-between gap-3 rounded-control px-4 py-3.5 text-15" :class="compareAmounts(remaining, '0') > 0 ? 'bg-warning-soft text-warning' : 'bg-positive-soft text-positive'">
            <span>{{ t('Залишок {tax} за {period}', { tax: TAX_TYPES[form.tax_type], period: periodLabel }) }}</span>
            <span class="num font-semibold">{{ formatMoney(remaining, 'UAH') }}</span>
        </div>

        <p v-if="loadError" class="m-0 flex flex-wrap items-center gap-x-2 text-14 text-negative" role="alert">
            {{ loadError }}
            <button type="button" class="min-h-11 text-14 font-medium text-accent" @click="retryLoading">{{ t('Повторити') }}</button>
        </p>

        <div class="grid grid-cols-2 gap-2.5">
            <TextField
                v-model="form.amount"
                :label="t('Сума, ₴')"
                numeric
                class="text-18 font-bold"
                :error="fieldError(saveError, 'amount')"
                @update:model-value="amountTouched = true"
            />
            <DateField v-model="form.paid_at" :label="t('Дата')" :error="fieldError(saveError, 'paid_at')" />
        </div>

        <div class="flex flex-col gap-1.5">
            <span class="text-13 font-medium text-ink-2">{{ t('Статус') }}</span>
            <SegmentedControl v-model="form.status" :label="t('Статус')" size="lg" :options="statusOptions" />
            <span class="text-12 text-ink-3">{{ t('«Надіслано» показується як очікуване й не зменшує борг') }}</span>
        </div>

        <SelectField
            v-model="form.paid_from_account_id"
            :label="t('Сплачено з рахунку')"
            :placeholder="t('Не вказувати')"
            clearable
            :options="accountOptions"
            :hint="t('За замовчуванням із налаштувань · лише активні рахунки в UAH')"
            :error="fieldError(saveError, 'paid_from_account_id')"
        />

        <section v-if="requisite" class="flex flex-col rounded-card border border-line bg-surface pr-1 pl-4">
            <div class="flex items-center justify-between py-2">
                <SectionTitle as="span">{{ t('Реквізити {tax}', { tax: TAX_TYPES[form.tax_type] }) }}</SectionTitle>
                <button type="button" class="flex min-h-11 items-center px-3 text-15 font-medium text-accent" @click="copyAll">
                    {{ copiedAll ? t('Скопійовано') : t('Копіювати все') }}
                </button>
            </div>
            <div class="flex flex-col divide-y divide-divider border-t border-divider">
                <CopyRow :label="t('Отримувач')" :value="requisite.recipient_name" :figure="false" />
                <CopyRow label="IBAN" :value="requisite.iban" />
                <CopyRow :label="t('Код отримувача')" :value="requisite.recipient_code" />
                <CopyRow :label="t('Призначення платежу')" :value="requisite.purpose" wrap />
            </div>
        </section>
        <p v-else-if="requisiteMissing" class="m-0 rounded-card border border-line bg-surface p-4 text-14 text-ink-2">
            {{ t('Реквізитів {tax} для цього періоду немає. Додайте їх у налаштуваннях податків.', { tax: TAX_TYPES[form.tax_type] }) }}
        </p>

        <TextField v-model="form.reference" :label="t('Номер платіжки')" :placeholder="t('Необов\'язково')" />

        <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>

        <template v-if="canPay" #footer>
            <PrimaryButton :busy="saving" :disabled="parseAmount(form.amount) === null || (form.allocation === 'payment' && !form.payment_id)" @click="save">
                {{ t('Зберегти сплату') }}
            </PrimaryButton>
        </template>
    </SubScreen>
</template>
