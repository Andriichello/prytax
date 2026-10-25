<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError, api, newIdempotencyKey } from '@/api/client';
import type { Account, ApiResponse, Client, Contract, Invoice, InvoiceCoverage, Payment, PaymentGroupResponse, PaymentPreview, PaymentSavedResponse } from '@/api/types';
import DateField from '@/components/form/DateField.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextAreaField from '@/components/form/TextAreaField.vue';
import TextField from '@/components/form/TextField.vue';
import VirtualMarker from '@/components/invoice/VirtualMarker.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import InvoicePickerSheet from '@/components/payment/InvoicePickerSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import Translated from '@/components/ui/Translated.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useSession } from '@/composables/useSession';
import { useLoader } from '@/composables/useLoader';
import { t } from '@/i18n';
import { accountLabel } from '@/utils/accounts';
import { formatDate, monthNameInText, quarterLabel, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { compareAmounts, formatAmount, formatMoney, parseAmount, subtractAmounts, sumAmounts } from '@/utils/money';
import { unpaidStatusOf } from '@/utils/statuses';

/**
 * Нове надходження (M-PaymentNew, M-VPaymentNew), and its editing: income of an invoice, or of a payment without an
 * invoice («Без інвойсу»: the client, the contract, whose currency it takes, the service period and «За що», which
 * become its virtual invoice, design.md §4.2), where it landed, and — before saving — what will be stored: the
 * hryvnias at the NBU rate, ЄП and ВЗ, the quarter. One wire, which covers several invoices of one currency, is
 * recorded as one receipt of each (§6.1). Editing may move a receipt to another invoice of its currency (the one
 * replacing a cancelled invoice, or an issued one, which a payment without an invoice is attached to).
 */
const KIND_ACCOUNT_LABELS: Record<string, string> = {
    get platform() {
        return t('money.paymentForm.platform');
    },
    get fop_bank() {
        return t('money.paymentForm.fop');
    },
    get foreign_bank() {
        return t('money.paymentForm.foreignBank');
    },
};

const route = useRoute();
const router = useRouter();
const { can } = useSession();
const { profileId, path } = useCurrentProfile();

const paymentId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isNew = computed(() => paymentId.value === null);

const invoices = ref<Invoice[]>([]);
const accounts = ref<Account[]>([]);
const clients = ref<Client[]>([]);
const contracts = ref<Contract[]>([]);
const existing = ref<Payment | null>(null);

const form = reactive({
    invoice_id: null as number | null,
    /** A payment without an invoice, whose virtual invoice the fields below describe. */
    virtual: false,
    client_id: null as number | null,
    contract_id: null as number | null,
    period_start: '',
    period_end: '',
    notes: '',
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

/** The invoices of one wire besides the first one (`form.invoice_id`), each with its invoiced amount. */
const extra = ref<{ invoice_id: number; amount: string }[]>([]);

const { error: loadError, loading, reload } = useLoader(async (signal) => {
    const [open, accountList, clientList, payment] = await Promise.all([
        api.all<Invoice>(path('invoices'), {
            filter: { status: { in: ['ready', 'sent'] } },
            include: ['client'],
            sort: '-issued_at,-id',
        }, signal),
        api.all<Account>(path('accounts'), {}, signal),
        api.all<Client>(path('clients'), {}, signal),
        paymentId.value === null
            ? Promise.resolve(null)
            : api.get<ApiResponse<Payment>>(path(`payments/${paymentId.value}`), { include: ['invoice.client'] }, signal).then(({ data }) => data),
    ]);
    const virtual = payment?.invoice?.source === 'virtual' ? payment.invoice : null;

    accounts.value = accountList;
    clients.value = clientList;
    existing.value = payment;
    extra.value = [];
    invoices.value = payment?.invoice && !virtual && !open.some((invoice) => invoice.id === payment.invoice_id)
        ? [payment.invoice, ...open]
        : open;

    if (payment) {
        Object.assign(form, {
            invoice_id: virtual ? null : payment.invoice_id,
            virtual: virtual !== null,
            client_id: virtual?.client_id ?? null,
            contract_id: virtual?.contract_id ?? null,
            period_start: virtual?.period_start ?? '',
            period_end: virtual?.period_end ?? '',
            notes: virtual?.notes ?? '',
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

    form.virtual = route.query.without !== undefined;

    if (wanted && invoices.value.some((invoice) => invoice.id === wanted)) {
        form.invoice_id = wanted;
    }
}, [profileId, paymentId]);

const invoiceOf = (id: number | null): Invoice | null => invoices.value.find((item) => item.id === id) ?? null;
const remainingOf = (item: Invoice): string => subtractAmounts(item.total, item.paid_amount ?? '0');

const invoice = computed(() => (form.virtual ? null : invoiceOf(form.invoice_id)));
const account = computed(() => accounts.value.find((item) => item.id === form.received_to_account_id) ?? null);
const contract = computed(() => contracts.value.find((item) => item.id === form.contract_id) ?? null);

/** A receipt keeps its currency: its invoice's, or, without an invoice, the contract's. */
const currency = computed(() => existing.value?.currency
    ?? (form.virtual ? contract.value?.currency : invoice.value?.currency)
    ?? 'USD');
const sameCurrency = computed(() => !account.value || account.value.currency === currency.value);
const remaining = computed(() => (invoice.value ? remainingOf(invoice.value) : null));

/* Without an invoice: the client's contracts, an only active one taken at once */
const clientOptions = computed(() => clients.value
    .filter((client) => client.is_active || client.id === form.client_id)
    .map((client) => ({ value: client.id, label: client.name })));
const contractOptions = computed(() => contracts.value
    .filter((item) => item.is_active || item.id === form.contract_id)
    .map((item) => ({ value: item.id, label: `${item.name} · ${formatDate(item.started_at)}` })));

watch(() => form.client_id, async (clientId) => {
    contracts.value = clientId === null ? [] : await api.all<Contract>(path(`clients/${clientId}/contracts`));

    if (!contracts.value.some((item) => item.id === form.contract_id)) {
        const active = contracts.value.filter((item) => item.is_active);

        form.contract_id = active.length === 1 ? active[0]!.id : null;
    }
});

/** The name of a wire's amount field: "Сума за INV-0042". */
function amountLabel(invoiceId: number | null): string {
    const number = invoiceOf(invoiceId)?.number;

    return number ? t('money.paymentForm.amountFor', { number }) : t('settings.taxes.invoicedAmount');
}

/* One wire for several invoices */
const isGroup = computed(() => isNew.value && !form.virtual && extra.value.length > 0);
const groupTotal = computed(() => sumAmounts(parseAmount(form.amount), ...extra.value.map((line) => parseAmount(line.amount))));
const amountTotal = computed(() => (isGroup.value ? groupTotal.value : parseAmount(form.amount)));

/** How each invoice of the wire would be covered, as the API tells for one receipt. */
const groupCoverage = computed(() => [{ invoice_id: form.invoice_id, amount: form.amount }, ...extra.value].map((line) => {
    const item = invoiceOf(line.invoice_id);
    const left = item ? remainingOf(item) : '0.00';
    const amount = parseAmount(line.amount) ?? '0.00';

    return { invoice: item, coversTotal: !!item && compareAmounts(amount, left) >= 0, overpaid: !!item && compareAmounts(amount, left) > 0 };
}));

/**
 * Leave an invoice out of the wire; leaving out the first one makes the next one first.
 */
function removeLine(index: number): void {
    if (index > 0) {
        extra.value.splice(index - 1, 1);

        return;
    }

    const [next] = extra.value.splice(0, 1);

    if (next) {
        form.invoice_id = next.invoice_id;
        form.amount = next.amount;
    }
}

/* Choosing invoices: the first one, another one of the wire, or the one an edited receipt belongs to */
const picking = ref<'main' | 'extra' | null>(null);
const pickerOpen = computed({
    get: () => picking.value !== null,
    set: (isOpen: boolean) => {
        if (!isOpen) {
            picking.value = null;
        }
    },
});

const pickable = computed(() => {
    if (picking.value === 'extra') {
        const chosen = new Set([form.invoice_id, ...extra.value.map((line) => line.invoice_id)]);

        return invoices.value.filter((item) => item.currency === invoice.value?.currency && !chosen.has(item.id));
    }

    return isNew.value ? invoices.value : invoices.value.filter((item) => item.currency === existing.value?.currency);
});

function pick(chosen: Invoice): void {
    if (picking.value === 'extra') {
        extra.value.push({ invoice_id: chosen.id, amount: remainingOf(chosen) });
    } else {
        form.invoice_id = chosen.id;
        form.virtual = false;
    }
}

function pickWithout(): void {
    form.virtual = true;
    form.invoice_id = null;
    extra.value = [];
}

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

/* Without an invoice, the money lands by default on the first active account in the contract's currency. */
watch(contract, (chosen) => {
    if (!chosen || !isNew.value || touched.account) {
        return;
    }

    form.received_to_account_id = accounts.value
        .find((item) => item.is_active && item.kind !== 'personal' && item.currency === chosen.currency)?.id ?? null;
});

/* What arrived follows the amount in the same currency, until it is typed; in another currency it is typed anew. */
watch(() => [amountTotal.value, sameCurrency.value] as const, ([amount, same]) => {
    if (!touched.received_amount) {
        form.received_amount = same ? amount ?? '' : '';
    }
});

const accountOptions = computed(() => accounts.value
    .filter((item) => (item.is_active && item.kind !== 'personal') || item.id === form.received_to_account_id)
    .map((item) => ({ value: item.id, label: `${accountLabel(item.name, item.currency)} · ${KIND_ACCOUNT_LABELS[item.kind] ?? item.kind}` })));

/**
 * The fields of the receipt: its invoice, or, without an invoice, what its virtual invoice is made of.
 */
function body(): Record<string, unknown> {
    const owner = form.virtual
        ? {
            virtual_invoice: {
                client_id: form.client_id,
                contract_id: form.contract_id,
                period_start: form.period_start,
                period_end: form.period_end,
                notes: form.notes.trim() || null,
            },
        }
        : { invoice_id: form.invoice_id };

    return {
        ...owner,
        amount: parseAmount(form.amount) ?? form.amount,
        client_fee_amount: parseAmount(form.client_fee_amount),
        received_to_account_id: form.received_to_account_id,
        received_amount: parseAmount(form.received_amount),
        received_at: form.received_at,
        nbu_rate: parseAmount(form.nbu_rate),
    };
}

/**
 * What the preview is asked about: the receipt (an edit names its id, so it isn't counted twice), or for one wire
 * its total as one income of its first invoice, which tells the quarter, the hryvnias and the taxes.
 */
function previewBody(): Record<string, unknown> {
    if (isGroup.value) {
        return { ...body(), amount: groupTotal.value };
    }

    return { ...body(), payment_id: existing.value?.id ?? null };
}

/* The preview: what saving will store, asked again as the form changes. */
const preview = ref<PaymentPreview | null>(null);
const previewError = ref<unknown>(null);
const previewing = ref(false);
let previewTimer: ReturnType<typeof setTimeout> | undefined;
let previewRun = 0;

const ready = computed(() => !loading.value
    && amountTotal.value !== null
    && (!isGroup.value || extra.value.every((line) => parseAmount(line.amount) !== null))
    && form.received_to_account_id !== null
    && !!form.received_at
    && (form.virtual
        ? form.client_id !== null && form.contract_id !== null && !!form.period_start && !!form.period_end
        : form.invoice_id !== null)
    && (sameCurrency.value || parseAmount(form.received_amount) !== null));

watch(() => [ready.value, JSON.stringify(previewBody())], () => {
    clearTimeout(previewTimer);

    if (!ready.value) {
        // A request still on its way is outdated too
        previewRun++;
        preview.value = null;
        previewing.value = false;

        return;
    }

    previewTimer = setTimeout(async () => {
        const run = ++previewRun;

        previewing.value = true;

        try {
            const { data } = await api.post<ApiResponse<PaymentPreview>>(path('payments/preview'), previewBody());

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
const coverage = computed(() => (isGroup.value ? null : preview.value?.invoice ?? null));
const percentOfIncome = (tax: string): number => (preview.value && Number(preview.value.uah_amount) > 0
    ? Math.round((Number(tax) / Number(preview.value.uah_amount)) * 100)
    : 0);

/** How many invoices this receipt pays in full, so saving may mark them paid. */
const paysInFull = computed(() => {
    if (isGroup.value) {
        return groupCoverage.value.filter((line) => line.coversTotal && line.invoice?.status !== 'paid').length;
    }

    return coverage.value?.covers_total && coverage.value.status !== 'paid' ? 1 : 0;
});
const overpays = computed(() => (isGroup.value ? groupCoverage.value.some((line) => line.overpaid) : !!coverage.value?.overpaid));

/** The paid invoice, which the edit leaves uncovered, so it returns to unpaid on saving. */
const reopens = computed(() => {
    const reopened = preview.value?.reopens_invoice;

    return reopened ? { number: reopened.number, status: unpaidStatusOf(existing.value?.invoice ?? { sent_at: null }).label } : null;
});

/* Saving */
const idempotencyKey = newIdempotencyKey();
const saving = ref(false);
const saveError = ref<unknown>(null);

/** A declared quarter: told by the preview, or by a 409 of the save. */
const needsNote = computed(() => lockedQuarter.value !== null || (saveError.value instanceof ApiError && saveError.value.status === 409));

/**
 * Mark the invoices, which the saved receipts pay in full, paid on the receipt's date; the numbers of those, which
 * couldn't be marked, are returned.
 */
async function markInvoicesPaid(covered: (InvoiceCoverage | null)[]): Promise<string[]> {
    const failed: string[] = [];

    for (const item of covered) {
        if (!item?.covers_total || item.status === 'paid') {
            continue;
        }

        try {
            await api.post(path(`invoices/${item.id}/pay`), { paid_at: form.received_at });
        } catch {
            failed.push(item.number ?? String(item.id));
        }
    }

    return failed;
}

async function save(): Promise<void> {
    saving.value = true;
    saveError.value = null;

    const note = form.correction_note.trim() || null;

    try {
        let target: number;
        let covered: (InvoiceCoverage | null)[];

        if (isGroup.value) {
            const response = await api.post<PaymentGroupResponse>(path('payments/group'), {
                receipts: [{ invoice_id: form.invoice_id, amount: form.amount }, ...extra.value]
                    .map((line) => ({ invoice_id: line.invoice_id, amount: parseAmount(line.amount) ?? line.amount })),
                received_to_account_id: form.received_to_account_id,
                received_amount: parseAmount(form.received_amount),
                client_fee_amount: parseAmount(form.client_fee_amount),
                received_at: form.received_at,
                nbu_rate: parseAmount(form.nbu_rate),
                correction_note: note,
            }, { 'Idempotency-Key': idempotencyKey });

            target = response.data[0]!.id;
            covered = response.meta.invoices;
        } else {
            const response = existing.value === null
                ? await api.post<PaymentSavedResponse>(path('payments'), { ...body(), correction_note: note }, { 'Idempotency-Key': idempotencyKey })
                : await api.patch<PaymentSavedResponse>(path(`payments/${existing.value.id}`), { ...body(), correction_note: note });

            target = response.data.id;
            covered = [response.meta.invoice];
        }

        const unpaid = isNew.value && markPaid.value ? await markInvoicesPaid(covered) : [];

        await router.replace({ name: 'payment', params: { id: target }, query: unpaid.length ? { unpaid: unpaid.join(', ') } : {} });
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

const shownError = computed(() => saveError.value ?? previewError.value);
const generalError = computed(() => {
    const error = shownError.value;

    // A declared quarter's 409 is told next to the correction note
    if (!error || (error === saveError.value && error instanceof ApiError && error.status === 409)) {
        return null;
    }

    const fields = [
        'invoice_id', 'amount', 'client_fee_amount', 'received_to_account_id', 'received_amount', 'received_at', 'nbu_rate',
        'virtual_invoice.client_id', 'virtual_invoice.contract_id', 'virtual_invoice.period_start', 'virtual_invoice.period_end', 'virtual_invoice.notes',
    ];

    if (error instanceof ApiError && error.status === 422 && fields.some((field) => fieldError(error, field))) {
        return null;
    }

    return errorMessage(error);
});
</script>

<template>
    <SubScreen :title="isNew ? t('money.paymentForm.newReceipt') : t('money.paymentForm.editReceipt')" :back="isNew ? { name: 'payment-pick' } : { name: 'payment', params: { id: paymentId } }">
        <LoadState :loading="loading" :error="loadError" @retry="reload">
            <section v-if="isGroup" class="flex flex-col gap-2" :aria-label="t('money.paymentForm.invoicesOnePayment')">
                <SectionTitle>{{ t('money.paymentForm.invoicesPayment') }}</SectionTitle>
                <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-3.5">
                    <div
                        v-for="(line, index) in [{ invoice_id: form.invoice_id, amount: form.amount }, ...extra]"
                        :key="line.invoice_id ?? 0"
                        class="flex items-center gap-2.5 py-3"
                    >
                        <span class="flex min-w-0 flex-1 flex-col gap-[3px]">
                            <span class="num text-15 font-semibold text-accent">{{ invoiceOf(line.invoice_id)?.number }}</span>
                            <span class="truncate text-14 font-light">{{ invoiceOf(line.invoice_id)?.client?.name }}</span>
                            <span v-if="invoiceOf(line.invoice_id)" class="text-12 text-ink-3">
                                <Translated :text="t('money.paymentForm.remainingAmount')">
                                    <template #amount><span class="num">{{ formatAmount(remainingOf(invoiceOf(line.invoice_id)!)) }}</span></template>
                                </Translated>
                            </span>
                        </span>
                        <input
                            v-if="index === 0"
                            v-model="form.amount"
                            inputmode="decimal"
                            :aria-label="amountLabel(line.invoice_id)"
                            class="num h-11 w-28 rounded-control border border-line-input bg-surface px-3 text-right text-16"
                            @input="touched.amount = true"
                        >
                        <input
                            v-else
                            v-model="extra[index - 1]!.amount"
                            inputmode="decimal"
                            :aria-label="amountLabel(line.invoice_id)"
                            class="num h-11 w-28 rounded-control border border-line-input bg-surface px-3 text-right text-16"
                        >
                        <button type="button" :aria-label="t('money.paymentForm.removeInvoice')" class="flex size-11 shrink-0 items-center justify-center text-ink-3" @click="removeLine(index)">
                            <Icon name="close" :size="18" />
                        </button>
                    </div>
                    <div class="flex items-center justify-between py-3 text-15 font-semibold">
                        <span>{{ t('core.common.total') }}</span>
                        <span class="num">{{ formatMoney(groupTotal, currency) }}</span>
                    </div>
                </div>
                <span v-if="fieldError(shownError, 'receipts')" class="text-12 text-negative">{{ fieldError(shownError, 'receipts') }}</span>
            </section>

            <div v-else class="flex flex-col gap-1.5">
                <span id="payment-invoice" class="text-13 font-medium text-ink-2">{{ t('core.common.invoice') }}</span>
                <button
                    type="button"
                    aria-labelledby="payment-invoice"
                    class="flex w-full items-center gap-3 rounded-control bg-surface px-3.5 py-3 text-left"
                    :class="[
                        fieldError(shownError, 'invoice_id') ? 'border-negative' : form.virtual ? 'border-ink-3' : 'border-line-input',
                        form.virtual ? 'border border-dashed' : 'border',
                    ]"
                    @click="picking = 'main'"
                >
                    <span v-if="form.virtual" class="flex min-w-0 flex-1 flex-col gap-1.5">
                        <VirtualMarker />
                        <span class="text-14 text-ink-2">{{ t('invoices.virtual.forBooks') }}</span>
                    </span>
                    <span v-else-if="invoice" class="flex min-w-0 flex-1 flex-col gap-[3px]">
                        <span class="num text-16 font-semibold text-accent">{{ invoice.number }}</span>
                        <span class="truncate text-15 font-light text-ink">{{ invoice.client?.name }}</span>
                    </span>
                    <span v-else class="flex-1 text-16 text-ink-3">{{ t('money.paymentForm.chooseInvoice') }}</span>
                    <Icon name="chevronDown" :size="18" class="text-ink-2" />
                </button>
                <span v-if="invoice" class="text-12 text-ink-3">
                    <Translated :text="t('money.paymentForm.receivedRemaining')">
                        <template #received><span class="num">{{ formatAmount(invoice.paid_amount ?? '0') }} / {{ formatMoney(invoice.total, invoice.currency) }}</span></template>
                        <template #remaining><span class="num">{{ formatAmount(remaining) }}</span></template>
                    </Translated>
                </span>
                <span v-if="fieldError(shownError, 'invoice_id')" class="text-12 text-negative">{{ fieldError(shownError, 'invoice_id') }}</span>
            </div>

            <button
                v-if="isNew && !form.virtual && invoice"
                type="button"
                class="-mt-1 flex min-h-11 items-center gap-1.5 self-start text-14 font-medium text-accent"
                @click="picking = 'extra'"
            >
                <Icon name="plus" :size="18" />
                {{ isGroup ? t('money.paymentForm.addInvoice') : t('money.paymentForm.onePaymentForSeveral') }}
            </button>

            <template v-if="form.virtual">
                <SelectField
                    v-model="form.client_id"
                    :label="t('core.common.client')"
                    :placeholder="t('invoices.editor.chooseClient')"
                    :options="clientOptions"
                    :error="fieldError(shownError, 'virtual_invoice.client_id')"
                />
                <SelectField
                    v-model="form.contract_id"
                    :label="t('core.common.contract')"
                    :aside="contract ? `${contract.currency} · ${t('money.paymentForm.contractRequired')}` : t('money.paymentForm.contractRequired')"
                    :placeholder="t('money.paymentForm.chooseContract')"
                    :options="contractOptions"
                    :disabled="form.client_id === null"
                    :hint="form.client_id !== null && contractOptions.length === 0 ? t('money.paymentForm.noContracts') : t('money.paymentForm.contractTakes')"
                    :error="fieldError(shownError, 'virtual_invoice.contract_id')"
                />
            </template>

            <section class="flex flex-col gap-3">
                <SectionTitle>{{ t('money.paymentForm.income') }}</SectionTitle>
                <TextField
                    v-if="!isGroup"
                    v-model="form.amount"
                    :label="form.virtual ? t('money.paymentForm.amountFromClient', { currency }) : t('money.paymentForm.invoiceAmountIn', { currency })"
                    numeric
                    class="h-14 text-20 font-semibold"
                    :hint="form.virtual ? t('money.paymentForm.amountFromClientHint') : t('money.paymentForm.taxBaseHint')"
                    :error="fieldError(shownError, 'amount')"
                    @update:model-value="touched.amount = true"
                />
                <template v-if="form.virtual">
                    <TextField
                        v-model="form.notes"
                        :label="t('money.paymentForm.whatFor')"
                        :placeholder="t('money.paymentForm.whatForPlaceholder')"
                        :hint="t('money.paymentForm.whatForHint')"
                        :error="fieldError(shownError, 'virtual_invoice.notes')"
                    />
                    <div class="flex flex-col gap-1.5">
                        <div class="grid grid-cols-2 gap-2.5">
                            <DateField
                                v-model="form.period_start"
                                :label="t('invoices.editor.periodFrom')"
                                :error="fieldError(shownError, 'virtual_invoice.period_start')"
                            />
                            <DateField
                                v-model="form.period_end"
                                :label="t('invoices.filters.through')"
                                :min="form.period_start || undefined"
                                :error="fieldError(shownError, 'virtual_invoice.period_end')"
                            />
                        </div>
                        <span class="text-12 text-ink-3">{{ t('money.paymentForm.servicePeriodHint') }}</span>
                    </div>
                </template>
                <TextField
                    v-if="!form.virtual"
                    v-model="form.client_fee_amount"
                    :label="t('money.paymentForm.clientFeeHint')"
                    numeric
                    placeholder="0.00"
                    :hint="t('money.paymentForm.notIncome')"
                    :error="fieldError(shownError, 'client_fee_amount')"
                />
            </section>

            <section class="flex flex-col gap-3">
                <SectionTitle>{{ t('money.paymentForm.moneyIn') }}</SectionTitle>
                <SelectField
                    v-model="form.received_to_account_id"
                    :label="t('money.paymentForm.creditedTo')"
                    :placeholder="t('core.common.chooseAccount')"
                    :options="accountOptions"
                    :error="fieldError(shownError, 'received_to_account_id')"
                    @update:model-value="touched.account = true"
                />
                <div class="grid grid-cols-2 gap-2.5">
                    <TextField
                        v-model="form.received_amount"
                        :label="t('money.paymentForm.receivedIn', { currency: account?.currency ?? currency })"
                        numeric
                        :error="fieldError(shownError, 'received_amount')"
                        @update:model-value="touched.received_amount = true"
                    />
                    <DateField
                        v-model="form.received_at"
                        :label="t('money.paymentForm.dateCredited')"
                        :max="today()"
                        :error="fieldError(shownError, 'received_at')"
                    />
                </div>
                <div
                    v-if="preview?.receipt_fee && compareAmounts(preview.receipt_fee, '0') !== 0"
                    class="flex items-center justify-between rounded-control bg-muted px-3.5 py-3 text-13 text-ink-2"
                >
                    <span>{{ t('money.paymentForm.feeReceipt') }}</span>
                    <span class="num font-semibold text-ink">{{ formatMoney(preview.receipt_fee, currency) }}</span>
                </div>
                <p class="m-0 text-12 leading-[1.45] text-ink-3">{{ t('money.paymentForm.incomeDateHint') }}</p>
            </section>

            <TextField
                v-if="needsManualRate"
                v-model="form.nbu_rate"
                :label="t('money.paymentForm.rateUahPer', { currency })"
                numeric
                placeholder="41.8710"
                :hint="t('money.paymentForm.manualRateHint')"
                :error="fieldError(shownError, 'nbu_rate')"
            />

            <section
                v-if="preview"
                :aria-label="t('money.paymentForm.taxSnapshot')"
                class="flex flex-col gap-3 rounded-card border border-line bg-surface p-4"
                :class="previewing ? 'opacity-60' : ''"
            >
                <div class="flex items-center justify-between">
                    <SectionTitle as="span">{{ t('money.paymentForm.willBeSaved') }}</SectionTitle>
                    <span class="rounded-badge bg-accent-soft px-2 py-[3px] text-12 font-semibold text-accent-hover">
                        {{ quarterLabel(preview.period_quarter) }} · {{ monthNameInText(preview.period_month) }}
                    </span>
                </div>
                <div class="flex flex-col gap-0.5">
                    <span class="num text-26 font-semibold tracking-[-0.02em]">{{ formatMoney(preview.uah_amount, 'UAH') }}</span>
                    <span v-if="preview.currency !== 'UAH'" class="num text-13 text-ink-3">
                        {{ preview.currency }} {{ formatAmount(preview.amount) }} @ {{ formatAmount(preview.nbu_rate) }}
                        · {{ preview.rate_is_manual ? t('money.paymentForm.manual') : t('money.paymentForm.nbu') }} {{ formatDate(preview.nbu_rate_date) }}
                    </span>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="flex flex-col gap-1 rounded-control bg-paper p-2.5">
                        <span class="text-12 text-ink-3">{{ t('money.paymentForm.singleTaxPercent', { percent: percentOfIncome(preview.single_tax_uah) }) }}</span>
                        <span class="num text-15 font-semibold">{{ formatAmount(preview.single_tax_uah) }}</span>
                    </div>
                    <div class="flex flex-col gap-1 rounded-control bg-paper p-2.5">
                        <span class="text-12 text-ink-3">{{ t('money.paymentForm.militaryLevyPercent', { percent: percentOfIncome(preview.military_levy_uah) }) }}</span>
                        <span class="num text-15 font-semibold">{{ formatAmount(preview.military_levy_uah) }}</span>
                    </div>
                </div>
                <span v-if="isGroup" class="text-12 text-ink-3">{{ t('money.paymentForm.groupExplained') }}</span>
            </section>

            <TextAreaField
                v-if="needsNote"
                v-model="form.correction_note"
                :label="lockedQuarter
                    ? t('money.paymentForm.correctionQuestionFor', { quarter: quarterLabel(lockedQuarter.quarter), year: lockedQuarter.year })
                    : t('money.payment.correctionQuestion')"
                :placeholder="t('money.paymentForm.correctionPlaceholder')"
                :rows="2"
            />
            <p v-if="needsNote && !lockedQuarter && saveError" class="m-0 text-14 text-warning-text" role="alert">{{ errorMessage(saveError) }}</p>

            <label
                v-if="isNew && paysInFull > 0"
                class="flex items-start gap-3 rounded-button bg-positive-soft p-3.5 text-14 leading-[1.45] text-[#0f4d2d]"
            >
                <input v-model="markPaid" type="checkbox" class="m-0 mt-px size-5 shrink-0 accent-positive">
                <span class="flex flex-col gap-0.5">
                    <strong class="font-semibold">{{ isGroup ? t('money.paymentForm.paidInFull', { count: paysInFull, total: extra.length + 1 }) : t('money.paymentForm.invoicePaidFull') }}</strong>
                    <span>{{ t('money.paymentForm.markPaidOn', { date: formatDate(form.received_at) }) }}</span>
                </span>
            </label>
            <p v-if="overpays" class="m-0 rounded-button bg-warning-soft p-3.5 text-14 leading-[1.45] text-warning">
                {{ t('money.paymentForm.amountTooHigh') }}
            </p>

            <section v-if="isNew && form.virtual" class="flex flex-col gap-1.5 rounded-card bg-accent-soft p-4 text-14 leading-[1.45] text-ink">
                <span class="flex items-center gap-2 font-semibold text-accent-hover">
                    <Icon name="info" :size="18" />
                    {{ t('money.paymentForm.virtualTitle') }}
                </span>
                <ul class="m-0 flex list-disc flex-col gap-1 pl-5">
                    <li>{{ t('money.paymentForm.virtualNoDocument') }}</li>
                    <li>{{ t('money.paymentForm.virtualCounter') }}</li>
                    <li>{{ t('money.paymentForm.virtualCounted') }}</li>
                    <li>{{ t('money.paymentForm.virtualLater') }}</li>
                </ul>
            </section>

            <p v-if="reopens" class="m-0 rounded-button bg-warning-soft p-3.5 text-14 leading-[1.45] text-warning">
                <Translated :text="t('money.paymentForm.invoiceWillReopen', { status: reopens.status })">
                    <template #number><span class="num">{{ reopens.number }}</span></template>
                </Translated>
            </p>

            <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
        </LoadState>

        <template v-if="can(isNew ? 'create' : 'update', 'payments')" #footer>
            <PrimaryButton
                :busy="saving"
                :disabled="loading || !ready || (needsNote && !form.correction_note.trim())"
                @click="save"
            >
                {{ isNew ? t('money.paymentForm.saveReceipt') : t('core.common.saveChanges') }}
            </PrimaryButton>
        </template>

        <InvoicePickerSheet
            v-model:open="pickerOpen"
            :invoices="pickable"
            :selected-id="picking === 'main' ? form.invoice_id : null"
            :without-invoice="picking === 'main' && isNew"
            :title="picking === 'extra' ? t('money.paymentForm.anotherInvoicePayment') : t('money.paymentForm.chooseInvoice')"
            :empty-text="picking === 'extra'
                ? t('money.paymentForm.noOtherOpenInvoices', { currency })
                : isNew ? undefined : t('money.paymentForm.noOpenInvoices', { currency })"
            @select="pick"
            @without="pickWithout"
        />
    </SubScreen>
</template>
