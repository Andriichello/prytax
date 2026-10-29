<script setup lang="ts">
import { computed } from 'vue';
import type { TaxLedger, TaxType } from '@/api/types';
import TaxBar from '@/components/taxes/TaxBar.vue';
import TaxFigures from '@/components/taxes/TaxFigures.vue';
import Icon from '@/components/ui/Icon.vue';
import Translated from '@/components/ui/Translated.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { t } from '@/i18n';
import { formatDate } from '@/utils/dates';
import { compareAmounts, formatAmount } from '@/utils/money';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * «Підсумок за період» of Податки (design.md §16.5): with two taxes or more, the black «Усього податків» band (the
 * share paid, a bar split by quarter, the three columns); then ЄСВ, ЄП and ВЗ, each with its deadline, or «✓ закрито»
 * once all of it is paid, a bar split by month (ЄСВ) or by quarter, the three columns, and the failed payments apart.
 * On desktop (D-Taxes, §17.4) it is a table: tax · bar · Нараховано · Надіслано · Сплачено, whose columns narrow under
 * 760 px of its width, and which drops the bar under 600 px (container queries).
 */
const { summary, quarters } = defineProps<{
    summary: TaxLedger['summary'];
    /** The period's quarters: 4 for the whole year. */
    quarters: number;
}>();

const ORDER: TaxType[] = ['social_contribution', 'single_tax', 'military_levy'];

const rows = computed(() => ORDER.flatMap((type) => summary.rows.filter((row) => row.tax_type === type)));

/** The share paid of what is accrued, to a tenth of a percent: «57.3 %». */
const paidShare = computed(() => {
    const accrued = Number(summary.total.accrued);

    return (accrued > 0 ? (Number(summary.total.paid) / accrued) * 100 : 0).toFixed(1);
});

const { isDesktop } = useBreakpoint();

const columns = 'grid-cols-[minmax(0,1fr)_96px_96px_96px] gap-3 @min-[600px]:grid-cols-[150px_minmax(0,1fr)_100px_100px_100px] @min-[600px]:gap-4 @min-[760px]:grid-cols-[180px_minmax(0,1fr)_140px_140px_140px] @min-[760px]:gap-5';

const isClosed = (row: TaxLedger['summary']['rows'][number]): boolean => compareAmounts(row.accrued, '0') > 0 && compareAmounts(row.paid, row.accrued) >= 0;
</script>

