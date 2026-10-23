<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, EsvMonth, EsvQuarter, EsvYear } from '@/api/types';
import AccountingHeader from '@/components/accounting/AccountingHeader.vue';
import YearSelect from '@/components/accounting/YearSelect.vue';
import TextField from '@/components/form/TextField.vue';
import TabScreen from '@/components/layout/TabScreen.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SwitchTile from '@/components/ui/SwitchTile.vue';
import Translated from '@/components/ui/Translated.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { currentQuarter, dateParts, formatDate, formatDayMonth, monthName, quarterLabel } from '@/utils/dates';
import { errorMessage } from '@/utils/errors';
import { compareAmounts, formatAmount } from '@/utils/money';

/**
 * Облік, ЄСВ (S-Esv): the year's ЄСВ accrued, paid and left, and a table of months by quarter — what each owes and
 * what was paid, with the quarter's deadline and payment date. A tap on a month marks it exempt (the martial-law
 * window of 2022–2024, a territorial exemption) or due again, with a note.
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

/* Marking a month exempt */
const canChangeMonths = computed(() => can('update', 'esv-obligations'));
const editing = ref<EsvMonth | null>(null);
const monthOpen = ref(false);
const monthDraft = reactive({ exempt: false, note: '' });
const monthSaving = ref(false);
const monthError = ref<unknown>(null);

function openMonth(month: EsvMonth): void {
    if (!canChangeMonths.value || month.obligation_id === null) {
        return;
    }

    editing.value = month;
    Object.assign(monthDraft, { exempt: month.status === 'exempt', note: month.note ?? '' });
    monthError.value = null;
    monthOpen.value = true;
}

async function saveMonth(): Promise<void> {
    if (!editing.value?.obligation_id) {
        return;
    }

    monthSaving.value = true;
    monthError.value = null;

    try {
        await api.patch(path(`esv-obligations/${editing.value.obligation_id}`), {
            status: monthDraft.exempt ? 'exempt' : 'due',
            note: monthDraft.note.trim() || null,
        });
        monthOpen.value = false;
        await reload();
    } catch (caught) {
        monthError.value = caught;
    } finally {
        monthSaving.value = false;
    }
}
</script>

