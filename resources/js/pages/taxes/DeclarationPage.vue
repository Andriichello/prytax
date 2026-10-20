<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, Declaration, DeclarationStatus } from '@/api/types';
import TextAreaField from '@/components/form/TextAreaField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import CopyRow from '@/components/ui/CopyRow.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import Stepper from '@/components/ui/Stepper.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { dateParts, daysBetween, formatDate, formatDayMonth, monthName, quarterLabel, today } from '@/utils/dates';
import { errorMessage } from '@/utils/errors';
import { compareAmounts, formatAmount, formatMoney, sumAmounts } from '@/utils/money';
import { countOf, DAYS } from '@/utils/plural';
import { DECLARATION_STATUSES } from '@/utils/statuses';

/**
 * Декларація (S-Declaration): the status of a quarter's declaration, its deadlines (filing, ЄП and ВЗ, ЄСВ) with what
 * is left to pay, the figures to copy into it, and the status changes. Submitting locks the quarter. A correction of a
 * declared quarter makes it `corrected`: the уточнююча декларація is due, and marking it submitted locks it again.
 */
const WEEKDAYS = ['неділя', 'понеділок', 'вівторок', 'середа', 'четвер', "п'ятниця", 'субота'];
const SHORT_MONTHS = ['січ', 'лют', 'бер', 'кві', 'тра', 'чер', 'лип', 'сер', 'вер', 'жов', 'лис', 'гру'];

const route = useRoute();
const { profileId, path } = useCurrentProfile();
const { can } = useSession();

const year = computed(() => Number(route.params.year));
const quarter = computed(() => Number(route.params.quarter));

const { data: declaration, error, loading, reload } = useLoader(async (signal) => {
    const { data } = await api.get<ApiResponse<Declaration>>(path(`declarations/${year.value}/${quarter.value}`), undefined, signal);

    return data;
}, [profileId, year, quarter]);

const notes = ref('');

watch(declaration, (loaded) => {
    notes.value = loaded?.period.notes ?? '';
});

const canUpdate = computed(() => can('update', 'tax-periods') && Boolean(declaration.value?.period.id));

const status = computed<DeclarationStatus>(() => declaration.value?.period.declaration_status ?? 'none');

/** The steps of the status: a declaration's own, or those of its уточнююча after a correction. */
const steps = computed(() => (status.value === 'corrected'
    ? [
        { key: 'submitted', label: 'Подано' },
        { key: 'corrected', label: 'Потрібна уточнююча' },
        { key: 'resubmitted', label: 'Уточнюючу подано' },
        { key: 'accepted', label: 'Прийнято' },
    ]
    : [
        { key: 'none', label: 'Не подано' },
        { key: 'filled', label: 'Заповнено' },
        { key: 'submitted', label: status.value === 'rejected' ? 'Відхилено' : 'Подано' },
        { key: 'accepted', label: 'Прийнято' },
    ]));
const stepKey = computed(() => (status.value === 'rejected' ? 'submitted' : status.value));
const previous = computed(() => (quarter.value > 1 ? `за ${quarterLabel(quarter.value - 1)}` : 'раніше'));
const ended = computed(() => {
    const lastMonth = quarter.value * 3;
    const end = new Date(Date.UTC(year.value, lastMonth, 0)).toISOString().slice(0, 10);

    return end < today();
});

const taxesLeft = computed(() => (declaration.value
    ? sumAmounts(declaration.value.remaining.single_tax.to_pay, declaration.value.remaining.military_levy.to_pay)
    : '0.00'));

function tile(date: string): { day: string; month: string } {
    const { month, day } = dateParts(date);

    return { day: String(day).padStart(2, '0'), month: SHORT_MONTHS[month - 1] ?? '' };
}

function countdown(date: string): string {
    const days = daysBetween(today(), date);
    const weekday = WEEKDAYS[new Date(`${date}T12:00:00Z`).getUTCDay()];

    return days > 0 ? `${weekday} · через ${countOf(days, DAYS)}` : days === 0 ? `${weekday} · сьогодні` : `строк минув ${formatDate(date)}`;
}

/* The notes, saved when the field is left; they also go with every status change */
const actionError = ref<string | null>(null);
let savingNotes: Promise<void> | null = null;

function saveNotes(): void {
    const period = declaration.value?.period;

    if (!period?.id || !canUpdate.value || notes.value.trim() === (period.notes ?? '').trim()) {
        return;
    }

    actionError.value = null;
    savingNotes = api.patch(path(`tax-periods/${period.id}`), { notes: notes.value.trim() || null })
        .then(() => {
            period.notes = notes.value.trim() || null;
        })
        .catch((caught: unknown) => {
            actionError.value = errorMessage(caught);
        })
        .finally(() => {
            savingNotes = null;
        });
}

/* Status changes */
const saving = ref(false);

