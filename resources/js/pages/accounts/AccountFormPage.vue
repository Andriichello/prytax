<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { Account, AccountKind, AccountOpeningBalance, ApiResponse, LimitCounts, LimitPeriod, LimitWindow } from '@/api/types';
import OpeningBalanceSheet from '@/components/account/OpeningBalanceSheet.vue';
import DateField from '@/components/form/DateField.vue';
import TextField from '@/components/form/TextField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import Translated from '@/components/ui/Translated.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { ACCOUNT_KINDS } from '@/utils/accounts';
import { formatDate, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { formatMoney, parseAmount } from '@/utils/money';

/**
 * Рахунок — новий або редагування (S-AccountEdit): name, kind, currency (fixed once created), the requisites printed
 * on invoices (stored encrypted), an optional limit (not for personal accounts, whose limits aren't tracked), the
 * balances on dates its tracked balance is counted from, and whether the account is active.
 */
const KINDS: AccountKind[] = ['platform', 'fop_bank', 'foreign_bank', 'personal'];
const CURRENCIES = ['USD', 'EUR', 'UAH', 'GBP', 'PLN', 'CHF', 'CAD'];

const route = useRoute();
const router = useRouter();
const { can } = useSession();
const { profileId, path } = useCurrentProfile();

const accountId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isNew = computed(() => accountId.value === null);

const form = reactive({
    name: '',
    kind: 'platform' as AccountKind,
    currency: 'USD',
    holder_name: '',
    bank_name: '',
    account_number: '',
    swift: '',
    iban: '',
    details: [] as { label: string; value: string }[],
    hasLimit: false,
    limit_amount: '',
    limit_period: 'year' as LimitPeriod,
    limit_window: 'calendar' as LimitWindow,
    limit_counts: 'receipts' as LimitCounts,
    is_active: true,
    opening_as_of: today(),
    opening_amount: '',
});

const balances = ref<AccountOpeningBalance[]>([]);
const sortBalances = (list: AccountOpeningBalance[]) => [...list].sort((a, b) => b.as_of.localeCompare(a.as_of));

const { data: existing, error: loadError, loading, reload } = useLoader(async (signal) => {
    if (accountId.value === null) {
        return null;
    }

    const { data } = await api.get<ApiResponse<Account>>(path(`accounts/${accountId.value}`), { include: ['openingBalances'] }, signal);

    Object.assign(form, {
        name: data.name,
        kind: data.kind,
        currency: data.currency,
        holder_name: data.holder_name ?? '',
        bank_name: data.bank_name ?? '',
        account_number: data.account_number ?? '',
        swift: data.swift ?? '',
        iban: data.iban ?? '',
        details: (data.details ?? []).map((detail) => ({ ...detail })),
        hasLimit: data.limit_amount !== null,
        limit_amount: data.limit_amount ?? '',
        limit_period: data.limit_period ?? 'year',
        limit_window: data.limit_window,
        limit_counts: data.limit_counts,
        is_active: data.is_active,
    });
    balances.value = sortBalances(data.openingBalances ?? []);

    return data;
}, [profileId, accountId]);

/* Opening balances of an existing account, saved on their own: reloading them keeps the unsaved form */
const balanceOpen = ref(false);
const balance = ref<AccountOpeningBalance | null>(null);

function openBalance(item: AccountOpeningBalance | null): void {
    balance.value = item;
    balanceOpen.value = true;
}

async function reloadBalances(): Promise<void> {
    balances.value = sortBalances(await api.all<AccountOpeningBalance>(path(`accounts/${accountId.value}/opening-balances`)));
}

const periodOptions = computed<{ value: LimitPeriod; label: string }[]>(() => [
    { value: 'month', label: t('Місяць') },
    { value: 'quarter', label: t('Квартал') },
    { value: 'year', label: t('Рік') },
]);

const windowOptions = computed<{ value: LimitWindow; label: string }[]>(() => [
    { value: 'calendar', label: t('Календарне') },
    { value: 'rolling', label: t('Ковзне') },
]);

/** What a limit counts: the clients' receipts only, or everything coming in. */
const countsOptions = computed<{ value: LimitCounts; title: string; text: string }[]>(() => [
    { value: 'receipts', title: t('Лише надходження від клієнтів'), text: t('Як річний ліміт Payoneer') },
    { value: 'all_inbound', title: t('Усі зарахування'), text: t('Разом із вашими переказами — для лімітів банку') },
]);

const hasLimit = computed(() => form.hasLimit && form.kind !== 'personal');
const limitMissing = computed(() => hasLimit.value && form.limit_amount.trim() === '');

const saving = ref(false);
const saveError = ref<unknown>(null);

function body(): Record<string, unknown> {
    const compact = (value: string): string | null => value.replace(/\s/g, '').toUpperCase() || null;

    return {
        name: form.name.trim(),
        kind: form.kind,
        ...(isNew.value ? { currency: form.currency } : {}),
        holder_name: form.holder_name.trim() || null,
        bank_name: form.bank_name.trim() || null,
        account_number: form.account_number.trim() || null,
        swift: compact(form.swift),
        iban: compact(form.iban),
        details: form.details.filter((detail) => detail.label.trim() && detail.value.trim())
            .map((detail) => ({ label: detail.label.trim(), value: detail.value.trim() })),
        limit_amount: hasLimit.value ? parseAmount(form.limit_amount) ?? form.limit_amount : null,
        limit_period: hasLimit.value ? form.limit_period : null,
        limit_window: form.limit_window,
        limit_counts: form.limit_counts,
        is_active: form.is_active,
    };
}

const openingError = ref<unknown>(null);

async function save(): Promise<void> {
    saving.value = true;
    saveError.value = null;
    openingError.value = null;

    try {
        const { data } = isNew.value
            ? await api.post<ApiResponse<Account>>(path('accounts'), body())
            : await api.patch<ApiResponse<Account>>(path(`accounts/${accountId.value}`), body());

        // A new account's balance on a date is saved once the account exists; if that fails, the account is edited
        if (isNew.value && form.opening_amount.trim()) {
            try {
                await api.post(path(`accounts/${data.id}/opening-balances`), {
                    as_of: form.opening_as_of,
                    amount: parseAmount(form.opening_amount) ?? form.opening_amount,
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

const generalError = computed(() => {
    const fields = ['name', 'kind', 'currency', 'iban', 'swift', 'account_number', 'limit_amount', 'limit_period'];

    return saveError.value && !fields.some((field) => fieldError(saveError.value, field)) ? errorMessage(saveError.value) : null;
});

const tile = 'flex min-h-12 items-center justify-center rounded-control px-2 text-center text-15';
</script>

<template>
    <SubScreen :title="isNew ? t('Новий рахунок') : existing?.name ?? t('Рахунок')" :back="isNew ? { name: 'accounts' } : { name: 'account', params: { id: accountId } }">
        <LoadState :loading="loading" :error="loadError" @retry="reload">
            <TextField v-model="form.name" :label="t('Назва')" :placeholder="t('Напр., Wise USD')" :error="fieldError(saveError, 'name')" />

            <div class="flex flex-col gap-1.5">
                <span id="account-kind" class="text-13 font-medium text-ink-2">{{ t('Тип') }}</span>
                <div role="radiogroup" aria-labelledby="account-kind" class="grid grid-cols-2 gap-2">
                    <button
                        v-for="kind in KINDS"
                        :key="kind"
                        type="button"
                        role="radio"
                        :aria-checked="form.kind === kind ? 'true' : 'false'"
                        :class="[tile, form.kind === kind ? 'border-2 border-accent bg-accent-soft font-semibold text-accent-hover' : 'border border-line-input bg-surface']"
                        @click="form.kind = kind"
                    >
                        {{ ACCOUNT_KINDS[kind].label }}
                    </button>
                </div>
                <span class="text-12 text-ink-3">{{ t('Лише ФОП-рахунок вважається «коштами в Україні»') }}</span>
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="account-currency" class="text-13 font-medium text-ink-2">{{ t('Валюта') }}</label>
                <select
                    id="account-currency"
                    v-model="form.currency"
                    :disabled="!isNew"
                    class="num h-13 w-full appearance-none rounded-control border border-line-input bg-surface px-3.5 text-16 disabled:bg-muted disabled:text-ink-3"
                >
                    <option v-for="currency in CURRENCIES" :key="currency" :value="currency">{{ currency }}</option>
                </select>
                <span class="text-12 text-ink-3">{{ t('Один рахунок — одна валюта. Для іншої валюти створіть окремий.') }}</span>
            </div>

            <section class="flex flex-col gap-3">
                <SectionTitle>{{ t('Реквізити для інвойсу') }}</SectionTitle>
                <TextField v-model="form.holder_name" :label="t('Власник')" :placeholder="t('Як у банку, латиницею')" />
                <TextField v-model="form.bank_name" :label="t('Банк')" />
                <div class="grid grid-cols-2 gap-2.5">
                    <TextField v-model="form.account_number" :label="t('Номер рахунку')" class="num" :error="fieldError(saveError, 'account_number')" />
                    <TextField v-model="form.swift" label="SWIFT" class="num" placeholder="—" :error="fieldError(saveError, 'swift')" />
                </div>
                <TextField
                    v-model="form.iban"
                    label="IBAN"
                    class="num"
                    :placeholder="t('Немає')"
                    :hint="t('IBAN і номер рахунку зберігаються зашифрованими')"
                    :error="fieldError(saveError, 'iban')"
                />
                <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                    <div v-for="(detail, index) in form.details" :key="index" class="flex items-center gap-2 py-2">
                        <input v-model="detail.label" :aria-label="t('Назва поля')" placeholder="Routing (ABA)" class="h-11 min-w-0 flex-1 rounded-control bg-paper px-3 text-15">
                        <input v-model="detail.value" :aria-label="t('Значення')" class="num h-11 min-w-0 flex-1 rounded-control bg-paper px-3 text-right text-15">
                        <button type="button" :aria-label="t('Прибрати поле')" class="flex size-11 shrink-0 items-center justify-center text-ink-3" @click="form.details.splice(index, 1)">
                            <Icon name="close" :size="18" />
                        </button>
                    </div>
                    <button type="button" class="flex min-h-12 items-center text-15 font-medium text-accent" @click="form.details.push({ label: '', value: '' })">
                        {{ t('+ Додати поле') }}
                    </button>
                </div>
            </section>

            <section v-if="form.kind !== 'personal'" class="flex flex-col gap-3">
                <div class="flex items-center justify-between">
                    <SectionTitle>{{ t('Ліміт') }}</SectionTitle>
                    <label class="flex min-h-11 items-center gap-2.5 text-15">
                        <input v-model="form.hasLimit" type="checkbox" class="size-5 accent-accent">
                        {{ t('Увімкнено') }}
                    </label>
                </div>
                <template v-if="form.hasLimit">
                    <TextField
                        v-model="form.limit_amount"
                        :label="t('Сума, {currency}', { currency: form.currency })"
                        numeric
                        :error="fieldError(saveError, 'limit_amount') ?? (limitMissing ? t('Вкажіть суму ліміту або вимкніть його.') : undefined)"
                    />
                    <div class="flex flex-col gap-1.5">
                        <span class="text-13 font-medium text-ink-2">{{ t('Період') }}</span>
                        <SegmentedControl v-model="form.limit_period" :label="t('Період ліміту')" size="lg" :options="periodOptions" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <span class="text-13 font-medium text-ink-2">{{ t('Вікно') }}</span>
                        <SegmentedControl v-model="form.limit_window" :label="t('Вікно ліміту')" size="lg" :options="windowOptions" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <span id="limit-counts" class="text-13 font-medium text-ink-2">{{ t('Що рахувати') }}</span>
                        <div role="radiogroup" aria-labelledby="limit-counts" class="flex flex-col gap-2">
                            <button
                                v-for="option in countsOptions"
                                :key="option.value"
                                type="button"
                                role="radio"
                                :aria-checked="form.limit_counts === option.value ? 'true' : 'false'"
                                class="flex items-start gap-3 rounded-control bg-surface p-3.5 text-left"
                                :class="form.limit_counts === option.value ? 'border-2 border-accent' : 'border border-line-input'"
                                @click="form.limit_counts = option.value"
                            >
                                <span
                                    class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border-2"
                                    :class="form.limit_counts === option.value ? 'border-accent' : 'border-switch-off'"
                                >
                                    <span v-if="form.limit_counts === option.value" class="size-2.5 rounded-full bg-accent" />
                                </span>
                                <span class="flex flex-col gap-0.5">
                                    <span class="text-15 font-semibold">{{ option.title }}</span>
                                    <span class="text-13 text-ink-2">{{ option.text }}</span>
                                </span>
                            </button>
                        </div>
                    </div>
                </template>
            </section>

            <section class="flex flex-col gap-3">
                <SectionTitle>
                    {{ t('Залишок на дату') }}
                    <template v-if="!isNew && can('create', 'account-opening-balances')" #aside>
                        <button type="button" class="flex min-h-11 items-center text-15 font-medium text-accent" @click="openBalance(null)">{{ t('+ Додати') }}</button>
                    </template>
                </SectionTitle>
                <div v-if="isNew" class="grid grid-cols-2 gap-2.5">
                    <DateField v-model="form.opening_as_of" :label="t('На дату')" :max="today()" />
                    <TextField v-model="form.opening_amount" :label="t('Сума, {currency}', { currency: form.currency })" numeric :placeholder="t('Немає')" />
                </div>
                <div v-else-if="balances.length" class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                    <button
                        v-for="item in balances"
                        :key="item.id"
                        type="button"
                        class="flex min-h-13 items-center justify-between gap-3 text-left"
                        :disabled="!can('update', 'account-opening-balances')"
                        @click="openBalance(item)"
                    >
                        <span class="text-15">
                            <Translated :text="t('на {date}')"><template #date><span class="num">{{ formatDate(item.as_of) }}</span></template></Translated>
                        </span>
                        <span class="num text-16 font-semibold">{{ formatMoney(item.amount, form.currency) }}</span>
                    </button>
                </div>
                <p v-if="openingError" class="m-0 text-14 text-negative" role="alert">
                    {{ t('Рахунок створено, але залишок не збережено: {error} Додайте його ще раз.', { error: errorMessage(openingError) }) }}
                </p>
                <p class="m-0 text-13 leading-normal text-ink-3">
                    {{ t('Якщо рахунок відкрито до початку обліку в PryTax. Баланс рахується від останнього залишку.') }}
                </p>
            </section>

            <label class="flex min-h-13 items-center justify-between rounded-control border border-line bg-surface px-4 text-15">
                {{ t('Активний') }}
                <input v-model="form.is_active" type="checkbox" class="size-5 accent-accent">
            </label>

            <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
        </LoadState>

        <template v-if="can(isNew ? 'create' : 'update', 'accounts')" #footer>
            <PrimaryButton :busy="saving" :disabled="loading || !form.name.trim() || limitMissing" @click="save">{{ t('Зберегти') }}</PrimaryButton>
        </template>

        <OpeningBalanceSheet
            v-if="accountId !== null"
            v-model:open="balanceOpen"
            :account-id="accountId"
            :currency="form.currency"
            :balance="balance"
            @saved="reloadBalances"
        />
    </SubScreen>
</template>
