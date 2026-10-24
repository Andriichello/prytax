<script setup lang="ts">
import { computed } from 'vue';
import { api } from '@/api/client';
import type { ApiResponse, TaxMonths, TaxType } from '@/api/types';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import Translated from '@/components/ui/Translated.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { t } from '@/i18n';
import { dateParts, formatDayMonth, monthName, quarterLabel, today } from '@/utils/dates';
import { compareAmounts, formatAmount, sumAmounts } from '@/utils/money';

/**
 * Податки by month: a tax's months grouped by quarter — the month's income, what it accrued (the sum of its receipts'
 * hints; for ЄСВ the month's contribution) and what was paid for it, with its share of the quarter's payments.
 */
const { year, taxType } = defineProps<{ year: number; taxType: TaxType }>();

const { profileId, path } = useCurrentProfile();

const { data, error, loading, reload } = useLoader(async (signal) => {
    const { data: months } = await api.get<ApiResponse<TaxMonths>>(path(`taxes/${year}/months`), undefined, signal);

    return months;
}, [profileId, () => year]);

const now = dateParts(today());

const quarters = computed(() => [1, 2, 3, 4]
    .map((quarter) => ({
        quarter,
        months: (data.value?.months ?? []).filter((month) => month.quarter === quarter && (year < now.year || month.month <= now.month)),
    }))
    .filter((quarter) => quarter.months.length > 0));

const grid = 'grid grid-cols-[1.1fr_1fr_1fr_24px] items-center gap-2 px-3.5';
</script>

<template>
    <LoadState :loading="loading && !data" :error="error" @retry="reload">
        <section v-if="data" class="flex flex-col overflow-hidden rounded-card border border-line bg-surface" :aria-label="t('accounting.taxMonths.title')">
            <div :class="grid" class="border-b border-divider bg-paper-light py-2.5 text-12 font-semibold text-ink-3">
                <span>{{ t('core.common.month') }}</span>
                <span class="text-right">{{ t('accounting.esv.owed') }}</span>
                <span class="text-right">{{ t('core.common.paid') }}</span>
                <span />
            </div>
            <template v-for="group in quarters" :key="group.quarter">
                <div class="flex justify-between gap-2 bg-paper px-3.5 py-2 text-12 text-ink-2">
                    <span class="font-semibold">{{ quarterLabel(group.quarter) }}</span>
                    <span v-if="group.months[0]?.[taxType].due_at"><Translated :text="t('core.common.untilDate')"><template #date><span class="num">{{ formatDayMonth(group.months[0][taxType].due_at) }}</span></template></Translated></span>
                </div>
                <div
                    v-for="(month, index) in group.months"
                    :key="month.month"
                    :class="[grid, index ? 'border-t border-divider' : '']"
                    class="py-2.5 text-14"
                >
                    <span class="flex flex-col">
                        <span>{{ monthName(month.month) }}</span>
                        <span v-if="taxType !== 'social_contribution'" class="num text-12 text-ink-3">{{ formatAmount(month.income) }}</span>
                    </span>
                    <span class="num text-right">{{ formatAmount(month[taxType].owed) }}</span>
                    <span class="num text-right">
                        {{ compareAmounts(month[taxType].paid, '0') > 0 ? formatAmount(month[taxType].paid) : '—' }}
                        <span v-if="compareAmounts(month[taxType].pending, '0') > 0" class="block text-12 text-warning-text">{{ t('accounting.taxMonths.sentAmount', { amount: formatAmount(month[taxType].pending) }) }}</span>
                        <span v-if="compareAmounts(month[taxType].from_quarter, '0') > 0" class="block text-12 text-ink-3">
                            <template v-if="compareAmounts(month[taxType].from_quarter, sumAmounts(month[taxType].paid, month[taxType].pending)) === 0">{{ t('accounting.taxMonths.forQuarter') }}</template>
                            <template v-else>{{ t('accounting.taxMonths.ofItForQuarter', { amount: formatAmount(month[taxType].from_quarter) }) }}</template>
                        </span>
                    </span>
                    <Icon v-if="month[taxType].quarter_state === 'closed'" name="check" :size="18" class="text-positive" />
                    <span v-else />
                </div>
            </template>
        </section>
        <p class="m-0 text-13 leading-normal text-ink-3">
            <template v-if="taxType === 'social_contribution'">{{ t('accounting.taxMonths.sscEveryMonth') }}</template>
            <template v-else>{{ t('accounting.taxMonths.owedExplained') }}</template>
            {{ t('accounting.taxMonths.spreadExplained') }}
            {{ t('accounting.taxMonths.tickMeansClosed') }}
        </p>
    </LoadState>
</template>
