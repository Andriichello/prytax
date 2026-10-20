<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, ChargeType, Client, Contract, Paginated } from '@/api/types';
import DateField from '@/components/form/DateField.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextField from '@/components/form/TextField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { describeContract } from '@/utils/contracts';
import { formatDate, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { formatAmount, parseAmount } from '@/utils/money';

/**
 * Договір (S-ContractEdit): name and number, dates, currency, payment terms, the template of item descriptions with
 * a live preview, and hourly rates by work type, each from a date.
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
const { profileId, path } = useCurrentProfile();

const clientId = computed(() => Number(route.params.clientId));
const contractId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isNew = computed(() => contractId.value === null);

const form = reactive({
    name: '',
    number: '',
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
        api.get<Paginated<ChargeType>>(path(`clients/${clientId.value}/charge-types`), { page: { size: 50 } }, signal),
        contractId.value === null
            ? Promise.resolve(null)
            : api.get<ApiResponse<Contract>>(path(`clients/${clientId.value}/contracts/${contractId.value}`), { include: ['rates'] }, signal)
                .then(({ data: loaded }) => loaded),
    ]);

    if (contract) {
        Object.assign(form, {
            name: contract.name,
            number: contract.number ?? '',
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
        form.currency = client.data.currency ?? 'USD';
    }

    return { client: client.data, chargeTypes: types.data };
}, [profileId, clientId, contractId]);

const preview = computed(() => describeContract(form.description_template, {
    name: form.name,
    number: form.number,
    started_at: form.started_at,
    ends_at: form.ends_at,
}));

const template = ref<HTMLTextAreaElement | null>(null);

function insertToken(token: string): void {
    const element = template.value;
    const start = element?.selectionStart ?? form.description_template.length;
    const end = element?.selectionEnd ?? start;

    form.description_template = form.description_template.slice(0, start) + token + form.description_template.slice(end);
}

const typeName = (id: number | null): string => data.value?.chargeTypes.find((type) => type.id === id)?.name ?? 'За замовчуванням';
const sortedRates = computed(() => [...form.rates].sort((a, b) => (a.charge_type_id ?? 0) - (b.charge_type_id ?? 0)
    || b.effective_from.localeCompare(a.effective_from)));

/* A rate, edited in a sheet */
const rateOpen = ref(false);
const rateDraft = ref<RateDraft>({ key: 0, charge_type_id: null, effective_from: today(), hourly_rate: '' });

function openRate(rate: RateDraft | null): void {
    rateDraft.value = rate ? { ...rate } : { key: nextKey++, charge_type_id: null, effective_from: form.started_at || today(), hourly_rate: '' };
    rateOpen.value = true;
}

function saveRate(): void {
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
    const fields = ['name', 'number', 'started_at', 'ends_at', 'currency', 'payment_terms_days', 'description_template'];

    return saveError.value && !fields.some((field) => fieldError(saveError.value, field)) ? errorMessage(saveError.value) : null;
});
</script>

<template>
    <SubScreen :title="isNew ? 'Новий договір' : 'Договір'" :subtitle="data?.client.name" :back="{ name: 'client', params: { id: clientId } }">
        <LoadState :loading="loading" :error="loadError" @retry="reload">
            <TextField v-model="form.name" label="Назва" placeholder="Service Agreement" :error="fieldError(saveError, 'name')" />
            <TextField v-model="form.number" label="Номер" placeholder="Необов'язково" :error="fieldError(saveError, 'number')" />
            <div class="grid grid-cols-2 gap-2.5">
                <DateField v-model="form.started_at" label="Діє з" :error="fieldError(saveError, 'started_at')" />
                <DateField v-model="form.ends_at" label="Діє до" hint="Порожньо — безстроковий" :min="form.started_at || undefined" :error="fieldError(saveError, 'ends_at')" />
                <SelectField v-model="form.currency" label="Валюта" :options="CURRENCIES.map((currency) => ({ value: currency, label: currency }))" />
                <TextField v-model="form.payment_terms_days" label="Оплата, днів" inputmode="numeric" class="num" :error="fieldError(saveError, 'payment_terms_days')" />
            </div>

            <div class="flex flex-col gap-1.5">
                <label for="contract-template" class="text-13 font-medium text-ink-2">Шаблон опису позиції</label>
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
                    <span class="text-12 text-ink-3">Так виглядатиме в інвойсі</span>
                    <span class="text-15 leading-[1.45]">{{ preview }}</span>
                </div>
            </div>

            <section class="flex flex-col gap-2">
                <SectionTitle>
                    Погодинні ставки
                    <template #aside>
                        <button type="button" class="flex min-h-11 items-center text-15 font-medium text-accent" @click="openRate(null)">+ Ставка</button>
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
                            <span class="text-12 text-ink-3">з <span class="num">{{ formatDate(rate.effective_from) }}</span></span>
                        </span>
                        <span class="num text-16" :class="rate.charge_type_id ? 'font-bold' : 'text-ink-2'">{{ formatAmount(rate.hourly_rate) }}</span>
                    </button>
                </div>
                <p class="m-0 text-13 text-ink-3">Ставка підставляється в позицію за датою кінця періоду</p>
            </section>

            <label class="flex min-h-13 items-center justify-between rounded-control border border-line bg-surface px-4 text-15">
                Активний
                <input v-model="form.is_active" type="checkbox" class="size-5 accent-accent">
            </label>

            <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
        </LoadState>

        <template v-if="can(isNew ? 'create' : 'update', 'contracts')" #footer>
            <PrimaryButton :busy="saving" :disabled="loading || !form.name.trim() || !form.started_at" @click="save">Зберегти</PrimaryButton>
        </template>

        <BottomSheet v-model:open="rateOpen" title="Ставка">
            <SelectField
                :model-value="rateDraft.charge_type_id ?? 0"
                label="Тип робіт"
                :options="[{ value: 0, label: 'За замовчуванням' }, ...(data?.chargeTypes ?? []).map((type) => ({ value: type.id, label: type.name }))]"
                @update:model-value="rateDraft.charge_type_id = $event || null"
            />
            <div class="grid grid-cols-2 gap-2.5">
                <DateField v-model="rateDraft.effective_from" label="Діє з" />
                <TextField v-model="rateDraft.hourly_rate" :label="`За годину, ${form.currency}`" numeric />
            </div>
            <div class="grid grid-cols-[1fr_2fr] gap-2.5">
                <SecondaryButton tone="negative" @click="removeRate">Видалити</SecondaryButton>
                <PrimaryButton :disabled="!rateDraft.effective_from || parseAmount(rateDraft.hourly_rate) === null" @click="saveRate">Готово</PrimaryButton>
            </div>
        </BottomSheet>
    </SubScreen>
</template>
