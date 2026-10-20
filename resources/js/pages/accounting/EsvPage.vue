<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, EsvQuarter, EsvYear } from '@/api/types';
import AccountingHeader from '@/components/accounting/AccountingHeader.vue';
import YearSelect from '@/components/accounting/YearSelect.vue';
import TabScreen from '@/components/layout/TabScreen.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { currentQuarter, dateParts, formatDate, formatDayMonth, monthName, quarterLabel } from '@/utils/dates';
import { compareAmounts, formatAmount } from '@/utils/money';

/**
 * Облік, ЄСВ (S-Esv): the year's ЄСВ accrued, paid and left, and a table of months by quarter — what each owes and
 * what was paid, with the quarter's deadline and payment date.
 */
const route = useRoute();
const router = useRouter();
const { profileId, path } = useCurrentProfile();
const { can } = useSession();

const now = currentQuarter();
const year = computed(() => Number(route.query.year) || now.year);
const yearModel = computed({
    get: () => year.value,
    set: (value: number) => void router.replace({ query: { year: value } }),
});

const { data: esv, error, loading, reload } = useLoader(async (signal) => {
    const { data } = await api.get<ApiResponse<EsvYear>>(path(`esv/${year.value}`), undefined, signal);

    return data;
}, [profileId, year]);

const percentOfWage = computed(() => (esv.value && Number(esv.value.min_wage) > 0
    ? Math.round(Number(esv.value.monthly) / Number(esv.value.min_wage) * 100)
    : 22));

/** The first quarter with money left to pay: the button pays it. */
const toPay = computed(() => esv.value?.quarters.find((quarter) => !quarter.is_paid && compareAmounts(quarter.owed, '0') > 0) ?? null);

function deadline(quarter: EsvQuarter): string {
    return dateParts(quarter.due_at).year === year.value ? formatDayMonth(quarter.due_at) : formatDate(quarter.due_at);
}

const grid = 'grid grid-cols-[1.2fr_1fr_1fr_28px] items-center gap-2 px-3.5';
</script>

<template>
    <TabScreen title="Облік">
        <template #title-action>
            <YearSelect v-model="yearModel" />
        </template>

        <AccountingHeader active="esv" :year="year" />

        <LoadState :loading="loading && !esv" :error="error" @retry="reload">
            <template v-if="esv">
                <div class="grid grid-cols-3 gap-2">
                    <div class="flex flex-col gap-1 rounded-button border border-line bg-surface p-3">
                        <span class="text-12 text-ink-3">Нараховано</span>
                        <span class="num text-14 font-semibold">{{ formatAmount(esv.accrued) }}</span>
                    </div>
                    <div class="flex flex-col gap-1 rounded-button border border-line bg-surface p-3">
                        <span class="text-12 text-ink-3">Сплачено</span>
                        <span class="num text-14 font-semibold">{{ formatAmount(esv.paid) }}</span>
                    </div>
                    <div class="flex flex-col gap-1 rounded-button border border-line bg-surface p-3">
                        <span class="text-12 text-ink-3">{{ compareAmounts(esv.balance, '0') > 0 ? 'Переплата' : 'Залишок' }}</span>
                        <span class="num text-14 font-semibold">{{ formatAmount(compareAmounts(esv.balance, '0') > 0 ? esv.balance : esv.remaining) }}</span>
                    </div>
                </div>
                <p class="-mt-1 m-0 text-12 leading-[1.45] text-ink-3">
                    {{ percentOfWage }} % мінзарплати (<span class="num">{{ formatAmount(esv.min_wage.replace(/\.00$/, '')) }} ₴</span>) =
                    <span class="num">{{ formatAmount(esv.monthly) }}</span> на місяць. Генерується незалежно від доходу.
                </p>

                <section class="flex flex-col overflow-hidden rounded-card border border-line bg-surface" aria-label="ЄСВ за місяцями">
                    <div :class="grid" class="border-b border-divider bg-paper-light py-2.5 text-12 font-semibold text-ink-3">
                        <span>Місяць</span>
                        <span class="text-right">Нараховано</span>
                        <span class="text-right">Сплачено</span>
                        <span />
                    </div>
                    <template v-for="quarter in esv.quarters" :key="quarter.quarter">
                        <div class="flex justify-between gap-2 bg-paper px-3.5 py-2 text-12 text-ink-2">
                            <span class="font-semibold">{{ quarterLabel(quarter.quarter) }} · до <span class="num">{{ deadline(quarter) }}</span></span>
                            <span v-if="quarter.is_annual">річна декларація, додаток 1</span>
                            <span v-else-if="quarter.is_paid && quarter.paid_at">сплачено <span class="num">{{ formatDayMonth(quarter.paid_at) }}</span></span>
                            <span v-else-if="compareAmounts(quarter.pending, '0') > 0">надіслано <span class="num">{{ formatAmount(quarter.pending) }}</span></span>
                            <span v-else-if="quarter.state === 'overdue'" class="text-negative">прострочено</span>
                        </div>
                        <div
                            v-for="(month, index) in quarter.months"
                            :key="month.month"
                            :class="[grid, index ? 'border-t border-divider' : '']"
                            class="py-2.5 text-14"
                        >
                            <span>{{ monthName(month.month) }}</span>
                            <template v-if="month.status === 'exempt'">
                                <span class="col-span-2 text-right text-13 text-ink-3">звільнено{{ month.note ? ` · ${month.note}` : '' }}</span>
                                <span />
                            </template>
                            <template v-else>
                                <span class="num text-right">{{ formatAmount(month.amount_due) }}</span>
                                <span class="num text-right">{{ compareAmounts(month.paid, '0') > 0 ? formatAmount(month.paid) : '—' }}</span>
                                <Icon v-if="compareAmounts(month.paid, month.amount_due) >= 0 && compareAmounts(month.amount_due, '0') > 0" name="check" :size="20" class="text-positive" />
                                <span v-else class="size-4.5 rounded-[5px] border-2 border-ink-3" aria-label="Не сплачено" role="img" />
                            </template>
                        </div>
                    </template>
                    <div :class="grid" class="border-t border-line bg-paper-light py-3 text-15 font-semibold">
                        <span>Рік</span>
                        <span class="num text-right">{{ formatAmount(esv.accrued) }}</span>
                        <span class="num text-right">{{ formatAmount(esv.paid) }}</span>
                        <span />
                    </div>
                </section>

                <RouterLink
                    v-if="toPay && can('create', 'tax-payments')"
                    :to="{ name: 'tax-payment-new', query: { tax: 'social_contribution', year, quarter: toPay.quarter } }"
                    class="flex h-13 items-center justify-center rounded-button bg-accent text-16 font-semibold text-white no-underline hover:bg-accent-hover hover:text-white"
                >
                    Сплатити ЄСВ за {{ quarterLabel(toPay.quarter) }}
                </RouterLink>
                <p class="m-0 text-13 leading-normal text-ink-3">
                    Можна сплатити більше мінімуму (до 15 мінзарплат): переплата зараховується в наступні квартали.
                </p>
            </template>
        </LoadState>
    </TabScreen>
</template>
