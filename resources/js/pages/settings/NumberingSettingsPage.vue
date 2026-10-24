<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, Client, InvoiceCounter, NumberingDateSource, NumberingPrefix, NumberingReset, NumberingScope, NumberingStrategy, NumberPreview, Paginated, ProfileSettings } from '@/api/types';
import TextAreaField from '@/components/form/TextAreaField.vue';
import TextField from '@/components/form/TextField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import Translated from '@/components/ui/Translated.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { dateParts, formatDayMonth, today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * Нумерація інвойсів (S-NumberingSettings): the client's abbreviation in front of numbers or none, the format of
 * numbers, the date in them, one counter per ФОП or per client, its yearly reset, the current counters (which move
 * forward only), and the note printed under invoices.
 */
const router = useRouter();
const { can } = useSession();
const { profileId, path, loadSettings } = useCurrentProfile();

const form = reactive({
    prefix: 'client_abbreviation' as NumberingPrefix,
    strategy: 'date_sequence' as NumberingStrategy,
    date_source: 'period_end' as NumberingDateSource,
    scope: 'per_client' as NumberingScope,
    reset: 'never' as NumberingReset,
    footer_note: '',
});

const counters = ref<InvoiceCounter[]>([]);
const sampleClient = ref<Client | null>(null);

/** The saved numbering, which the preview of the next number follows until the form is saved. */
const saved = ref<ProfileSettings['invoice']['numbering'] | null>(null);

const { error: loadError, loading, reload } = useLoader(async (signal) => {
    const [settings, list, clients] = await Promise.all([
        api.get<ApiResponse<ProfileSettings>>(path('settings'), undefined, signal),
        api.get<ApiResponse<InvoiceCounter[]>>(path('invoices/counters'), undefined, signal),
        api.get<Paginated<Client>>(path('clients'), { page: { size: 1 } }, signal),
    ]);

    Object.assign(form, { ...settings.data.invoice.numbering, footer_note: settings.data.invoice.footer_note ?? '' });
    saved.value = settings.data.invoice.numbering;
    counters.value = list.data;
    sampleClient.value = clients.data[0] ?? null;

    return true;
}, [profileId]);

/**
 * Load the counters and the next number again (after moving a counter), keeping the unsaved form.
 */
async function reloadCounters(): Promise<void> {
    const list = await api.get<ApiResponse<InvoiceCounter[]>>(path('invoices/counters'));

    counters.value = list.data;
    await reloadPreview();
}

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

/* Moving a counter forward */
const editing = ref<InvoiceCounter | null>(null);
const counterOpen = ref(false);
const nextNumber = ref('');
const counterError = ref<unknown>(null);
const movingCounter = ref(false);

function openCounter(counter: InvoiceCounter): void {
    editing.value = counter;
    nextNumber.value = String(counter.last_number + 1);
    counterError.value = null;
    counterOpen.value = true;
}

async function moveCounter(): Promise<void> {
    if (!editing.value) {
        return;
    }

    movingCounter.value = true;
    counterError.value = null;

    try {
        await api.patch(path('invoices/counters'), { scope_key: editing.value.scope_key, next_number: Number(nextNumber.value) });
        counterOpen.value = false;
        await reloadCounters();
    } catch (caught) {
        counterError.value = caught;
    } finally {
        movingCounter.value = false;
    }
}

/* Saving */
const saving = ref(false);
const saveError = ref<unknown>(null);

async function save(): Promise<void> {
    saving.value = true;
    saveError.value = null;

    try {
        await api.patch(path('settings'), {
            invoice: {
                numbering: { prefix: form.prefix, strategy: form.strategy, date_source: form.date_source, scope: form.scope, reset: form.reset },
                footer_note: form.footer_note.trim() || null,
            },
        });
        await loadSettings();
        await router.replace({ name: 'more' });
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <SubScreen :title="t('settings.numbering.title')" :back="{ name: 'more' }">
        <LoadState :loading="loading" :error="loadError" @retry="reload">
            <section v-if="preview" class="flex flex-col gap-1 rounded-card bg-ink p-4 text-white">
                <span class="text-14 text-on-ink-2">
                    {{ t('settings.numbering.nextNumber') }}<template v-if="sampleClient"> · {{ t('settings.numbering.periodEnding', { client: sampleClient.name, date: formatDayMonth(periodEnd) }) }}</template>
                </span>
                <span class="num text-30 font-bold">{{ preview.number }}</span>
                <span v-if="saved" class="text-13 text-on-ink-2">{{ savedSummary }}</span>
                <span class="text-12 text-on-ink-3">{{ t('settings.numbering.shownSavedSettings') }}</span>
            </section>

            <section class="flex flex-col gap-2">
                <SectionTitle>{{ t('settings.numbering.prefix') }}</SectionTitle>
                <SegmentedControl v-model="form.prefix" :label="t('settings.numbering.prefix')" size="lg" :disabled="!canConfigure" :options="prefixOptions" />
                <p class="m-0 text-13 leading-normal text-ink-3">{{ t('settings.numbering.abbreviationHint') }}</p>
            </section>

            <section class="flex flex-col gap-2">
                <SectionTitle>{{ t('settings.numbering.format') }}</SectionTitle>
                <div role="radiogroup" :aria-label="t('settings.numbering.numberFormat')" class="flex flex-col gap-2">
                    <button
                        v-for="option in formatOptions"
                        :key="option.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.strategy === option.value ? 'true' : 'false'"
                        :disabled="!canConfigure"
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
            </section>

            <section v-if="form.strategy === 'date_sequence'" class="flex flex-col gap-2">
                <SectionTitle>{{ t('settings.numbering.dateInNumber') }}</SectionTitle>
                <SegmentedControl v-model="form.date_source" :label="t('settings.numbering.dateInNumber')" size="lg" :disabled="!canConfigure" :options="dateSourceOptions" />
            </section>

            <section class="flex flex-col gap-2">
                <SectionTitle>{{ t('settings.numbering.counter') }}</SectionTitle>
                <SegmentedControl v-model="form.scope" :label="t('settings.numbering.counter')" size="lg" :disabled="!canConfigure" :options="scopeOptions" />
                <p v-if="mayCollide" class="m-0 text-13 leading-normal text-warning-text" role="status">
                    {{ t('settings.numbering.noPrefixWarning') }}
                </p>
            </section>

            <section class="flex flex-col gap-2">
                <SectionTitle>{{ t('settings.numbering.counterReset') }}</SectionTitle>
                <SegmentedControl v-model="form.reset" :label="t('settings.numbering.counterReset')" size="lg" :disabled="!canConfigure" :options="resetOptions" />
            </section>

            <section class="flex flex-col gap-2">
                <SectionTitle>{{ t('settings.numbering.currentCounters') }}</SectionTitle>
                <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                    <button
                        v-for="counter in counters"
                        :key="counter.scope_key"
                        type="button"
                        class="flex min-h-13 items-center justify-between gap-3 text-left"
                        :disabled="!canConfigure"
                        @click="openCounter(counter)"
                    >
                        <span class="text-15">
                            {{ counterName(counter) }}
                            <span v-if="/:\d{4}$/.test(counter.scope_key)" class="num text-13 text-ink-3">{{ counter.scope_key.slice(-4) }}</span>
                        </span>
                        <span class="num text-16 font-semibold">{{ counter.last_number }}</span>
                    </button>
                    <p v-if="counters.length === 0" class="m-0 py-3.5 text-14 text-ink-3">{{ t('settings.numbering.noCountersYet') }}</p>
                </div>
                <p class="m-0 text-13 leading-normal text-ink-3">
                    {{ t('settings.numbering.explained') }}
                </p>
            </section>

            <section class="flex flex-col gap-2">
                <SectionTitle>{{ t('settings.numbering.invoiceNote') }}</SectionTitle>
                <TextAreaField v-model="form.footer_note" :label="t('core.common.note')" :placeholder="t('settings.numbering.notePlaceholder')" :rows="3" :disabled="!canConfigure" />
            </section>

            <p v-if="saveError" class="m-0 text-14 text-negative" role="alert">{{ errorMessage(saveError) }}</p>
        </LoadState>

        <template v-if="canConfigure" #footer>
            <PrimaryButton :busy="saving" :disabled="loading" @click="save">{{ t('core.common.save') }}</PrimaryButton>
        </template>

        <BottomSheet v-model:open="counterOpen" :title="t('settings.numbering.counter')">
            <p class="m-0 text-15 leading-normal text-ink-2">
                <Translated :text="t('settings.numbering.lastNumber')">
                    <template #name>{{ counterName(editing) }}</template>
                    <template #number><span class="num font-semibold text-ink">{{ editing?.last_number }}</span></template>
                </Translated>
                {{ t('settings.numbering.nextNumberHint') }}
            </p>
            <TextField v-model="nextNumber" :label="t('settings.numbering.nextNumber')" inputmode="numeric" class="num" :error="fieldError(counterError, 'next_number')" />
            <p v-if="counterError && !fieldError(counterError, 'next_number')" class="m-0 text-14 text-negative">{{ errorMessage(counterError) }}</p>
            <template #footer>
                <PrimaryButton :busy="movingCounter" :disabled="!/^\d+$/.test(nextNumber)" @click="moveCounter">{{ t('core.common.save') }}</PrimaryButton>
            </template>
        </BottomSheet>
    </SubScreen>
</template>
