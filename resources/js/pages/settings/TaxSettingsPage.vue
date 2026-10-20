<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '@/api/client';
import type {
    Account,
    ApiResponse,
    FxGainPolicy,
    Paginated,
    ProfileSettings,
    TaxableBase,
    TaxGrouping,
    TaxOpeningBalance,
    TaxPaymentRequisite,
    TaxType,
} from '@/api/types';
import DateField from '@/components/form/DateField.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextField from '@/components/form/TextField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { formatDate, quarterLabel, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { formatAmount, parseAmount } from '@/utils/money';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * Податки й сповіщення (S-IncomeTaxSettings): the tax base, FX gains, how taxes are shown and paid, the ЄСВ day,
 * opening balances, the budget's requisites, and the reminders about money left on platforms and deadlines.
 */
type Repatriation = 'quarter_end' | 'days' | 'off';

const TAXES: TaxType[] = ['single_tax', 'military_levy', 'social_contribution'];
const TAX_OPTIONS = TAXES.map((type) => ({ value: type, label: TAX_TYPES[type] }));

const router = useRouter();
const { can } = useSession();
const { profileId, path, loadSettings } = useCurrentProfile();

const form = reactive({
    taxable_base: 'invoiced' as TaxableBase,
    fx_gain: 'ignore' as FxGainPolicy,
    default_grouping: 'payment' as TaxGrouping,
    default_account_id: null as number | null,
    esv_due_day: '',
    repatriation: 'quarter_end' as Repatriation,
    repatriation_days: '30',
    deadline_days: 7,
    ready_days: 14,
});

const accounts = ref<Account[]>([]);
const balances = ref<TaxOpeningBalance[]>([]);
const requisites = ref<TaxPaymentRequisite[]>([]);

const { error: loadError, loading, reload } = useLoader(async (signal) => {
    const [settings, accountList, balanceList, requisiteList] = await Promise.all([
        api.get<ApiResponse<ProfileSettings>>(path('settings'), undefined, signal),
        api.get<Paginated<Account>>(path('accounts'), { page: { size: 50 } }, signal),
        api.get<Paginated<TaxOpeningBalance>>(path('tax-opening-balances'), { page: { size: 50 } }, signal),
        api.get<Paginated<TaxPaymentRequisite>>(path('tax-payment-requisites'), { page: { size: 50 } }, signal),
    ]);

    const repatriation = settings.data.compliance.platform_repatriation;

    Object.assign(form, {
        taxable_base: settings.data.income.taxable_base,
        fx_gain: settings.data.income.fx_gain,
        default_grouping: settings.data.taxes.default_grouping,
        default_account_id: settings.data.taxes.default_account_id,
        esv_due_day: settings.data.taxes.esv_due_day === null ? '' : String(settings.data.taxes.esv_due_day),
        repatriation: repatriation.startsWith('days:') ? 'days' : repatriation,
        repatriation_days: repatriation.startsWith('days:') ? repatriation.slice(5) : '30',
        deadline_days: settings.data.alerts.deadline_days,
        ready_days: settings.data.alerts.ready_days,
    });
    accounts.value = accountList.data;
    balances.value = balanceList.data;
    requisites.value = requisiteList.data;

    return true;
}, [profileId]);

const canConfigure = computed(() => can('configure', 'profiles'));
const taxAccounts = computed(() => accounts.value
    .filter((account) => account.currency === 'UAH' && account.is_active && account.kind === 'fop_bank')
    .map((account) => ({ value: account.id, label: account.name })));

/* Opening balances: dated on a quarter's first day; "+" is overpaid, "−" owed */
const balanceOpen = ref(false);
const balanceDraft = reactive({ id: null as number | null, tax_type: 'single_tax' as TaxType, as_of: '', amount: '' });
const balanceError = ref<unknown>(null);
const balanceSaving = ref(false);

function openBalance(balance: TaxOpeningBalance | null): void {
    Object.assign(balanceDraft, balance
        ? { id: balance.id, tax_type: balance.tax_type, as_of: balance.as_of, amount: balance.amount }
        : { id: null, tax_type: 'single_tax', as_of: `${today().slice(0, 4)}-01-01`, amount: '' });
    balanceError.value = null;
    balanceOpen.value = true;
}

async function saveBalance(remove = false): Promise<void> {
    balanceSaving.value = true;
    balanceError.value = null;

    try {
        if (remove && balanceDraft.id) {
            await api.delete(path(`tax-opening-balances/${balanceDraft.id}`));
        } else if (balanceDraft.id) {
            await api.patch(path(`tax-opening-balances/${balanceDraft.id}`), { amount: parseAmount(balanceDraft.amount) ?? balanceDraft.amount });
        } else {
            await api.post(path('tax-opening-balances'), {
                tax_type: balanceDraft.tax_type,
                as_of: balanceDraft.as_of,
                amount: parseAmount(balanceDraft.amount) ?? balanceDraft.amount,
            });
        }

        balanceOpen.value = false;
        await reload();
    } catch (caught) {
        balanceError.value = caught;
    } finally {
        balanceSaving.value = false;
    }
}

const quarterStarts = computed(() => {
    const year = Number(today().slice(0, 4));

    return [year, year - 1, year - 2].flatMap((value) => [1, 2, 3, 4].map((quarter) => ({
        value: `${value}-${String((quarter - 1) * 3 + 1).padStart(2, '0')}-01`,
        label: `${formatDate(`${value}-${String((quarter - 1) * 3 + 1).padStart(2, '0')}-01`)} · перед ${quarterLabel(quarter)} ${value}`,
    })));
});

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

const PURPOSES: Record<TaxType, string> = {
    single_tax: '*;101;{tax_id};Єдиний податок, {period};{full_name}',
    military_levy: '*;101;{tax_id};Військовий збір, {period};{full_name}',
    social_contribution: '*;101;{tax_id};Єдиний внесок, {period};{full_name}',
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
        await reload();
    } catch (caught) {
        requisiteError.value = caught;
    } finally {
        requisiteSaving.value = false;
    }
}

