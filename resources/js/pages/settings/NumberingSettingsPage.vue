<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { api } from '@/api/client';
import type { ApiResponse, Client, InvoiceCounter, NumberingDateSource, NumberingPrefix, NumberingReset, NumberingScope, NumberingStrategy, NumberPreview, Paginated, ProfileSettings } from '@/api/types';
import AccordionGroup from '@/components/form/AccordionGroup.vue';
import Dimmable from '@/components/form/Dimmable.vue';
import ReadRow from '@/components/form/ReadRow.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { provideAccordion } from '@/composables/useAccordion';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { dateParts, formatDayMonth, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * Нумерація інвойсів (M-NumberingSettings, design.md §5, §16.5): the dark «Наступний номер» card, which never dims,
 * then the groups: «Формат номера» (the client's abbreviation in front or none, date and number or the number only,
 * the date in it), «Лічильник» (one per ФОП or per client, its yearly reset) and «Поточні лічильники» (the last number
 * of each, which moves forward only; read-only, they show as disabled fields). Then the rules and a link to the
 * invoices' notes, which moved to Документ інвойсу.
 */
const { can } = useSession();
const { profileId, path, loadSettings } = useCurrentProfile();
const accordion = provideAccordion();

const form = reactive({
    prefix: 'client_abbreviation' as NumberingPrefix,
    strategy: 'date_sequence' as NumberingStrategy,
    date_source: 'period_end' as NumberingDateSource,
    scope: 'per_client' as NumberingScope,
    reset: 'never' as NumberingReset,
});

const counters = ref<InvoiceCounter[]>([]);
/** The last numbers of the counters, by scope, as «Поточні лічильники» edits them. */
const lastNumbers = ref<Record<string, string>>({});
const sampleClient = ref<Client | null>(null);

/** The saved numbering, which the preview of the next number follows until the form is saved. */
const saved = ref<ProfileSettings['invoice']['numbering'] | null>(null);

const { error: loadError, loading, reload } = useLoader(async (signal) => {
    const [settings, list, clients] = await Promise.all([
        api.get<ApiResponse<ProfileSettings>>(path('settings'), undefined, signal),
        api.get<ApiResponse<InvoiceCounter[]>>(path('invoices/counters'), undefined, signal),
        api.get<Paginated<Client>>(path('clients'), { page: { size: 1 } }, signal),
    ]);

    Object.assign(form, settings.data.invoice.numbering);
    saved.value = settings.data.invoice.numbering;
    counters.value = list.data;
    lastNumbers.value = Object.fromEntries(list.data.map((counter) => [counter.scope_key, String(counter.last_number)]));
    sampleClient.value = clients.data[0] ?? null;

    return true;
}, [profileId]);

const periodEnd = computed(() => {
    const { year, month } = dateParts(today());

    return new Date(Date.UTC(year, month, 0)).toISOString().slice(0, 10);
});

const { data: preview, reload: reloadPreview } = useLoader(async (signal) => {
    if (!sampleClient.value) {
        return null;
    }

    const { data } = await api.get<ApiResponse<NumberPreview>>(path('invoices/number-preview'), {
        client_id: sampleClient.value.id,
        period_end: periodEnd.value,
    }, signal);

    return data;
}, [sampleClient]);

const exampleCount = computed(() => preview.value?.number.split('-').pop() ?? '14');
const examplePrefix = computed(() => (form.prefix === 'client_abbreviation' ? `${sampleClient.value?.abbreviation ?? 'BL'}-` : ''));
const patternPrefix = computed(() => (form.prefix === 'client_abbreviation' ? t('settings.numbering.abbreviationSample') : ''));
const formatOptions = computed(() => [
    {
        value: 'date_sequence' as const,
        title: t('settings.numbering.dateAndNumber'),
        example: t('settings.numbering.formatExample', { prefix: patternPrefix.value, example: `${examplePrefix.value}${periodEnd.value}-${exampleCount.value}` }),
    },
    { value: 'sequence' as const, title: t('settings.numbering.numberOnly'), example: `${patternPrefix.value}n → ${examplePrefix.value}${exampleCount.value}` },
]);

