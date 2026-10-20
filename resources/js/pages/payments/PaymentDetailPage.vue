<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError, api } from '@/api/client';
import type { Account, ApiResponse, Payment, PaymentFlow } from '@/api/types';
import TextAreaField from '@/components/form/TextAreaField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import MoneyFlow from '@/components/payment/MoneyFlow.vue';
import RefundSheet from '@/components/payment/RefundSheet.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import ConfirmSheet from '@/components/ui/ConfirmSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { formatDate, monthName, quarterLabel } from '@/utils/dates';
import { errorMessage } from '@/utils/errors';
import { compareAmounts, formatAmount, formatMoney } from '@/utils/money';
import { TAX_PAYMENT_STATUSES, TAX_TYPES } from '@/utils/statuses';

/**
 * Надходження (S-PaymentDetail): the amount in its currency with hryvnias below, the invoice, the income date, the
 * NBU rate (only here), ЄП / ВЗ as hints, the way the money went, what it cost and what is left, and the taxes paid
 * for it. Deleting it, or one of its refunds, in a declared quarter asks for a correction note.
 */
const route = useRoute();
const router = useRouter();
const { profileId, path } = useCurrentProfile();
const { can } = useSession();

const id = computed(() => Number(route.params.id));

const { data, error, loading, reload } = useLoader(async (signal) => {
    const [payment, flow, accounts] = await Promise.all([
        api.get<ApiResponse<Payment>>(path(`payments/${id.value}`), {
            include: ['invoice.client', 'receivedToAccount', 'taxPayments.paidFromAccount', 'taxRule'],
        }, signal),
        api.get<ApiResponse<PaymentFlow>>(path(`payments/${id.value}/flow`), undefined, signal),
        api.all<Account>(path('accounts'), {}, signal),
    ]);

    return { payment: payment.data, flow: flow.data, accounts };
}, [profileId, id]);

const payment = computed(() => data.value?.payment ?? null);
const flow = computed(() => data.value?.flow ?? null);

const kindTitle = computed(() => (payment.value?.kind === 'advance' ? 'Аванс' : payment.value?.kind === 'other' ? 'Інший дохід' : 'Надходження'));
const ratePercent = (rate: string | undefined): string => (rate ? `${Number((Number(rate) * 100).toFixed(2))} %` : '');
const onPlatform = computed(() => flow.value?.holdings.find((holding) => holding.kind !== 'fop_bank' && compareAmounts(holding.amount, '0') > 0) ?? null);
const landed = computed(() => flow.value?.landed_on_fop_bank.filter((holding) => compareAmounts(holding.amount, '0') > 0) ?? []);
const taxPayments = computed(() => [...(payment.value?.taxPayments ?? [])].sort((a, b) => (a.paid_at ?? '').localeCompare(b.paid_at ?? '')));

/** Invoices, which saving the receipt couldn't mark paid (the form passes their numbers). */
const unpaid = computed(() => (typeof route.query.unpaid === 'string' ? route.query.unpaid : null));

