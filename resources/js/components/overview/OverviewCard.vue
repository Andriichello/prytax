<script setup lang="ts">
import { computed } from 'vue';
import type { PracticeOverview } from '@/api/types';
import Avatar from '@/components/ui/Avatar.vue';
import Icon from '@/components/ui/Icon.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import Translated from '@/components/ui/Translated.vue';
import { t } from '@/i18n';
import { quarterLabel } from '@/utils/dates';
import { formatAmount, formatMoney } from '@/utils/money';
import { countOf, type PluralForms } from '@/utils/plural';
import { TAX_TYPES } from '@/utils/statuses';

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

const TAXES = ['social_contribution', 'single_tax', 'military_levy'] as const;

const UNREAD_ALERTS: PluralForms = {
    uk: ['непрочитане сповіщення', 'непрочитані сповіщення', 'непрочитаних сповіщень'],
    en: ['unread notification', 'unread notifications'],
};
</script>

<template>
    <article class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
        <button type="button" class="flex min-h-15 items-center gap-3 text-left text-ink" @click="emit('open', 'home')">
            <Avatar :initials="entry.profile.initials" :variant="current ? 'dark' : entry.profile.is_active ? 'light' : 'dashed'" :size="36" />
            <span class="flex flex-1 flex-col">
                <span class="text-16 font-semibold">{{ entry.profile.short_name }}</span>
                <span v-if="!entry.profile.is_active" class="text-12 text-ink-3">{{ t('Неактивний') }}</span>
            </span>
            <Icon name="chevronRight" :size="18" class="text-ink-3" />
        </button>

        <div class="flex flex-col gap-2 py-3.5">
            <div class="flex items-baseline justify-between">
                <span class="text-13 font-semibold tracking-[0.04em] text-ink-3 uppercase">{{ t('Ліміт доходу {year}', { year }) }}</span>
                <span class="num text-14 font-semibold" :class="capWarns ? 'text-warning-text' : ''">{{ entry.cap.percent }} %</span>
            </div>
            <div class="relative flex">
                <ProgressBar :percent="capPercent" :warn-from="80" :height="10" />
                <span class="absolute -top-[3px] left-[80%] h-4 w-0.5 bg-handle" aria-hidden="true" />
                <span class="absolute -top-[3px] left-[90%] h-4 w-0.5 bg-handle" aria-hidden="true" />
            </div>
            <div class="flex justify-between text-13 text-ink-3">
                <span class="num">{{ formatMoney(entry.cap.ytd_income, 'UAH') }}</span>
                <span class="num">{{ t('з {total}', { total: cap }) }}</span>
            </div>
        </div>

        <div class="flex flex-col gap-2.5 py-3.5">
            <span class="text-13 font-semibold tracking-[0.04em] text-ink-3 uppercase">{{ t('Дохід {quarter}', { quarter: quarterLabel(quarter) }) }}</span>
            <div v-for="income in entry.income" :key="income.currency" class="flex flex-col gap-1.5">
                <div class="flex items-baseline justify-between gap-2 text-14">
                    <span class="font-semibold">{{ income.currency }}</span>
                    <span class="text-ink-2">
                        <Translated :text="t('отримано {received} з {invoiced} · {percent}')">
                            <template #received><span class="num font-semibold text-ink">{{ formatAmount(income.received) }}</span></template>
                            <template #invoiced><span class="num">{{ formatAmount(income.invoiced) }}</span></template>
                            <template #percent><span class="num">{{ income.percent }} %</span></template>
                        </Translated>
                    </span>
                </div>
                <ProgressBar :percent="Number(income.percent)" />
            </div>
            <span v-if="entry.income.length === 0" class="text-14 text-ink-3">{{ t('Інвойсів за квартал немає.') }}</span>
            <span class="text-13 text-ink-3">
                <Translated :text="t('Дохід для податків: {amount}')">
                    <template #amount><span class="num">{{ formatMoney(entry.taxable_income, 'UAH') }}</span></template>
                </Translated>
            </span>
        </div>

        <div class="flex flex-col gap-2.5 py-3.5">
            <span class="text-13 font-semibold tracking-[0.04em] text-ink-3 uppercase">{{ t('Податки {quarter}', { quarter: quarterLabel(quarter) }) }}</span>
            <div class="grid grid-cols-3 gap-2">
                <div v-for="tax in TAXES" :key="tax" class="flex flex-col gap-[3px]">
                    <span class="text-13 text-ink-3">{{ TAX_TYPES[tax] }}</span>
                    <span class="num text-15 font-semibold">{{ formatAmount(entry.taxes[tax].owed) }}</span>
                    <span class="text-12 text-ink-3">
                        <Translated :text="t('сплачено {amount}')">
                            <template #amount><span class="num">{{ formatAmount(entry.taxes[tax].paid) }}</span></template>
                        </Translated>
                    </span>
                    <span v-if="Number(entry.taxes[tax].pending) > 0" class="text-12 text-warning-text">
                        <Translated :text="t('надіслано {amount}')">
                            <template #amount><span class="num">{{ formatAmount(entry.taxes[tax].pending) }}</span></template>
                        </Translated>
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
            <span class="flex-1">{{ countOf(entry.unread_alerts, UNREAD_ALERTS) }}</span>
            <Icon name="chevronRight" :size="18" class="text-ink-3" />
        </button>
        <div v-else class="flex min-h-13 items-center gap-2.5 text-14 text-ink-3">
            <Icon name="bell" :size="18" />
            {{ t('Немає сповіщень') }}
        </div>
    </article>
</template>
