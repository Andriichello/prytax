<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, ChargeType, Client, Contract } from '@/api/types';
import DateField from '@/components/form/DateField.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextField from '@/components/form/TextField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import Translated from '@/components/ui/Translated.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { describeContract } from '@/utils/contracts';
import { formatDate, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { formatAmount, parseAmount } from '@/utils/money';

/**
 * Договір (S-ContractEdit): name and number, its subject (the {services} of the invoices' payment purpose), dates,
 * currency (a new one's is the client's, else the ФОП's default one), payment terms, the template of item
 * descriptions with a live preview, and hourly rates by work type, each from a date (one rate of a type per date).
 */
interface RateDraft {
    key: number;
    charge_type_id: number | null;
    effective_from: string;
    hourly_rate: string;
}

const TOKENS = ['{name}', '{number}', '{started_at:F j, Y}'];
const CURRENCIES = ['USD', 'EUR', 'GBP', 'PLN', 'CHF', 'CAD', 'UAH'];

const route = useRoute();
const router = useRouter();
const { can } = useSession();
const { profileId, path, profile } = useCurrentProfile();

const clientId = computed(() => Number(route.params.clientId));
const contractId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isNew = computed(() => contractId.value === null);

const form = reactive({
    name: '',
    number: '',
    subject: '',
    started_at: today(),
    ends_at: '',
    currency: 'USD',
    payment_terms_days: '14',
    description_template: 'IT services in accordance to the {name} dated {started_at:F j, Y}',
    is_active: true,
    rates: [] as RateDraft[],
});

let nextKey = 1;

const { data, error: loadError, loading, reload } = useLoader(async (signal) => {
    const [client, types, contract] = await Promise.all([
        api.get<ApiResponse<Client>>(path(`clients/${clientId.value}`), undefined, signal),
        api.all<ChargeType>(path(`clients/${clientId.value}/charge-types`), {}, signal),
        contractId.value === null
            ? Promise.resolve(null)
            : api.get<ApiResponse<Contract>>(path(`clients/${clientId.value}/contracts/${contractId.value}`), { include: ['rates'] }, signal)
                .then(({ data: loaded }) => loaded),
    ]);

    if (contract) {
        Object.assign(form, {
            name: contract.name,
            number: contract.number ?? '',
            subject: contract.subject ?? '',
            started_at: contract.started_at,
            ends_at: contract.ends_at ?? '',
            currency: contract.currency,
            payment_terms_days: contract.payment_terms_days === null ? '' : String(contract.payment_terms_days),
            description_template: contract.description_template ?? '',
            is_active: contract.is_active,
            rates: (contract.rates ?? []).map((rate) => ({
                key: nextKey++,
                charge_type_id: rate.charge_type_id,
                effective_from: rate.effective_from,
                hourly_rate: rate.hourly_rate,
            })),
        });
    } else {
        form.currency = client.data.currency ?? profile.value?.default_currency ?? 'USD';
    }

    return { client: client.data, chargeTypes: types };
}, [profileId, clientId, contractId]);

const preview = computed(() => describeContract(form.description_template, {
    name: form.name,
    number: form.number,
    started_at: form.started_at,
    ends_at: form.ends_at,
    currency: form.currency,
    payment_terms_days: form.payment_terms_days,
}));

const template = ref<HTMLTextAreaElement | null>(null);

function insertToken(token: string): void {
    const element = template.value;
    const start = element?.selectionStart ?? form.description_template.length;
    const end = element?.selectionEnd ?? start;

    form.description_template = form.description_template.slice(0, start) + token + form.description_template.slice(end);
}

const typeName = (id: number | null): string => data.value?.chargeTypes.find((type) => type.id === id)?.name ?? t('clients.contract.default');
const sortedRates = computed(() => [...form.rates].sort((a, b) => (a.charge_type_id ?? 0) - (b.charge_type_id ?? 0)
    || b.effective_from.localeCompare(a.effective_from)));

/** The work types of a rate: the default one (for all items) first. */
const rateTypeOptions = computed(() => [
    { value: 0, label: t('clients.contract.default') },
    ...(data.value?.chargeTypes ?? []).map((type) => ({ value: type.id, label: type.name })),
]);

/* A rate, edited in a sheet */
const rateOpen = ref(false);
const rateDraft = ref<RateDraft>({ key: 0, charge_type_id: null, effective_from: today(), hourly_rate: '' });

function openRate(rate: RateDraft | null): void {
    rateDraft.value = rate ? { ...rate } : { key: nextKey++, charge_type_id: null, effective_from: form.started_at || today(), hourly_rate: '' };
    rateOpen.value = true;
}

/** Another rate of the same work type starts on the same date: the API refuses two such rates. */
const rateClash = computed(() => form.rates.some((rate) => rate.key !== rateDraft.value.key
    && rate.charge_type_id === rateDraft.value.charge_type_id
    && rate.effective_from === rateDraft.value.effective_from));

function saveRate(): void {
    if (rateClash.value) {
        return;
    }

    const index = form.rates.findIndex((rate) => rate.key === rateDraft.value.key);

    if (index === -1) {
        form.rates.push({ ...rateDraft.value });
    } else {
        form.rates[index] = { ...rateDraft.value };
    }

    rateOpen.value = false;
}

function removeRate(): void {
    form.rates = form.rates.filter((rate) => rate.key !== rateDraft.value.key);
    rateOpen.value = false;
}

/* Saving */
const saving = ref(false);
const saveError = ref<unknown>(null);

async function save(): Promise<void> {
    saving.value = true;
    saveError.value = null;

    const body = {
        name: form.name.trim(),
        number: form.number.trim() || null,
        subject: form.subject.trim() || null,
        started_at: form.started_at,
        ends_at: form.ends_at || null,
        currency: form.currency,
        payment_terms_days: form.payment_terms_days === '' ? null : Number(form.payment_terms_days),
        description_template: form.description_template.trim() || null,
        is_active: form.is_active,
        rates: form.rates.map((rate) => ({
            charge_type_id: rate.charge_type_id,
            effective_from: rate.effective_from,
            hourly_rate: parseAmount(rate.hourly_rate) ?? rate.hourly_rate,
        })),
    };

    try {
        if (isNew.value) {
            await api.post(path(`clients/${clientId.value}/contracts`), body);
        } else {
            await api.patch(path(`clients/${clientId.value}/contracts/${contractId.value}`), body);
        }

        await router.replace({ name: 'client', params: { id: clientId.value } });
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

const generalError = computed(() => {
    const fields = ['name', 'number', 'subject', 'started_at', 'ends_at', 'currency', 'payment_terms_days', 'description_template'];

    return saveError.value && !fields.some((field) => fieldError(saveError.value, field)) ? errorMessage(saveError.value) : null;
});
</script>

<template>
    <SubScreen :title="isNew ? t('clients.contract.newContract') : t('core.common.contract')" :subtitle="data?.client.name" :back="{ name: 'client', params: { id: clientId } }">
        <LoadState :loading="loading" :error="loadError" @retry="reload">
            <TextField v-model="form.name" :label="t('core.common.name')" placeholder="Service Agreement" :error="fieldError(saveError, 'name')" />
            <TextField v-model="form.number" :label="t('core.common.number')" :placeholder="t('core.common.optional')" :error="fieldError(saveError, 'number')" />
            <TextField
                v-model="form.subject"
                :label="t('clients.contract.subject')"
                placeholder="IT services"
                :hint="t('clients.contract.subjectHint', { placeholder: '{services}' })"
                :error="fieldError(saveError, 'subject')"
            />
            <div class="grid grid-cols-2 gap-2.5">
                <DateField v-model="form.started_at" :label="t('clients.contract.validFrom')" :error="fieldError(saveError, 'started_at')" />
                <DateField v-model="form.ends_at" :label="t('clients.contract.validUntil')" :hint="t('clients.contract.emptyOpenEnded')" :min="form.started_at || undefined" :error="fieldError(saveError, 'ends_at')" />
                <SelectField v-model="form.currency" :label="t('core.common.currency')" :options="CURRENCIES.map((currency) => ({ value: currency, label: currency }))" />
                <TextField v-model="form.payment_terms_days" :label="t('clients.contract.paymentTermsDays')" inputmode="numeric" class="num" :error="fieldError(saveError, 'payment_terms_days')" />
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="contract-template" class="text-13 font-medium text-ink-2">{{ t('clients.contract.itemDescriptionTemplate') }}</label>
                <textarea
                    id="contract-template"
                    ref="template"
                    v-model="form.description_template"
                    rows="3"
                    class="num resize-none rounded-control border border-line-input bg-surface px-3.5 py-3 text-15 leading-[1.45] whitespace-normal"
                />
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="token in TOKENS"
                        :key="token"
                        type="button"
                        class="num flex min-h-10 items-center rounded-control border border-line-input bg-surface px-3 text-14"
                        @click="insertToken(token)"
                    >
                        {{ token }}
                    </button>
                </div>
                <div v-if="preview" class="flex flex-col gap-1 rounded-control bg-muted px-3.5 py-3">
                    <span class="text-12 text-ink-3">{{ t('clients.contract.invoicePreview') }}</span>
                    <span class="text-15 leading-[1.45]">{{ preview }}</span>
                </div>
            </div>

            <section class="flex flex-col gap-2">
                <SectionTitle>
                    {{ t('clients.contract.hourlyRates') }}
                    <template #aside>
                        <button type="button" class="flex min-h-11 items-center text-15 font-medium text-accent" @click="openRate(null)">{{ t('clients.contract.addRate') }}</button>
                    </template>
                </SectionTitle>
                <div v-if="sortedRates.length" class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                    <button
                        v-for="rate in sortedRates"
                        :key="rate.key"
                        type="button"
                        class="flex min-h-14 items-center justify-between gap-3 py-2.5 text-left"
                        @click="openRate(rate)"
                    >
                        <span class="flex flex-col gap-0.5">
                            <span class="text-15" :class="rate.charge_type_id ? '' : 'text-ink-2'">{{ typeName(rate.charge_type_id) }}</span>
                            <span class="text-12 text-ink-3">
                                <Translated :text="t('core.common.fromDate')"><template #date><span class="num">{{ formatDate(rate.effective_from) }}</span></template></Translated>
                            </span>
                        </span>
                        <span class="num text-16" :class="rate.charge_type_id ? 'font-bold' : 'text-ink-2'">{{ formatAmount(rate.hourly_rate) }}</span>
                    </button>
                </div>
                <p class="m-0 text-13 text-ink-3">{{ t('clients.contract.rateByPeriodEnd') }}</p>
            </section>

            <label class="flex min-h-13 items-center justify-between rounded-control border border-line bg-surface px-4 text-15">
                {{ t('core.common.active') }}
                <input v-model="form.is_active" type="checkbox" class="size-5 accent-accent">
            </label>

            <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
        </LoadState>

        <template v-if="can(isNew ? 'create' : 'update', 'contracts')" #footer>
            <PrimaryButton :busy="saving" :disabled="loading || !form.name.trim() || !form.started_at" @click="save">{{ t('core.common.save') }}</PrimaryButton>
        </template>

        <BottomSheet v-model:open="rateOpen" :title="t('clients.contract.rate')">
            <SelectField
                :model-value="rateDraft.charge_type_id ?? 0"
                :label="t('core.common.workType')"
                :options="rateTypeOptions"
                @update:model-value="rateDraft.charge_type_id = $event || null"
            />
            <div class="grid grid-cols-2 gap-2.5">
                <DateField
                    v-model="rateDraft.effective_from"
                    :label="t('clients.contract.validFrom')"
                    :error="rateClash ? t('clients.contract.duplicateRate') : undefined"
                />
                <TextField v-model="rateDraft.hourly_rate" :label="t('clients.contract.perHour', { currency: form.currency })" numeric />
            </div>
            <div class="grid grid-cols-[1fr_2fr] gap-2.5">
                <SecondaryButton tone="negative" @click="removeRate">{{ t('core.common.delete') }}</SecondaryButton>
                <PrimaryButton :disabled="!rateDraft.effective_from || parseAmount(rateDraft.hourly_rate) === null || rateClash" @click="saveRate">{{ t('core.common.done') }}</PrimaryButton>
            </div>
        </BottomSheet>
    </SubScreen>
</template>