const menuOpen = ref(false);
const refunding = ref(false);
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
        const invoiceId = payment.value?.invoice_id;

        await api.delete(path(`payments/${id.value}`), noteQuery());
        deleteOpen.value = false;
        await router.replace(invoiceId ? { name: 'invoice', params: { id: invoiceId } } : { name: 'home' });
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
    <SubScreen :title="kindTitle" muted :back="payment?.invoice_id ? { name: 'invoice', params: { id: payment.invoice_id } } : { name: 'home' }">
        <template #action>
            <button
                v-if="payment && can('update', 'payments')"
                type="button"
                aria-label="Інші дії"
                class="flex size-11 items-center justify-center text-ink"
                @click="menuOpen = true"
            >
                <Icon name="more" :size="22" />
            </button>
        </template>

        <LoadState :loading="loading && !data" :error="error" @retry="reload">
            <template v-if="payment && flow">
                <p v-if="unpaid" class="m-0 rounded-button bg-warning-soft p-3.5 text-14 leading-[1.45] text-warning" role="alert">
                    Надходження збережено, але позначити оплаченим інвойс <span class="num">{{ unpaid }}</span> не вдалося. Зробіть це на сторінці інвойсу.
                </p>
                <div class="flex flex-col gap-1.5">
                    <span class="num text-32 font-semibold tracking-[-0.03em]">{{ formatMoney(payment.amount, payment.currency) }}</span>
                    <span v-if="payment.currency !== 'UAH'" class="num text-16 font-light text-ink-2">{{ formatMoney(payment.uah_amount, 'UAH') }}</span>
                    <div class="mt-1 flex gap-2">
                        <StatusBadge :label="`${quarterLabel(payment.period_quarter)} ${payment.period_year} · ${monthName(payment.period_month).toLowerCase()}`" tone="info" />
                    </div>
                </div>

                <div class="mt-2.5 mb-0.5 border-t border-dashed border-line-input" />

                <div v-if="payment.invoice" class="-mt-2 flex flex-col">
                    <RouterLink
                        :to="{ name: 'invoice', params: { id: payment.invoice.id } }"
                        class="flex min-h-11 items-center gap-1.5 text-accent no-underline"
                    >
                        <span class="num text-17 font-semibold">{{ payment.invoice.number }}</span>
                        <Icon name="chevronRight" :size="18" />
                    </RouterLink>
                    <span class="-mt-1.5 text-16 font-light">{{ payment.invoice.client?.name }}</span>
                </div>
                <p v-else-if="payment.description" class="m-0 text-16 font-light">{{ payment.description }}</p>

                <section class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4 py-1 text-14">
                    <div class="flex justify-between gap-3 py-3">
                        <span class="text-ink-3">Дата доходу</span>
                        <span class="num">{{ formatDate(payment.received_at) }}</span>
                    </div>
                    <div v-if="payment.currency !== 'UAH'" class="flex justify-between gap-3 py-3">
                        <span class="text-ink-3">{{ payment.rate_is_manual ? 'Курс (вручну)' : 'Курс НБУ' }}</span>
                        <span class="num">{{ formatAmount(payment.nbu_rate) }} · {{ formatDate(payment.nbu_rate_date) }}</span>
                    </div>
                    <div class="flex justify-between gap-3 py-3">
                        <span class="text-ink-3">ЄП {{ ratePercent(payment.taxRule?.single_tax_rate) }} (підказка)</span>
                        <span class="num font-semibold">{{ formatAmount(payment.single_tax_uah) }}</span>
                    </div>
                    <div class="flex justify-between gap-3 py-3">
                        <span class="text-ink-3">ВЗ {{ ratePercent(payment.taxRule?.military_levy_rate) }} (підказка)</span>
                        <span class="num font-semibold">{{ formatAmount(payment.military_levy_uah) }}</span>
                    </div>
                    <div v-if="payment.taxRule" class="flex justify-between gap-3 py-3">
                        <span class="text-ink-3">Податкові правила</span>
                        <span>з {{ formatDate(payment.taxRule.effective_from) }}</span>
                    </div>
                </section>

                <section class="flex flex-col gap-2.5">
                    <SectionTitle>Шлях коштів</SectionTitle>
                    <MoneyFlow :flow="flow" :can-remove-refunds="can('delete', 'refunds')" @remove-refund="openRefundRemoval" />
                    <RouterLink
                        v-if="onPlatform && can('create', 'account-transfers')"
                        :to="{ name: 'transfer-new', query: { from: onPlatform.account_id ?? undefined } }"
                        class="flex h-12 items-center justify-center gap-2 rounded-control border border-line-input bg-surface text-15 font-medium text-ink no-underline hover:text-ink"
                    >
                        <Icon name="transfer" :size="20" />
                        Вивести залишок
                    </RouterLink>
                </section>

                <section class="grid grid-cols-2 gap-2" aria-label="Підсумки">
                    <div class="flex flex-col gap-1 rounded-button border border-line bg-surface p-3">
                        <span class="text-12 text-ink-3">Втрати на комісіях</span>
                        <span class="num text-16 font-semibold">{{ flow.fees !== null ? formatMoney(flow.fees, flow.currency) : '—' }}</span>
                        <span v-if="flow.transfer_fee_share && compareAmounts(flow.transfer_fee_share, '0') !== 0" class="num text-11 text-ink-3">
                            {{ formatAmount(flow.receipt_fee ?? '0') }} + {{ formatAmount(flow.transfer_fee_share) }}
                        </span>
                        <span v-else class="text-11 text-ink-3">при отриманні</span>
                    </div>
                    <div class="flex flex-col gap-1 rounded-button border border-line bg-surface p-3">
                        <span class="text-12 text-ink-3">Дійшло на ФОП</span>
                        <template v-if="landed.length">
                            <span v-for="holding in landed" :key="holding.account_id ?? 0" class="num text-16 font-semibold">
                                {{ formatMoney(holding.amount, holding.currency ?? flow.currency) }}
                            </span>
                            <span class="text-11 text-ink-3">{{ landed.map((holding) => holding.account_name).join(', ') }}</span>
                        </template>
                        <template v-else>
                            <span class="num text-16 font-semibold">{{ formatMoney('0.00', flow.currency) }}</span>
                            <span class="text-11 text-ink-3">ще не переказано</span>
                        </template>
                    </div>
                    <div class="flex flex-col gap-1 rounded-button border border-line bg-surface p-3">
                        <span class="text-12 text-ink-3">Ще на платформі</span>
                        <span class="num text-16 font-semibold">{{ formatMoney(flow.still_on_platform, flow.currency) }}</span>
                        <span class="text-11 text-ink-3">не втрата, а залишок</span>
                    </div>
                    <div class="flex flex-col gap-1 rounded-button border border-line bg-surface p-3">
                        <span class="text-12 text-ink-3">Чистий дохід</span>
                        <span class="num text-16 font-semibold">{{ flow.net_income !== null ? formatMoney(flow.net_income, flow.currency) : '—' }}</span>
                        <span class="text-11 text-ink-3">після комісій, ЄП і ВЗ</span>
                    </div>
                </section>

                <section class="flex flex-col gap-2.5">
                    <SectionTitle>
                        Сплата податків
                        <template v-if="can('create', 'tax-payments')" #aside>
                            <RouterLink
                                :to="{ name: 'tax-payment-new', query: { allocation: 'payment', payment: payment.id, year: payment.period_year, quarter: payment.period_quarter } }"
                                class="flex min-h-11 items-center text-14 font-medium no-underline"
                            >
                                + Додати
                            </RouterLink>
                        </template>
                    </SectionTitle>
                    <div v-if="taxPayments.length" class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                        <div v-for="taxPayment in taxPayments" :key="taxPayment.id" class="flex items-center gap-3 py-3">
                            <span class="flex flex-1 flex-col gap-0.5">
                                <span class="text-15 font-medium">{{ TAX_TYPES[taxPayment.tax_type] }}</span>
                                <span class="text-12 text-ink-3">
                                    {{ formatDate(taxPayment.paid_at) }}<template v-if="taxPayment.paidFromAccount"> · {{ taxPayment.paidFromAccount.name }}</template>
                                </span>
                            </span>
                            <span class="flex flex-col items-end gap-1">
                                <span class="num text-14 font-semibold">{{ formatAmount(taxPayment.amount) }}</span>
                                <StatusBadge v-bind="TAX_PAYMENT_STATUSES[taxPayment.status]" size="sm" />
                            </span>
                        </div>
                    </div>
                    <p v-else class="m-0 rounded-card border border-line bg-surface p-4 text-14 text-ink-3">
                        Податки за цим надходженням ще не сплачено. Їх можна сплатити й за квартал загалом.
                    </p>
                </section>

                <p v-if="actionError" class="m-0 text-14 text-negative" role="alert">{{ actionError }}</p>

                <SecondaryButton v-if="can('create', 'refunds') && payment.kind !== 'fx_gain'" class="bg-muted" @click="refunding = true">
                    Оформити повернення клієнту
                </SecondaryButton>
            </template>
        </LoadState>

        <BottomSheet v-model:open="menuOpen" title="Дії з надходженням">
            <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                <RouterLink
                    v-if="payment?.kind !== 'fx_gain'"
                    :to="{ name: 'payment-edit', params: { id } }"
                    class="flex min-h-13 items-center gap-3 text-15 text-ink no-underline hover:text-ink"
                >
                    <Icon name="invoice" :size="20" /> Редагувати
                </RouterLink>
                <button
                    v-if="onPlatform && !payment?.repatriation_ack_at"
                    type="button"
                    class="flex min-h-13 items-center gap-3 text-left text-15"
                    @click="acknowledge"
                >
                    <Icon name="bell" :size="20" /> Не нагадувати про кошти на платформі
                </button>
                <button
                    v-if="can('delete', 'payments') && payment?.kind !== 'fx_gain'"
                    type="button"
                    class="flex min-h-13 items-center gap-3 text-left text-15 text-negative"
                    @click="openDelete"
                >
                    <Icon name="close" :size="20" /> Видалити надходження
                </button>
            </div>
        </BottomSheet>

        <RefundSheet v-if="payment && data" v-model:open="refunding" :payment="payment" :accounts="data.accounts" @saved="reload" />

        <ConfirmSheet
            v-model:open="deleteOpen"
            title="Видалити надходження?"
            confirm-label="Видалити"
            destructive
            :busy="busy"
            :error="actionError"
            @confirm="remove"
        >
            <p class="m-0">Дохід і податки за ним зникнуть з обліку. Якщо кошти вже переказано, повернено або за ними сплачено податки, видалити не вийде.</p>
            <TextAreaField v-if="needsNote" v-model="correctionNote" label="Квартал уже задекларовано — що уточнюєте?" :rows="2" />
        </ConfirmSheet>

        <ConfirmSheet
            v-model:open="refundOpen"
            title="Видалити повернення?"
            confirm-label="Видалити"
            destructive
            :busy="busy"
            :error="actionError"
            @confirm="removeRefund"
        >
            <p class="m-0">Повернення зникне з обліку, а дохід його періоду знову збільшиться. Щоб виправити повернення, видаліть його і запишіть знову.</p>
            <TextAreaField v-if="needsNote" v-model="correctionNote" label="Квартал уже задекларовано — що уточнюєте?" :rows="2" />
        </ConfirmSheet>
    </SubScreen>
</template>
