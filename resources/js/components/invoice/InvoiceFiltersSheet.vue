<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api } from '@/api/client';
import type { InvoiceFacets, InvoiceStatus, Paginated, Invoice } from '@/api/types';
import DateField from '@/components/form/DateField.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Chip from '@/components/ui/Chip.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import Icon from '@/components/ui/Icon.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { emptyFilters, filtersToApi, type InvoiceFilters } from '@/composables/useInvoiceFilters';
import { t } from '@/i18n';
import { dateParts, monthName, quarterLabel, today } from '@/utils/dates';
import { INVOICE_STATUSES } from '@/utils/statuses';

/**
 * Фільтри інвойсів (S-InvoiceFilters): a period (years, quarters, months, all multi-select, or a range of issue
 * dates — one kind at a time: switching the kind clears the other's choice), clients, statuses and currencies. The
 * button shows how many invoices the choice finds.
 */
type PeriodMode = 'years' | 'quarters' | 'months' | 'range';

const STATUSES: InvoiceStatus[] = ['draft', 'ready', 'sent', 'paid', 'cancelled'];

const { facets, filters } = defineProps<{
    facets: InvoiceFacets | null;
    filters: InvoiceFilters;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ apply: [filters: InvoiceFilters] }>();

const { path } = useCurrentProfile();

const now = dateParts(today());
const draft = ref<InvoiceFilters>(emptyFilters());
const mode = ref<PeriodMode>('months');
const shownYear = ref(now.year);
const count = ref<number | null>(null);
const counting = ref(false);

const years = computed(() => {
    const known = (facets?.years ?? []).map((entry) => entry.year);

    return [...new Set([...known, now.year])].sort((a, b) => b - a);
});
const firstYear = computed(() => Math.min(...years.value));

/** "Січ", "Jan": the first three letters of each month's name, in the language of the screens. */
const monthsShort = computed(() => Array.from({ length: 12 }, (_, index) => monthName(index + 1).slice(0, 3)));

const periodModes = computed<{ value: PeriodMode; label: string }[]>(() => [
    { value: 'years', label: t('invoices.filters.years') },
    { value: 'quarters', label: t('invoices.filters.quarters') },
    { value: 'months', label: t('invoices.filters.months') },
    { value: 'range', label: t('invoices.filters.range') },
]);

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    draft.value = {
        ...filters,
        periods: [...filters.periods],
        clients: [...filters.clients],
        statuses: [...filters.statuses],
        currencies: [...filters.currencies],
    };

    const tokens = filters.periods;

    mode.value = filters.from || filters.to
        ? 'range'
        : tokens.some((token) => /-\d{2}$/.test(token)) ? 'months'
            : tokens.some((token) => token.includes('-Q')) ? 'quarters'
                : tokens.length ? 'years' : 'months';

    const latest = tokens.map((token) => Number(token.slice(0, 4))).sort((a, b) => b - a)[0];

    shownYear.value = latest ?? now.year;
});

function toggle<T>(list: T[], value: T): void {
    const index = list.indexOf(value);

    if (index === -1) {
        list.push(value);
    } else {
        list.splice(index, 1);
    }
}

/**
 * Switch the kind of period; what was chosen in another kind is dropped, as only one kind applies.
 */
function changeMode(next: PeriodMode): void {
    if (next === mode.value) {
        return;
    }

    mode.value = next;
    draft.value.periods = [];
    draft.value.from = '';
    draft.value.to = '';
}

function togglePeriod(token: string): void {
    draft.value.from = '';
    draft.value.to = '';
    toggle(draft.value.periods, token);
}

watch(() => [draft.value.from, draft.value.to], ([from, to]) => {
    if (from || to) {
        draft.value.periods = [];
    }
});

const quarterToken = (quarter: number): string => `${shownYear.value}-Q${quarter}`;
const monthToken = (month: number): string => `${shownYear.value}-${String(month).padStart(2, '0')}`;
const isFutureQuarter = (quarter: number): boolean => shownYear.value > now.year
    || (shownYear.value === now.year && (quarter - 1) * 3 + 1 > now.month);
const isFutureMonth = (month: number): boolean => shownYear.value > now.year || (shownYear.value === now.year && month > now.month);

let countTimer: ReturnType<typeof setTimeout> | undefined;
let countRequest: AbortController | null = null;

watch(draft, () => {
    clearTimeout(countTimer);
    countRequest?.abort();

    if (!open.value) {
        return;
    }

    counting.value = true;
    countTimer = setTimeout(async () => {
        const own = (countRequest = new AbortController());

        try {
            const result = await api.get<Paginated<Invoice>>(path('invoices'), {
                filter: filtersToApi(draft.value),
                page: { size: 1 },
            }, own.signal);

            count.value = result.meta.total;
        } catch {
            if (own === countRequest) {
                count.value = null;
            }
        } finally {
            if (own === countRequest) {
                counting.value = false;
            }
        }
    }, 250);
}, { deep: true });

function reset(): void {
    draft.value = emptyFilters();
}

function apply(): void {
    emit('apply', draft.value);
    open.value = false;
}

const tile = 'flex h-11 items-center justify-center rounded-control text-14';
const tileOff = 'border border-line-input bg-surface';
const tileOn = 'border-2 border-accent bg-accent-soft font-semibold text-accent-hover';
const tileDisabled = 'border border-dashed border-line-input text-switch-off';
</script>

