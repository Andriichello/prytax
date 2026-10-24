<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '@/api/client';
import type { Account, AccountStatement, ApiResponse, StatementEntry, StatementMovementEntry } from '@/api/types';
import MovementSheet from '@/components/account/MovementSheet.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import CopyRow from '@/components/ui/CopyRow.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PeriodStepper from '@/components/ui/PeriodStepper.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import Translated from '@/components/ui/Translated.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { ACCOUNT_KINDS, MOVEMENT_CATEGORIES, type MovementDraft } from '@/utils/accounts';
import { currentQuarter, formatDate, formatDayMonth, quarterOf, quarterLabel } from '@/utils/dates';
import { formatAmount, formatMoney } from '@/utils/money';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * Рахунок (S-AccountDetail): the balance from tracked movements (counted from the latest opening balance) with what
 * came in and went out this year, the receipts whose money is still here (late ones marked), the history of
 * movements, where bank fees, interest, expenses and withdrawals are added and changed, and the requisites printed
 * on invoices, each copyable.
 */
const route = useRoute();
const { profileId, path } = useCurrentProfile();
const { can } = useSession();

const id = computed(() => Number(route.params.id));
const now = currentQuarter().year;
const year = ref(now);

const { data, error, loading, reload } = useLoader(async (signal) => {
    const [account, statement] = await Promise.all([
        api.get<ApiResponse<Account>>(path(`accounts/${id.value}`), { include: ['openingBalances'] }, signal),
        api.get<ApiResponse<AccountStatement>>(path(`accounts/${id.value}/statement`), { year: year.value }, signal),
    ]);

    return { account: account.data, statement: statement.data };
}, [profileId, id, year]);

const account = computed(() => data.value?.account ?? null);
const statement = computed(() => data.value?.statement ?? null);

/** The opening balance the tracked balance starts from: the latest one. */
const opening = computed(() => [...account.value?.openingBalances ?? []].sort((a, b) => b.as_of.localeCompare(a.as_of))[0] ?? null);

const hasRequisites = computed(() => Boolean(account.value && (
    account.value.holder_name || account.value.bank_name || account.value.iban || account.value.swift
    || account.value.account_number || account.value.details?.length
)));

/* Movements, which aren't income or transfers: bank fees, interest, expenses and withdrawals */
const movementOpen = ref(false);
const movement = ref<MovementDraft | null>(null);

function openMovement(entry: StatementMovementEntry | null): void {
    movement.value = entry && {
        id: entry.movement_id,
        direction: entry.amount.startsWith('-') ? 'out' : 'in',
        amount: entry.amount.replace(/^-/, ''),
        booked_at: entry.date,
        category: entry.category,
        note: entry.title,
    };
    movementOpen.value = true;
}

const entryTag = (entry: StatementEntry) => (entryLink(entry) ? 'RouterLink' : entry.type === 'movement' ? 'button' : 'div');

const mask = (value: string): string => {
    const digits = value.replace(/\s/g, '');

    return digits.length > 8 ? `•••• •••• ${digits.slice(-4)}` : `•••• ${digits.slice(-4)}`;
};

function entryTitle(entry: StatementEntry): string {
    switch (entry.type) {
        case 'receipt':
            return t('money.account.receiptTitle', { title: entry.title ?? entry.invoice_number ?? '' });
        case 'transfer_in':
            return t('money.account.transferIn', { account: entry.title });
        case 'transfer_out':
            return t('money.account.transferOut', { account: entry.title });
        case 'tax':
            return t('money.account.taxPayment', { tax: TAX_TYPES[entry.title] });
        case 'refund':
            return entry.title ? t('money.account.refundReason', { reason: entry.title }) : t('money.refund.refundClient');
        default:
            return entry.title ? `${MOVEMENT_CATEGORIES[entry.category].label} · ${entry.title}` : MOVEMENT_CATEGORIES[entry.category].label;
    }
}

function entryLink(entry: StatementEntry) {
    switch (entry.type) {
        case 'receipt':
        case 'refund':
            return { name: 'payment', params: { id: entry.payment_id } };
        case 'transfer_in':
        case 'transfer_out':
            return { name: 'transfer', params: { id: entry.transfer_id } };
        default:
            return null;
    }
}
</script>

