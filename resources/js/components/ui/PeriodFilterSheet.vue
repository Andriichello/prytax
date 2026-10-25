<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { t } from '@/i18n';
import { currentQuarter, dateParts, quarterLabel } from '@/utils/dates';

/**
 * «Фільтри» of Облік (design.md §16.2): one year (‹ 2026 ›, from the profile's first year, at most six back, to now)
 * and one quarter; with `groupings`, «Групувати» too. The drawer edits a draft:
 * «Застосувати» commits it, «Скинути» returns it to the current period, and closing it discards it.
 */
export interface PeriodFilter {
    year: number;
    quarter: number;
    grouping?: string;
}

const { value, groupings = [] } = defineProps<{
    value: PeriodFilter;
    groupings?: { value: string; label: string }[];
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ apply: [filter: PeriodFilter] }>();

const { profile } = useCurrentProfile();
const now = currentQuarter();

const draft = reactive<PeriodFilter>({ ...value });

watch(open, (isOpen) => {
    if (isOpen) {
        Object.assign(draft, value);
    }
});

const firstYear = computed(() => {
    const created = profile.value?.created_at ? dateParts(profile.value.created_at).year : now.year;
    const registered = profile.value?.registered_at ? dateParts(profile.value.registered_at).year : now.year;

    return Math.max(now.year - 6, Math.min(created, registered, value.year));
});

/** A future quarter has nothing yet. */
const isFuture = (quarter: number): boolean => draft.year === now.year && quarter > now.quarter;

function stepYear(by: number): void {
    draft.year += by;

    if (isFuture(draft.quarter)) {
        draft.quarter = now.quarter;
    }
}

function reset(): void {
    Object.assign(draft, { year: now.year, quarter: now.quarter, grouping: groupings[0]?.value });
}

function apply(): void {
    emit('apply', { ...draft });
    open.value = false;
}
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
                    @click="stepYear(-1)"
                >
                    <Icon name="chevronLeft" :size="20" />
                </button>
                <span class="num text-18 font-semibold" aria-live="polite">{{ draft.year }}</span>
                <button
                    type="button"
                    :aria-label="t('shell.ui.next')"
                    :disabled="draft.year >= now.year"
                    class="flex size-11 items-center justify-center rounded-full border border-line-input bg-surface text-ink disabled:border-line disabled:bg-transparent disabled:text-handle"
                    @click="stepYear(1)"
                >
                    <Icon name="chevronRight" :size="20" />
                </button>
            </div>
            <div role="radiogroup" :aria-label="t('core.common.quarter')" class="grid grid-cols-4 gap-2">
                <button
                    v-for="quarter in 4"
                    :key="quarter"
                    type="button"
                    role="radio"
                    :aria-checked="draft.quarter === quarter ? 'true' : 'false'"
                    :disabled="isFuture(quarter)"
                    class="flex h-12 items-center justify-center rounded-control text-16 disabled:text-handle"
                    :class="draft.quarter === quarter ? 'border-2 border-accent bg-accent-soft font-semibold text-accent' : 'border border-line-input bg-surface'"
                    @click="draft.quarter = quarter"
                >
                    {{ quarterLabel(quarter) }}
                </button>
            </div>
            <span class="text-14 text-ink-3">{{ t('shell.filters.oneQuarter') }}</span>
        </section>

        <section v-if="groupings.length" class="flex flex-col gap-3">
            <SectionTitle as="h3">{{ t('shell.filters.grouping') }}</SectionTitle>
            <div role="radiogroup" :aria-label="t('shell.filters.grouping')" class="flex flex-wrap gap-2">
                <button
                    v-for="option in groupings"
                    :key="option.value"
                    type="button"
                    role="radio"
                    :aria-checked="draft.grouping === option.value ? 'true' : 'false'"
                    class="flex h-11 items-center rounded-full px-4 text-15"
                    :class="draft.grouping === option.value ? 'bg-ink text-white' : 'border border-line-input bg-surface text-ink'"
                    @click="draft.grouping = option.value"
                >
                    {{ option.label }}
                </button>
            </div>
        </section>

        <template #footer>
            <PrimaryButton @click="apply">{{ t('shell.filters.apply') }}</PrimaryButton>
        </template>
    </BottomSheet>
</template>