async function setStatus(next: DeclarationStatus): Promise<void> {
    const period = declaration.value?.period;

    if (!period?.id) {
        return;
    }

    saving.value = true;
    actionError.value = null;

    try {
        // A tap on the button leaves the notes first, so their save is awaited; the status change carries them too
        await savingNotes;
        await api.patch(path(`tax-periods/${period.id}`), {
            declaration_status: next,
            ...(next === 'submitted' ? { declaration_submitted_at: today() } : {}),
            notes: notes.value.trim() || null,
        });
        await reload();
    } catch (caught) {
        actionError.value = errorMessage(caught);
    } finally {
        saving.value = false;
    }
}

const actions = computed<{ primary?: { label: string; status: DeclarationStatus }; secondary?: { label: string; status: DeclarationStatus } }>(() => {
    switch (status.value) {
        case 'none':
            return { secondary: { label: 'Заповнено', status: 'filled' }, primary: { label: 'Позначити поданою', status: 'submitted' } };
        case 'filled':
            return { primary: { label: 'Позначити поданою', status: 'submitted' } };
        case 'submitted':
            return { secondary: { label: 'Відхилено', status: 'rejected' }, primary: { label: 'Прийнято', status: 'accepted' } };
        case 'rejected':
            return { secondary: { label: 'Заповнено', status: 'filled' }, primary: { label: 'Подано знову', status: 'submitted' } };
        case 'corrected':
            return { primary: { label: 'Уточнюючу подано', status: 'submitted' } };
        default:
            return {};
    }
});
</script>

