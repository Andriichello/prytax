<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, newIdempotencyKey } from '@/api/client';
import type { Account, ApiResponse, TaxMonths, TaxPayment } from '@/api/types';
import TextField from '@/components/form/TextField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import TaxPaymentFields, { type TaxPaymentDraft } from '@/components/taxes/TaxPaymentFields.vue';
import ConfirmSheet from '@/components/ui/ConfirmSheet.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PeriodStepper from '@/components/ui/PeriodStepper.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { currentQuarter, dateParts, formatDate, formatDayMonth, formatMonthYear, monthNameInText, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { compareAmounts, formatAmount, parseAmount, subtractAmounts, sumAmounts } from '@/utils/money';

/**
 * Сплата ЄСВ (M-TaxPaymentEsv, design.md §8.4, §16.5): a month's ЄСВ. The month (‹ Жовтень 2026 ›), with its quarter's
 * deadline, as ЄСВ is due with its quarter however it is paid, and what was paid for the month before (or already
 * for this one); the amount, the month's minimum unless typed; then the date, the status, the account, the
 * requisites and the payment order's number. Without a month in the link, the form opens on the earliest month of the
 * quarter, which isn't fully paid.
 */
const route = useRoute();
const router = useRouter();
const { can } = useSession();
const { profileId, path, settings } = useCurrentProfile();

const taxPaymentId = computed(() => (route.params.id ? Number(route.params.id) : null));

const now = currentQuarter();
const year = ref(Number(route.query.year) || now.year);
const month = ref(Number(route.query.month) || dateParts(today()).month);
/** Given by the link or the record, or stepped to: the month then isn't moved to the earliest unpaid one. */
const monthChosen = ref(Number(route.query.month) > 0);

const existing = ref<TaxPayment | null>(null);
const accounts = ref<Account[]>([]);
const amount = ref('');
const amountTouched = ref(false);
const form = ref<TaxPaymentDraft>({ paid_at: today(), status: 'paid', paid_from_account_id: null, original_amount: '', reference: '' });

const { error: loadError, loading, reload } = useLoader(async (signal) => {
    const [record, accountList] = await Promise.all([
        taxPaymentId.value === null
            ? Promise.resolve(null)
            : api.get<ApiResponse<TaxPayment>>(path(`tax-payments/${taxPaymentId.value}`), undefined, signal).then(({ data }) => data),
        api.all<Account>(path('accounts'), {}, signal),
    ]);

    existing.value = record;
    accounts.value = accountList;

    if (record) {
        year.value = record.period_year;
        month.value = record.period_month ?? month.value;
        monthChosen.value = true;
        amount.value = record.amount;
        amountTouched.value = true;
        form.value = {
            paid_at: record.paid_at ?? '',
            status: record.status,
            paid_from_account_id: record.paid_from_account_id,
            original_amount: record.original_amount ?? '',
            reference: record.reference ?? '',
        };
    } else {
        form.value.paid_from_account_id = settings.value?.taxes.default_account_id ?? null;
    }

    return true;
}, [profileId, taxPaymentId]);

/* The year's months (the minimum, what is paid, the quarter's deadline), and the ЄСВ payments of it and the year before */
const monthsLoader = useLoader(async (signal) => {
    const key = year.value;
    const [months, payments] = await Promise.all([
        api.get<ApiResponse<TaxMonths>>(path(`taxes/${key}/months`), undefined, signal),
        api.all<TaxPayment>(path('tax-payments'), {
            filter: { tax_type: 'social_contribution', period_year: { in: [key - 1, key] } },
        }, signal),
    ]);

    return { key, months: months.data.months, payments };
}, [profileId, year]);
const loaded = computed(() => (monthsLoader.data.value?.key === year.value ? monthsLoader.data.value : null));
const figures = computed(() => loaded.value?.months.find((item) => item.month === month.value)?.social_contribution ?? null);

/* A new payment opens on the earliest month of the quarter, which isn't fully paid */
watch(loaded, (data) => {
    if (!data || monthChosen.value || existing.value) {
        return;
    }

    const quarter = Number(route.query.quarter) || (year.value === now.year ? now.quarter : 1);
    const unpaid = data.months
        .filter((item) => item.quarter === quarter)
        .find((item) => compareAmounts(sumAmounts(item.social_contribution.paid, item.social_contribution.pending), item.social_contribution.owed) < 0);

    if (unpaid) {
        month.value = unpaid.month;
    }

    monthChosen.value = true;
});

/* The amount is the month's minimum, less what is paid for it, until it is typed */
watch(figures, (value) => {
    if (!value || amountTouched.value) {
        return;
    }

    const left = subtractAmounts(value.owed, sumAmounts(value.paid, value.pending));

    amount.value = compareAmounts(left, '0') > 0 ? left : value.owed;
});

function step(by: number): void {
    const index = year.value * 12 + (month.value - 1) + by;

    year.value = Math.floor(index / 12);
    month.value = (index % 12) + 1;
    monthChosen.value = true;
}

/** The payments of a month, other than the one edited, which didn't fail. */
const paymentsOf = (forYear: number, forMonth: number): TaxPayment[] => (monthsLoader.data.value?.payments ?? [])
    .filter((item) => item.period_year === forYear && item.period_month === forMonth && item.status !== 'failed' && item.id !== existing.value?.id);

/** «За вересень уже сплачено 19.10 · 1 902.34 ₴»: this month's payments, else the month before's. */
const alreadyPaid = computed(() => {
    const own = paymentsOf(year.value, month.value);
    const previous = month.value === 1 ? { year: year.value - 1, month: 12 } : { year: year.value, month: month.value - 1 };
    const [shown, forMonth, isOwn] = own.length
        ? [own, month.value, true]
        : [paymentsOf(previous.year, previous.month), previous.month, false];

    if (shown.length === 0) {
        return null;
    }

    const last = [...shown].sort((a, b) => (b.paid_at ?? '').localeCompare(a.paid_at ?? ''))[0]!;

    return {
        isOwn,
        text: t('accounting.taxPayment.alreadyPaidFor', {
            month: monthNameInText(forMonth),
            date: formatDayMonth(last.paid_at),
            amount: `${formatAmount(sumAmounts(...shown.map((item) => item.amount)))} ₴`,
        }),
    };
});

const requisiteQuery = computed(() => ({
    tax_type: 'social_contribution' as const,
    period_year: year.value,
    period_month: month.value,
    date: form.value.paid_at || undefined,
}));

const isForeign = computed(() => {
    const account = accounts.value.find((item) => item.id === form.value.paid_from_account_id);

    return !!account && account.currency !== 'UAH';
});

function leave(): void {
    if (window.history.state?.back) {
        router.back();
    } else {
        void router.replace({ name: 'taxes' });
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
        tax_type: 'social_contribution',
        period_year: year.value,
        period_month: month.value,
        amount: parseAmount(amount.value) ?? amount.value,
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
const amountError = computed(() => fieldError(saveError.value, 'amount') ?? fieldError(saveError.value, 'period_month'));
const generalError = computed(() => (saveError.value && !amountError.value && !Object.values(fieldErrors.value).some(Boolean)
    ? errorMessage(saveError.value)
    : null));

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
    <SubScreen :title="t('accounting.taxPayment.esvTitle')" :back="{ name: 'taxes' }">
        <LoadState :loading="loading && accounts.length === 0" :error="loadError ?? monthsLoader.error.value" @retry="reload(); monthsLoader.reload()">
            <div class="flex flex-col gap-1.5">
                <span class="text-13 font-medium text-ink-2">{{ t('core.common.month') }}</span>
                <PeriodStepper
                    :label="formatMonthYear(year, month)"
                    :sublabel="figures?.due_at ? t('accounting.taxPayment.payBy', { date: formatDate(figures.due_at) }) : undefined"
                    @previous="step(-1)"
                    @next="step(1)"
                />
                <span v-if="alreadyPaid" class="text-13" :class="alreadyPaid.isOwn ? 'text-warning-text' : 'text-ink-3'">{{ alreadyPaid.text }}</span>
            </div>

            <TextField
                v-model="amount"
                :label="t('accounting.taxPayment.amountUah')"
                numeric
                class="text-18 font-semibold"
                :hint="figures ? t('accounting.taxPayment.esvMinimum', { amount: formatAmount(figures.owed) }) : undefined"
                :error="amountError"
                @update:model-value="amountTouched = true"
            />

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
        </LoadState>

        <template v-if="can(existing ? 'update' : 'create', 'tax-payments')" #footer>
            <PrimaryButton :busy="saving" :disabled="loading || parseAmount(amount) === null" @click="save">
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
