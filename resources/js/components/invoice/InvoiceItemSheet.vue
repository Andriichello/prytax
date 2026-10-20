<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { ChargeType, ContractRate, ItemUnit, Project } from '@/api/types';
import SelectField from '@/components/form/SelectField.vue';
import TextAreaField from '@/components/form/TextAreaField.vue';
import TextField from '@/components/form/TextField.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { rateOn } from '@/utils/contracts';
import { formatDate } from '@/utils/dates';
import { formatMoney, multiplyAmounts, parseAmount } from '@/utils/money';

/**
 * Позиція інвойсу (S-InvoiceItem): project, work type, the description (the contract's template by default), the
 * unit, quantity × price (the contract's rate on the period's end by default) and the line's amount.
 */
export interface ItemDraft {
    key: number;
    project_id: number | null;
    charge_type_id: number | null;
    description: string;
    unit: ItemUnit;
    quantity: string;
    unit_price: string;
}

const {
    item,
    position,
    currency,
    projects,
    chargeTypes,
    rates,
    rateDate,
    template,
    removable = true,
} = defineProps<{
    item: ItemDraft | null;
    position: number;
    currency: string;
    projects: Project[];
    chargeTypes: ChargeType[];
    /** The contract's rates, when the contract is in the invoice's currency. */
    rates: ContractRate[];
    /** The date the rate is taken on: the end of the invoice's period. */
    rateDate: string;
    /** The description from the contract's template. */
    template: string | null;
    removable?: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ save: [item: ItemDraft]; remove: [key: number] }>();

const draft = ref<ItemDraft>({ key: 0, project_id: null, charge_type_id: null, description: '', unit: 'hour', quantity: '', unit_price: '' });
const errors = ref<{ quantity?: string; unit_price?: string }>({});

watch(open, (isOpen) => {
    if (isOpen && item) {
        draft.value = { ...item };
        errors.value = {};
    }
});

const rate = computed(() => (draft.value.unit === 'hour' && rateDate ? rateOn(rates, rateDate, draft.value.charge_type_id) : null));

/**
 * A new work type takes its rate, unless the price was typed by hand (it differs from the previous type's rate).
 */
watch(() => [draft.value.charge_type_id, draft.value.unit] as const, (_next, previous) => {
    if (!open.value || !previous) {
        return;
    }

    const before = previous[1] === 'hour' ? rateOn(rates, rateDate, previous[0])?.hourly_rate ?? '' : '';

    if (draft.value.unit_price === '' || draft.value.unit_price === before) {
        draft.value.unit_price = rate.value?.hourly_rate ?? draft.value.unit_price;
    }
});

const amount = computed(() => {
    const quantity = parseAmount(draft.value.quantity);
    const price = parseAmount(draft.value.unit_price);

    return quantity !== null && price !== null ? multiplyAmounts(quantity, price) : null;
});

/** The selects take 0 for "none". */
const projectId = computed({
    get: () => draft.value.project_id ?? 0,
    set: (value: number | null) => {
        draft.value.project_id = value || null;
    },
});
const chargeTypeId = computed({
    get: () => draft.value.charge_type_id ?? 0,
    set: (value: number | null) => {
        draft.value.charge_type_id = value || null;
    },
});

const quantityLabel = computed(() => ({ hour: 'Кількість, год', fixed: 'Кількість', item: 'Кількість, шт' })[draft.value.unit]);
const chargeTypeName = computed(() => chargeTypes.find((type) => type.id === draft.value.charge_type_id)?.name);

function done(): void {
    errors.value = {
        quantity: parseAmount(draft.value.quantity) === null || Number(parseAmount(draft.value.quantity)) <= 0 ? 'Вкажіть кількість.' : undefined,
        unit_price: parseAmount(draft.value.unit_price) === null ? 'Вкажіть ціну.' : undefined,
    };

    if (errors.value.quantity || errors.value.unit_price) {
        return;
    }

    emit('save', { ...draft.value, description: draft.value.description.trim() });
    open.value = false;
}

function remove(): void {
    emit('remove', draft.value.key);
    open.value = false;
}
</script>

<template>
    <BottomSheet v-model:open="open" :title="`Позиція ${position}`">
        <div class="grid grid-cols-2 gap-2.5">
            <SelectField
                v-model="projectId"
                label="Проєкт"
                :options="[{ value: 0, label: 'Без проєкту' }, ...projects.map((project) => ({ value: project.id, label: project.code ?? project.name }))]"
            />
            <SelectField
                v-model="chargeTypeId"
                label="Тип робіт"
                :options="[{ value: 0, label: 'Без типу' }, ...chargeTypes.map((type) => ({ value: type.id, label: type.name }))]"
            />
        </div>

        <TextAreaField
            v-model="draft.description"
            label="Опис"
            :rows="3"
            :aside="template && draft.description === template ? 'з шаблону договору' : undefined"
        />

        <div class="flex flex-col gap-1.5">
            <span class="text-13 font-medium text-ink-2">Одиниця</span>
            <SegmentedControl
                v-model="draft.unit"
                label="Одиниця"
                size="lg"
                :options="[
                    { value: 'hour', label: 'Година' },
                    { value: 'fixed', label: 'Фіксовано' },
                    { value: 'item', label: 'Штука' },
                ]"
            />
        </div>

        <div class="flex flex-col gap-1.5">
            <div class="grid grid-cols-2 gap-2.5">
                <TextField v-model="draft.quantity" :label="quantityLabel" numeric :error="errors.quantity" />
                <TextField v-model="draft.unit_price" :label="`Ціна, ${currency}`" numeric :error="errors.unit_price" />
            </div>
            <p v-if="rate" class="m-0 text-13 text-ink-3">
                Ставка договору<template v-if="rate.charge_type_id && chargeTypeName"> для {{ chargeTypeName }}</template>
                з <span class="num">{{ formatDate(rate.effective_from) }}</span>
            </p>
        </div>

        <div class="flex items-center justify-between rounded-card border border-line bg-surface px-4 py-4">
            <span class="text-15 text-ink-2">Сума позиції</span>
            <span class="num text-22 font-bold">{{ amount ? formatMoney(amount, currency) : '—' }}</span>
        </div>

        <div class="grid grid-cols-[1fr_2fr] gap-2.5">
            <SecondaryButton v-if="removable" tone="negative" @click="remove">Видалити</SecondaryButton>
            <PrimaryButton :class="removable ? '' : 'col-span-2'" @click="done">Готово</PrimaryButton>
        </div>
    </BottomSheet>
</template>
