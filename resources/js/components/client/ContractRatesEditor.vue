<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ChargeType } from '@/api/types';
import { rateKey, type ContractForm, type RateDraft } from '@/components/client/ContractFormModel';
import DateField from '@/components/form/DateField.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextField from '@/components/form/TextField.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import { t } from '@/i18n';
import { formatDate, today } from '@/utils/dates';
import { formatAmount, parseAmount } from '@/utils/money';

/**
 * The hourly rates of a contract by work type, each from a date (one rate of a type per date), as a list, which a
 * sheet edits; «+ Ставка» adds one.
 */
const { chargeTypes } = defineProps<{ chargeTypes: ChargeType[] }>();

const form = defineModel<ContractForm>({ required: true });

const typeName = (id: number | null): string => chargeTypes.find((type) => type.id === id)?.name ?? t('clients.contract.default');
const sortedRates = computed(() => [...form.value.rates].sort((a, b) => (a.charge_type_id ?? 0) - (b.charge_type_id ?? 0)
    || b.effective_from.localeCompare(a.effective_from)));

/** The work types of a rate: the default one (for all items) first. */
const rateTypeOptions = computed(() => [
    { value: 0, label: t('clients.contract.default') },
    ...chargeTypes.map((type) => ({ value: type.id, label: type.name })),
]);

const rateOpen = ref(false);
const rateDraft = ref<RateDraft>({ key: 0, charge_type_id: null, effective_from: today(), hourly_rate: '' });
const isListed = computed(() => form.value.rates.some((rate) => rate.key === rateDraft.value.key));

function openRate(rate: RateDraft | null): void {
    rateDraft.value = rate ? { ...rate } : { key: rateKey(), charge_type_id: null, effective_from: form.value.started_at || today(), hourly_rate: '' };
    rateOpen.value = true;
}

/** Another rate of the same work type starts on the same date: the API refuses two such rates. */
const rateClash = computed(() => form.value.rates.some((rate) => rate.key !== rateDraft.value.key
    && rate.charge_type_id === rateDraft.value.charge_type_id
    && rate.effective_from === rateDraft.value.effective_from));

function saveRate(): void {
    if (rateClash.value) {
        return;
    }

    form.value.rates = isListed.value
        ? form.value.rates.map((rate) => (rate.key === rateDraft.value.key ? { ...rateDraft.value } : rate))
        : [...form.value.rates, { ...rateDraft.value }];
    rateOpen.value = false;
}

function removeRate(): void {
    form.value.rates = form.value.rates.filter((rate) => rate.key !== rateDraft.value.key);
    rateOpen.value = false;
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div v-if="sortedRates.length" class="flex flex-col divide-y divide-divider rounded-control border border-line bg-surface px-3.5">
            <button
                v-for="rate in sortedRates"
                :key="rate.key"
                type="button"
                class="flex min-h-13 items-center justify-between gap-3 py-2 text-left"
                @click="openRate(rate)"
            >
                <span class="flex flex-col gap-0.5">
                    <span class="text-15" :class="rate.charge_type_id ? '' : 'text-ink-2'">{{ typeName(rate.charge_type_id) }}</span>
                    <span class="num text-12 text-ink-3">{{ t('core.common.fromDate', { date: formatDate(rate.effective_from) }) }}</span>
                </span>
                <span class="num text-16" :class="rate.charge_type_id ? 'font-bold' : 'text-ink-2'">{{ formatAmount(rate.hourly_rate) }}</span>
            </button>
        </div>
        <button type="button" class="flex min-h-11 items-center gap-1 self-start text-15 font-medium text-accent" @click="openRate(null)">
            <Icon name="plus" :size="18" />
            {{ t('clients.contract.rate') }}
        </button>
        <span class="text-12 text-ink-3">{{ t('clients.contract.rateByPeriodEnd') }}</span>

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
            <div class="grid gap-2.5" :class="isListed ? 'grid-cols-[1fr_2fr]' : 'grid-cols-1'">
                <SecondaryButton v-if="isListed" tone="negative" @click="removeRate">{{ t('core.common.delete') }}</SecondaryButton>
                <PrimaryButton :disabled="!rateDraft.effective_from || parseAmount(rateDraft.hourly_rate) === null || rateClash" @click="saveRate">{{ t('core.common.done') }}</PrimaryButton>
            </div>
        </BottomSheet>
    </div>
</template>
