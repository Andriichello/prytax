<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { Account, AccountOpeningBalance, ApiResponse } from '@/api/types';
import AccountDetailsFields from '@/components/account/AccountDetailsFields.vue';
import type { AccountForm } from '@/components/account/AccountFormModel';
import AccountLimitFields from '@/components/account/AccountLimitFields.vue';
import AccountMainFields from '@/components/account/AccountMainFields.vue';
import OpeningBalanceSheet from '@/components/account/OpeningBalanceSheet.vue';
import AccordionGroup from '@/components/form/AccordionGroup.vue';
import DateField from '@/components/form/DateField.vue';
import Dimmable from '@/components/form/Dimmable.vue';
import ReadRow from '@/components/form/ReadRow.vue';
import SwitchCard from '@/components/form/SwitchCard.vue';
import TextField from '@/components/form/TextField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import Translated from '@/components/ui/Translated.vue';
import SideNote from '@/components/ui/SideNote.vue';
import { provideAccordion } from '@/composables/useAccordion';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { ACCOUNT_KINDS } from '@/utils/accounts';
import { formatDate, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { formatMoney, parseAmount } from '@/utils/money';

/**
 * Рахунок — новий або редагування (M-AccountEdit, design.md §16.5). A new account is one form with its own «Зберегти»
 * and its first balance. An existing one is an accordion: Основне (name, kind; the currency is fixed), Реквізити для
 * інвойсу (stored encrypted), Ліміт (client receipts over a calendar month, quarter or year; none for a personal
 * account), each saved on its own, then its balances on dates and an «Активний» switch, which saves at once.
 */
type Group = 'main' | 'details' | 'limit';

const route = useRoute();
const router = useRouter();
const { can } = useSession();
const { profileId, path } = useCurrentProfile();
const accordion = provideAccordion();

const accountId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isNew = computed(() => accountId.value === null);

const blank = (): AccountForm => ({
    name: '',
    kind: 'platform',
    currency: 'USD',
    holder_name: '',
    bank_name: '',
    account_number: '',
    swift: '',
    iban: '',
    details: [],
    hasLimit: false,
    limit_amount: '',
    limit_period: 'year',
});

/** The form: a new account's fields, or the open group's copy of the account's. */
const form = ref<AccountForm>(blank());
const opening = ref({ as_of: today(), amount: '' });

const balances = ref<AccountOpeningBalance[]>([]);
const sortBalances = (list: AccountOpeningBalance[]) => [...list].sort((a, b) => b.as_of.localeCompare(a.as_of));

function formOf(account: Account): AccountForm {
    return {
        name: account.name,
        kind: account.kind,
        currency: account.currency,
        holder_name: account.holder_name ?? '',
        bank_name: account.bank_name ?? '',
        account_number: account.account_number ?? '',
        swift: account.swift ?? '',
        iban: account.iban ?? '',
        details: (account.details ?? []).map((detail) => ({ ...detail })),
        hasLimit: account.limit_amount !== null,
        limit_amount: account.limit_amount ?? '',
        limit_period: account.limit_period ?? 'year',
    };
}

const { data: account, error: loadError, loading, reload } = useLoader(async (signal) => {
    if (accountId.value === null) {
        return null;
    }

    const { data } = await api.get<ApiResponse<Account>>(path(`accounts/${accountId.value}`), { include: ['openingBalances'] }, signal);

    balances.value = sortBalances(data.openingBalances ?? []);

    return data;
}, [profileId, accountId]);

const canSave = computed(() => can(isNew.value ? 'create' : 'update', 'accounts'));

/* Opening balances of an existing account, saved on their own */
const balanceOpen = ref(false);
const balance = ref<AccountOpeningBalance | null>(null);

function openBalance(item: AccountOpeningBalance | null): void {
    balance.value = item;
    balanceOpen.value = true;
}

async function reloadBalances(): Promise<void> {
    balances.value = sortBalances(await api.all<AccountOpeningBalance>(path(`accounts/${accountId.value}/opening-balances`)));
}

/** The fields of each group, as the API takes them. */
function fields(group: Group | 'all'): Record<string, unknown> {
    const compact = (value: string): string | null => value.replace(/\s/g, '').toUpperCase() || null;
    const values = form.value;
    const limited = values.hasLimit && values.kind !== 'personal';
    const main = { name: values.name.trim(), kind: values.kind };
    const details = {
        holder_name: values.holder_name.trim() || null,
        bank_name: values.bank_name.trim() || null,
        account_number: values.account_number.trim() || null,
        swift: compact(values.swift),
        iban: compact(values.iban),
        details: values.details.filter((detail) => detail.label.trim() && detail.value.trim())
            .map((detail) => ({ label: detail.label.trim(), value: detail.value.trim() })),
    };
    const limit = {
        limit_amount: limited ? parseAmount(values.limit_amount) ?? values.limit_amount : null,
        limit_period: limited ? values.limit_period : null,
    };

    switch (group) {
        case 'main':
            return main;
        case 'details':
            return details;
        case 'limit':
            return limit;
        default:
            return { ...main, currency: values.currency, ...details, ...limit };
    }
}

const saving = ref(false);
const saveError = ref<unknown>(null);
const openingError = ref<unknown>(null);

function open(): void {
    if (account.value) {
        form.value = formOf(account.value);
    }

    saveError.value = null;
}

/** Save a group of an existing account. */
async function saveGroup(group: Group): Promise<void> {
    saving.value = true;
    saveError.value = null;

    try {
        await api.patch(path(`accounts/${accountId.value}`), fields(group));
        await reload();
        accordion.open.value = null;
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

/** Create the account with its first balance; if the balance fails, the new account opens to add it again. */
async function create(): Promise<void> {
    saving.value = true;
    saveError.value = null;
    openingError.value = null;

    try {
        const { data } = await api.post<ApiResponse<Account>>(path('accounts'), fields('all'));

        if (opening.value.amount.trim()) {
            try {
                await api.post(path(`accounts/${data.id}/opening-balances`), {
                    as_of: opening.value.as_of,
                    amount: parseAmount(opening.value.amount) ?? opening.value.amount,
                });
            } catch (caught) {
                openingError.value = caught;
                await router.replace({ name: 'account-edit', params: { id: data.id } });

                return;
            }
        }

        await router.replace({ name: 'account', params: { id: data.id } });
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

/** The «Активний» switch saves at once. */
const switching = ref(false);

async function setActive(value: boolean): Promise<void> {
    switching.value = true;

    try {
        await api.patch(path(`accounts/${accountId.value}`), { is_active: value });
        await reload();
    } catch (caught) {
        saveError.value = caught;
    } finally {
        switching.value = false;
    }
}

const errors = computed(() => ({
    account_number: fieldError(saveError.value, 'account_number'),
    swift: fieldError(saveError.value, 'swift'),
    iban: fieldError(saveError.value, 'iban'),
}));
const generalError = computed(() => {
    const names = ['name', 'kind', 'currency', 'iban', 'swift', 'account_number', 'limit_amount', 'limit_period'];

    return saveError.value && !names.some((name) => fieldError(saveError.value, name)) ? errorMessage(saveError.value) : null;
});
const limitMissing = computed(() => form.value.hasLimit && form.value.kind !== 'personal' && form.value.limit_amount.trim() === '');

/** The name of a limit's period. */
const periodName = (period: string | null): string => (period === 'month'
    ? t('core.common.month')
    : period === 'quarter' ? t('core.common.quarter') : t('core.common.year'));

/** The masked tail of a number: `•••• 7731`. */
const masked = (value: string | null): string | null => (value ? `•••• ${value.replace(/\s/g, '').slice(-4)}` : null);
</script>

<template>
    <SubScreen
        :title="isNew ? t('money.accountForm.newAccount') : account?.name ?? t('money.account.account')"
        :back="isNew ? { name: 'accounts' } : { name: 'account', params: { id: accountId } }"
    >
        <template #aside>
            <SideNote :text="t('settings.sideNotes.account')" />
        </template>

        <template v-if="isNew">
            <section class="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-4">
                <AccountMainFields v-model="form" is-new :name-error="fieldError(saveError, 'name')" />
            </section>
            <section class="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-4">
                <h2 class="m-0 text-16 font-semibold">{{ t('money.accountForm.paymentDetailsInvoices') }}</h2>
                <AccountDetailsFields v-model="form" :errors="errors" />
            </section>
            <section v-if="form.kind !== 'personal'" class="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-4">
                <h2 class="m-0 text-16 font-semibold">{{ t('money.accountForm.limit') }}</h2>
                <AccountLimitFields v-model="form" :error="fieldError(saveError, 'limit_amount')" />
            </section>
            <section class="flex flex-col gap-3">
                <SectionTitle>{{ t('money.accountForm.openingBalance') }}</SectionTitle>
                <div class="grid grid-cols-2 gap-2.5">
                    <DateField v-model="opening.as_of" :label="t('core.common.asOf')" :max="today()" />
                    <TextField
                        v-model="opening.amount"
                        :label="t('core.common.amountInCurrency', { currency: form.currency })"
                        numeric
                        :placeholder="t('money.accountForm.none')"
                    />
                </div>
                <p class="m-0 text-13 leading-normal text-ink-3">{{ t('money.accountForm.openingBalanceHint') }}</p>
            </section>
            <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
        </template>

        <LoadState v-else :loading="loading && !account" :error="loadError" @retry="reload">
            <template v-if="account">
                <AccordionGroup
                    id="main"
                    :title="t('money.accountForm.mainGroup')"
                    :editable="canSave"
                    :saving="saving"
                    :error="generalError"
                    @open="open"
                    @save="saveGroup('main')"
                >
                    <ReadRow :label="t('core.common.name')" :value="account.name" />
                    <ReadRow :label="t('money.accountForm.type')" :value="ACCOUNT_KINDS[account.kind].label" />
                    <ReadRow :label="t('core.common.currency')" :value="account.currency" />
                    <template #edit>
                        <AccountMainFields v-model="form" :is-new="false" :name-error="fieldError(saveError, 'name')" />
                    </template>
                </AccordionGroup>

                <AccordionGroup
                    id="details"
                    :title="t('money.accountForm.paymentDetailsInvoices')"
                    :editable="canSave"
                    :saving="saving"
                    :error="generalError"
                    @open="open"
                    @save="saveGroup('details')"
                >
                    <ReadRow :label="t('money.accountForm.holder')" :value="account.holder_name" />
                    <ReadRow :label="t('money.account.bank')" :value="account.bank_name" />
                    <ReadRow :label="t('money.accountForm.accountNumber')"><span class="num">{{ masked(account.account_number) ?? '—' }}</span></ReadRow>
                    <ReadRow label="SWIFT"><span class="num">{{ account.swift ?? '—' }}</span></ReadRow>
                    <ReadRow label="IBAN"><span class="num">{{ masked(account.iban) ?? t('money.accountForm.none') }}</span></ReadRow>
                    <ReadRow v-for="detail in account.details ?? []" :key="detail.label" :label="detail.label">
                        <span class="num">{{ detail.value }}</span>
                    </ReadRow>
                    <template #edit>
                        <AccountDetailsFields v-model="form" :errors="errors" />
                    </template>
                </AccordionGroup>

                <AccordionGroup
                    v-if="account.kind !== 'personal'"
                    id="limit"
                    :title="t('money.accountForm.limit')"
                    :editable="canSave"
                    :saving="saving"
                    :error="generalError"
                    @open="open"
                    @save="!limitMissing && saveGroup('limit')"
                >
                    <template v-if="account.limit_amount !== null">
                        <ReadRow :label="t('money.accountForm.limit')"><span class="num">{{ formatMoney(account.limit_amount, account.currency) }}</span></ReadRow>
                        <ReadRow :label="t('core.common.period')" :value="periodName(account.limit_period)" />
                    </template>
                    <ReadRow v-else :label="t('money.accountForm.limit')" :value="t('money.accountForm.noLimit')" />
                    <template #edit>
                        <AccountLimitFields v-model="form" :error="fieldError(saveError, 'limit_amount')" />
                    </template>
                </AccordionGroup>

                <Dimmable class="flex flex-col gap-3">
                    <SectionTitle>
                        {{ t('money.accountForm.openingBalance') }}
                        <template v-if="can('create', 'account-opening-balances')" #aside>
                            <button type="button" class="flex min-h-11 items-center text-15 font-medium text-accent" @click="openBalance(null)">
                                {{ t('core.common.plusAdd') }}
                            </button>
                        </template>
                    </SectionTitle>
                    <div v-if="balances.length" class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                        <button
                            v-for="item in balances"
                            :key="item.id"
                            type="button"
                            class="flex min-h-13 items-center justify-between gap-3 text-left"
                            :disabled="!can('update', 'account-opening-balances')"
                            @click="openBalance(item)"
                        >
                            <span class="text-15">
                                <Translated :text="t('core.common.asOfDate')"><template #date><span class="num">{{ formatDate(item.as_of) }}</span></template></Translated>
                            </span>
                            <span class="num text-16 font-semibold">{{ formatMoney(item.amount, account.currency) }}</span>
                        </button>
                    </div>
                    <p v-if="openingError" class="m-0 text-14 text-negative" role="alert">
                        {{ t('money.accountForm.balanceNotSaved', { error: errorMessage(openingError) }) }}
                    </p>
                    <p class="m-0 text-13 leading-normal text-ink-3">{{ t('money.accountForm.openingBalanceHint') }}</p>
                </Dimmable>

                <Dimmable>
                    <SwitchCard
                        :model-value="account.is_active"
                        :title="t('core.common.active')"
                        :sub="t('money.accountForm.inactiveHint')"
                        :disabled="!canSave || switching"
                        @update:model-value="setActive"
                    />
                </Dimmable>

                <Dimmable>
                    <p class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('core.accordion.oneAtATime') }}</p>
                </Dimmable>
            </template>
        </LoadState>

        <template v-if="isNew && canSave" #footer>
            <PrimaryButton :busy="saving" :disabled="!form.name.trim() || limitMissing" @click="create">{{ t('core.common.save') }}</PrimaryButton>
        </template>

        <OpeningBalanceSheet
            v-if="accountId !== null && account"
            v-model:open="balanceOpen"
            :account-id="accountId"
            :currency="account.currency"
            :balance="balance"
            @saved="reloadBalances"
        />
    </SubScreen>
</template>
