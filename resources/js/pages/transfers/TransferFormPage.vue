<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError, api, newIdempotencyKey } from '@/api/client';
import type { Account, AccountTransfer, ApiResponse, ExchangeRate, TransferProposal } from '@/api/types';
import DateField from '@/components/form/DateField.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextAreaField from '@/components/form/TextAreaField.vue';
import TextField from '@/components/form/TextField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import Translated from '@/components/ui/Translated.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useSession } from '@/composables/useSession';
import { useLoader } from '@/composables/useLoader';
import { t } from '@/i18n';
import { ACCOUNT_KINDS, accountLabel } from '@/utils/accounts';
import { formatDayMonth, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { compareAmounts, formatAmount, formatMoney, multiplyDecimals, parseAmount, subtractAmounts, sumAmounts } from '@/utils/money';

/**
 * Новий переказ (S-TransferNew), and its editing: money moved between two of the profile's accounts, what arrived,
 * the fee, and which receipts' money it moves (oldest first, or the one that matches exactly, of the receipts that
 * arrived by the transfer's day). Saving waits for the proposal; an edit sends the allocations only when they
 * changed, so the saved ones are never replaced by an empty list.
 */
const route = useRoute();
const router = useRouter();
const { can } = useSession();
const { profileId, path } = useCurrentProfile();

const transferId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isNew = computed(() => transferId.value === null);

const accounts = ref<Account[]>([]);
const existing = ref<AccountTransfer | null>(null);

const form = reactive({
    from_account_id: null as number | null,
    to_account_id: null as number | null,
    amount_sent: '',
    amount_received: '',
    fee_amount: '',
    sent_at: '',
    received_at: today(),
    reference: '',
    nbu_rate: '',
    correction_note: '',
});
const touchedReceived = ref(false);

const { error: loadError, loading, reload } = useLoader(async (signal) => {
    const [accountList, transfer] = await Promise.all([
        api.all<Account>(path('accounts'), { append: ['balance'] }, signal),
        transferId.value === null
            ? Promise.resolve(null)
            : api.get<ApiResponse<AccountTransfer>>(path(`transfers/${transferId.value}`), { include: ['allocations'] }, signal).then(({ data }) => data),
    ]);

    accounts.value = accountList;
    existing.value = transfer;

    if (transfer) {
        Object.assign(form, {
            from_account_id: transfer.from_account_id,
            to_account_id: transfer.to_account_id,
            amount_sent: transfer.amount_sent,
            amount_received: transfer.amount_received,
            fee_amount: transfer.currency_sent !== transfer.currency_received ? transfer.fee_amount ?? '' : '',
            sent_at: transfer.sent_at ?? '',
            received_at: transfer.received_at,
            reference: transfer.reference ?? '',
            nbu_rate: transfer.rate_is_manual ? transfer.nbu_rate ?? '' : '',
        });
        touchedReceived.value = true;

        return;
    }

    const active = accountList.filter((account) => account.is_active);
    const from = active.find((account) => account.id === Number(route.query.from)) ?? active.find((account) => account.kind === 'platform') ?? active[0];

    form.from_account_id = from?.id ?? null;
    form.to_account_id = active.find((account) => account.kind === 'fop_bank' && account.currency === from?.currency && account.id !== from?.id)?.id
        ?? active.find((account) => account.kind === 'fop_bank' && account.id !== from?.id)?.id
        ?? null;
}, [profileId, transferId]);

const from = computed(() => accounts.value.find((account) => account.id === form.from_account_id) ?? null);
const to = computed(() => accounts.value.find((account) => account.id === form.to_account_id) ?? null);
const isSale = computed(() => !!from.value && !!to.value && from.value.currency !== to.value.currency);

const options = (except: number | null) => accounts.value
    .filter((account) => (account.is_active || account.id === form.from_account_id || account.id === form.to_account_id) && account.id !== except)
    .map((account) => ({ value: account.id, label: accountLabel(account.name, account.currency) }));

/** The kind of the account money leaves; its balance follows, as a button. */
const fromKind = computed(() => (from.value ? ACCOUNT_KINDS[from.value.kind].label : undefined));

/** Send the whole balance (design.md §16.5): what arrives is typed anew. */
function sendBalance(): void {
    if (from.value?.balance === undefined) {
        return;
    }

    form.amount_sent = from.value.balance;
    form.amount_received = '';
    touchedReceived.value = true;
}

function swap(): void {
    [form.from_account_id, form.to_account_id] = [form.to_account_id, form.from_account_id];
}

/* Same currency: what arrives follows what was sent, until it is typed (the difference is the fee); a sale's is typed. */
watch(() => [form.amount_sent, isSale.value] as const, ([sent, sale]) => {
    if (!touchedReceived.value) {
        form.amount_received = sale ? '' : sent;
    }
});

const sent = computed(() => parseAmount(form.amount_sent));
const received = computed(() => parseAmount(form.amount_received));

/** Same currency: the fee is sent − received. */
const fee = computed(() => (!isSale.value && sent.value && received.value ? subtractAmounts(sent.value, received.value) : null));
const feePercent = computed(() => (fee.value && sent.value && Number(sent.value) > 0
    ? (Number(fee.value) / Number(sent.value) * 100).toFixed(2)
    : null));

/* A sale of currency: its rate against the NBU's on the day it arrived. */
const nbuRate = ref<string | null>(null);
let rateRun = 0;

watch(() => [isSale.value, from.value?.currency, to.value?.currency, form.received_at] as const, async ([sale, fromCurrency, toCurrency, date]) => {
    const run = ++rateRun;

    nbuRate.value = null;

    const foreign = fromCurrency === 'UAH' ? toCurrency : fromCurrency;

    if (!sale || !foreign || foreign === 'UAH' || !date || (fromCurrency !== 'UAH' && toCurrency !== 'UAH')) {
        return;
    }

    try {
        const { data } = await api.get<ApiResponse<ExchangeRate>>(`exchange-rates/${foreign}/${date}`);

        if (run === rateRun) {
            nbuRate.value = data.rate;
        }
    } catch {
        // No rate of the day: no spread is shown, and saving asks for a rate typed in
    }
});

/** UAH per unit of the foreign currency, as the transfer was made. */
const effectiveRate = computed(() => {
    if (!isSale.value || !sent.value || !received.value || Number(sent.value) === 0 || Number(received.value) === 0) {
        return null;
    }

    return from.value?.currency === 'UAH'
        ? (Number(sent.value) / Number(received.value)).toFixed(4)
        : (Number(received.value) / Number(sent.value)).toFixed(4);
});

/**
 * The loss against the NBU in UAH, as the API counts it (TransferMetrics::spread): the bank's separate fee isn't
 * converted, so only the rest is sold or bought. Selling: what the rest was worth less the hryvnias got; buying: the
 * hryvnias paid less what the currency got was worth.
 */
const spread = computed(() => {
    if (!nbuRate.value || !sent.value || !received.value) {
        return null;
    }

    const converted = subtractAmounts(sent.value, parseAmount(form.fee_amount) ?? '0.00');

    if (to.value?.currency === 'UAH') {
        return subtractAmounts(multiplyDecimals(converted, nbuRate.value), received.value);
    }

    if (from.value?.currency === 'UAH') {
        return subtractAmounts(converted, multiplyDecimals(received.value, nbuRate.value));
    }

    return null;
});

/* Which receipts' money the transfer moves */
const proposal = ref<TransferProposal | null>(null);
const proposing = ref(false);
const proposalFailed = ref(false);
const mode = ref<'fifo' | 'exact'>('fifo');
const allocations = reactive<Record<number, string>>({});
let proposalTimer: ReturnType<typeof setTimeout> | undefined;
let proposalRun = 0;

/** The saved allocations of the edited transfer, which the form starts with. */
let savedAllocations: string | null = null;

/**
 * Ask for the proposal of the current account, amount and day, after a pause in typing; answers of earlier
 * requests are dropped.
 */
function requestProposal(): void {
    clearTimeout(proposalTimer);

    const run = ++proposalRun;
    const accountId = form.from_account_id;
    const amount = sent.value;

    proposalFailed.value = false;

    if (accountId === null || amount === null || Number(amount) <= 0) {
        proposal.value = null;
        proposing.value = false;

        return;
    }

    proposing.value = true;

    proposalTimer = setTimeout(async () => {
        try {
            const { data } = await api.post<ApiResponse<TransferProposal>>(path('transfers/propose'), {
                from_account_id: accountId,
                amount_sent: amount,
                transfer_id: transferId.value ?? undefined,
                received_at: form.received_at || undefined,
            });

            if (run !== proposalRun) {
                return;
            }

            const first = proposal.value === null;

            proposal.value = data;

            if (first && existing.value?.allocations && savedAllocations === null) {
                setAllocations(existing.value.allocations.map((allocation) => ({ payment_id: allocation.payment_id, amount: allocation.amount })));
                savedAllocations = JSON.stringify(allocationList());
            } else {
                applyMode();
            }
        } catch {
            if (run === proposalRun) {
                proposal.value = null;
                proposalFailed.value = true;
            }
        } finally {
            if (run === proposalRun) {
                proposing.value = false;
            }
        }
    }, 300);
}

watch(() => [form.from_account_id, sent.value, form.received_at] as const, requestProposal);

function setAllocations(list: { payment_id: number; amount: string }[]): void {
    for (const key of Object.keys(allocations)) {
        delete allocations[Number(key)];
    }

    for (const candidate of proposal.value?.candidates ?? []) {
        allocations[candidate.payment_id] = list.find((item) => item.payment_id === candidate.payment_id)?.amount ?? '';
    }
}

function applyMode(): void {
    setAllocations((mode.value === 'exact' ? proposal.value?.exact : proposal.value?.fifo) ?? []);
}

watch(mode, applyMode);

/** The receipts the transfer may move, the newest first; «Від найстаріших» still fills the oldest ones first. */
const candidates = computed(() => [...(proposal.value?.candidates ?? [])]
    .sort((a, b) => b.received_at.localeCompare(a.received_at) || b.payment_id - a.payment_id));

const modeOptions = computed<{ value: 'fifo' | 'exact'; label: string }[]>(() => [
    { value: 'fifo', label: t('money.transferForm.oldestFirst') },
    { value: 'exact', label: proposal.value?.exact ? t('money.transferForm.exactMatch') : t('money.transferForm.exactMatchNone') },
]);

/**
 * The allocations as the API takes them: the receipts with an amount.
 */
function allocationList(): { payment_id: number; amount: string | null }[] {
    return Object.entries(allocations)
        .map(([paymentId, amount]) => ({ payment_id: Number(paymentId), amount: parseAmount(amount) }))
        .filter((allocation) => allocation.amount !== null && Number(allocation.amount) > 0);
}

const allocated = computed(() => sumAmounts(...Object.values(allocations).map((value) => parseAmount(value))));
const allocationState = computed(() => (sent.value === null ? 'none' : compareAmounts(allocated.value, sent.value) === 0 ? 'full'
    : compareAmounts(allocated.value, sent.value) > 0 ? 'over' : 'partial'));

/* Saving */
const idempotencyKey = newIdempotencyKey();
const saving = ref(false);
const saveError = ref<unknown>(null);
const needsNote = computed(() => saveError.value instanceof ApiError && saveError.value.status === 409);
const needsRate = computed(() => !!fieldError(saveError.value, 'nbu_rate') || form.nbu_rate !== '');

function body(): Record<string, unknown> {
    const list = allocationList();
    // Without a proposal, or unchanged, an edit keeps its saved allocations (a missing key keeps them)
    const keepsSaved = !isNew.value && (proposal.value === null || JSON.stringify(list) === savedAllocations);

    return {
        ...(keepsSaved || proposal.value === null ? {} : { allocations: list }),
        from_account_id: form.from_account_id,
        to_account_id: form.to_account_id,
        amount_sent: sent.value ?? form.amount_sent,
        amount_received: received.value ?? form.amount_received,
        fee_amount: isSale.value ? parseAmount(form.fee_amount) : null,
        sent_at: form.sent_at || null,
        received_at: form.received_at,
        reference: form.reference.trim() || null,
        nbu_rate: parseAmount(form.nbu_rate),
        correction_note: form.correction_note.trim() || null,
    };
}

async function save(): Promise<void> {
    saving.value = true;
    saveError.value = null;

    try {
        const { data } = existing.value === null
            ? await api.post<ApiResponse<AccountTransfer>>(path('transfers'), body(), { 'Idempotency-Key': idempotencyKey })
            : await api.patch<ApiResponse<AccountTransfer>>(path(`transfers/${existing.value.id}`), body());

        await router.replace({ name: 'transfer', params: { id: data.id } });
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

const generalError = computed(() => {
    const fields = ['from_account_id', 'to_account_id', 'amount_sent', 'amount_received', 'fee_amount', 'sent_at', 'received_at', 'nbu_rate'];

    if (!saveError.value || needsNote.value || fields.some((field) => fieldError(saveError.value, field))) {
        return null;
    }

    return errorMessage(saveError.value);
});
</script>

<template>
    <SubScreen :title="isNew ? t('money.transferForm.newTransfer') : t('money.transferForm.editTransfer')" :back="isNew ? { name: 'transfers' } : { name: 'transfer', params: { id: transferId } }">
        <LoadState :loading="loading" :error="loadError" @retry="reload">
            <section class="flex flex-col gap-2 rounded-card border border-line bg-surface p-4" :aria-label="t('money.transferForm.route')">
                <SelectField
                    v-model="form.from_account_id"
                    :label="t('money.transferForm.from')"
                    :placeholder="t('core.common.chooseAccount')"
                    :options="options(form.to_account_id)"
                    :error="fieldError(saveError, 'from_account_id')"
                />
                <span v-if="from" class="-mt-1 flex flex-wrap items-center gap-x-1 text-12 text-ink-3">
                    {{ fromKind }}
                    <template v-if="from.balance !== undefined">
                        · {{ t('money.transferForm.balance') }}
                        <button
                            type="button"
                            class="num min-h-8 font-semibold text-accent"
                            :aria-label="t('money.transferForm.sendBalance', { balance: formatMoney(from.balance, from.currency) })"
                            @click="sendBalance"
                        >
                            {{ formatMoney(from.balance, from.currency) }}
                        </button>
                    </template>
                </span>
                <div class="relative flex items-center justify-center py-1">
                    <span class="absolute inset-x-0 top-1/2 border-t border-divider" aria-hidden="true" />
                    <button
                        type="button"
                        :aria-label="t('money.transferForm.swap')"
                        class="relative flex size-11 items-center justify-center rounded-full border border-line-input bg-surface text-ink"
                        @click="swap"
                    >
                        <Icon name="swap" :size="20" />
                    </button>
                </div>
                <SelectField
                    v-model="form.to_account_id"
                    :label="t('money.transferForm.to')"
                    :placeholder="t('core.common.chooseAccount')"
                    :options="options(form.from_account_id)"
                    :hint="to ? ACCOUNT_KINDS[to.kind].label : undefined"
                    :error="fieldError(saveError, 'to_account_id')"
                />
            </section>

            <div class="grid grid-cols-2 gap-2.5">
                <TextField
                    v-model="form.amount_sent"
                    :label="t('money.transferForm.sentIn', { currency: from?.currency ?? '' })"
                    numeric
                    :error="fieldError(saveError, 'amount_sent')"
                />
                <TextField
                    v-model="form.amount_received"
                    :label="t('money.paymentForm.receivedIn', { currency: to?.currency ?? '' })"
                    numeric
                    :error="fieldError(saveError, 'amount_received')"
                    @update:model-value="touchedReceived = true"
                />
                <DateField v-model="form.sent_at" :label="t('money.transfer.sent')" :max="form.received_at || undefined" :error="fieldError(saveError, 'sent_at')" />
                <DateField v-model="form.received_at" :label="t('money.transfer.credited')" :max="today()" :error="fieldError(saveError, 'received_at')" />
            </div>

            <div v-if="fee !== null" class="flex items-center justify-between gap-3 rounded-control bg-muted px-3.5 py-3 text-13 text-ink-2">
                <span>{{ t('money.transferForm.feeSentReceived') }}</span>
                <span class="num font-semibold" :class="compareAmounts(fee, '0') < 0 ? 'text-negative' : 'text-ink'">
                    {{ formatMoney(fee, from?.currency ?? '') }}<template v-if="feePercent"> · {{ feePercent }} %</template>
                </span>
            </div>
            <template v-if="isSale">
                <div v-if="effectiveRate" class="flex flex-col gap-1 rounded-control bg-muted px-3.5 py-3 text-13 text-ink-2">
                    <span class="flex justify-between gap-3">
                        <span>{{ t('money.transfer.transferRate') }}</span>
                        <span class="num font-semibold text-ink">{{ formatAmount(effectiveRate) }}<template v-if="nbuRate"> · {{ t('money.transferForm.nbuRate', { rate: formatAmount(nbuRate) }) }}</template></span>
                    </span>
                    <span v-if="spread" class="flex justify-between gap-3">
                        <span>{{ t('money.transfers.fxSpread') }}</span>
                        <span class="num font-semibold text-ink">{{ formatMoney(spread, 'UAH') }}</span>
                    </span>
                </div>
                <TextField
                    v-model="form.fee_amount"
                    :label="t('money.transferForm.separateBankFee', { currency: from?.currency ?? '' })"
                    numeric
                    placeholder="0.00"
                    :hint="t('money.transferForm.separateFeeHint')"
                    :error="fieldError(saveError, 'fee_amount')"
                />
            </template>

            <TextField
                v-if="needsRate"
                v-model="form.nbu_rate"
                :label="t('money.transferForm.nbuRateManual')"
                numeric
                :hint="t('money.transferForm.manualRateHint')"
                :error="fieldError(saveError, 'nbu_rate')"
            />

            <section v-if="proposal && proposal.candidates.length" class="flex flex-col gap-3">
                <SectionTitle>{{ t('money.transferForm.whichReceipts') }}</SectionTitle>
                <SegmentedControl
                    v-model="mode"
                    :label="t('money.transferForm.allocation')"
                    :options="modeOptions"
                />
                <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                    <div v-for="candidate in candidates" :key="candidate.payment_id" class="flex items-center gap-3 py-3">
                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="truncate text-15">
                                <span class="num">{{ formatDayMonth(candidate.received_at) }}</span> ·
                                <span :class="candidate.invoice_number ? 'num' : ''">{{ candidate.invoice_number ?? candidate.description ?? candidate.client_name }}</span>
                            </span>
                            <span class="text-12 text-ink-3">
                                <Translated :text="t('money.transferForm.availableAmount')"><template #amount><span class="num">{{ formatAmount(candidate.holding) }}</span></template></Translated>
                            </span>
                        </span>
                        <input
                            v-model="allocations[candidate.payment_id]"
                            inputmode="decimal"
                            :aria-label="t('money.transferForm.amountReceipt', { receipt: candidate.invoice_number ?? candidate.payment_id })"
                            placeholder="0.00"
                            class="num h-11 w-32 rounded-control border border-line-input bg-surface px-3 text-right text-16"
                            :class="allocations[candidate.payment_id] ? '' : 'bg-muted'"
                        >
                    </div>
                    <div class="flex items-center justify-between py-3">
                        <span
                            class="flex items-center gap-1.5 text-15 font-semibold"
                            :class="{ 'text-positive': allocationState === 'full', 'text-warning-text': allocationState === 'partial', 'text-negative': allocationState === 'over' }"
                        >
                            <Icon v-if="allocationState === 'full'" name="check" :size="18" />
                            {{ allocationState === 'over' ? t('money.transferForm.tooMuch') : allocationState === 'partial' ? t('money.transferForm.partial') : t('money.transferForm.allocated') }}
                        </span>
                        <span class="num text-15 font-semibold">{{ formatAmount(allocated) }} / {{ formatAmount(sumAmounts(sent)) }}</span>
                    </div>
                </div>
                <p class="m-0 text-13 leading-normal text-ink-3">
                    {{ t('money.transferForm.allocationHint') }}
                </p>
            </section>

            <p v-if="proposalFailed" class="m-0 flex flex-wrap items-center gap-x-2 text-14 text-warning-text" role="alert">
                {{ t('money.transferForm.proposalFailed') }}
                <button type="button" class="min-h-11 font-medium text-accent" @click="requestProposal">{{ t('core.common.retry') }}</button>
            </p>

            <TextField v-model="form.reference" :label="t('money.transferForm.referenceNote')" :placeholder="t('core.common.optional')" />

            <TextAreaField
                v-if="needsNote"
                v-model="form.correction_note"
                :label="t('money.payment.correctionQuestion')"
                :rows="2"
            />

            <p v-if="needsNote" class="m-0 text-14 text-warning-text" role="alert">{{ errorMessage(saveError) }}</p>
            <p v-else-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
        </LoadState>

        <template v-if="can(isNew ? 'create' : 'update', 'account-transfers')" #footer>
            <PrimaryButton
                :busy="saving"
                :disabled="loading || proposing || !form.from_account_id || !form.to_account_id || sent === null || received === null || allocationState === 'over'"
                @click="save"
            >
                {{ isNew ? t('money.transferForm.saveTransfer') : t('core.common.saveChanges') }}
            </PrimaryButton>
        </template>
    </SubScreen>
</template>
