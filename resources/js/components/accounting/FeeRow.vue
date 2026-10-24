<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from 'vue-router';
import Icon from '@/components/ui/Icon.vue';
import Translated from '@/components/ui/Translated.vue';
import { t } from '@/i18n';
import { compareAmounts, formatAmount } from '@/utils/money';

/**
 * A row of Комісії: an account, a client or a transfer route, with what it lost (fees and spread in its income or in
 * what it sent) on the right, what it brought or moved below (the default slot), and its fees and spread in
 * hryvnias. With `to`, the row links to the record.
 */
const { title, percent, fees, spread, to = undefined } = defineProps<{
    title: string;
    /** Two decimals; null without income. */
    percent: string | null;
    fees: string;
    spread: string;
    to?: RouteLocationRaw;
}>();

/** Cheap paths are green, costly ones (1 % and more) red, as on Перекази. */
const tone = (value: string | null): string => (value === null ? 'text-ink-3' : Number(value) >= 1 ? 'text-negative' : Number(value) < 0.5 ? 'text-positive' : '');
</script>

<template>
    <component
        :is="to ? RouterLink : 'div'"
        :to="to"
        class="flex min-h-14 items-center gap-3 py-3 text-ink no-underline hover:text-ink"
    >
        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span class="flex items-baseline justify-between gap-3">
                <span class="text-15 font-medium">{{ title }}</span>
                <span class="num shrink-0 text-15 font-semibold" :class="tone(percent)">{{ percent !== null ? `${percent} %` : '—' }}</span>
            </span>
            <span class="text-12 text-ink-3"><slot /></span>
            <span class="text-12 text-ink-3">
                <Translated v-if="compareAmounts(fees, '0') !== 0 || compareAmounts(spread, '0') === 0" :text="t('accounting.fees.feesAmount')"><template #amount><span class="num">{{ formatAmount(fees) }}</span></template></Translated>
                <template v-if="compareAmounts(fees, '0') !== 0 && compareAmounts(spread, '0') !== 0"> · </template>
                <Translated v-if="compareAmounts(spread, '0') !== 0" :text="t('money.transfers.spreadAmount')"><template #amount><span class="num">{{ formatAmount(spread) }}</span></template></Translated>
                ₴
            </span>
        </span>
        <Icon v-if="to" name="chevronRight" :size="18" class="shrink-0 text-ink-2" />
    </component>
</template>