<template>
    <SubScreen title="Податковий період" muted :back="{ name: 'taxes', query: { year } }">
        <LoadState :loading="loading && !declaration" :error="error" @retry="reload">
            <template v-if="declaration">
                <div class="-mt-1 flex flex-col gap-2">
                    <h1 class="m-0 text-28 font-semibold tracking-[-0.02em]">
                        {{ declaration.is_annual ? 'Річна декларація' : 'Декларація' }} за {{ quarterLabel(quarter) }} {{ year }}
                    </h1>
                    <div class="flex flex-wrap items-center gap-2">
                        <StatusBadge v-bind="DECLARATION_STATUSES[status]" />
                        <span v-if="declaration.period.declaration_submitted_at" class="text-13 text-ink-3">
                            подано {{ formatDate(declaration.period.declaration_submitted_at) }}
                        </span>
                    </div>
                </div>

                <Stepper :steps="steps" :current="stepKey" label="Статус декларації" />

                <p v-if="status === 'corrected'" class="m-0 rounded-card bg-warning-soft p-4 text-14 leading-normal text-warning">
                    Після подання декларації доходи або податки цього року змінено. Подайте уточнюючу декларацію і
                    позначте її поданою.
                </p>

                <section class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4" aria-label="Строки">
                    <div class="flex items-center gap-3 py-3">
                        <span class="flex w-12 shrink-0 flex-col items-center rounded-control bg-accent-soft py-1.5 text-accent-hover">
                            <span class="num text-20 font-bold leading-tight">{{ tile(declaration.period.declaration_due_at).day }}</span>
                            <span class="text-11">{{ tile(declaration.period.declaration_due_at).month }}</span>
                        </span>
                        <span class="flex flex-col gap-0.5">
                            <span class="text-16 font-medium">Подати декларацію</span>
                            <span v-if="status === 'corrected'" class="text-13 font-semibold text-warning-text">потрібна уточнююча декларація</span>
                            <span v-else class="text-13 text-ink-3">{{ ['submitted', 'accepted'].includes(status) ? 'подано' : countdown(declaration.period.declaration_due_at) }}</span>
                        </span>
                    </div>
                    <div class="flex items-center gap-3 py-3">
                        <span class="flex w-12 shrink-0 flex-col items-center rounded-control py-1.5" :class="compareAmounts(taxesLeft, '0') > 0 ? 'bg-accent-soft text-accent-hover' : 'bg-positive-soft text-positive'">
                            <span class="num text-20 font-bold leading-tight">{{ tile(declaration.period.tax_due_at).day }}</span>
                            <span class="text-11">{{ tile(declaration.period.tax_due_at).month }}</span>
                        </span>
                        <span class="flex flex-col gap-0.5">
                            <span class="text-16 font-medium">Сплатити ЄП і ВЗ</span>
                            <span v-if="compareAmounts(taxesLeft, '0') > 0" class="text-13 text-negative">залишок <span class="num">{{ formatMoney(taxesLeft, 'UAH') }}</span></span>
                            <span v-else class="text-13 text-positive">сплачено</span>
                        </span>
                    </div>
                    <div v-if="declaration.esv_quarter" class="flex items-center gap-3 py-3">
                        <span class="flex w-12 shrink-0 flex-col items-center rounded-control py-1.5" :class="declaration.esv_quarter.is_paid ? 'bg-positive-soft text-positive' : 'bg-accent-soft text-accent-hover'">
                            <span class="num text-20 font-bold leading-tight">{{ tile(declaration.esv_quarter.due_at).day }}</span>
                            <span class="text-11">{{ tile(declaration.esv_quarter.due_at).month }}</span>
                        </span>
                        <span class="flex flex-col gap-0.5">
                            <span class="text-16 font-medium">Сплатити ЄСВ</span>
                            <span v-if="declaration.esv_quarter.is_paid" class="text-13 text-positive">
                                {{ ['сплачено', declaration.esv_quarter.paid_at ? formatDayMonth(declaration.esv_quarter.paid_at) : null].filter(Boolean).join(' ') }} ·
                                <span class="num">{{ formatAmount(declaration.esv_quarter.paid) }}</span>
                            </span>
                            <span v-else class="text-13 text-negative">
                                залишок <span class="num">{{ formatMoney(declaration.esv_quarter.balance?.replace('-', '') ?? declaration.esv_quarter.owed, 'UAH') }}</span>
                            </span>
                        </span>
                    </div>
                </section>

                <section class="flex flex-col gap-2">
                    <SectionTitle>Показники для декларації</SectionTitle>
                    <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface pr-1 pl-4">
                        <CopyRow :label="`Дохід наростаючим з 01.01`" :value="declaration.income_ytd" :shown="formatAmount(declaration.income_ytd)" />
                        <div v-for="tax in [
                            { key: 'single_tax', name: 'Єдиний податок' },
                            { key: 'military_levy', name: 'Військовий збір' },
                        ] as const" :key="tax.key" class="flex flex-col gap-1.5 py-3 pr-3">
                            <span class="text-15 font-semibold">{{ tax.name }}, {{ Number((Number(declaration[tax.key].rate) * 100).toFixed(2)) }} %</span>
                            <span class="flex justify-between gap-3 text-14 text-ink-2">
                                <span>Нараховано з початку року</span>
                                <span class="num">{{ formatAmount(declaration[tax.key].ytd) }}</span>
                            </span>
                            <span class="flex justify-between gap-3 text-14 text-ink-2">
                                <span>Задекларовано {{ previous }}</span>
                                <span class="num">{{ formatAmount(declaration[tax.key].previously_declared) }}</span>
                            </span>
                            <div class="-mr-3">
                                <CopyRow :label="`До сплати за ${quarterLabel(quarter)}`" :value="declaration[tax.key].to_pay" :shown="formatAmount(declaration[tax.key].to_pay)" />
                            </div>
                        </div>
                        <div v-if="declaration.esv_months?.length" class="flex flex-col gap-1.5 py-3 pr-3">
                            <span class="text-15 font-semibold">ЄСВ, додаток 1</span>
                            <span v-for="month in declaration.esv_months" :key="month.month" class="flex justify-between gap-3 text-14 text-ink-2">
                                <span>{{ monthName(month.month) }}</span>
                                <span class="num">{{ month.status === 'exempt' ? 'звільнено' : formatAmount(month.amount_due) }}</span>
                            </span>
                        </div>
                    </div>
                </section>

                <p class="m-0 flex gap-3 rounded-card bg-accent-soft p-4 text-14 leading-normal text-accent-hover">
                    <Icon name="lock" :size="20" class="shrink-0" />
                    Після позначки «Подано» квартал блокується. Зміни в ньому потребуватимуть примітки й позначать цей і
                    наступні квартали року як «потрібна уточнююча».
                </p>

                <TextAreaField
                    v-model="notes"
                    label="Примітки"
                    :rows="2"
                    :placeholder="canUpdate ? 'Номер квитанції, коментар' : ''"
                    :disabled="!canUpdate"
                    @focusout="saveNotes"
                />

                <p v-if="!declaration.period.id" class="m-0 text-14 text-ink-3">Цей квартал не відстежується, тож статус декларації не змінюється.</p>
                <p v-else-if="actions.primary?.status === 'submitted' && !ended" class="m-0 text-14 text-ink-3">Подати можна після закінчення кварталу.</p>
                <p v-if="actionError" class="m-0 text-14 text-negative" role="alert">{{ actionError }}</p>
            </template>
        </LoadState>

        <template v-if="canUpdate && (actions.primary || actions.secondary)" #footer>
            <div class="grid gap-2.5" :class="actions.secondary ? 'grid-cols-[1fr_1.4fr]' : 'grid-cols-1'">
                <SecondaryButton v-if="actions.secondary" :busy="saving" @click="setStatus(actions.secondary.status)">{{ actions.secondary.label }}</SecondaryButton>
                <PrimaryButton
                    v-if="actions.primary"
                    :busy="saving"
                    :disabled="actions.primary.status === 'submitted' && !ended"
                    @click="setStatus(actions.primary.status)"
                >
                    {{ actions.primary.label }}
                </PrimaryButton>
            </div>
        </template>
    </SubScreen>
</template>
