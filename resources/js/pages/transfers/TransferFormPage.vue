<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError, api, newIdempotencyKey } from '@/api/client';
import type { Account, AccountTransfer, ApiResponse, ExchangeRate, Paginated, TransferProposal } from '@/api/types';
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
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useSession } from '@/composables/useSession';
import { useLoader } from '@/composables/useLoader';
import { ACCOUNT_KINDS, accountLabel } from '@/utils/accounts';
import { formatDayMonth, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { compareAmounts, formatAmount, formatMoney, multiplyDecimals, parseAmount, subtractAmounts, sumAmounts } from '@/utils/money';

/**
 * Новий переказ (S-TransferNew), and its editing: money moved between two of the profile's accounts, what arrived,
 * the fee, and which receipts' money it moves (oldest first, or the one that matches exactly).
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
        api.get<Paginated<Account>>(path('accounts'), { append: ['balance'], page: { size: 50 } }, signal),
        transferId.value === null
            ? Promise.resolve(null)
            : api.get<ApiResponse<AccountTransfer>>(path(`transfers/${transferId.value}`), { include: ['allocations'] }, signal).then(({ data }) => data),
    ]);

    accounts.value = accountList.data;
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

    const active = accountList.data.filter((account) => account.is_active);
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

function swap(): void {
    [form.from_account_id, form.to_account_id] = [form.to_account_id, form.from_account_id];
}

/* Same currency: what arrives follows what was sent, until it is typed (the difference is the fee). */
watch(() => [form.amount_sent, isSale.value] as const, ([sent, sale]) => {
    if (!touchedReceived.value && !sale) {
        form.amount_received = sent;
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

watch(() => [isSale.value, from.value?.currency, to.value?.currency, form.received_at] as const, async ([sale, fromCurrency, toCurrency, date]) => {
    nbuRate.value = null;

    const foreign = fromCurrency === 'UAH' ? toCurrency : fromCurrency;

    if (!sale || !foreign || foreign === 'UAH' || !date || (fromCurrency !== 'UAH' && toCurrency !== 'UAH')) {
        return;
    }

    try {
        const { data } = await api.get<ApiResponse<ExchangeRate>>(`exchange-rates/${foreign}/${date}`);

        nbuRate.value = data.rate;
    } catch {
        nbuRate.value = null;
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

/** The loss against the NBU in UAH: what the foreign amount was worth less what was got for it. */
const spread = computed(() => {
    if (!nbuRate.value || !sent.value || !received.value || from.value?.currency === 'UAH') {
        return null;
    }

    return subtractAmounts(multiplyDecimals(sent.value, nbuRate.value), received.value);
});

/* Which receipts' money the transfer moves */
const proposal = ref<TransferProposal | null>(null);
const mode = ref<'fifo' | 'exact'>('fifo');
const allocations = reactive<Record<number, string>>({});
let proposalTimer: ReturnType<typeof setTimeout> | undefined;
let proposalRun = 0;

watch(() => [form.from_account_id, sent.value] as const, ([accountId, amount]) => {
    clearTimeout(proposalTimer);

    if (accountId === null || amount === null || Number(amount) <= 0) {
        proposal.value = null;

        return;
    }

    proposalTimer = setTimeout(async () => {
        const run = ++proposalRun;

        try {
            const { data } = await api.post<ApiResponse<TransferProposal>>(path('transfers/propose'), {
                from_account_id: accountId,
                amount_sent: amount,
                transfer_id: transferId.value ?? undefined,
            });

            if (run !== proposalRun) {
                return;
            }

            const first = proposal.value === null;

            proposal.value = data;

            if (first && existing.value?.allocations) {
                setAllocations(existing.value.allocations.map((allocation) => ({ payment_id: allocation.payment_id, amount: allocation.amount })));
            } else {
                applyMode();
            }
        } catch {
            if (run === proposalRun) {
                proposal.value = null;
            }
        }
    }, 300);
});

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
    return {
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
        allocations: Object.entries(allocations)
            .map(([paymentId, amount]) => ({ payment_id: Number(paymentId), amount: parseAmount(amount) }))
            .filter((allocation) => allocation.amount !== null && Number(allocation.amount) > 0),
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
    <SubScreen :title="isNew ? 'Новий переказ' : 'Редагування переказу'" :back="isNew ? { name: 'transfers' } : { name: 'transfer', params: { id: transferId } }">
        <LoadState :loading="loading" :error="loadError" @retry="reload">
            <section class="flex flex-col gap-2 rounded-card border border-line bg-surface p-4" aria-label="Маршрут">
                <SelectField
                    v-model="form.from_account_id"
                    label="Звідки"
                    placeholder="Оберіть рахунок"
                    :options="options(form.to_account_id)"
                    :hint="from ? `${ACCOUNT_KINDS[from.kind].label}${from.balance !== undefined ? ` · баланс ${formatMoney(from.balance, from.currency)}` : ''}` : undefined"
                    :error="fieldError(saveError, 'from_account_id')"
                />
                <div class="relative flex items-center justify-center py-1">
                    <span class="absolute inset-x-0 top-1/2 border-t border-divider" aria-hidden="true" />
                    <button
                        type="button"
                        aria-label="Поміняти місцями"
                        class="relative flex size-11 items-center justify-center rounded-full border border-line-input bg-surface text-ink"
                        @click="swap"
                    >
                        <Icon name="swap" :size="20" />
                    </button>
                </div>
                <SelectField
                    v-model="form.to_account_id"
                    label="Куди"
                    placeholder="Оберіть рахунок"
                    :options="options(form.from_account_id)"
                    :hint="to ? ACCOUNT_KINDS[to.kind].label : undefined"
                    :error="fieldError(saveError, 'to_account_id')"
                />
            </section>

            <div class="grid grid-cols-2 gap-2.5">
                <TextField
                    v-model="form.amount_sent"
                    :label="`Відправлено, ${from?.currency ?? ''}`"
                    numeric
                    :error="fieldError(saveError, 'amount_sent')"
                />
                <TextField
                    v-model="form.amount_received"
                    :label="`Отримано, ${to?.currency ?? ''}`"
                    numeric
                    :error="fieldError(saveError, 'amount_received')"
                    @update:model-value="touchedReceived = true"
                />
                <DateField v-model="form.sent_at" label="Відправлено" :max="form.received_at || undefined" :error="fieldError(saveError, 'sent_at')" />
                <DateField v-model="form.received_at" label="Зараховано" :max="today()" :error="fieldError(saveError, 'received_at')" />
            </div>

            <div v-if="fee !== null" class="flex items-center justify-between gap-3 rounded-control bg-muted px-3.5 py-3 text-13 text-ink-2">
                <span>Комісія (відправлено − отримано)</span>
                <span class="num font-semibold" :class="compareAmounts(fee, '0') < 0 ? 'text-negative' : 'text-ink'">
                    {{ formatMoney(fee, from?.currency ?? '') }}<template v-if="feePercent"> · {{ feePercent }} %</template>
                </span>
            </div>
            <template v-if="isSale">
                <div v-if="effectiveRate" class="flex flex-col gap-1 rounded-control bg-muted px-3.5 py-3 text-13 text-ink-2">
                    <span class="flex justify-between gap-3">
                        <span>Курс переказу</span>
                        <span class="num font-semibold text-ink">{{ formatAmount(effectiveRate) }}<template v-if="nbuRate"> · НБУ {{ formatAmount(nbuRate) }}</template></span>
                    </span>
                    <span v-if="spread" class="flex justify-between gap-3">
                        <span>Курсовий спред</span>
                        <span class="num font-semibold text-ink">{{ formatMoney(spread, 'UAH') }}</span>
                    </span>
                </div>
                <TextField
                    v-model="form.fee_amount"
                    :label="`Комісія банку окремо, ${from?.currency ?? ''}`"
                    numeric
                    placeholder="0.00"
                    hint="Якщо банк узяв комісію понад курс"
                    :error="fieldError(saveError, 'fee_amount')"
                />
            </template>

            <TextField
                v-if="needsRate"
                v-model="form.nbu_rate"
                label="Курс НБУ, ₴ (вручну)"
                numeric
                hint="Лише поки НБУ не опублікував курс на цю дату."
                :error="fieldError(saveError, 'nbu_rate')"
            />

            <section v-if="proposal && proposal.candidates.length" class="flex flex-col gap-3">
                <SectionTitle>Які надходження переказуєте</SectionTitle>
                <SegmentedControl
                    v-model="mode"
                    label="Розподіл"
                    :options="[
                        { value: 'fifo', label: 'Від найстаріших' },
                        { value: 'exact', label: proposal.exact ? 'Точний збіг' : 'Точний збіг · немає' },
                    ]"
                />
                <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                    <div v-for="candidate in proposal.candidates" :key="candidate.payment_id" class="flex items-center gap-3 py-3">
                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="truncate text-15">
                                <span class="num">{{ formatDayMonth(candidate.received_at) }}</span> ·
                                <span :class="candidate.invoice_number ? 'num' : ''">{{ candidate.invoice_number ?? candidate.description ?? candidate.client_name }}</span>
                            </span>
                            <span class="text-12 text-ink-3">доступно <span class="num">{{ formatAmount(candidate.holding) }}</span></span>
                        </span>
                        <input
                            v-model="allocations[candidate.payment_id]"
                            inputmode="decimal"
                            :aria-label="`Сума з надходження ${candidate.invoice_number ?? candidate.payment_id}`"
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
                            {{ allocationState === 'over' ? 'Забагато' : allocationState === 'partial' ? 'Частково' : 'Розподілено' }}
                        </span>
                        <span class="num text-15 font-semibold">{{ formatAmount(allocated) }} / {{ formatAmount(sumAmounts(sent)) }}</span>
                    </div>
                </div>
                <p class="m-0 text-13 leading-normal text-ink-3">
                    Суми можна змінити. Нерозподілене лишається на рахунку і не губиться.
                </p>
            </section>

            <TextField v-model="form.reference" label="Референс або примітка" placeholder="Необов'язково" />

            <TextAreaField
                v-if="needsNote"
                v-model="form.correction_note"
                label="Квартал уже задекларовано — що уточнюєте?"
                :rows="2"
            />

            <p v-if="needsNote" class="m-0 text-14 text-warning-text" role="alert">{{ errorMessage(saveError) }}</p>
            <p v-else-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
        </LoadState>

        <template v-if="can(isNew ? 'create' : 'update', 'account-transfers')" #footer>
            <PrimaryButton
                :busy="saving"
                :disabled="loading || !form.from_account_id || !form.to_account_id || sent === null || received === null || allocationState === 'over'"
                @click="save"
            >
                {{ isNew ? 'Зберегти переказ' : 'Зберегти зміни' }}
            </PrimaryButton>
        </template>
    </SubScreen>
</template>
