<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, ChargeType, Client, Contract } from '@/api/types';
import ContractItemFields from '@/components/client/ContractItemFields.vue';
import ContractMainFields from '@/components/client/ContractMainFields.vue';
import { contractFields, contractForm, type ContractForm, type ContractGroup } from '@/components/client/ContractFormModel';
import ContractRatesEditor from '@/components/client/ContractRatesEditor.vue';
import AccordionGroup from '@/components/form/AccordionGroup.vue';
import Dimmable from '@/components/form/Dimmable.vue';
import ReadRow from '@/components/form/ReadRow.vue';
import SwitchCard from '@/components/form/SwitchCard.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import { provideAccordion } from '@/composables/useAccordion';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { describeContract } from '@/utils/contracts';
import { formatDate } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { formatAmount } from '@/utils/money';

/**
 * Договір (M-ContractEdit, design.md §16.5): «Основне» (the name and number, its subject, which is the {services} of
 * the payment purpose, the dates, the currency and the payment terms), «Опис позиції в інвойсі» (closed, the line as
 * it reads; open, the template with its placeholder chips), «Погодинні ставки» by work type, each from a date, and
 * the «Активний» switch, which saves at once. A new contract is one form; its currency is the client's, else the
 * ФОП's.
 */
const route = useRoute();
const router = useRouter();
const { can } = useSession();
const { profileId, path, profile } = useCurrentProfile();
const accordion = provideAccordion();

const clientId = computed(() => Number(route.params.clientId));
const contractId = computed(() => (route.params.id ? Number(route.params.id) : null));
const isNew = computed(() => contractId.value === null);

const contract = ref<Contract | null>(null);
/** The saved contract, which the closed groups show, and the form, which the open group (or a new contract) edits. */
const saved = ref<ContractForm>(contractForm(null, 'USD'));
const form = ref<ContractForm>(contractForm(null, 'USD'));

const { data, error: loadError, loading, reload } = useLoader(async (signal) => {
    const [client, types, loaded] = await Promise.all([
        api.get<ApiResponse<Client>>(path(`clients/${clientId.value}`), undefined, signal),
        api.all<ChargeType>(path(`clients/${clientId.value}/charge-types`), {}, signal),
        contractId.value === null
            ? Promise.resolve(null)
            : api.get<ApiResponse<Contract>>(path(`clients/${clientId.value}/contracts/${contractId.value}`), { include: ['rates'] }, signal)
                .then((response) => response.data),
    ]);

    contract.value = loaded;
    saved.value = contractForm(loaded, client.data.currency ?? profile.value?.default_currency ?? 'USD');
    form.value = JSON.parse(JSON.stringify(saved.value)) as ContractForm;

    return { client: client.data, chargeTypes: types };
}, [profileId, clientId, contractId]);

const canSave = computed(() => can(isNew.value ? 'create' : 'update', 'contracts'));

/* What the closed groups show */
const validity = computed(() => t('clients.contract.validity', {
    from: formatDate(saved.value.started_at),
    until: saved.value.ends_at ? t('clients.contract.until', { date: formatDate(saved.value.ends_at) }) : t('clients.contract.openEnded'),
}));
const savedDescription = computed(() => describeContract(saved.value.description_template, {
    name: saved.value.name,
    number: saved.value.number,
    started_at: saved.value.started_at,
    ends_at: saved.value.ends_at,
    currency: saved.value.currency,
    payment_terms_days: saved.value.payment_terms_days,
}));
const typeName = (id: number | null): string => data.value?.chargeTypes.find((type) => type.id === id)?.name ?? t('clients.contract.default');
const savedRates = computed(() => [...saved.value.rates].sort((a, b) => (a.charge_type_id ?? 0) - (b.charge_type_id ?? 0)
    || b.effective_from.localeCompare(a.effective_from)));

/* Saving a group, or a new contract */
const saving = ref(false);
const saveError = ref<unknown>(null);

function open(): void {
    form.value = JSON.parse(JSON.stringify(saved.value)) as ContractForm;
    saveError.value = null;
}

