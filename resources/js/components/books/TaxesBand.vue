<script setup lang="ts">
import type { TaxesBookYear } from '@/api/types';
import TaxBar from '@/components/taxes/TaxBar.vue';
import TaxFigures from '@/components/taxes/TaxFigures.vue';
import Translated from '@/components/ui/Translated.vue';
import { t } from '@/i18n';

/**
 * The black band of the books' Податки (M-Books-Taxes, design.md §16.7): «Податки з початку року» with the share
 * paid, the bar (paid in light green, sent in amber) and the three columns, as Податки's summary has them.
 */
const { totals } = defineProps<{ totals: TaxesBookYear['totals'] }>();
</script>

<template>
    <section class="flex flex-col gap-2.5 rounded-card bg-ink p-4 text-white">
        <div class="flex items-baseline justify-between gap-3">
            <span class="text-12 font-semibold tracking-[0.04em] text-on-ink-3 uppercase">{{ t('accounting.books.taxesSinceStart') }}</span>
            <span class="text-15 font-semibold">
                <Translated :text="t('accounting.taxes.paidShare')"><template #percent><span class="num">{{ totals.percent }}</span></template></Translated>
            </span>
        </div>
        <TaxBar :accrued="totals.accrued" :sent="totals.sent" :paid="totals.paid" :parts="1" dark />
        <TaxFigures :figures="totals" dark />
    </section>
</template>
