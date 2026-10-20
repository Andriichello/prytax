<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError, api, newIdempotencyKey } from '@/api/client';
import type {
    Account,
    ApiResponse,
    CurrentTaxPaymentRequisite,
    Paginated,
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
import { useSession } from '@/composables/useSession';
import { currentQuarter, formatDayMonth, monthName, quarterLabel, quarterOf, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { compareAmounts, formatAmount, formatMoney, parseAmount, subtractAmounts, sumAmounts } from '@/utils/money';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * Сплата податку (S-TaxPaymentNew): which tax, what it pays (a receipt, a month or a quarter), how much is left,
 * the amount and date, its status, the UAH account it left, and the budget requisites with the payment's purpose.
 */
const TAXES: TaxType[] = ['single_tax', 'military_levy', 'social_contribution'];
const TAX_OPTIONS = TAXES.map((type) => ({ value: type, label: TAX_TYPES[type] }));

const route = useRoute();
const router = useRouter();
const { can } = useSession();
const { profileId, path, settings } = useCurrentProfile();

const now = currentQuarter();
const query = route.query;

const form = reactive({
    tax_type: (TAXES.includes(query.tax as TaxType) ? query.tax : 'single_tax') as TaxType,
    allocation: (['payment', 'month', 'quarter'].includes(query.allocation as string) ? query.allocation : 'quarter') as TaxAllocation,
    payment_id: Number(query.payment) || null as number | null,
    period_year: Number(query.year) || now.year,
    period_quarter: Number(query.quarter) || now.quarter,
    period_month: Number(query.month) || (Number(query.quarter) ? Number(query.quarter) * 3 : new Date().getMonth() + 1),
    amount: typeof query.amount === 'string' ? query.amount : '',
    paid_at: today(),
    status: 'paid' as TaxPaymentStatus,
    paid_from_account_id: null as number | null,
    reference: '',
});
const amountTouched = ref(typeof query.amount === 'string');

if (form.tax_type === 'social_contribution' && form.allocation === 'payment') {
    form.allocation = 'quarter';
}

/* Reference data, loaded as the choice changes */
const accounts = ref<Account[]>([]);
const reconciliation = ref<TaxReconciliation | null>(null);
const months = ref<TaxMonths | null>(null);
const receipts = ref<QuarterTaxPayments | null>(null);
const requisite = ref<CurrentTaxPaymentRequisite | null>(null);
const requisiteMissing = ref(false);

void api.get<Paginated<Account>>(path('accounts'), { page: { size: 50 } }).then(({ data }) => {
    accounts.value = data;
    form.paid_from_account_id = settings.value?.taxes.default_account_id
        ?? data.find((account) => account.currency === 'UAH' && account.is_active && account.kind === 'fop_bank')?.id
        ?? null;
});

watch(() => [profileId.value, form.tax_type, form.period_year] as const, async ([, taxType, year]) => {
    reconciliation.value = null;
    months.value = null;

    const [loaded, monthly] = await Promise.all([
        api.get<ApiResponse<TaxReconciliation>>(path(`taxes/${year}`), { tax_type: taxType }),
        api.get<ApiResponse<TaxMonths>>(path(`taxes/${year}/months`)),
    ]);

    reconciliation.value = loaded.data;
    months.value = monthly.data;
}, { immediate: true });

watch(() => [form.allocation, form.period_year, form.period_quarter] as const, async ([allocation, year, quarter]) => {
    if (allocation !== 'payment') {
        return;
    }

    const { data } = await api.get<ApiResponse<QuarterTaxPayments>>(path(`taxes/${year}/${quarter}/payments`));

    receipts.value = data;

    if (!data.rows.some((row) => row.type === 'payment' && row.payment_id === form.payment_id)) {
        form.payment_id = data.rows.find((row) => row.type === 'payment')?.payment_id ?? null;
    }
}, { immediate: true });

const receipt = computed(() => receipts.value?.rows.find((row) => row.type === 'payment' && row.payment_id === form.payment_id) ?? null);

/* What is left to pay for the chosen period or receipt */
const remaining = computed<string | null>(() => {
    if (form.allocation === 'quarter') {
        const quarter = reconciliation.value?.quarters.find((item) => item.quarter === form.period_quarter);

        return quarter ? (compareAmounts(quarter.balance, '0') < 0 ? quarter.balance.replace('-', '') : '0.00') : null;
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
    if (!amountTouched.value && value !== null && compareAmounts(value, '0') > 0) {
        form.amount = value;
    }
});

const periodLabel = computed(() => {
    if (form.allocation === 'month') {
        return `${monthName(form.period_month).toLowerCase()} ${form.period_year}`;
    }

    if (form.allocation === 'payment') {
        return receipt.value ? `надходження ${formatDayMonth(receipt.value.received_at)}` : 'надходження';
    }

    return `${quarterLabel(form.period_quarter)}`;
});

/* The budget's requisites for the tax and period, with the purpose filled in */
watch(() => [form.tax_type, form.allocation, form.period_year, form.period_quarter, form.period_month, receipt.value?.received_at, form.paid_at] as const, async () => {
    requisiteMissing.value = false;

    const month = form.allocation === 'month' ? form.period_month : null;
    const quarter = form.allocation === 'payment' && receipt.value ? quarterOf(receipt.value.received_at) : form.period_quarter;

    try {
        const { data } = await api.get<ApiResponse<CurrentTaxPaymentRequisite>>(path('tax-payment-requisites/current'), {
            tax_type: form.tax_type,
            period_year: form.period_year,
            period_quarter: month ? undefined : quarter,
            period_month: month ?? undefined,
            date: form.paid_at || undefined,
        });

        requisite.value = data;
    } catch (caught) {
        requisite.value = null;
        requisiteMissing.value = caught instanceof ApiError && caught.status === 404;
    }
}, { immediate: true });

const allRequisites = computed(() => (requisite.value
    ? [
        `Отримувач: ${requisite.value.recipient_name}`,
        `IBAN: ${requisite.value.iban}`,
        `Код отримувача: ${requisite.value.recipient_code}`,
        `Призначення платежу: ${requisite.value.purpose}`,
        `Сума: ${parseAmount(form.amount) ?? form.amount}`,
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
    .map((account) => ({ value: account.id, label: account.kind === 'personal' ? `${account.name} · особистий` : account.name })));

const receiptOptions = computed(() => (receipts.value?.rows ?? [])
    .filter((row) => row.type === 'payment')
    .map((row) => ({
        value: row.payment_id,
        label: `${formatDayMonth(row.received_at)} · ${row.invoice_number ?? row.description ?? ''} · ${formatAmount(row.uah_amount)} ₴`,
    })));

const years = computed(() => [now.year, now.year - 1, now.year - 2].map((value) => ({ value, label: String(value) })));

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

        await router.replace({ name: 'taxes', query: { tax: form.tax_type, year: form.period_year } });
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
    <SubScreen title="Сплата податку" :back="{ name: 'taxes', query: { tax: form.tax_type, year: form.period_year } }">
        <div class="flex flex-col gap-1.5">
            <span class="text-13 font-medium text-ink-2">Податок</span>
            <SegmentedControl
                v-model="form.tax_type"
                label="Податок"
                size="lg"
                :options="TAX_OPTIONS"
                @update:model-value="$event === 'social_contribution' && form.allocation === 'payment' && (form.allocation = 'quarter')"
            />
        </div>

        <div class="flex flex-col gap-1.5">
            <span class="text-13 font-medium text-ink-2">Зарахувати на</span>
            <SegmentedControl
                v-model="form.allocation"
                label="Зарахувати на"
                size="lg"
                :options="form.tax_type === 'social_contribution'
                    ? [{ value: 'month', label: 'Місяць' }, { value: 'quarter', label: 'Квартал' }]
                    : [{ value: 'payment', label: 'Надходження' }, { value: 'month', label: 'Місяць' }, { value: 'quarter', label: 'Квартал' }]"
            />
        </div>

        <div class="grid grid-cols-[2fr_1fr] gap-2.5">
            <SelectField
                v-if="form.allocation === 'month'"
                v-model="form.period_month"
                label="Місяць"
                :options="Array.from({ length: 12 }, (_, index) => ({ value: index + 1, label: monthName(index + 1) }))"
                :error="fieldError(saveError, 'period_month')"
            />
            <SelectField
                v-else
                v-model="form.period_quarter"
                label="Квартал"
                :options="[1, 2, 3, 4].map((value) => ({ value, label: quarterLabel(value) }))"
                :error="fieldError(saveError, 'period_quarter')"
            />
            <SelectField v-model="form.period_year" label="Рік" :options="years" />
        </div>

        <SelectField
            v-if="form.allocation === 'payment'"
            v-model="form.payment_id"
            label="Надходження"
            placeholder="Надходжень у кварталі немає"
            :options="receiptOptions"
            :error="fieldError(saveError, 'payment_id')"
        />

        <div v-if="remaining !== null" class="flex items-center justify-between gap-3 rounded-control px-4 py-3.5 text-15" :class="compareAmounts(remaining, '0') > 0 ? 'bg-warning-soft text-warning' : 'bg-positive-soft text-positive'">
            <span>Залишок {{ TAX_TYPES[form.tax_type] }} за {{ periodLabel }}</span>
            <span class="num font-semibold">{{ formatMoney(remaining, 'UAH') }}</span>
        </div>

        <div class="grid grid-cols-2 gap-2.5">
            <TextField
                v-model="form.amount"
                label="Сума, ₴"
                numeric
                class="text-18 font-bold"
                :error="fieldError(saveError, 'amount')"
                @update:model-value="amountTouched = true"
            />
            <DateField v-model="form.paid_at" label="Дата" :error="fieldError(saveError, 'paid_at')" />
        </div>

        <div class="flex flex-col gap-1.5">
            <span class="text-13 font-medium text-ink-2">Статус</span>
            <SegmentedControl
                v-model="form.status"
                label="Статус"
                size="lg"
                :options="[{ value: 'sent', label: 'Надіслано' }, { value: 'paid', label: 'Сплачено' }, { value: 'failed', label: 'Не пройшов' }]"
            />
            <span class="text-12 text-ink-3">«Надіслано» показується як очікуване й не зменшує борг</span>
        </div>

        <SelectField
            v-model="form.paid_from_account_id"
            label="Сплачено з рахунку"
            placeholder="Оберіть рахунок"
            :options="accountOptions"
            hint="За замовчуванням із налаштувань · лише активні рахунки в UAH"
            :error="fieldError(saveError, 'paid_from_account_id')"
        />

        <section v-if="requisite" class="flex flex-col rounded-card border border-line bg-surface pr-1 pl-4">
            <div class="flex items-center justify-between py-2">
                <SectionTitle as="span">Реквізити {{ TAX_TYPES[form.tax_type] }}</SectionTitle>
                <button type="button" class="flex min-h-11 items-center px-3 text-15 font-medium text-accent" @click="copyAll">
                    {{ copiedAll ? 'Скопійовано' : 'Копіювати все' }}
                </button>
            </div>
            <div class="flex flex-col divide-y divide-divider border-t border-divider">
                <CopyRow label="Отримувач" :value="requisite.recipient_name" :figure="false" />
                <CopyRow label="IBAN" :value="requisite.iban" />
                <CopyRow label="Код отримувача" :value="requisite.recipient_code" />
                <CopyRow label="Призначення платежу" :value="requisite.purpose" wrap />
            </div>
        </section>
        <p v-else-if="requisiteMissing" class="m-0 rounded-card border border-line bg-surface p-4 text-14 text-ink-2">
            Реквізитів {{ TAX_TYPES[form.tax_type] }} для цього періоду немає. Додайте їх у налаштуваннях податків.
        </p>

        <TextField v-model="form.reference" label="Номер платіжки" placeholder="Необов'язково" />

        <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>

        <template v-if="can('create', 'tax-payments')" #footer>
            <PrimaryButton :busy="saving" :disabled="parseAmount(form.amount) === null || (form.allocation === 'payment' && !form.payment_id)" @click="save">
                Зберегти сплату
            </PrimaryButton>
        </template>
    </SubScreen>
</template>