const prefixOptions = computed<{ value: NumberingPrefix; label: string }[]>(() => [
    { value: 'client_abbreviation', label: t('settings.numbering.clientAbbreviation') },
    { value: 'none', label: t('settings.numbering.noPrefix') },
]);
const dateSourceOptions = computed<{ value: NumberingDateSource; label: string }[]>(() => [
    { value: 'period_end', label: t('settings.numbering.periodEnd') },
    { value: 'issued_at', label: t('invoices.editor.issueDate') },
]);
const scopeOptions = computed<{ value: NumberingScope; label: string }[]>(() => [
    { value: 'per_client', label: t('settings.numbering.perClient') },
    { value: 'global', label: t('settings.numbering.onePerFop') },
]);
const resetOptions = computed<{ value: NumberingReset; label: string }[]>(() => [
    { value: 'never', label: t('settings.numbering.never') },
    { value: 'yearly', label: t('settings.numbering.yearly') },
]);

/** «АБР-РРРР-ММ-ДД-n», «АБР-n»: the pattern of the saved format. */
const savedPattern = computed(() => {
    const prefix = saved.value?.prefix === 'client_abbreviation' ? t('settings.numbering.abbreviationSample') : '';

    return saved.value?.strategy === 'sequence' ? `${prefix}n` : `${prefix}${t('settings.numbering.datePattern')}-n`;
});

/** What the saved numbering is made of, under the next number. */
const savedSummary = computed(() => (saved.value
    ? [
        saved.value.prefix === 'client_abbreviation' ? t('invoices.numberParts.abbreviation') : null,
        saved.value.strategy === 'date_sequence' ? (saved.value.date_source === 'period_end' ? t('invoices.numberParts.periodEnd') : t('invoices.numberParts.issueDate')) : null,
        saved.value.scope === 'per_client' ? t('invoices.numberParts.clientCounter') : t('invoices.numberParts.fopCounter'),
    ].filter(Boolean).join(' · ')
    : ''));

/** Without the prefix, two clients' counters give the same number for the same date, which the freeze refuses. */
const mayCollide = computed(() => form.prefix === 'none' && form.scope === 'per_client');
const canConfigure = computed(() => can('configure', 'profiles'));

/** The name of a counter: its client with the abbreviation, or the ФОП's one. */
function counterName(counter: InvoiceCounter | null): string {
    if (!counter?.client_id) {
        return t('settings.numbering.onePerFop');
    }

    return [counter.client_abbreviation, counter.client_name].filter(Boolean).join(' · ');
}

/* Saving a group: the format and the counter are settings, the current counters move forward */
const saving = ref(false);
const saveError = ref<unknown>(null);

function open(): void {
    if (saved.value) {
        Object.assign(form, saved.value);
    }

    lastNumbers.value = Object.fromEntries(counters.value.map((counter) => [counter.scope_key, String(counter.last_number)]));
    saveError.value = null;
}

