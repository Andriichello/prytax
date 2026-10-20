<script setup lang="ts">
import { computed } from 'vue';
import type { PracticeOverview } from '@/api/types';
import Avatar from '@/components/ui/Avatar.vue';
import Icon from '@/components/ui/Icon.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import { quarterLabel } from '@/utils/dates';
import { formatAmount, formatMoney } from '@/utils/money';
import { countOf } from '@/utils/plural';

/**
 * One ФОП on Огляд практики (design §16): the yearly income cap, the quarter's income per currency, its taxes owed
 * and paid, and the unread notifications. The name and the notifications switch the app to this ФОП.
 */
const { entry, year, quarter, current = false } = defineProps<{
    entry: PracticeOverview['profiles'][number];
    year: number;
    quarter: number;
    current?: boolean;
}>();

const emit = defineEmits<{ open: [target: 'home' | 'alerts'] }>();

const capPercent = computed(() => Number(entry.cap.percent));
const capWarns = computed(() => capPercent.value >= 80);

/** The cap is a whole number of hryvnias: "10 091 049 ₴". */
const cap = computed(() => formatMoney(entry.cap.income_cap.replace(/\.00$/, ''), 'UAH'));

const TAXES = [
    { key: 'social_contribution', label: 'ЄСВ' },
    { key: 'single_tax', label: 'ЄП' },
    { key: 'military_levy', label: 'ВЗ' },
] as const;
</script>

<template>
    <article class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
        <button type="button" class="flex min-h-15 items-center gap-3 text-left text-ink" @click="emit('open', 'home')">
            <Avatar :initials="entry.profile.initials" :variant="current ? 'dark' : entry.profile.is_active ? 'light' : 'dashed'" :size="36" />
            <span class="flex flex-1 flex-col">
                <span class="text-16 font-semibold">{{ entry.profile.short_name }}</span>
                <span v-if="!entry.profile.is_active" class="text-12 text-ink-3">Неактивний</span>
            </span>
            <Icon name="chevronRight" :size="18" class="text-ink-3" />
        </button>

        <div class="flex flex-col gap-2 py-3.5">
            <div class="flex items-baseline justify-between">
                <span class="text-13 font-semibold tracking-[0.04em] text-ink-3 uppercase">Ліміт доходу {{ year }}</span>
                <span class="num text-14 font-semibold" :class="capWarns ? 'text-warning-text' : ''">{{ entry.cap.percent }} %</span>
            </div>
            <div class="relative flex">
                <ProgressBar :percent="capPercent" :warn-from="80" :height="10" />
                <span class="absolute -top-[3px] left-[80%] h-4 w-0.5 bg-handle" aria-hidden="true" />
                <span class="absolute -top-[3px] left-[90%] h-4 w-0.5 bg-handle" aria-hidden="true" />
            </div>
            <div class="flex justify-between text-13 text-ink-3">
                <span class="num">{{ formatMoney(entry.cap.ytd_income, 'UAH') }}</span>
                <span class="num">з {{ cap }}</span>
            </div>
        </div>

        <div class="flex flex-col gap-2.5 py-3.5">
            <span class="text-13 font-semibold tracking-[0.04em] text-ink-3 uppercase">Дохід {{ quarterLabel(quarter) }}</span>
            <div v-for="income in entry.income" :key="income.currency" class="flex flex-col gap-1.5">
                <div class="flex items-baseline justify-between gap-2 text-14">
                    <span class="font-semibold">{{ income.currency }}</span>
                    <span class="text-ink-2">
                        отримано <span class="num font-semibold text-ink">{{ formatAmount(income.received) }}</span>
                        з <span class="num">{{ formatAmount(income.invoiced) }}</span> · <span class="num">{{ income.percent }} %</span>
                    </span>
                </div>
                <ProgressBar :percent="Number(income.percent)" />
            </div>
            <span v-if="entry.income.length === 0" class="text-14 text-ink-3">Інвойсів за квартал немає.</span>
            <span class="text-13 text-ink-3">Дохід для податків: <span class="num">{{ formatMoney(entry.taxable_income, 'UAH') }}</span></span>
        </div>

        <div class="flex flex-col gap-2.5 py-3.5">
            <span class="text-13 font-semibold tracking-[0.04em] text-ink-3 uppercase">Податки {{ quarterLabel(quarter) }}</span>
            <div class="grid grid-cols-3 gap-2">
                <div v-for="tax in TAXES" :key="tax.key" class="flex flex-col gap-[3px]">
                    <span class="text-13 text-ink-3">{{ tax.label }}</span>
                    <span class="num text-15 font-semibold">{{ formatAmount(entry.taxes[tax.key].owed) }}</span>
                    <span class="text-12 text-ink-3">сплачено <span class="num">{{ formatAmount(entry.taxes[tax.key].paid) }}</span></span>
                    <span v-if="Number(entry.taxes[tax.key].pending) > 0" class="text-12 text-warning-text">
                        надіслано <span class="num">{{ formatAmount(entry.taxes[tax.key].pending) }}</span>
                    </span>
                </div>
            </div>
        </div>

        <button
            v-if="entry.unread_alerts > 0"
            type="button"
            class="flex min-h-13 items-center gap-2.5 text-left text-14 font-medium text-warning-text"
            @click="emit('open', 'alerts')"
        >
            <Icon name="bell" :size="18" />
            <span class="flex-1">{{ countOf(entry.unread_alerts, ['непрочитане сповіщення', 'непрочитані сповіщення', 'непрочитаних сповіщень']) }}</span>
            <Icon name="chevronRight" :size="18" class="text-ink-3" />
        </button>
        <div v-else class="flex min-h-13 items-center gap-2.5 text-14 text-ink-3">
            <Icon name="bell" :size="18" />
            Немає сповіщень
        </div>
    </article>
</template>