<template>
    <SubScreen :title="t('money.account.account')" muted :back="{ name: 'accounts' }">
        <template #action>
            <RouterLink
                v-if="account && can('update', 'accounts')"
                :to="{ name: 'account-edit', params: { id } }"
                class="flex min-h-11 items-center px-3 text-15 font-medium no-underline"
            >
                {{ t('core.common.edit') }}
            </RouterLink>
        </template>

        <LoadState :loading="loading && !data" :error="error" @retry="reload">
            <template v-if="account && statement">
                <div class="-mt-1 flex flex-col gap-1.5">
                    <div class="flex gap-2">
                        <StatusBadge :label="`${ACCOUNT_KINDS[account.kind].label} · ${account.currency}`" />
                        <StatusBadge v-if="!account.is_active" :label="t('core.common.inactive')" tone="negative" />
                    </div>
                    <h1 class="m-0 text-28 font-semibold tracking-[-0.02em]">{{ account.name }}</h1>
                </div>

                <section class="flex flex-col gap-3 rounded-card border border-line bg-surface p-4" :aria-label="t('money.account.balance')">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-14 text-ink-3">{{ t('money.account.balanceTrackedMovements') }}</span>
                        <span class="num text-30 font-bold">{{ formatMoney(statement.balance, account.currency) }}</span>
                    </div>
                    <div class="flex flex-col divide-y divide-divider border-t border-divider text-15">
                        <div class="flex justify-between gap-3 py-2.5">
                            <span class="text-ink-2">{{ t('core.common.receipts') }}</span>
                            <span class="num font-semibold text-positive">{{ formatAmount(statement.flows.inbound, { signed: true }) }}</span>
                        </div>
                        <div class="flex justify-between gap-3 py-2.5">
                            <span class="text-ink-2">{{ t('money.transfers.title') }}</span>
                            <span class="num font-semibold text-negative">{{ formatAmount(`-${statement.flows.outbound}`) }}</span>
                        </div>
                    </div>
                    <template v-if="statement.limit">
                        <ProgressBar :percent="Number(statement.limit.percent)" :warn-from="80" />
                        <span class="flex justify-between gap-3 text-13 text-ink-2">
                            <span>{{ t('money.account.limitWindow', { from: formatDate(statement.limit.from), to: formatDate(statement.limit.to) }) }}</span>
                            <span class="num">{{ formatAmount(statement.limit.used) }} / {{ formatAmount(statement.limit.limit) }} · {{ statement.limit.percent }} %</span>
                        </span>
                    </template>
                    <p class="m-0 text-12 leading-normal text-ink-3">
                        <Translated :text="t('money.account.yearNote')">
                            <template #year><span class="num">{{ statement.year }}</span></template>
                        </Translated>
                        <template v-if="opening">
                            · <Translated :text="t('money.account.fromOpeningBalance')">
                                <template #amount><span class="num">{{ formatMoney(opening.amount, account.currency) }}</span></template>
                                <template #date><span class="num">{{ formatDate(opening.as_of) }}</span></template>
                            </Translated>
                        </template>
                    </p>
                </section>

                <section v-if="statement.holdings.length" class="flex flex-col gap-2">
                    <SectionTitle>
                        {{ t('money.account.receiptsNotWithdrawn') }}
                        <template v-if="can('create', 'account-transfers') && account.is_active" #aside>
                            <RouterLink :to="{ name: 'transfer-new', query: { from: account.id } }" class="flex min-h-11 items-center text-15 font-medium no-underline">
                                {{ t('money.account.withdraw') }}
                            </RouterLink>
                        </template>
                    </SectionTitle>
                    <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                        <RouterLink
                            v-for="holding in statement.holdings"
                            :key="holding.payment_id"
                            :to="{ name: 'payment', params: { id: holding.payment_id } }"
                            class="flex items-center justify-between gap-3 py-3 text-ink no-underline hover:text-ink"
                        >
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="truncate text-15">
                                    <span class="num">{{ formatDayMonth(holding.received_at) }}</span> ·
                                    <span :class="holding.invoice_number ? 'num' : ''">{{ holding.invoice_number ?? holding.description }}</span>
                                </span>
                                <span v-if="holding.is_late && holding.repatriation_deadline" class="text-12 text-warning-text">
                                    {{ t('money.account.lateSince', { quarter: quarterLabel(quarterOf(holding.received_at)), date: formatDayMonth(holding.repatriation_deadline) }) }}
                                </span>
                                <span v-else class="truncate text-12 text-ink-3">{{ holding.client_name }}</span>
                            </span>
                            <span class="num text-15 font-semibold">{{ formatAmount(holding.amount) }}</span>
                        </RouterLink>
                    </div>
                </section>

                <section class="flex flex-col gap-2">
                    <SectionTitle>
                        {{ t('money.account.moneyMovement') }}
                        <template v-if="can('create', 'account-movements')" #aside>
                            <button type="button" class="flex min-h-11 items-center text-15 font-medium text-accent" @click="openMovement(null)">{{ t('money.account.addMovement') }}</button>
                        </template>
                    </SectionTitle>
                    <PeriodStepper
                        :label="t('money.account.yearTitle', { year })"
                        :can-next="year < now"
                        :previous-label="t('core.common.previousYear')"
                        :next-label="t('core.common.nextYear')"
                        @previous="year -= 1"
                        @next="year += 1"
                    />
                    <EmptyState v-if="statement.history.length === 0" :text="t('money.account.noMovementsYear')" />
                    <div v-else class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                        <component
                            :is="entryTag(entry)"
                            v-for="(entry, index) in statement.history"
                            :key="`${entry.type}-${index}`"
                            :to="entryLink(entry) ?? undefined"
                            :type="entry.type === 'movement' ? 'button' : undefined"
                            class="flex items-center justify-between gap-3 py-3 text-left text-ink no-underline hover:text-ink"
                            @click="entry.type === 'movement' && openMovement(entry)"
                        >
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="truncate text-15">{{ entryTitle(entry) }}</span>
                                <span class="text-12 text-ink-3">
                                    <span class="num">{{ formatDate(entry.date) }}</span>
                                    <template v-if="(entry.type === 'receipt' || entry.type === 'transfer_out') && entry.fee && Number(entry.fee) !== 0">
                                        · <Translated :text="t('money.flow.feeAmount')"><template #fee><span class="num">{{ formatAmount(entry.fee) }}</span></template></Translated>
                                    </template>
                                    <template v-if="entry.type === 'tax' && entry.status === 'sent'"> · {{ t('money.account.sent') }}</template>
                                </span>
                            </span>
                            <span class="num text-15 font-semibold" :class="entry.amount.startsWith('-') ? 'text-negative' : 'text-positive'">
                                {{ formatAmount(entry.amount, { signed: true }) }}
                            </span>
                        </component>
                    </div>
                </section>

                <section v-if="hasRequisites" class="flex flex-col gap-2">
                    <SectionTitle>{{ t('money.account.paymentDetailsInvoices') }}</SectionTitle>
                    <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface pr-1 pl-4">
                        <CopyRow v-if="account.holder_name" :label="t('core.common.recipient')" :value="account.holder_name" :figure="false" />
                        <CopyRow v-if="account.bank_name" :label="t('money.account.bank')" :value="account.bank_name" :figure="false" />
                        <CopyRow v-if="account.iban" label="IBAN" :value="account.iban" :shown="mask(account.iban)" />
                        <CopyRow v-if="account.account_number" label="Account number" :value="account.account_number" :shown="mask(account.account_number)" />
                        <CopyRow v-if="account.swift" label="SWIFT / BIC" :value="account.swift" />
                        <CopyRow
                            v-for="detail in account.details ?? []"
                            :key="detail.label"
                            :label="detail.label"
                            :value="detail.value"
                            :shown="/^\d{6,}$/.test(detail.value.replace(/\s/g, '')) ? mask(detail.value) : undefined"
                        />
                    </div>
                </section>

                <RouterLink
                    v-else-if="can('update', 'accounts')"
                    :to="{ name: 'account-edit', params: { id } }"
                    class="flex h-12 items-center justify-center gap-2 rounded-control border border-dashed border-switch-off text-15 font-medium text-accent no-underline"
                >
                    <Icon name="plus" :size="20" />
                    {{ t('money.account.addPaymentDetails') }}
                </RouterLink>
            </template>
        </LoadState>

        <MovementSheet
            v-if="account"
            v-model:open="movementOpen"
            :account-id="account.id"
            :currency="account.currency"
            :movement="movement"
            :editable="can(movement ? 'update' : 'create', 'account-movements')"
            @saved="reload"
        />
    </SubScreen>
</template>