async function saveGroup(group: 'format' | 'counter' | 'current'): Promise<void> {
    saving.value = true;
    saveError.value = null;

    try {
        if (group === 'current') {
            for (const counter of counters.value) {
                const last = Number(lastNumbers.value[counter.scope_key]);

                if (last !== counter.last_number) {
                    await api.patch(path('invoices/counters'), { scope_key: counter.scope_key, next_number: last + 1 });
                }
            }
        } else {
            await api.patch(path('settings'), {
                invoice: {
                    numbering: { prefix: form.prefix, strategy: form.strategy, date_source: form.date_source, scope: form.scope, reset: form.reset },
                },
            });
            await loadSettings();
        }

        await reload();
        await reloadPreview();
        accordion.open.value = null;
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

const countersValid = computed(() => Object.values(lastNumbers.value).every((value) => /^\d+$/.test(value)));
const generalError = computed(() => (saveError.value ? fieldError(saveError.value, 'next_number') ?? errorMessage(saveError.value) : null));
</script>

<template>
    <SubScreen :title="t('settings.numbering.title')" :back="{ name: 'more' }">
        <LoadState :loading="loading && !saved" :error="loadError" @retry="reload">
            <section v-if="preview" class="flex flex-col gap-1 rounded-card bg-ink p-4 text-white">
                <span class="text-14 text-on-ink-2">
                    {{ t('settings.numbering.nextNumber') }}<template v-if="sampleClient"> · {{ t('settings.numbering.periodEnding', { client: sampleClient.name, date: formatDayMonth(periodEnd) }) }}</template>
                </span>
                <span class="num text-30 font-bold">{{ preview.number }}</span>
                <span v-if="saved" class="text-13 text-on-ink-2">{{ savedSummary }}</span>
                <span class="text-12 text-on-ink-3">{{ t('settings.numbering.shownSavedSettings') }}</span>
            </section>

            <template v-if="saved">
                <AccordionGroup
                    id="format"
                    :title="t('settings.numbering.numberFormat')"
                    :editable="canConfigure"
                    :saving="saving"
                    :error="generalError"
                    @open="open"
                    @save="saveGroup('format')"
                >
                    <ReadRow :label="t('settings.numbering.prefix')" :value="prefixOptions.find((option) => option.value === saved!.prefix)?.label" />
                    <ReadRow :label="t('settings.numbering.format')">
                        {{ formatOptions.find((option) => option.value === saved!.strategy)?.title }}
                        <span class="num mt-0.5 block text-13 text-ink-3">{{ savedPattern }}</span>
                    </ReadRow>
                    <ReadRow
                        v-if="saved.strategy === 'date_sequence'"
                        :label="t('settings.numbering.dateInNumber')"
                        :value="dateSourceOptions.find((option) => option.value === saved!.date_source)?.label"
                    />
                    <template #edit>
                        <div class="flex flex-col gap-1.5">
                            <span class="text-13 font-medium text-ink-2">{{ t('settings.numbering.prefix') }}</span>
                            <SegmentedControl v-model="form.prefix" :label="t('settings.numbering.prefix')" size="lg" :options="prefixOptions" />
                            <span class="text-12 leading-normal text-ink-3">{{ t('settings.numbering.abbreviationHint') }}</span>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <span class="text-13 font-medium text-ink-2">{{ t('settings.numbering.format') }}</span>
                            <div role="radiogroup" :aria-label="t('settings.numbering.numberFormat')" class="flex flex-col gap-2">
                                <button
                                    v-for="option in formatOptions"
                                    :key="option.value"
                                    type="button"
                                    role="radio"
                                    :aria-checked="form.strategy === option.value ? 'true' : 'false'"
                                    class="flex items-start gap-3 rounded-control bg-surface p-3.5 text-left"
                                    :class="form.strategy === option.value ? 'border-2 border-accent' : 'border border-line-input'"
                                    @click="form.strategy = option.value"
                                >
                                    <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border-2" :class="form.strategy === option.value ? 'border-accent' : 'border-switch-off'">
                                        <span v-if="form.strategy === option.value" class="size-2.5 rounded-full bg-accent" />
                                    </span>
                                    <span class="flex flex-col gap-0.5">
                                        <span class="text-16 font-semibold">{{ option.title }}</span>
                                        <span class="num text-13 text-ink-2">{{ option.example }}</span>
                                    </span>
                                </button>
                            </div>
                        </div>
                        <div v-if="form.strategy === 'date_sequence'" class="flex flex-col gap-1.5">
                            <span class="text-13 font-medium text-ink-2">{{ t('settings.numbering.dateInNumber') }}</span>
                            <SegmentedControl v-model="form.date_source" :label="t('settings.numbering.dateInNumber')" size="lg" :options="dateSourceOptions" />
                        </div>
                    </template>
                </AccordionGroup>

                <AccordionGroup
                    id="counter"
                    :title="t('settings.numbering.counter')"
                    :editable="canConfigure"
                    :saving="saving"
                    :error="generalError"
                    @open="open"
                    @save="saveGroup('counter')"
                >
                    <ReadRow :label="t('settings.numbering.counter')" :value="scopeOptions.find((option) => option.value === saved!.scope)?.label" />
                    <ReadRow :label="t('settings.numbering.resetShort')" :value="resetOptions.find((option) => option.value === saved!.reset)?.label" />
                    <template #edit>
                        <div class="flex flex-col gap-1.5">
                            <span class="text-13 font-medium text-ink-2">{{ t('settings.numbering.counter') }}</span>
                            <SegmentedControl v-model="form.scope" :label="t('settings.numbering.counter')" size="lg" :options="scopeOptions" />
                            <span v-if="mayCollide" class="text-12 leading-normal text-warning-text" role="status">{{ t('settings.numbering.noPrefixWarning') }}</span>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <span class="text-13 font-medium text-ink-2">{{ t('settings.numbering.counterReset') }}</span>
                            <SegmentedControl v-model="form.reset" :label="t('settings.numbering.counterReset')" size="lg" :options="resetOptions" />
                        </div>
                    </template>
                </AccordionGroup>

                <AccordionGroup
                    id="current"
                    :title="t('settings.numbering.currentCounters')"
                    :editable="canConfigure && counters.length > 0"
                    :saving="saving"
                    :error="generalError"
                    :rows="false"
                    @open="open"
                    @save="countersValid && saveGroup('current')"
                >
                    <div v-for="counter in counters" :key="counter.scope_key" class="flex items-center gap-3">
                        <span class="min-w-0 flex-1 text-15">
                            {{ counterName(counter) }}
                            <span v-if="/:\d{4}$/.test(counter.scope_key)" class="num text-13 text-ink-3">{{ counter.scope_key.slice(-4) }}</span>
                        </span>
                        <input
                            :value="counter.last_number"
                            disabled
                            :aria-label="counterName(counter)"
                            class="num h-11 w-20 rounded-control border border-line bg-muted text-center text-16 text-ink-2"
                        >
                    </div>
                    <span v-if="counters.length === 0" class="text-14 text-ink-3">{{ t('settings.numbering.noCountersYet') }}</span>
                    <template #edit>
                        <div v-for="counter in counters" :key="counter.scope_key" class="flex items-center gap-3">
                            <span class="min-w-0 flex-1 text-15">
                                {{ counterName(counter) }}
                                <span v-if="/:\d{4}$/.test(counter.scope_key)" class="num text-13 text-ink-3">{{ counter.scope_key.slice(-4) }}</span>
                            </span>
                            <input
                                v-model="lastNumbers[counter.scope_key]"
                                inputmode="numeric"
                                :aria-label="counterName(counter)"
                                class="num h-11 w-20 rounded-control border border-line-input bg-surface text-center text-16"
                            >
                        </div>
                        <span class="text-12 text-ink-3">{{ t('settings.numbering.lastIssuedHint') }}</span>
                    </template>
                </AccordionGroup>

                <Dimmable class="flex flex-col gap-3">
                    <p class="m-0 text-13 leading-normal text-ink-3">{{ t('settings.numbering.explained') }}</p>
                    <RouterLink
                        :to="{ name: 'settings-invoice-document' }"
                        class="flex min-h-15 items-center gap-3 rounded-card border border-line bg-surface px-4 py-3 text-ink no-underline hover:text-ink"
                    >
                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="text-16">{{ t('settings.numbering.notesLink') }}</span>
                            <span class="text-13 text-ink-3">{{ t('settings.numbering.noteMoved') }}</span>
                        </span>
                        <Icon name="chevronRight" :size="18" class="text-ink-2" />
                    </RouterLink>
                    <p class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('core.accordion.oneAtATime') }}</p>
                </Dimmable>
            </template>
        </LoadState>
    </SubScreen>
</template>
