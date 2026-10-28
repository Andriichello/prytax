<script setup lang="ts">
import { computed } from 'vue';
import Translated from '@/components/ui/Translated.vue';
import { t } from '@/i18n';
import { formatMoney } from '@/utils/money';

/**
 * The black band of Доходи (M-Books-Income, design.md §16.7): «Дохід з початку року» with the share of the limit, the
 * year's income, the bar with ticks at 80 and 90 %, and the group's limit with what is left of it.
 */
const { ytd, percent, cap, left, group } = defineProps<{
    ytd: string;
    percent: string;
    cap: string;
    left: string;
    group: string | number;
}>();

const share = computed(() => Number(percent));
</script>

<template>
    <section class="flex flex-col gap-2.5 rounded-card bg-ink p-4 text-white">
        <div class="flex items-baseline justify-between gap-3">
            <span class="text-12 font-semibold tracking-[0.04em] text-on-ink-3 uppercase">{{ t('accounting.books.incomeSinceStart') }}</span>
            <span class="text-15 font-semibold" :class="share >= 80 ? 'text-warning-on-ink' : ''">
                <Translated :text="t('accounting.books.ofLimit')"><template #percent><span class="num">{{ percent }}</span></template></Translated>
            </span>
        </div>
        <span class="num text-26 font-bold">{{ formatMoney(ytd, 'UAH') }}</span>
        <div class="relative h-2 rounded-[4px] bg-divider-ink" role="progressbar" :aria-valuenow="Math.round(share)" aria-valuemin="0" aria-valuemax="100">
            <div class="h-2 rounded-[4px]" :class="share >= 80 ? 'bg-warning-bar' : 'bg-white'" :style="{ width: `${Math.min(100, share)}%` }" />
            <span class="absolute -top-0.75 left-[80%] h-3.5 w-0.5 bg-tick-on-ink" aria-hidden="true" />
            <span class="absolute -top-0.75 left-[90%] h-3.5 w-0.5 bg-tick-on-ink" aria-hidden="true" />
        </div>
        <div class="flex justify-between gap-3 text-12 text-on-ink-3">
            <span>{{ t('accounting.books.groupLimit', { group }) }} <span class="num">{{ formatMoney(cap.replace(/\.00$/, ''), 'UAH') }}</span></span>
            <span>{{ t('accounting.books.left') }} <span class="num">{{ formatMoney(left, 'UAH') }}</span></span>
        </div>
    </section>
</template>
