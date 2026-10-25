<script setup lang="ts">
import { computed } from 'vue';
import type { TaxType } from '@/api/types';
import SelectField from '@/components/form/SelectField.vue';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';
import { formatDate, quarterLabel, today } from '@/utils/dates';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * The opening balances of the taxes, as «Вхідні сальдо» edits them: each a tax, the first day of a quarter and an
 * amount («+» overpaid, «−» owed). A saved one changes its amount only; a new one chooses its tax and date. The group
 * saves the drafts.
 */
export interface OpeningBalanceDraft {
    key: string;
    id: number | null;
    tax_type: TaxType;
    as_of: string;
    amount: string;
}

const { errors = {} } = defineProps<{
    /** Errors by the draft's key. */
    errors?: Record<string, string | undefined>;
}>();

const drafts = defineModel<OpeningBalanceDraft[]>({ required: true });

const TAXES: TaxType[] = ['single_tax', 'military_levy', 'social_contribution'];
const taxOptions = computed(() => TAXES.map((type) => ({ value: type, label: TAX_TYPES[type] })));

/** The first days of the quarters of this year and the two before. */
const quarterStarts = computed(() => {
    const year = Number(today().slice(0, 4));

    return [year, year - 1, year - 2].flatMap((value) => [1, 2, 3, 4].map((quarter) => {
        const date = `${value}-${String((quarter - 1) * 3 + 1).padStart(2, '0')}-01`;

        return { value: date, label: t('settings.taxes.openingBalanceDate', { date: formatDate(date), quarter: quarterLabel(quarter), year: value }) };
    }));
});

function add(): void {
    drafts.value = [...drafts.value, {
        key: `new-${Date.now()}`,
        id: null,
        tax_type: 'single_tax',
        as_of: `${today().slice(0, 4)}-01-01`,
        amount: '',
    }];
}

function remove(key: string): void {
    drafts.value = drafts.value.filter((draft) => draft.key !== key);
}
</script>

<template>
    <div class="flex flex-col gap-2.5">
        <div v-for="draft in drafts" :key="draft.key" class="flex flex-col gap-2 rounded-control border border-line bg-surface p-3">
            <div class="flex items-center gap-2">
                <span v-if="draft.id" class="flex-1 text-15">
                    <span class="font-semibold">{{ TAX_TYPES[draft.tax_type] }}</span>
                    <span class="text-ink-2"> · {{ t('core.common.asOfDate', { date: formatDate(draft.as_of) }) }}</span>
                </span>
                <span v-else class="flex-1 text-15 font-semibold">{{ t('settings.taxes.newOpeningBalance') }}</span>
                <button type="button" :aria-label="t('core.common.delete')" class="-mr-1.5 flex size-10 items-center justify-center text-ink-3" @click="remove(draft.key)">
                    <Icon name="close" :size="18" />
                </button>
            </div>
            <div v-if="!draft.id" class="grid grid-cols-[1fr_2fr] gap-2">
                <SelectField v-model="draft.tax_type" :label="t('core.common.tax')" :options="taxOptions" />
                <SelectField v-model="draft.as_of" :label="t('core.common.asOf')" :options="quarterStarts" />
            </div>
            <input
                v-model="draft.amount"
                inputmode="decimal"
                placeholder="-765.69"
                :aria-label="t('settings.taxes.openingBalanceAmount')"
                class="num h-12 rounded-control border bg-surface px-3.5 text-16"
                :class="errors[draft.key] ? 'border-negative' : 'border-line-input'"
            >
            <span v-if="errors[draft.key]" class="text-12 text-negative">{{ errors[draft.key] }}</span>
        </div>
        <button type="button" class="flex min-h-11 items-center gap-1 self-start text-15 font-medium text-accent" @click="add">
            <Icon name="plus" :size="18" />
            {{ t('settings.taxes.addOpeningBalance') }}
        </button>
        <p class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('settings.taxes.openingBalancesHint') }}</p>
    </div>
</template>
