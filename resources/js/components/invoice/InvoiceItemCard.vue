<script setup lang="ts">
import type { ItemUnit } from '@/api/types';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';
import { formatAmount } from '@/utils/money';

/**
 * A line of an invoice: the project's code and the work type, the description, and quantity × price = amount. With
 * `editable`, the whole card is a button that opens the item.
 */
export interface ItemView {
    project_code: string | null;
    charge_type: string | null;
    description: string | null;
    unit: ItemUnit;
    quantity: string;
    unit_price: string | null;
    amount: string | null;
}

const { item, editable = false } = defineProps<{
    item: ItemView;
    editable?: boolean;
}>();

const emit = defineEmits<{ open: [] }>();

/** The short names of the units, which translate when they are read. */
const UNITS: Record<ItemUnit, string> = {
    get hour() {
        return t('invoices.detail.hours');
    },
    get fixed() {
        return t('invoices.detail.fixed');
    },
    get item() {
        return t('invoices.detail.pcs');
    },
};
</script>

<template>
    <component
        :is="editable ? 'button' : 'div'"
        :type="editable ? 'button' : undefined"
        class="flex w-full flex-col gap-2.5 rounded-card border border-line bg-surface p-3.5 text-left text-ink"
        @click="editable && emit('open')"
    >
        <span v-if="item.project_code || item.charge_type || editable" class="flex items-center gap-1.5">
            <span v-if="item.project_code" class="num rounded-md bg-ink px-2 py-[3px] text-12 font-semibold text-white">{{ item.project_code }}</span>
            <span v-if="item.charge_type" class="rounded-md bg-neutral-soft px-2 py-[3px] text-12 font-medium text-ink-2">{{ item.charge_type }}</span>
            <span class="flex-1" />
            <Icon v-if="editable" name="chevronRight" :size="18" class="text-ink-2" />
        </span>
        <span class="text-14 leading-[1.45]">{{ item.description || t('invoices.detail.noDescription') }}</span>
        <span class="flex items-baseline justify-between border-t border-divider pt-2">
            <span class="num text-13 text-ink-2">
                {{ formatAmount(item.quantity) }} {{ UNITS[item.unit] }}<template v-if="item.unit_price"> × {{ formatAmount(item.unit_price) }}</template>
            </span>
            <span class="num text-16 font-semibold">{{ item.amount ? formatAmount(item.amount) : '—' }}</span>
        </span>
    </component>
</template>