<template>
    <article v-if="isDesktop" class="@container overflow-hidden rounded-card border border-line bg-surface">
        <div class="grid border-b border-line px-5 py-2.5 text-12 text-ink-3" :class="columns">
            <span /><span class="@max-[600px]:hidden" />
            <span class="text-right">{{ t('accounting.taxes.accrued') }}</span>
            <span class="text-right">{{ t('accounting.taxes.sent') }}</span>
            <span class="text-right">{{ t('accounting.taxes.paid') }}</span>
        </div>
        <div v-if="rows.length > 1" class="grid items-center bg-ink px-5 py-3.5 text-white" :class="columns">
            <span class="flex flex-col">
                <span class="text-12 font-semibold tracking-[0.04em] text-on-ink-2 uppercase">{{ t('accounting.taxes.total') }}</span>
                <span class="text-12">
                    <Translated :text="t('accounting.taxes.paidShare')"><template #percent><span class="num">{{ paidShare }}</span></template></Translated>
                </span>
            </span>
            <TaxBar :accrued="summary.total.accrued" :sent="summary.total.sent" :paid="summary.total.paid" :parts="quarters" dark class="@max-[600px]:hidden" />
            <span class="num text-right text-15 font-semibold">{{ formatAmount(summary.total.accrued) }}</span>
            <span class="num text-right text-15 font-semibold" :class="compareAmounts(summary.total.sent, '0') > 0 ? 'text-warning-on-ink' : ''">{{ formatAmount(summary.total.sent) }}</span>
            <span class="num text-right text-15 font-semibold" :class="compareAmounts(summary.total.paid, '0') > 0 ? 'text-positive-on-ink' : ''">{{ formatAmount(summary.total.paid) }}</span>
        </div>
        <div v-for="row in rows" :key="row.tax_type" class="grid items-center border-t border-divider px-5 py-3" :class="columns">
            <span class="flex flex-col">
                <span class="text-15 font-semibold">{{ TAX_TYPES[row.tax_type] }}</span>
                <span v-if="isClosed(row)" class="flex items-center gap-1 text-12 text-positive"><Icon name="check" :size="14" /> {{ t('accounting.taxes.closed') }}</span>
                <span v-else-if="row.due_at" class="text-12 text-ink-3">{{ t('accounting.taxes.dueBy', { date: formatDate(row.due_at) }) }}</span>
                <span v-if="compareAmounts(row.failed, '0') > 0" class="text-12 text-negative">{{ t('accounting.taxes.failedNotCounted', { amount: formatAmount(row.failed) }) }}</span>
            </span>
            <TaxBar :accrued="row.accrued" :sent="row.sent" :paid="row.paid" :parts="row.tax_type === 'social_contribution' ? quarters * 3 : quarters" class="@max-[600px]:hidden" />
            <span class="num text-right text-15 font-semibold">{{ formatAmount(row.accrued) }}</span>
            <span class="num text-right text-15 font-semibold" :class="compareAmounts(row.sent, '0') > 0 ? 'text-warning' : ''">{{ formatAmount(row.sent) }}</span>
            <span class="num text-right text-15 font-semibold" :class="compareAmounts(row.paid, '0') > 0 ? 'text-positive' : ''">{{ formatAmount(row.paid) }}</span>
        </div>
    </article>
    <article v-else class="overflow-hidden rounded-card border border-line bg-surface px-4">
        <div v-if="rows.length > 1" class="relative z-[1] -mx-4 flex flex-col gap-2 bg-ink px-4 pt-4 pb-3.5 text-white">
            <div class="flex items-baseline justify-between">
                <span class="text-13 font-semibold tracking-[0.04em] text-on-ink-2 uppercase">{{ t('accounting.taxes.total') }}</span>
                <span class="text-15 font-semibold">
                    <Translated :text="t('accounting.taxes.paidShare')"><template #percent><span class="num">{{ paidShare }}</span></template></Translated>
                </span>
            </div>
            <TaxBar :accrued="summary.total.accrued" :sent="summary.total.sent" :paid="summary.total.paid" :parts="quarters" dark />
            <TaxFigures :figures="summary.total" dark class="pt-0.5" />
        </div>
        <div class="-mt-px flex flex-col">
            <div v-for="row in rows" :key="row.tax_type" class="flex flex-col gap-2 border-t border-divider py-3">
                <div class="flex items-baseline justify-between">
                    <span class="text-15 font-semibold">{{ TAX_TYPES[row.tax_type] }}</span>
                    <span v-if="isClosed(row)" class="flex items-center gap-1 text-12 text-positive">
                        <Icon name="check" :size="14" /> {{ t('accounting.taxes.closed') }}
                    </span>
                    <span v-else-if="row.due_at" class="text-12 text-ink-3">{{ t('accounting.taxes.dueBy', { date: formatDate(row.due_at) }) }}</span>
                </div>
                <TaxBar
                    :accrued="row.accrued"
                    :sent="row.sent"
                    :paid="row.paid"
                    :parts="row.tax_type === 'social_contribution' ? quarters * 3 : quarters"
                />
                <TaxFigures :figures="row" />
                <span v-if="compareAmounts(row.failed, '0') > 0" class="text-12 text-negative">
                    {{ t('accounting.taxes.failedNotCounted', { amount: formatAmount(row.failed) }) }}
                </span>
            </div>
        </div>
    </article>
</template>