/* Saving the settings */
const saving = ref(false);
const saveError = ref<unknown>(null);

async function save(): Promise<void> {
    saving.value = true;
    saveError.value = null;

    try {
        await api.patch(path('settings'), {
            income: { taxable_base: form.taxable_base, fx_gain: form.fx_gain },
            taxes: {
                default_grouping: form.default_grouping,
                default_account_id: form.default_account_id,
                esv_due_day: form.esv_due_day === '' ? null : Number(form.esv_due_day),
            },
            compliance: {
                platform_repatriation: form.repatriation === 'days' ? `days:${Number(form.repatriation_days) || 30}` : form.repatriation,
            },
            alerts: { deadline_days: form.deadline_days, ready_days: form.ready_days },
        });
        await loadSettings();
        await router.replace({ name: 'more' });
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <SubScreen title="Податки й сповіщення" :back="{ name: 'more' }">
        <LoadState :loading="loading" :error="loadError" @retry="reload">
            <section class="flex flex-col gap-2">
                <SectionTitle>База оподаткування</SectionTitle>
                <div role="radiogroup" aria-label="База оподаткування" class="flex flex-col gap-2">
                    <button
                        v-for="option in [
                            { value: 'invoiced', title: 'Сума за інвойсом', text: 'Комісія, утримана банком чи платформою, — теж дохід (ІПК 462/ІПК/99-00-24-03-03-06)', risk: false },
                            { value: 'received', title: 'Фактично отримана сума', text: '', risk: true },
                        ] as const"
                        :key="option.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.taxable_base === option.value ? 'true' : 'false'"
                        :disabled="!canConfigure"
                        class="flex items-start gap-3 rounded-control bg-surface p-3.5 text-left"
                        :class="form.taxable_base === option.value ? 'border-2 border-accent' : 'border border-line-input'"
                        @click="form.taxable_base = option.value"
                    >
                        <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border-2" :class="form.taxable_base === option.value ? 'border-accent' : 'border-switch-off'">
                            <span v-if="form.taxable_base === option.value" class="size-2.5 rounded-full bg-accent" />
                        </span>
                        <span class="flex flex-col items-start gap-1">
                            <span class="text-16 font-semibold">{{ option.title }}</span>
                            <span v-if="option.text" class="text-14 leading-normal text-ink-2">{{ option.text }}</span>
                            <span v-if="option.risk" class="rounded-md bg-warning-soft px-1.5 py-0.5 text-12 font-semibold text-warning">на власний ризик</span>
                        </span>
                    </button>
                </div>
            </section>

            <section class="flex flex-col gap-2">
                <SectionTitle>Курсовий дохід при продажу валюти</SectionTitle>
                <SegmentedControl v-model="form.fx_gain" label="Курсовий дохід" size="lg" :options="[{ value: 'ignore', label: 'Не враховувати' }, { value: 'include', label: 'Враховувати' }]" />
            </section>

            <section class="flex flex-col gap-3">
                <SectionTitle>Сплата податків</SectionTitle>
                <div class="flex flex-col gap-1.5">
                    <span class="text-13 font-medium text-ink-2">Показувати податки за</span>
                    <SegmentedControl v-model="form.default_grouping" label="Показувати податки за" size="lg" :options="[{ value: 'payment', label: 'Надходж.' }, { value: 'month', label: 'Місяць' }, { value: 'quarter', label: 'Квартал' }]" />
                </div>
                <SelectField
                    v-model="form.default_account_id"
                    label="Рахунок для сплати"
                    placeholder="Оберіть рахунок"
                    :options="taxAccounts"
                    hint="Лише активні ФОП-рахунки в UAH"
                    :disabled="!canConfigure"
                />
                <label class="flex items-center justify-between gap-3 text-15">
                    ЄСВ сплачувати до
                    <span class="flex items-center gap-3">
                        <input v-model="form.esv_due_day" inputmode="numeric" placeholder="19" class="num h-12 w-17 rounded-control border border-line-input bg-surface text-center text-16" :disabled="!canConfigure">
                        <span class="text-ink-2">числа</span>
                    </span>
                </label>
            </section>

            <section class="flex flex-col gap-2">
                <SectionTitle>
                    Вхідні сальдо
                    <template v-if="canConfigure" #aside>
                        <button type="button" class="flex min-h-11 items-center text-15 font-medium text-accent" @click="openBalance(null)">+ Додати</button>
                    </template>
                </SectionTitle>
                <div v-if="balances.length" class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                    <button
                        v-for="balance in balances"
                        :key="balance.id"
                        type="button"
                        class="flex min-h-14 items-center justify-between gap-3 text-left"
                        :disabled="!canConfigure"
                        @click="openBalance(balance)"
                    >
                        <span class="flex flex-col gap-0.5">
                            <span class="text-16 font-medium">{{ TAX_TYPES[balance.tax_type] }}</span>
                            <span class="text-12 text-ink-3">на {{ formatDate(balance.as_of) }}</span>
                        </span>
                        <span class="num text-16 font-semibold" :class="balance.amount.startsWith('-') ? 'text-negative' : Number(balance.amount) > 0 ? 'text-positive' : 'text-ink-3'">
                            {{ formatAmount(balance.amount, { signed: true }) }}
                        </span>
                    </button>
                </div>
                <p class="m-0 text-13 leading-normal text-ink-3">Для початку обліку з середини історії. «+» — переплата, «−» — борг.</p>
            </section>

            <section class="flex flex-col gap-2">
                <SectionTitle>
                    Реквізити для сплати
                    <template v-if="canConfigure" #aside>
                        <button type="button" class="flex min-h-11 items-center text-15 font-medium text-accent" @click="openRequisite(null)">+ Додати</button>
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
                            <span class="text-16 font-medium">{{ TAX_TYPES[requisite.tax_type] }} <span class="text-13 font-normal text-ink-3">з {{ formatDate(requisite.effective_from) }}</span></span>
                            <span class="truncate text-13 text-ink-3">{{ requisite.recipient_name }}</span>
                        </span>
                        <Icon name="chevronRight" :size="18" class="shrink-0 text-ink-2" />
                    </button>
                </div>
                <p v-else class="m-0 rounded-card border border-line bg-surface p-4 text-14 text-ink-2">
                    Додайте реквізити бюджетних рахунків за місцем реєстрації — тоді в сплаті податку з'являться отримувач,
                    IBAN і призначення платежу.
                </p>
            </section>

            <section class="flex flex-col gap-3">
                <SectionTitle>Сповіщення</SectionTitle>
                <div class="flex flex-col gap-1.5">
                    <span class="text-13 font-medium text-ink-2">Кошти на платформах — нагадати</span>
                    <SegmentedControl v-model="form.repatriation" label="Кошти на платформах" size="lg" :options="[{ value: 'quarter_end', label: 'Кінець кв.' }, { value: 'days', label: 'Через N днів' }, { value: 'off', label: 'Вимкнено' }]" />
                </div>
                <TextField v-if="form.repatriation === 'days'" v-model="form.repatriation_days" label="Через скільки днів після надходження" inputmode="numeric" class="num" />
                <div v-for="option in [
                    { key: 'deadline_days', label: 'Попереджати про терміни за' },
                    { key: 'ready_days', label: 'Нагадувати про ненадіслані інвойси через' },
                ] as const" :key="option.key" class="flex items-center justify-between gap-3">
                    <span class="text-15">{{ option.label }}</span>
                    <span class="flex h-12 shrink-0 items-center rounded-control border border-line-input bg-surface">
                        <button type="button" aria-label="Менше" class="flex size-11 items-center justify-center text-18" :disabled="!canConfigure || form[option.key] <= 1" @click="form[option.key] -= 1">−</button>
                        <span class="num w-14 text-center text-16 font-semibold">{{ form[option.key] }} дн.</span>
                        <button type="button" aria-label="Більше" class="flex size-11 items-center justify-center text-18" :disabled="!canConfigure || form[option.key] >= 60" @click="form[option.key] += 1">+</button>
                    </span>
                </div>
            </section>

            <p v-if="saveError" class="m-0 text-14 text-negative" role="alert">{{ errorMessage(saveError) }}</p>
        </LoadState>

        <template v-if="canConfigure" #footer>
            <PrimaryButton :busy="saving" :disabled="loading" @click="save">Зберегти</PrimaryButton>
        </template>

        <BottomSheet v-model:open="balanceOpen" title="Вхідне сальдо">
            <SegmentedControl
                v-if="!balanceDraft.id"
                v-model="balanceDraft.tax_type"
                label="Податок"
                size="lg"
                :options="TAX_OPTIONS"
            />
            <SelectField
                v-model="balanceDraft.as_of"
                label="На дату"
                :options="quarterStarts"
                :disabled="!!balanceDraft.id"
                :error="fieldError(balanceError, 'as_of')"
            />
            <TextField v-model="balanceDraft.amount" label="Сума, ₴ («+» — переплата, «−» — борг)" numeric placeholder="-765.69" :error="fieldError(balanceError, 'amount')" />
            <p v-if="balanceError && !fieldError(balanceError, 'as_of') && !fieldError(balanceError, 'amount')" class="m-0 text-14 text-negative">{{ errorMessage(balanceError) }}</p>
            <template #footer>
                <div class="grid gap-2.5" :class="balanceDraft.id ? 'grid-cols-[1fr_2fr]' : 'grid-cols-1'">
                    <SecondaryButton v-if="balanceDraft.id" tone="negative" :busy="balanceSaving" @click="saveBalance(true)">Видалити</SecondaryButton>
                    <PrimaryButton :busy="balanceSaving" :disabled="parseAmount(balanceDraft.amount) === null" @click="saveBalance()">Зберегти</PrimaryButton>
                </div>
            </template>
        </BottomSheet>

        <BottomSheet v-model:open="requisiteOpen" title="Реквізити для сплати">
            <SegmentedControl
                v-model="requisiteDraft.tax_type"
                label="Податок"
                size="lg"
                :options="TAX_OPTIONS"
                @update:model-value="!requisiteDraft.id && (requisiteDraft.purpose_template = PURPOSES[$event as TaxType])"
            />
            <TextField v-model="requisiteDraft.recipient_name" label="Отримувач" placeholder="ГУК у м. Київ/18050400" :error="fieldError(requisiteError, 'recipient_name')" />
            <TextField v-model="requisiteDraft.iban" label="IBAN" class="num" placeholder="UA…" :error="fieldError(requisiteError, 'iban')" />
            <div class="grid grid-cols-2 gap-2.5">
                <TextField v-model="requisiteDraft.recipient_code" label="Код отримувача" class="num" placeholder="ЄДРПОУ" :error="fieldError(requisiteError, 'recipient_code')" />
                <DateField v-model="requisiteDraft.effective_from" label="Діє з" :error="fieldError(requisiteError, 'effective_from')" />
            </div>
            <TextField
                v-model="requisiteDraft.purpose_template"
                label="Призначення платежу"
                class="num text-14"
                hint="{tax_id}, {period}, {full_name} підставляються під час сплати"
                :error="fieldError(requisiteError, 'purpose_template')"
            />
            <p v-if="requisiteError && !['recipient_name', 'iban', 'recipient_code', 'effective_from', 'purpose_template'].some((field) => fieldError(requisiteError, field))" class="m-0 text-14 text-negative">
                {{ errorMessage(requisiteError) }}
            </p>
            <template #footer>
                <PrimaryButton :busy="requisiteSaving" @click="saveRequisite">Зберегти</PrimaryButton>
            </template>
        </BottomSheet>
    </SubScreen>
</template>
