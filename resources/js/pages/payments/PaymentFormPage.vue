<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError, api, newIdempotencyKey } from '@/api/client';
import type { Account, ApiResponse, Invoice, Paginated, Payment, PaymentKind, PaymentPreview, PaymentSavedResponse } from '@/api/types';
import DateField from '@/components/form/DateField.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextAreaField from '@/components/form/TextAreaField.vue';
import TextField from '@/components/form/TextField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import InvoicePickerSheet from '@/components/payment/InvoicePickerSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { formatDate, monthName, quarterLabel, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { compareAmounts, formatAmount, formatMoney, parseAmount, subtractAmounts } from '@/utils/money';

/**
 * Нове надходження (S-PaymentNew), and its editing: income of an invoice, an advance or other income, where it
 * landed, and — before saving — what will be stored: the hryvnias at the NBU rate, ЄП and ВЗ, the quarter.
 */
const KIND_ACCOUNT_LABELS: Record<string, string> = {
    platform: 'платформа',
    fop_bank: 'ФОП',
    foreign_bank: 'іноземний банк',
};

const route = useRoute();
const router = useRouter();
const { profileId, path } = useCurrentProfile();

const paymentId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isNew = computed(() => paymentId.value === null);

const invoices = ref<Invoice[]>([]);
const accounts = ref<Account[]>([]);
const existing = ref<Payment | null>(null);

const form = reactive({
    kind: 'invoice' as PaymentKind,
    invoice_id: null as number | null,
    description: '',
    amount: '',
    client_fee_amount: '',
    received_to_account_id: null as number | null,
    received_amount: '',
    received_at: today(),
    nbu_rate: '',
    correction_note: '',
});
const touched = reactive({ amount: false, account: false, received_amount: false });
const markPaid = ref(true);

const { error: loadError, loading, reload } = useLoader(async (signal) => {
    const [open, accountList, payment] = await Promise.all([
        api.get<Paginated<Invoice>>(path('invoices'), {
            filter: { status: { in: ['ready', 'sent'] } },
            include: ['client'],
            sort: 'issued_at',
            page: { size: 50 },
        }, signal),
        api.get<Paginated<Account>>(path('accounts'), { page: { size: 50 } }, signal),
        paymentId.value === null
            ? Promise.resolve(null)
            : api.get<ApiResponse<Payment>>(path(`payments/${paymentId.value}`), { include: ['invoice.client'] }, signal).then(({ data }) => data),
    ]);

    accounts.value = accountList.data;
    existing.value = payment;
    invoices.value = payment?.invoice && !open.data.some((invoice) => invoice.id === payment.invoice_id)
        ? [payment.invoice, ...open.data]
        : open.data;

    if (payment) {
        Object.assign(form, {
            kind: payment.kind,
            invoice_id: payment.invoice_id,
            description: payment.description ?? '',
            amount: payment.amount,
            client_fee_amount: payment.client_fee_amount ?? '',
            received_to_account_id: payment.received_to_account_id,
            received_amount: payment.received_amount,
            received_at: payment.received_at,
            nbu_rate: payment.rate_is_manual ? payment.nbu_rate : '',
        });
        Object.assign(touched, { amount: true, account: true, received_amount: true });
        markPaid.value = false;

        return;
    }

    const wanted = Number(route.query.invoice);

    form.kind = route.query.kind === 'advance' || route.query.kind === 'other' ? route.query.kind : 'invoice';

    if (wanted && invoices.value.some((invoice) => invoice.id === wanted)) {
        form.invoice_id = wanted;
    }
}, [profileId, paymentId]);

const invoice = computed(() => invoices.value.find((item) => item.id === form.invoice_id) ?? null);
const account = computed(() => accounts.value.find((item) => item.id === form.received_to_account_id) ?? null);
const currency = computed(() => (form.kind === 'invoice' ? invoice.value?.currency : account.value?.currency) ?? 'USD');
const sameCurrency = computed(() => !account.value || account.value.currency === currency.value);
const remaining = computed(() => (invoice.value ? subtractAmounts(invoice.value.total, invoice.value.paid_amount ?? '0') : null));

/* An invoice brings its remaining amount and its receiving account, unless they were set by hand. */
watch(invoice, (chosen) => {
    if (!chosen || !isNew.value) {
        return;
    }

    if (!touched.amount && remaining.value) {
        form.amount = remaining.value;
    }

    if (!touched.account) {
        form.received_to_account_id = chosen.receiving_account_id;
    }
});

/* What arrived follows the amount in the same currency, until it is typed. */
watch(() => [form.amount, sameCurrency.value] as const, ([amount, same]) => {
    if (!touched.received_amount && same) {
        form.received_amount = amount;
    }
});

const accountOptions = computed(() => accounts.value
    .filter((item) => (item.is_active && item.kind !== 'personal') || item.id === form.received_to_account_id)
    .map((item) => ({ value: item.id, label: `${item.name} · ${KIND_ACCOUNT_LABELS[item.kind] ?? item.kind}` })));

function body(): Record<string, unknown> {
    return {
        kind: form.kind,
        invoice_id: form.kind === 'invoice' ? form.invoice_id : null,
        description: form.kind === 'invoice' ? null : form.description.trim() || null,
        amount: parseAmount(form.amount) ?? form.amount,
        client_fee_amount: parseAmount(form.client_fee_amount),
        received_to_account_id: form.received_to_account_id,
        received_amount: parseAmount(form.received_amount),
        received_at: form.received_at,
        nbu_rate: parseAmount(form.nbu_rate),
        correction_note: form.correction_note.trim() || null,
    };
}

/* The preview: what saving will store, asked again as the form changes. */
const preview = ref<PaymentPreview | null>(null);
const previewError = ref<unknown>(null);
const previewing = ref(false);
let previewTimer: ReturnType<typeof setTimeout> | undefined;
let previewRun = 0;

const ready = computed(() => !loading.value
    && parseAmount(form.amount) !== null
    && form.received_to_account_id !== null
    && !!form.received_at
    && (form.kind !== 'invoice' || form.invoice_id !== null)
    && (sameCurrency.value || parseAmount(form.received_amount) !== null));

watch(() => [ready.value, JSON.stringify(body())], () => {
    clearTimeout(previewTimer);

    if (!ready.value) {
        preview.value = null;

        return;
    }

    previewTimer = setTimeout(async () => {
        const run = ++previewRun;

        previewing.value = true;

        try {
            const { data } = await api.post<ApiResponse<PaymentPreview>>(path('payments/preview'), body());

            if (run === previewRun) {
                preview.value = data;
                previewError.value = null;
            }
        } catch (caught) {
            if (run === previewRun) {
                preview.value = null;
                previewError.value = caught;
            }
        } finally {
            if (run === previewRun) {
                previewing.value = false;
            }
        }
    }, 350);
});

/** The NBU has no rate for the date: the form asks for one (the API takes a typed rate only then). */
const needsManualRate = computed(() => !!fieldError(previewError.value, 'nbu_rate') || !!existing.value?.rate_is_manual || form.nbu_rate !== '');
const lockedQuarter = computed(() => preview.value?.locked_quarter ?? null);
const coverage = computed(() => preview.value?.invoice ?? null);
const percentOfIncome = (tax: string): number => (preview.value && Number(preview.value.uah_amount) > 0
    ? Math.round((Number(tax) / Number(preview.value.uah_amount)) * 100)
    : 0);

/* Saving */
const idempotencyKey = newIdempotencyKey();
const saving = ref(false);
const saveError = ref<unknown>(null);

async function save(): Promise<void> {
    saving.value = true;
    saveError.value = null;

    try {
        const response = existing.value === null
            ? await api.post<PaymentSavedResponse>(path('payments'), body(), { 'Idempotency-Key': idempotencyKey })
            : await api.patch<PaymentSavedResponse>(path(`payments/${existing.value.id}`), body());

        const covered = response.meta.invoice;

        if (isNew.value && markPaid.value && covered?.covers_total && covered.status !== 'paid') {
            await api.post(path(`invoices/${covered.id}/pay`), { paid_at: form.received_at }).catch(() => undefined);
        }

        await router.replace({ name: 'payment', params: { id: response.data.id } });
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

const picking = ref(false);
const shownError = computed(() => saveError.value ?? previewError.value);
const generalError = computed(() => {
    const error = shownError.value;

    if (!error) {
        return null;
    }

    const fields = ['invoice_id', 'amount', 'client_fee_amount', 'received_to_account_id', 'received_amount', 'received_at', 'nbu_rate', 'description'];

    if (error instanceof ApiError && error.status === 422 && fields.some((field) => fieldError(error, field))) {
        return null;
    }

    return errorMessage(error);
});
</script>

<template>
    <SubScreen :title="isNew ? 'Нове надходження' : 'Редагування надходження'" :back="isNew ? { name: 'home' } : { name: 'payment', params: { id: paymentId } }">
        <LoadState :loading="loading" :error="loadError" @retry="reload">
            <SegmentedControl
                v-if="isNew"
                v-model="form.kind"
                label="Тип надходження"
                :options="[
                    { value: 'invoice', label: 'За інвойсом' },
                    { value: 'advance', label: 'Аванс' },
                    { value: 'other', label: 'Інший дохід' },
                ]"
            />

            <div v-if="form.kind === 'invoice'" class="flex flex-col gap-1.5">
                <span id="payment-invoice" class="text-13 font-medium text-ink-2">Інвойс</span>
                <button
                    type="button"
                    aria-labelledby="payment-invoice"
                    class="flex w-full items-center gap-3 rounded-control border bg-surface px-3.5 py-3 text-left"
                    :class="fieldError(shownError, 'invoice_id') ? 'border-negative' : 'border-line-input'"
                    :disabled="!isNew"
                    @click="picking = true"
                >
                    <span v-if="invoice" class="flex min-w-0 flex-1 flex-col gap-[3px]">
                        <span class="num text-16 font-semibold text-accent">{{ invoice.number }}</span>
                        <span class="truncate text-15 font-light text-ink">{{ invoice.client?.name }}</span>
                    </span>
                    <span v-else class="flex-1 text-16 text-ink-3">Оберіть інвойс</span>
                    <Icon v-if="isNew" name="chevronDown" :size="18" class="text-ink-2" />
                </button>
                <span v-if="invoice" class="text-12 text-ink-3">
                    Отримано <span class="num">{{ formatAmount(invoice.paid_amount ?? '0') }} / {{ formatMoney(invoice.total, invoice.currency) }}</span>
                    · залишок <span class="num">{{ formatAmount(remaining) }}</span>
                </span>
                <span v-if="fieldError(shownError, 'invoice_id')" class="text-12 text-negative">{{ fieldError(shownError, 'invoice_id') }}</span>
            </div>

            <TextField
                v-else
                v-model="form.description"
                :label="form.kind === 'advance' ? 'Від кого й за що' : 'Опис доходу'"
                :placeholder="form.kind === 'advance' ? 'Аванс від Brightline за листопад' : 'Напр., продаж обладнання'"
                :error="fieldError(shownError, 'description')"
            />

            <section class="flex flex-col gap-3">
                <SectionTitle>Дохід</SectionTitle>
                <TextField
                    v-model="form.amount"
                    :label="form.kind === 'invoice' ? `Сума за інвойсом, ${currency}` : `Сума, ${currency}`"
                    numeric
                    class="h-14 text-20 font-semibold"
                    hint="База оподаткування — повна сума, навіть якщо банк утримав комісію"
                    :error="fieldError(shownError, 'amount')"
                    @update:model-value="touched.amount = true"
                />
                <TextField
                    v-model="form.client_fee_amount"
                    label="Комісія, сплачена клієнтом понад суму"
                    numeric
                    placeholder="0.00"
                    hint="Не є доходом, лише фіксується"
                    :error="fieldError(shownError, 'client_fee_amount')"
                />
            </section>

            <section class="flex flex-col gap-3">
                <SectionTitle>Зарахування</SectionTitle>
                <SelectField
                    v-model="form.received_to_account_id"
                    label="Зараховано на"
                    placeholder="Оберіть рахунок"
                    :options="accountOptions"
                    :error="fieldError(shownError, 'received_to_account_id')"
                    @update:model-value="touched.account = true"
                />
                <div class="grid grid-cols-2 gap-2.5">
                    <TextField
                        v-model="form.received_amount"
                        :label="`Отримано, ${account?.currency ?? currency}`"
                        numeric
                        :error="fieldError(shownError, 'received_amount')"
                        @update:model-value="touched.received_amount = true"
                    />
                    <DateField
                        v-model="form.received_at"
                        label="Дата зарахування"
                        :max="today()"
                        :error="fieldError(shownError, 'received_at')"
                    />
                </div>
                <div
                    v-if="preview?.receipt_fee && compareAmounts(preview.receipt_fee, '0') !== 0"
                    class="flex items-center justify-between rounded-control bg-muted px-3.5 py-3 text-13 text-ink-2"
                >
                    <span>Комісія при отриманні</span>
                    <span class="num font-semibold text-ink">{{ formatMoney(preview.receipt_fee, currency) }}</span>
                </div>
                <p class="m-0 text-12 leading-[1.45] text-ink-3">Дата доходу — день зарахування на платформу (позиція ДПС).</p>
            </section>

            <TextField
                v-if="needsManualRate"
                v-model="form.nbu_rate"
                :label="`Курс, ₴ за 1 ${currency}`"
                numeric
                placeholder="41.8710"
                hint="Вручну — лише поки НБУ не опублікував курс на цю дату; потім запис візьме курс НБУ."
                :error="fieldError(shownError, 'nbu_rate')"
            />

            <section
                v-if="preview"
                aria-label="Податковий знімок"
                class="flex flex-col gap-3 rounded-card border border-line bg-surface p-4"
                :class="previewing ? 'opacity-60' : ''"
            >
                <div class="flex items-center justify-between">
                    <SectionTitle as="span">Буде збережено</SectionTitle>
                    <span class="rounded-badge bg-accent-soft px-2 py-[3px] text-12 font-semibold text-accent-hover">
                        {{ quarterLabel(preview.period_quarter) }} · {{ monthName(preview.period_month).toLowerCase() }}
                    </span>
                </div>
                <div class="flex flex-col gap-0.5">
                    <span class="num text-26 font-semibold tracking-[-0.02em]">{{ formatMoney(preview.uah_amount, 'UAH') }}</span>
                    <span v-if="preview.currency !== 'UAH'" class="num text-13 text-ink-3">
                        {{ preview.currency }} {{ formatAmount(preview.amount) }} @ {{ formatAmount(preview.nbu_rate) }}
                        · {{ preview.rate_is_manual ? 'вручну' : 'НБУ' }} {{ formatDate(preview.nbu_rate_date) }}
                    </span>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="flex flex-col gap-1 rounded-control bg-paper p-2.5">
                        <span class="text-12 text-ink-3">ЄП {{ percentOfIncome(preview.single_tax_uah) }} %</span>
                        <span class="num text-15 font-semibold">{{ formatAmount(preview.single_tax_uah) }}</span>
                    </div>
                    <div class="flex flex-col gap-1 rounded-control bg-paper p-2.5">
                        <span class="text-12 text-ink-3">ВЗ {{ percentOfIncome(preview.military_levy_uah) }} %</span>
                        <span class="num text-15 font-semibold">{{ formatAmount(preview.military_levy_uah) }}</span>
                    </div>
                </div>
            </section>

            <TextAreaField
                v-if="lockedQuarter"
                v-model="form.correction_note"
                :label="`${quarterLabel(lockedQuarter.quarter)} ${lockedQuarter.year} уже задекларовано — що уточнюєте?`"
                placeholder="Напр., надходження зараховано пізніше, ніж записано"
                :rows="2"
            />

            <label
                v-if="isNew && coverage?.covers_total && coverage.status !== 'paid'"
                class="flex items-start gap-3 rounded-button bg-positive-soft p-3.5 text-14 leading-[1.45] text-[#0f4d2d]"
            >
                <input v-model="markPaid" type="checkbox" class="m-0 mt-px size-5 shrink-0 accent-positive">
                <span class="flex flex-col gap-0.5">
                    <strong class="font-semibold">Інвойс буде оплачено повністю</strong>
                    <span>Позначити оплаченим з датою {{ formatDate(form.received_at) }}</span>
                </span>
            </label>
            <p v-if="coverage?.overpaid" class="m-0 rounded-button bg-warning-soft p-3.5 text-14 leading-[1.45] text-warning">
                Сума перевищує залишок інвойсу. Перевірте суму або запишіть різницю як інший дохід.
            </p>

            <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
        </LoadState>

        <template #footer>
            <PrimaryButton
                :busy="saving"
                :disabled="loading || !ready || (lockedQuarter !== null && !form.correction_note.trim())"
                @click="save"
            >
                {{ isNew ? 'Зберегти надходження' : 'Зберегти зміни' }}
            </PrimaryButton>
        </template>

        <InvoicePickerSheet
            v-model:open="picking"
            :invoices="invoices"
            :selected-id="form.invoice_id"
            @select="form.invoice_id = $event.id"
        />
    </SubScreen>
</template>