<template>
    <BottomSheet v-model:open="open" :title="t('invoices.list.filters')">
        <template #action>
            <button type="button" class="flex min-h-11 items-center px-1 text-15 font-medium text-accent" @click="reset">{{ t('invoices.list.reset') }}</button>
        </template>

        <fieldset class="m-0 flex flex-col gap-3 border-0 p-0">
            <legend class="mb-2.5 p-0"><SectionTitle as="span">{{ t('core.common.period') }}</SectionTitle></legend>
            <SegmentedControl
                :model-value="mode"
                :label="t('invoices.filters.periodType')"
                :options="periodModes"
                @update:model-value="changeMode"
            />

            <div v-if="mode === 'years'" class="flex flex-wrap gap-2">
                <Chip
                    v-for="year in years"
                    :key="year"
                    :selected="draft.periods.includes(String(year))"
                    @click="togglePeriod(String(year))"
                >
                    <span class="num">{{ year }}</span>
                </Chip>
            </div>

            <template v-else-if="mode !== 'range'">
                <div class="flex items-center justify-between">
                    <button
                        type="button"
                        :aria-label="t('core.common.previousYear')"
                        :disabled="shownYear <= firstYear"
                        class="flex size-11 items-center justify-center rounded-full border border-line-input bg-surface disabled:border-line disabled:bg-paper disabled:text-switch-off"
                        @click="shownYear -= 1"
                    >
                        <Icon name="chevronLeft" :size="20" />
                    </button>
                    <span class="num text-16 font-semibold">{{ shownYear }}</span>
                    <button
                        type="button"
                        :aria-label="t('core.common.nextYear')"
                        :disabled="shownYear >= now.year"
                        class="flex size-11 items-center justify-center rounded-full border border-line-input bg-surface disabled:border-line disabled:bg-paper disabled:text-switch-off"
                        @click="shownYear += 1"
                    >
                        <Icon name="chevronRight" :size="20" />
                    </button>
                </div>

                <div v-if="mode === 'quarters'" role="group" :aria-label="t('invoices.filters.quartersOfYear', { year: shownYear })" class="grid grid-cols-4 gap-2">
                    <button
                        v-for="quarter in 4"
                        :key="quarter"
                        type="button"
                        :disabled="isFutureQuarter(quarter)"
                        :aria-pressed="draft.periods.includes(quarterToken(quarter)) ? 'true' : 'false'"
                        :class="[tile, isFutureQuarter(quarter) ? tileDisabled : draft.periods.includes(quarterToken(quarter)) ? tileOn : tileOff]"
                        @click="togglePeriod(quarterToken(quarter))"
                    >
                        {{ quarterLabel(quarter) }}
                    </button>
                </div>

                <div v-else role="group" :aria-label="t('invoices.filters.monthsOfYear', { year: shownYear })" class="grid grid-cols-4 gap-2">
                    <button
                        v-for="(name, index) in monthsShort"
                        :key="index"
                        type="button"
                        :disabled="isFutureMonth(index + 1)"
                        :aria-pressed="draft.periods.includes(monthToken(index + 1)) ? 'true' : 'false'"
                        :class="[tile, isFutureMonth(index + 1) ? tileDisabled : draft.periods.includes(monthToken(index + 1)) ? tileOn : tileOff]"
                        @click="togglePeriod(monthToken(index + 1))"
                    >
                        {{ name }}
                    </button>
                </div>
            </template>

            <div v-else class="grid grid-cols-2 gap-2.5">
                <DateField v-model="draft.from" :label="t('invoices.filters.issuedFrom')" :max="draft.to || undefined" />
                <DateField v-model="draft.to" :label="t('invoices.filters.through')" :min="draft.from || undefined" />
            </div>

            <span class="text-13 text-ink-3">{{ t('invoices.filters.hint') }}</span>
        </fieldset>

        <fieldset v-if="facets?.clients.length" class="m-0 flex flex-col border-0 p-0">
            <legend class="mb-2.5 p-0"><SectionTitle as="span">{{ t('core.common.clients') }}</SectionTitle></legend>
            <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-3.5">
                <label v-for="client in facets.clients" :key="client.client_id" class="flex min-h-12 items-center gap-3 text-15">
                    <input
                        type="checkbox"
                        class="m-0 size-5 accent-accent"
                        :checked="draft.clients.includes(client.client_id)"
                        @change="toggle(draft.clients, client.client_id)"
                    >
                    <span class="min-w-0 flex-1 truncate">{{ client.name }}</span>
                    <span class="num text-13 text-ink-3">{{ client.count }}</span>
                </label>
            </div>
        </fieldset>

        <fieldset class="m-0 flex flex-col border-0 p-0">
            <legend class="mb-2.5 p-0"><SectionTitle as="span">{{ t('core.common.status') }}</SectionTitle></legend>
            <div class="flex flex-wrap gap-2">
                <Chip v-for="status in STATUSES" :key="status" :selected="draft.statuses.includes(status)" @click="toggle(draft.statuses, status)">
                    {{ INVOICE_STATUSES[status].label }}
                </Chip>
            </div>
        </fieldset>

        <fieldset v-if="facets && facets.currencies.length > 1" class="m-0 flex flex-col border-0 p-0">
            <legend class="mb-2.5 p-0"><SectionTitle as="span">{{ t('core.common.currency') }}</SectionTitle></legend>
            <div class="flex flex-wrap gap-2">
                <Chip
                    v-for="currency in facets.currencies"
                    :key="currency.currency"
                    :selected="draft.currencies.includes(currency.currency)"
                    @click="toggle(draft.currencies, currency.currency)"
                >
                    <span class="num">{{ currency.currency }}</span>
                </Chip>
            </div>
        </fieldset>

        <template #footer>
            <PrimaryButton :busy="counting && count === null" @click="apply">
                {{ count === null ? t('invoices.filters.showInvoices') : count === 0 ? t('invoices.filters.nothingFound') : t('invoices.filters.showCount', { invoices: t('core.counts.invoices', { count }) }) }}
            </PrimaryButton>
        </template>
    </BottomSheet>
</template>
