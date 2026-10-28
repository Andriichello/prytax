<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { api } from '@/api/client';
import type { Account, ApiResponse, FxGainPolicy, ProfileSettings, TaxOpeningBalance, TaxPaymentRequisite, TaxType } from '@/api/types';
import AccordionGroup from '@/components/form/AccordionGroup.vue';
import DateField from '@/components/form/DateField.vue';
import Dimmable from '@/components/form/Dimmable.vue';
import ReadRow from '@/components/form/ReadRow.vue';
import TextField from '@/components/form/TextField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import TaxOpeningBalancesEditor, { type OpeningBalanceDraft } from '@/components/taxes/TaxOpeningBalancesEditor.vue';
import TaxPaymentAccountsEditor from '@/components/taxes/TaxPaymentAccountsEditor.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import SideNote from '@/components/ui/SideNote.vue';
import { provideAccordion } from '@/composables/useAccordion';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { accountLabel } from '@/utils/accounts';
import { formatDate, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { formatAmount, parseAmount } from '@/utils/money';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * Податки й сповіщення (M-IncomeTaxSettings, design.md §16.5), in accordion groups, each saving its own settings:
 * «База оподаткування» (the invoiced amount, fixed, and FX gains), «Сплата податків» (the accounts taxes are paid
 * from, with their routes and the primary one, and the ЄСВ day), «Вхідні сальдо» and «Сповіщення» (money left on
 * platforms, deadlines, unsent invoices). The budget's requisites follow, as built.
 */
type Repatriation = 'quarter_end' | 'days' | 'off';

const TAXES: TaxType[] = ['single_tax', 'military_levy', 'social_contribution'];

/** The individual tax consultation, which counts a fee withheld by a bank or a platform as income. */
const FEE_CONSULTATION = '462/ІПК/99-00-24-03-03-06'; // i18n-ignore

/** An example of the budget's recipient, named in Ukrainian as the treasury names it. */
const RECIPIENT_EXAMPLE = 'ГУК у м. Київ/18050400'; // i18n-ignore

/** The ЄСВ day of the tax rules, which an empty setting takes. */
const ESV_DAY = 19;

const taxOptions = computed(() => TAXES.map((type) => ({ value: type, label: TAX_TYPES[type] })));

const { can } = useSession();
const { profileId, path, loadSettings } = useCurrentProfile();
const accordion = provideAccordion();

const settings = ref<ProfileSettings | null>(null);
const accounts = ref<Account[]>([]);
const balances = ref<TaxOpeningBalance[]>([]);
const requisites = ref<TaxPaymentRequisite[]>([]);

const { error: loadError, loading, reload } = useLoader(async (signal) => {
    const [loaded, accountList, balanceList, requisiteList] = await Promise.all([
        api.get<ApiResponse<ProfileSettings>>(path('settings'), undefined, signal),
        api.all<Account>(path('accounts'), {}, signal),
        api.all<TaxOpeningBalance>(path('tax-opening-balances'), {}, signal),
        api.all<TaxPaymentRequisite>(path('tax-payment-requisites'), {}, signal),
    ]);

    settings.value = loaded.data;
    accounts.value = accountList;
    balances.value = balanceList;
    requisites.value = requisiteList;

    return true;
}, [profileId]);

const canConfigure = computed(() => can('configure', 'profiles'));

/* The drafts of the groups, copied from the settings when a group opens */
const base = reactive({ fx_gain: 'ignore' as FxGainPolicy });
const pay = reactive({ ids: [] as number[], primary: null as number | null, esv_due_day: '' });
const notify = reactive({ repatriation: 'quarter_end' as Repatriation, repatriation_days: '30', deadline_days: 7, ready_days: 14 });
const balanceDrafts = ref<OpeningBalanceDraft[]>([]);

const repatriation = computed(() => settings.value?.compliance.platform_repatriation ?? 'quarter_end');

function open(): void {
    const loaded = settings.value;

    saveError.value = null;
    balanceErrors.value = {};

    if (!loaded) {
        return;
    }

    base.fx_gain = loaded.income.fx_gain;
    Object.assign(pay, {
        ids: [...loaded.taxes.payment_account_ids],
        primary: loaded.taxes.default_account_id,
        esv_due_day: loaded.taxes.esv_due_day === null ? '' : String(loaded.taxes.esv_due_day),
    });
    Object.assign(notify, {
        repatriation: repatriation.value.startsWith('days:') ? 'days' : repatriation.value,
        repatriation_days: repatriation.value.startsWith('days:') ? repatriation.value.slice(5) : '30',
        deadline_days: loaded.alerts.deadline_days,
        ready_days: loaded.alerts.ready_days,
    });
    balanceDrafts.value = balances.value.map((balance) => ({
        key: String(balance.id),
        id: balance.id,
        tax_type: balance.tax_type,
        as_of: balance.as_of,
        amount: balance.amount,
    }));
}

const fxGainOptions = computed<{ value: FxGainPolicy; label: string }[]>(() => [
    { value: 'ignore', label: t('settings.taxes.exclude') },
    { value: 'include', label: t('settings.taxes.include') },
]);
const repatriationOptions = computed<{ value: Repatriation; label: string }[]>(() => [
    { value: 'quarter_end', label: t('settings.taxes.quarterEnd') },
    { value: 'days', label: t('settings.taxes.afterNDays') },
    { value: 'off', label: t('settings.taxes.off') },
]);

/** The day steppers, within the setting's bounds (`Profile::settingDefinitions`). */
const daySteppers = computed(() => [
    { key: 'deadline_days', label: t('settings.taxes.warnAhead'), min: 0, max: 60 },
    { key: 'ready_days', label: t('settings.taxes.remindUnsent'), min: 1, max: 365 },
] as const);

/* What the closed groups show */
const paymentAccounts = computed(() => (settings.value?.taxes.payment_account_ids ?? [])
    .map((id) => accounts.value.find((account) => account.id === id))
    .filter((account): account is Account => account !== undefined));
const repatriationText = computed(() => {
    if (repatriation.value === 'off') {
        return t('settings.taxes.remindOff');
    }

    return repatriation.value.startsWith('days:')
        ? t('settings.taxes.remindAfterDays', { count: Number(repatriation.value.slice(5)) })
        : t('settings.taxes.remindQuarterEnd');
});

/* Saving a group */
const saving = ref(false);
const saveError = ref<unknown>(null);
const balanceErrors = ref<Record<string, string | undefined>>({});

/**
 * Save the opening balances: the removed ones go, the changed amounts are updated and the new ones added. Unreadable
 * amounts stop the save before anything is sent; a failure of the API stops it at its draft, which is marked.
 */
async function saveBalances(): Promise<void> {
    const unreadable = balanceDrafts.value.filter((draft) => parseAmount(draft.amount) === null);

    // Nothing is sent while an amount can't be read, so the group saves all of its drafts or none
    if (unreadable.length) {
        balanceErrors.value = Object.fromEntries(unreadable.map((draft) => [draft.key, t('settings.taxes.openingBalanceAmountRequired')]));

        throw new Error(t('settings.taxes.openingBalanceAmountRequired'));
    }

    const kept = new Set(balanceDrafts.value.map((draft) => draft.id).filter((id) => id !== null));

    for (const balance of balances.value.filter((item) => !kept.has(item.id))) {
        await api.delete(path(`tax-opening-balances/${balance.id}`));
    }

    for (const draft of balanceDrafts.value) {
        const amount = parseAmount(draft.amount) ?? draft.amount;

        try {
            if (draft.id === null) {
                const { data } = await api.post<ApiResponse<TaxOpeningBalance>>(path('tax-opening-balances'), { tax_type: draft.tax_type, as_of: draft.as_of, amount });

                draft.id = data.id;
            } else if (amount !== balances.value.find((balance) => balance.id === draft.id)?.amount) {
                await api.patch(path(`tax-opening-balances/${draft.id}`), { amount });
            }
        } catch (caught) {
            balanceErrors.value = { [draft.key]: fieldError(caught, 'amount') ?? fieldError(caught, 'as_of') ?? errorMessage(caught) };
            throw caught;
        }
    }
}

async function save(group: 'base' | 'pay' | 'opening' | 'notify'): Promise<void> {
    saving.value = true;
    saveError.value = null;
    balanceErrors.value = {};

    try {
        if (group === 'base') {
            await api.patch(path('settings'), { income: { fx_gain: base.fx_gain } });
        } else if (group === 'pay') {
            await api.patch(path('settings'), {
                taxes: {
                    payment_account_ids: pay.ids,
                    default_account_id: pay.primary,
                    esv_due_day: pay.esv_due_day === '' ? null : Number(pay.esv_due_day),
                },
            });
        } else if (group === 'notify') {
            await api.patch(path('settings'), {
                compliance: {
                    platform_repatriation: notify.repatriation === 'days' ? `days:${Number(notify.repatriation_days) || 30}` : notify.repatriation,
                },
                alerts: { deadline_days: notify.deadline_days, ready_days: notify.ready_days },
            });
        } else {
            await saveBalances();
        }

        await loadSettings();
        await reload();
        accordion.open.value = null;
    } catch (caught) {
        saveError.value = caught;
        // The balances saved before the failure are kept: the list loads again, while the drafts stay open
        balances.value = await api.all<TaxOpeningBalance>(path('tax-opening-balances')).catch(() => balances.value);
    } finally {
        saving.value = false;
    }
}

const fieldErrors = computed(() => ({
    accounts: fieldError(saveError.value, 'taxes.payment_account_ids') ?? fieldError(saveError.value, 'taxes.default_account_id'),
    esv_due_day: fieldError(saveError.value, 'taxes.esv_due_day'),
    repatriation: fieldError(saveError.value, 'compliance.platform_repatriation'),
}));
const generalError = computed(() => (saveError.value && !Object.values(fieldErrors.value).some(Boolean) && !Object.values(balanceErrors.value).some(Boolean)
    ? errorMessage(saveError.value)
    : null));

/* Budget requisites of tax payments */
const requisiteOpen = ref(false);
const requisiteDraft = reactive({
    id: null as number | null,
    tax_type: 'single_tax' as TaxType,
    recipient_name: '',
    iban: '',
    recipient_code: '',
    purpose_template: '',
    effective_from: '',
});
const requisiteError = ref<unknown>(null);
const requisiteSaving = ref(false);

/** The purposes of tax payments, which the treasury takes in Ukrainian whatever the language of the screens. */
const PURPOSES: Record<TaxType, string> = {
    single_tax: '*;101;{tax_id};Єдиний податок, {period};{full_name}', // i18n-ignore
    military_levy: '*;101;{tax_id};Військовий збір, {period};{full_name}', // i18n-ignore
    social_contribution: '*;101;{tax_id};Єдиний внесок, {period};{full_name}', // i18n-ignore
};

function openRequisite(requisite: TaxPaymentRequisite | null): void {
    Object.assign(requisiteDraft, requisite
        ? { ...requisite }
        : { id: null, tax_type: 'single_tax', recipient_name: '', iban: '', recipient_code: '', purpose_template: PURPOSES.single_tax, effective_from: `${today().slice(0, 4)}-01-01` });
    requisiteError.value = null;
    requisiteOpen.value = true;
}

async function saveRequisite(): Promise<void> {
    requisiteSaving.value = true;
    requisiteError.value = null;

    const body = {
        tax_type: requisiteDraft.tax_type,
        recipient_name: requisiteDraft.recipient_name.trim(),
        iban: requisiteDraft.iban.replace(/\s/g, '').toUpperCase(),
        recipient_code: requisiteDraft.recipient_code.trim(),
        purpose_template: requisiteDraft.purpose_template.trim(),
        effective_from: requisiteDraft.effective_from,
    };

    try {
        if (requisiteDraft.id) {
            await api.patch(path(`tax-payment-requisites/${requisiteDraft.id}`), body);
        } else {
            await api.post(path('tax-payment-requisites'), body);
        }

        requisiteOpen.value = false;
        requisites.value = await api.all<TaxPaymentRequisite>(path('tax-payment-requisites'));
    } catch (caught) {
        requisiteError.value = caught;
    } finally {
        requisiteSaving.value = false;
    }
}
</script>

<template>
    <SubScreen :title="t('settings.taxes.title')" :back="{ name: 'more' }">
        <template #aside>
            <SideNote :text="t('settings.sideNotes.taxes')" />
        </template>

        <LoadState :loading="loading && !settings" :error="loadError" @retry="reload">
            <template v-if="settings">
                <AccordionGroup
                    id="base"
                    :title="t('settings.taxes.taxBase')"
                    :editable="canConfigure"
                    :saving="saving"
                    :error="generalError"
                    @open="open"
                    @save="save('base')"
                >
                    <ReadRow :label="t('settings.taxes.baseShort')" :value="t('settings.taxes.invoicedAmount')" />
                    <ReadRow :label="t('settings.taxes.feesShort')" :value="t('settings.taxes.feesAlsoIncome')" />
                    <ReadRow
                        :label="t('settings.taxes.fxGain')"
                        :value="settings.income.fx_gain === 'include' ? t('settings.taxes.include') : t('settings.taxes.exclude')"
                    />
                    <template #edit>
                        <div class="flex flex-col gap-1 rounded-control border-2 border-accent bg-surface p-3.5">
                            <span class="text-16 font-semibold">{{ t('settings.taxes.invoicedAmount') }}</span>
                            <span class="text-14 leading-normal text-ink-2">{{ t('settings.taxes.feeIncomeHint', { consultation: FEE_CONSULTATION }) }}</span>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <span class="text-13 font-medium text-ink-2">{{ t('settings.taxes.fxGainSelling') }}</span>
                            <SegmentedControl v-model="base.fx_gain" :label="t('settings.taxes.fxGain')" size="lg" :options="fxGainOptions" />
                        </div>
                    </template>
                </AccordionGroup>

                <AccordionGroup
                    id="pay"
                    :title="t('settings.taxes.payingTaxes')"
                    :editable="canConfigure"
                    :saving="saving"
                    :error="generalError"
                    @open="open"
                    @save="save('pay')"
                >
                    <ReadRow :label="t('settings.taxes.accountsShort')">
                        <span v-if="paymentAccounts.length" class="flex flex-col gap-1">
                            <span v-for="account in paymentAccounts" :key="account.id">
                                {{ accountLabel(account.name, account.currency) }}<span v-if="account.id === settings.taxes.default_account_id" class="text-ink-3"> · {{ t('settings.taxes.primaryLower') }}</span>
                            </span>
                        </span>
                        <span v-else class="text-ink-3">{{ t('settings.taxes.noPaymentAccountsShort') }}</span>
                    </ReadRow>
                    <ReadRow :label="t('settings.taxes.esvDueShort')" :value="t('settings.taxes.dayValue', { day: settings.taxes.esv_due_day ?? ESV_DAY })" />
                    <template #edit>
                        <TaxPaymentAccountsEditor v-model:ids="pay.ids" v-model:primary="pay.primary" :accounts="accounts" />
                        <p v-if="fieldErrors.accounts" class="m-0 text-12 text-negative">{{ fieldErrors.accounts }}</p>
                        <label class="flex items-center justify-between gap-3 text-15">
                            {{ t('settings.taxes.sscDueDay') }}
                            <span class="flex items-center gap-3">
                                <input
                                    v-model="pay.esv_due_day"
                                    inputmode="numeric"
                                    :placeholder="String(ESV_DAY)"
                                    class="num h-12 w-17 rounded-control border bg-surface text-center text-16"
                                    :class="fieldErrors.esv_due_day ? 'border-negative' : 'border-line-input'"
                                >
                                <span class="text-ink-2">{{ t('settings.taxes.dayOfMonth') }}</span>
                            </span>
                        </label>
                        <p v-if="fieldErrors.esv_due_day" class="m-0 text-12 text-negative">{{ fieldErrors.esv_due_day }}</p>
                    </template>
                </AccordionGroup>

                <AccordionGroup
                    id="opening"
                    :title="t('settings.taxes.openingBalances')"
                    :editable="canConfigure"
                    :saving="saving"
                    :error="generalError"
                    @open="open"
                    @save="save('opening')"
                >
                    <ReadRow v-for="balance in balances" :key="balance.id" :label="TAX_TYPES[balance.tax_type]">
                        <span class="num">{{ formatAmount(balance.amount, { signed: true }) }}</span> · {{ t('core.common.asOfDate', { date: formatDate(balance.as_of) }) }}
                    </ReadRow>
                    <span v-if="balances.length === 0" class="text-15 text-ink-3">{{ t('settings.taxes.noOpeningBalances') }}</span>
                    <template #edit>
                        <TaxOpeningBalancesEditor v-model="balanceDrafts" :errors="balanceErrors" />
                    </template>
                </AccordionGroup>

                <AccordionGroup
                    id="notify"
                    :title="t('shell.alerts.notifications')"
                    :editable="canConfigure"
                    :saving="saving"
                    :error="generalError"
                    @open="open"
                    @save="save('notify')"
                >
                    <ReadRow :label="t('settings.taxes.moneyPlatforms')" :value="repatriationText" />
                    <ReadRow :label="t('settings.taxes.deadlinesShort')" :value="t('settings.taxes.warnDays', { count: settings.alerts.deadline_days })" />
                    <ReadRow :label="t('settings.taxes.unsentShort')" :value="t('settings.taxes.remindDays', { count: settings.alerts.ready_days })" />
                    <template #edit>
                        <div class="flex flex-col gap-1.5">
                            <span class="text-13 font-medium text-ink-2">{{ t('settings.taxes.remindPlatformMoney') }}</span>
                            <SegmentedControl v-model="notify.repatriation" :label="t('settings.taxes.moneyPlatforms')" size="lg" :options="repatriationOptions" />
                        </div>
                        <TextField
                            v-if="notify.repatriation === 'days'"
                            v-model="notify.repatriation_days"
                            :label="t('settings.taxes.daysAfterReceipt')"
                            inputmode="numeric"
                            class="num"
                            :error="fieldErrors.repatriation"
                        />
                        <div v-for="option in daySteppers" :key="option.key" class="flex items-center justify-between gap-3">
                            <span class="text-15">{{ option.label }}</span>
                            <span class="flex h-12 shrink-0 items-center rounded-control border border-line-input bg-surface">
                                <button type="button" :aria-label="t('settings.taxes.decrease')" class="flex size-11 items-center justify-center text-18" :disabled="notify[option.key] <= option.min" @click="notify[option.key] -= 1">−</button>
                                <span class="num w-14 text-center text-16 font-semibold">{{ t('settings.taxes.daysShort', { count: notify[option.key] }) }}</span>
                                <button type="button" :aria-label="t('settings.taxes.increase')" class="flex size-11 items-center justify-center text-18" :disabled="notify[option.key] >= option.max" @click="notify[option.key] += 1">+</button>
                            </span>
                        </div>
                    </template>
                </AccordionGroup>

                <Dimmable class="flex flex-col gap-2">
                    <SectionTitle>
                        {{ t('settings.taxes.paymentDetails') }}
                        <template v-if="canConfigure" #aside>
                            <button type="button" class="flex min-h-11 items-center text-15 font-medium text-accent" @click="openRequisite(null)">{{ t('core.common.plusAdd') }}</button>
                        </template>
                    </SectionTitle>
                    <div v-if="requisites.length" class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                        <button
                            v-for="requisite in requisites"
                            :key="requisite.id"
                            type="button"
                            class="flex min-h-14 items-center justify-between gap-3 py-2.5 text-left"
                            :disabled="!canConfigure"
                            @click="openRequisite(requisite)"
                        >
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="text-16 font-medium">{{ TAX_TYPES[requisite.tax_type] }} <span class="text-13 font-normal text-ink-3">{{ t('core.common.fromDate', { date: formatDate(requisite.effective_from) }) }}</span></span>
                                <span class="truncate text-13 text-ink-3">{{ requisite.recipient_name }}</span>
                            </span>
                            <Icon name="chevronRight" :size="18" class="shrink-0 text-ink-2" />
                        </button>
                    </div>
                    <p v-else class="m-0 rounded-card border border-line bg-surface p-4 text-14 text-ink-2">
                        {{ t('settings.taxes.paymentDetailsHint') }}
                    </p>
                </Dimmable>

                <Dimmable>
                    <p class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('core.accordion.oneAtATime') }}</p>
                </Dimmable>
            </template>
        </LoadState>

        <BottomSheet v-model:open="requisiteOpen" :title="t('settings.taxes.paymentDetails')">
            <SegmentedControl
                v-model="requisiteDraft.tax_type"
                :label="t('core.common.tax')"
                size="lg"
                :options="taxOptions"
                @update:model-value="!requisiteDraft.id && (requisiteDraft.purpose_template = PURPOSES[$event as TaxType])"
            />
            <TextField v-model="requisiteDraft.recipient_name" :label="t('core.common.recipient')" :placeholder="RECIPIENT_EXAMPLE" :error="fieldError(requisiteError, 'recipient_name')" />
            <TextField v-model="requisiteDraft.iban" label="IBAN" class="num" placeholder="UA…" :error="fieldError(requisiteError, 'iban')" />
            <div class="grid grid-cols-2 gap-2.5">
                <TextField v-model="requisiteDraft.recipient_code" :label="t('settings.taxes.recipientCode')" class="num" :placeholder="t('settings.taxes.edrpou')" :error="fieldError(requisiteError, 'recipient_code')" />
                <DateField v-model="requisiteDraft.effective_from" :label="t('clients.contract.validFrom')" :error="fieldError(requisiteError, 'effective_from')" />
            </div>
            <TextField
                v-model="requisiteDraft.purpose_template"
                :label="t('settings.taxes.paymentPurpose')"
                class="num text-14"
                :hint="t('settings.taxes.purposeHint')"
                :error="fieldError(requisiteError, 'purpose_template')"
            />
            <p v-if="requisiteError && !['recipient_name', 'iban', 'recipient_code', 'effective_from', 'purpose_template'].some((field) => fieldError(requisiteError, field))" class="m-0 text-14 text-negative">
                {{ errorMessage(requisiteError) }}
            </p>
            <template #footer>
                <PrimaryButton :busy="requisiteSaving" @click="saveRequisite">{{ t('core.common.save') }}</PrimaryButton>
            </template>
        </BottomSheet>
    </SubScreen>
</template>
