<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { t } from '@/i18n';
import { currentQuarter, dateParts, quarterLabel } from '@/utils/dates';
import { TAX_TYPES } from '@/utils/statuses';
import {
    defaultFilter,
    LEDGER_STATUSES,
    LEDGER_TAXES,
    ledgerStatusLabel,
    quarterRangeLabel,
    tapQuarter,
    type TaxLedgerFilter,
    toggleOption,
} from '@/utils/taxLedger';

/**
 * «Фільтри» of Податки (design.md §16.2): one year (‹ 2026 ›, from the profile's first year, at most six back, to
 * now; a change keeps the quarters) with a range of its quarters, none being the whole year (drawn as all four
 * included); the taxes and the statuses, several at once, where removing the last one chooses all again. The drawer
 * edits a draft: «Застосувати» commits it, «Скинути» returns it to the defaults, and closing it discards it.
 */
const { value } = defineProps<{ value: TaxLedgerFilter }>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ apply: [filter: TaxLedgerFilter] }>();

const { profile } = useCurrentProfile();
const now = currentQuarter();

/** A filter with lists of its own, so the draft never edits the applied one. */
const copyOf = (filter: TaxLedgerFilter): TaxLedgerFilter => ({
    ...filter,
    quarters: [...filter.quarters],
    taxes: [...filter.taxes],
    statuses: [...filter.statuses],
});

const draft = reactive<TaxLedgerFilter>(copyOf(value));

watch(open, (isOpen) => {
    if (isOpen) {
        Object.assign(draft, copyOf(value));
    }
});

const firstYear = computed(() => {
    const created = profile.value?.created_at ? dateParts(profile.value.created_at).year : now.year;
    const registered = profile.value?.registered_at ? dateParts(profile.value.registered_at).year : now.year;

    return Math.max(now.year - 6, Math.min(created, registered, value.year));
});

const wholeYear = computed(() => draft.quarters.length === 0);

const hint = computed(() => (wholeYear.value
    ? t('shell.filters.wholeYearHint')
    : t('shell.filters.rangeHint', { range: quarterRangeLabel(draft.quarters) })));

function reset(): void {
    Object.assign(draft, defaultFilter(now));
}

function apply(): void {
    emit('apply', copyOf(draft));
    open.value = false;
}

/** A chosen quarter, one only included in the whole year, or one left out. */
function quarterClass(quarter: number): string {
    if (wholeYear.value) {
        return 'border border-accent-line bg-accent-faint font-medium text-accent-hover';
    }

    return draft.quarters.includes(quarter)
        ? 'border-2 border-accent bg-accent-soft font-semibold text-accent-hover'
        : 'border border-line-input bg-surface';
}

const chipClass = (chosen: boolean): string => (chosen ? 'bg-ink font-medium text-white' : 'border border-line-input bg-surface text-ink');
</script>

<template>
    <BottomSheet v-model:open="open" :title="t('shell.filters.title')">
        <template #action>
            <button type="button" class="flex min-h-11 items-center text-16 font-medium text-accent" @click="reset">{{ t('shell.filters.reset') }}</button>
        </template>

        <section class="flex flex-col gap-3">
            <SectionTitle as="h3">{{ t('shell.filters.period') }}</SectionTitle>
            <div class="flex items-center justify-between">
                <button
                    type="button"
                    :aria-label="t('shell.ui.previous')"
                    :disabled="draft.year <= firstYear"
                    class="flex size-11 items-center justify-center rounded-full border border-line-input bg-surface text-ink disabled:border-line disabled:bg-transparent disabled:text-handle"
                    @click="draft.year -= 1"
                >
                    <Icon name="chevronLeft" :size="20" />
                </button>
                <span class="num text-18 font-semibold" aria-live="polite">{{ draft.year }}</span>
                <button
                    type="button"
                    :aria-label="t('shell.ui.next')"
                    :disabled="draft.year >= now.year"
                    class="flex size-11 items-center justify-center rounded-full border border-line-input bg-surface text-ink disabled:border-line disabled:bg-transparent disabled:text-handle"
                    @click="draft.year += 1"
                >
                    <Icon name="chevronRight" :size="20" />
                </button>
            </div>
            <div role="group" :aria-label="t('core.common.quarter')" class="grid grid-cols-4 gap-2">
                <button
                    v-for="quarter in 4"
                    :key="quarter"
                    type="button"
                    :aria-pressed="draft.quarters.includes(quarter) ? 'true' : 'false'"
                    class="flex h-12 items-center justify-center rounded-control text-16"
                    :class="quarterClass(quarter)"
                    @click="draft.quarters = tapQuarter(draft.quarters, quarter)"
                >
                    {{ quarterLabel(quarter) }}
                </button>
            </div>
            <span class="text-14 text-ink-3">{{ hint }}</span>
        </section>

        <section class="flex flex-col gap-3">
            <SectionTitle as="h3">{{ t('accounting.taxes.filterTaxes') }}</SectionTitle>
            <div role="group" :aria-label="t('accounting.taxes.filterTaxes')" class="flex flex-wrap gap-2">
                <button
                    v-for="tax in LEDGER_TAXES"
                    :key="tax"
                    type="button"
                    :aria-pressed="draft.taxes.includes(tax) ? 'true' : 'false'"
                    class="flex h-11 items-center rounded-full px-4 text-15"
                    :class="chipClass(draft.taxes.includes(tax))"
                    @click="draft.taxes = toggleOption(draft.taxes, tax, LEDGER_TAXES)"
                >
                    {{ TAX_TYPES[tax] }}
                </button>
            </div>
            <span class="text-14 text-ink-3">{{ t('accounting.taxes.taxesHint') }}</span>
        </section>

        <section class="flex flex-col gap-3">
            <SectionTitle as="h3">{{ t('accounting.taxes.filterStatuses') }}</SectionTitle>
            <div role="group" :aria-label="t('accounting.taxes.filterStatuses')" class="flex flex-wrap gap-2">
                <button
                    v-for="status in LEDGER_STATUSES"
                    :key="status"
                    type="button"
                    :aria-pressed="draft.statuses.includes(status) ? 'true' : 'false'"
                    class="flex h-11 items-center rounded-full px-4 text-15"
                    :class="chipClass(draft.statuses.includes(status))"
                    @click="draft.statuses = toggleOption(draft.statuses, status, LEDGER_STATUSES)"
                >
                    {{ ledgerStatusLabel(status) }}
                </button>
            </div>
            <span class="text-14 text-ink-3">{{ t('accounting.taxes.statusesHint') }}</span>
        </section>

        <template #footer>
            <PrimaryButton @click="apply">{{ t('shell.filters.apply') }}</PrimaryButton>
        </template>
    </BottomSheet>
</template>