async function saveGroup(group: ContractGroup): Promise<void> {
    saving.value = true;
    saveError.value = null;

    try {
        await api.patch(path(`clients/${clientId.value}/contracts/${contractId.value}`), contractFields(form.value, group));
        await reload();
        accordion.open.value = null;
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

async function create(): Promise<void> {
    saving.value = true;
    saveError.value = null;

    try {
        await api.post(path(`clients/${clientId.value}/contracts`), { ...contractFields(form.value, 'all'), is_active: true });
        await router.replace({ name: 'client', params: { id: clientId.value } });
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

/* «Активний» saves at once */
const switching = ref(false);
const switchError = ref<string | null>(null);

async function setActive(value: boolean): Promise<void> {
    switching.value = true;
    switchError.value = null;

    try {
        await api.patch(path(`clients/${clientId.value}/contracts/${contractId.value}`), { is_active: value });
        await reload();
    } catch (caught) {
        switchError.value = errorMessage(caught);
    } finally {
        switching.value = false;
    }
}

const errors = computed(() => Object.fromEntries(
    ['name', 'number', 'subject', 'started_at', 'ends_at', 'payment_terms_days', 'description_template']
        .map((field) => [field, fieldError(saveError.value, field)]),
));
const generalError = computed(() => (saveError.value && !Object.values(errors.value).some(Boolean) ? errorMessage(saveError.value) : null));
</script>

<template>
    <SubScreen :title="isNew ? t('clients.contract.newContract') : t('core.common.contract')" :subtitle="data?.client.name" :back="{ name: 'client', params: { id: clientId } }">
        <LoadState :loading="loading && !data" :error="loadError" @retry="reload">
            <template v-if="isNew && data">
                <section class="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-4">
                    <ContractMainFields v-model="form" :errors="errors" />
                </section>
                <section class="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-4">
                    <h2 class="m-0 text-16 font-semibold">{{ t('clients.contract.itemGroup') }}</h2>
                    <ContractItemFields v-model="form" :error="errors.description_template" />
                </section>
                <section class="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-4">
                    <h2 class="m-0 text-16 font-semibold">{{ t('clients.contract.hourlyRates') }}</h2>
                    <ContractRatesEditor v-model="form" :charge-types="data.chargeTypes" />
                </section>
                <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
            </template>

            <template v-else-if="contract && data">
                <AccordionGroup id="main" :title="t('clients.contract.mainGroup')" :editable="canSave" :saving="saving" :error="generalError" @open="open" @save="saveGroup('main')">
                    <ReadRow :label="t('core.common.name')" :value="saved.name" />
                    <ReadRow v-if="saved.number" :label="t('core.common.number')"><span class="num">{{ saved.number }}</span></ReadRow>
                    <ReadRow :label="t('clients.contract.subjectShort')" :value="saved.subject || '—'" />
                    <ReadRow :label="t('clients.contract.validFrom')" :value="validity" />
                    <ReadRow :label="t('core.common.currency')" :value="saved.currency" />
                    <ReadRow :label="t('clients.contract.paymentShort')" :value="saved.payment_terms_days ? t('core.counts.days', { count: Number(saved.payment_terms_days) }) : '—'" />
                    <template #edit>
                        <ContractMainFields v-model="form" :errors="errors" />
                    </template>
                </AccordionGroup>

                <AccordionGroup id="item" :title="t('clients.contract.itemGroup')" :editable="canSave" :saving="saving" :error="generalError" :rows="false" @open="open" @save="saveGroup('item')">
                    <span class="text-15 leading-[1.45]" :class="savedDescription ? '' : 'text-ink-3'">{{ savedDescription || t('clients.contract.noTemplate') }}</span>
                    <template #edit>
                        <ContractItemFields v-model="form" :error="errors.description_template" />
                    </template>
                </AccordionGroup>

                <AccordionGroup id="rates" :title="t('clients.contract.hourlyRates')" :editable="canSave" :saving="saving" :error="generalError" @open="open" @save="saveGroup('rates')">
                    <ReadRow v-for="rate in savedRates" :key="rate.key" :label="typeName(rate.charge_type_id)">
                        <span class="num font-semibold">{{ formatAmount(rate.hourly_rate) }}</span> · {{ t('core.common.fromDate', { date: formatDate(rate.effective_from) }) }}
                    </ReadRow>
                    <span v-if="savedRates.length === 0" class="text-15 text-ink-3">{{ t('clients.contract.noRates') }}</span>
                    <template #edit>
                        <ContractRatesEditor v-model="form" :charge-types="data.chargeTypes" />
                    </template>
                </AccordionGroup>

                <Dimmable class="flex flex-col gap-2">
                    <SwitchCard
                        :model-value="contract.is_active"
                        :title="t('core.common.active')"
                        :sub="t('clients.contract.inactiveHint')"
                        :disabled="!canSave || switching"
                        @update:model-value="setActive"
                    />
                    <p v-if="switchError" class="m-0 text-14 text-negative" role="alert">{{ switchError }}</p>
                    <p class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('core.accordion.oneAtATime') }}</p>
                </Dimmable>
            </template>
        </LoadState>

        <template v-if="isNew && canSave" #footer>
            <PrimaryButton :busy="saving" :disabled="loading || !form.name.trim() || !form.started_at" @click="create">{{ t('core.common.save') }}</PrimaryButton>
        </template>
    </SubScreen>
</template>