<template>
    <TabScreen :title="t('Облік')">
        <template #title-action>
            <YearSelect v-model="yearModel" />
        </template>

        <AccountingHeader active="esv" :year="year" />

        <LoadState :loading="loading && !esv" :error="error" @retry="reload">
            <template v-if="esv">
                <div class="grid grid-cols-3 gap-2">
                    <div class="flex flex-col gap-1 rounded-button border border-line bg-surface p-3">
                        <span class="text-12 text-ink-3">{{ t('Нараховано') }}</span>
                        <span class="num text-14 font-semibold">{{ formatAmount(esv.accrued) }}</span>
                    </div>
                    <div class="flex flex-col gap-1 rounded-button border border-line bg-surface p-3">
                        <span class="text-12 text-ink-3">{{ t('Сплачено') }}</span>
                        <span class="num text-14 font-semibold">{{ formatAmount(esv.paid) }}</span>
                    </div>
                    <div class="flex flex-col gap-1 rounded-button border border-line bg-surface p-3">
                        <span class="text-12 text-ink-3">{{ compareAmounts(esv.balance, '0') > 0 ? t('Переплата') : t('Залишок') }}</span>
                        <span class="num text-14 font-semibold">{{ formatAmount(compareAmounts(esv.balance, '0') > 0 ? esv.balance : esv.remaining) }}</span>
                    </div>
                </div>
                <p class="-mt-1 m-0 text-12 leading-[1.45] text-ink-3">
                    <Translated :text="t('{percent} % мінзарплати ({minWage}) = {monthly} на місяць. Генерується незалежно від доходу.', { percent: percentOfWage })">
                        <template #minWage><span class="num">{{ formatAmount(esv.min_wage.replace(/\.00$/, '')) }} ₴</span></template>
                        <template #monthly><span class="num">{{ formatAmount(esv.monthly) }}</span></template>
                    </Translated>
                </p>

                <section class="flex flex-col overflow-hidden rounded-card border border-line bg-surface" :aria-label="t('ЄСВ за місяцями')">
                    <div :class="grid" class="border-b border-divider bg-paper-light py-2.5 text-12 font-semibold text-ink-3">
                        <span>{{ t('Місяць') }}</span>
                        <span class="text-right">{{ t('Нараховано') }}</span>
                        <span class="text-right">{{ t('Сплачено') }}</span>
                        <span />
                    </div>
                    <template v-for="quarter in esv.quarters" :key="quarter.quarter">
                        <div class="flex justify-between gap-2 bg-paper px-3.5 py-2 text-12 text-ink-2">
                            <span class="font-semibold"><Translated :text="t('{quarter} · до {date}', { quarter: quarterLabel(quarter.quarter) })"><template #date><span class="num">{{ deadline(quarter) }}</span></template></Translated></span>
                            <span class="text-right">
                                <Translated v-if="quarter.is_paid && quarter.paid_at" :text="t('сплачено {date}')"><template #date><span class="num">{{ formatDayMonth(quarter.paid_at) }}</span></template></Translated>
                                <Translated v-else-if="compareAmounts(quarter.pending, '0') > 0" :text="t('надіслано {amount}')"><template #amount><span class="num">{{ formatAmount(quarter.pending) }}</span></template></Translated>
                                <span v-else-if="quarter.state === 'overdue'" class="text-negative">{{ t('прострочено') }}</span>
                                <template v-if="quarter.is_annual">{{ quarter.is_paid || compareAmounts(quarter.pending, '0') > 0 || quarter.state === 'overdue' ? ' · ' : '' }}{{ t('річна декларація, додаток 1') }}</template>
                            </span>
                        </div>
                        <component
                            :is="canChangeMonths && month.obligation_id !== null ? 'button' : 'div'"
                            v-for="(month, index) in quarter.months"
                            :key="month.month"
                            :type="canChangeMonths && month.obligation_id !== null ? 'button' : undefined"
                            :class="[grid, index ? 'border-t border-divider' : '']"
                            class="w-full py-2.5 text-left text-14"
                            @click="openMonth(month)"
                        >
                            <span>{{ monthName(month.month) }}</span>
                            <template v-if="month.status === 'exempt'">
                                <span class="col-span-2 text-right text-13 text-ink-3">{{ t('звільнено') }}{{ month.note ? ` · ${month.note}` : '' }}</span>
                                <span />
                            </template>
                            <template v-else-if="month.status === null">
                                <span class="col-span-2 text-right text-13 text-ink-3">— · {{ t('не відстежується') }}</span>
                                <span />
                            </template>
                            <template v-else>
                                <span class="num text-right">{{ formatAmount(month.amount_due) }}</span>
                                <span class="num text-right">{{ compareAmounts(month.paid, '0') > 0 ? formatAmount(month.paid) : '—' }}</span>
                                <Icon v-if="compareAmounts(month.paid, month.amount_due) >= 0 && compareAmounts(month.amount_due, '0') > 0" name="check" :size="20" class="text-positive" />
                                <span v-else class="size-4.5 rounded-[5px] border-2 border-ink-3" :aria-label="t('Не сплачено')" role="img" />
                            </template>
                        </component>
                    </template>
                    <div :class="grid" class="border-t border-line bg-paper-light py-3 text-15 font-semibold">
                        <span>{{ t('Рік') }}</span>
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
                    {{ t('Сплатити ЄСВ за {quarter}', { quarter: quarterLabel(toPay.quarter) }) }}
                </RouterLink>
                <p class="m-0 text-13 leading-normal text-ink-3">
                    {{ t('Можна сплатити більше мінімуму (до 15 мінзарплат): переплата зараховується в наступні квартали.') }}
                    <template v-if="canChangeMonths">{{ t('Місяць, звільнений від сплати, позначте дотиком.') }}</template>
                </p>
            </template>
        </LoadState>

        <BottomSheet v-model:open="monthOpen" :title="editing ? t('ЄСВ · {month} {year}', { month: monthName(editing.month), year }) : t('ЄСВ')">
            <SwitchTile v-model="monthDraft.exempt" :label="t('Звільнено від сплати')" />
            <TextField v-model="monthDraft.note" :label="t('Підстава')" :placeholder="t('Напр., воєнний стан, 2022–2024')" />
            <p class="m-0 text-13 leading-normal text-ink-3">{{ t('Звільнений місяць нічого не нараховує.') }}</p>
            <p v-if="monthError" class="m-0 text-14 text-negative" role="alert">{{ errorMessage(monthError) }}</p>
            <template #footer>
                <PrimaryButton :busy="monthSaving" @click="saveMonth">{{ t('Зберегти') }}</PrimaryButton>
            </template>
        </BottomSheet>
    </TabScreen>
</template>
