<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api } from '@/api/client';
import DateRangeField from '@/components/form/DateRangeField.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { t } from '@/i18n';
import { currentQuarter, dateOf, daysInMonth, formatPeriod, quarterLabel, quarterName, today } from '@/utils/dates';
import { errorMessage } from '@/utils/errors';

/**
 * «Архів за період» (M-BK-PeriodExport, D-BK-PeriodExport; backups.md §2.7, §4.8): the ФОП's archive of a year, a
 * quarter (by default Облік's, with a note while it still runs) or a period of the range picker, for an accountant
 * or a tax audit; what it holds, and that it isn't a backup. «Підготувати ZIP» starts the export, which a job builds;
 * the sheet waits for it, then downloads it.
 */
const { year: initialYear, quarter: initialQuarter } = defineProps<{ year: number; quarter: number }>();
const open = defineModel<boolean>('open', { required: true });

const { path, profile } = useCurrentProfile();
const { isDesktop } = useBreakpoint();

type Mode = 'year' | 'quarter' | 'range';

const mode = ref<Mode>('quarter');
const year = ref(initialYear);
const quarter = ref(initialQuarter);
const from = ref('');
const to = ref('');
const busy = ref(false);
const failure = ref<unknown>(null);

const now = currentQuarter();

watch(open, (isOpen) => {
    if (isOpen) {
        mode.value = 'quarter';
        year.value = initialYear;
        quarter.value = initialQuarter;
        failure.value = null;
    }
});

const MODES = computed(() => [
    { value: 'year' as Mode, label: t('accounting.export.year') },
    { value: 'quarter' as Mode, label: t('accounting.export.quarter') },
    { value: 'range' as Mode, label: t('accounting.export.range') },
]);

const firstYear = computed(() => Number((profile.value?.registered_at ?? `${now.year}`).slice(0, 4)));
const years = computed(() => Array.from({ length: now.year - firstYear.value + 1 }, (_, index) => now.year - index));

/** The period exported, cut at today while it runs. */
const period = computed<{ from: string; to: string } | null>(() => {
    const end = (date: string): string => (date > today() ? today() : date);

    if (mode.value === 'range') {
        return from.value && to.value ? { from: from.value, to: to.value } : null;
    }

    if (mode.value === 'year') {
        return { from: `${year.value}-01-01`, to: end(`${year.value}-12-31`) };
    }

    const first = (quarter.value - 1) * 3 + 1;

    return { from: dateOf(year.value, first, 1), to: end(dateOf(year.value, first + 2, daysInMonth(year.value, first + 2))) };
});

const running = computed(() => mode.value === 'quarter' && year.value === now.year && quarter.value === now.quarter);
const isFuture = (q: number): boolean => year.value === now.year && q > now.quarter;

async function exportPeriod(): Promise<void> {
    if (!period.value) {
        return;
    }

    busy.value = true;
    failure.value = null;

    try {
        const { data: run } = await api.post<{ data: { id: string } }>(path('exports'), period.value);

        for (let attempt = 0; attempt < 90; attempt++) {
            const { data: status } = await api.get<{ data: { status: string; url?: string } }>(path(`exports/${run.id}`));

            if (status.url) {
                open.value = false;
                window.location.href = status.url;

                return;
            }

            if (status.status === 'failed') {
                throw new Error(t('accounting.export.failed'));
            }

            await new Promise((resolve) => setTimeout(resolve, 2000));
        }
    } catch (caught) {
        failure.value = caught;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <BottomSheet v-model:open="open" :title="t('accounting.export.title')">
        <span class="-mt-2 text-14 text-ink-3">{{ profile?.full_name }}</span>
        <SegmentedControl v-model="mode" :label="t('accounting.export.title')" :options="MODES" />

        <template v-if="mode !== 'range'">
            <div class="flex flex-wrap items-center gap-2">
                <label class="relative flex h-10 items-center gap-1 rounded-full border border-line-input bg-surface pr-3 pl-3.5 text-14 font-medium">
                    <span class="num">{{ year }}</span>
                    <Icon name="chevronDown" :size="16" class="text-ink-3" />
                    <select v-model.number="year" :aria-label="t('accounting.export.year')" class="absolute inset-0 cursor-pointer appearance-none opacity-0">
                        <option v-for="option in years" :key="option" :value="option">{{ option }}</option>
                    </select>
                </label>
                <template v-if="mode === 'quarter'">
                    <button
                        v-for="q in 4"
                        :key="q"
                        type="button"
                        :disabled="isFuture(q)"
                        class="h-10 rounded-full border px-3.5 text-14 disabled:text-switch-off"
                        :class="quarter === q ? 'border-ink bg-ink font-medium text-white' : 'border-line-input bg-surface'"
                        @click="quarter = q"
                    >
                        {{ quarterLabel(q) }}
                    </button>
                </template>
            </div>
            <p v-if="mode === 'quarter' && year === initialYear && quarter === initialQuarter" class="m-0 text-13 leading-[1.45] text-ink-3">
                {{ t('accounting.export.fromOblik') }}<template v-if="running"> {{ t('accounting.export.running', { quarter: quarterName(quarter) }) }}</template>
            </p>
        </template>
        <DateRangeField
            v-else
            v-model:from="from"
            v-model:to="to"
            :title="t('accounting.export.range')"
            :from-label="t('accounting.export.from')"
            :to-label="t('accounting.export.to')"
            :max="today()"
        />

        <section v-if="period" class="flex flex-col gap-1.5 rounded-card border border-line bg-surface p-4">
            <h3 class="m-0 text-14 font-semibold">{{ t('accounting.export.contents', { period: formatPeriod(period.from, period.to) }) }}</h3>
            <p class="m-0 text-13 leading-[1.45] text-ink-2">{{ t('accounting.export.contentsText') }}</p>
        </section>
        <p class="m-0 flex gap-2 rounded-control bg-muted px-3.5 py-3 text-13 leading-[1.45] text-ink-2">
            <Icon name="info" :size="18" class="shrink-0" />
            <span>{{ isDesktop ? t('accounting.export.notBackupWide') : t('accounting.export.notBackup') }}</span>
        </p>
        <p v-if="failure" class="m-0 text-14 text-negative" role="alert">{{ errorMessage(failure) }}</p>

        <template #footer>
            <div class="flex flex-col gap-2.5">
                <div class="grid grid-cols-[1fr_1.4fr] gap-2">
                    <SecondaryButton @click="open = false">{{ t('core.common.cancel') }}</SecondaryButton>
                    <PrimaryButton :busy="busy" :disabled="!period" @click="exportPeriod">
                        {{ busy ? t('accounting.export.preparing') : t('accounting.export.prepare') }}
                    </PrimaryButton>
                </div>
                <p class="m-0 text-center text-12 text-ink-3">{{ t('accounting.export.background') }}</p>
            </div>
        </template>
    </BottomSheet>
</template>
