<script setup lang="ts">
import { computed } from 'vue';
import type { ReconciledQuarter, TaxReconciliation } from '@/api/types';
import Icon from '@/components/ui/Icon.vue';
import Translated from '@/components/ui/Translated.vue';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { currentQuarter, daysBetween, formatDate, formatDayMonth, quarterLabel, today } from '@/utils/dates';
import { compareAmounts, formatAmount, formatMoney, sumAmounts } from '@/utils/money';
import { countOf, DAYS } from '@/utils/plural';
import { TAX_TYPES } from '@/utils/statuses';
import { amountsToPay, isToPay } from '@/utils/taxes';

/**
 * Податки by quarter (S-Taxes): the balance with what makes it up, the opening balance, and a card per quarter —
 * accrued, paid and the running balance — the ones due now highlighted with "Сплатити" their own debt (an earlier
 * quarter's debt is paid on its own card). Sent payments are shown as pending; they don't lower the debt.
 */
const { reconciliation } = defineProps<{ reconciliation: TaxReconciliation }>();

const { can } = useSession();

const name = computed(() => TAX_TYPES[reconciliation.tax_type]);
const quarters = computed(() => [...reconciliation.quarters].sort((a, b) => b.quarter - a.quarter));
const pending = computed(() => sumAmounts(...reconciliation.quarters.map((quarter) => quarter.pending)));
const toPay = computed(() => amountsToPay(reconciliation));
const isOwed = (amount: string): boolean => compareAmounts(amount, '0') < 0;
const isDue = (quarter: ReconciledQuarter): boolean => isToPay(quarter, toPay.value.get(quarter.quarter));

/** A past quarter, whose debt was covered by money paid later. */
const isCovered = (quarter: ReconciledQuarter): boolean => quarter.state === 'closed' || ((quarter.state === 'due' || quarter.state === 'overdue') && !isDue(quarter));

/** The balance is today's for the current year, else the year's end. */
const balanceDate = computed(() => (reconciliation.year < currentQuarter().year ? t('на 31.12.{year}', { year: reconciliation.year }) : t('на сьогодні')));

/**
 * "З них 765.69 за III кв. — до 19.11. Решта — нараховане за IV кв., строк 19.02.2027." Each sentence is translated
 * with its `{amount}` left for the figure, which is set in mono.
 */
const explanation = computed(() => {
    const sentences: { text: string; amount?: string }[] = [];
    const due = [...reconciliation.quarters].sort((a, b) => a.quarter - b.quarter).filter((quarter) => isDue(quarter));
    const current = reconciliation.quarters.find((quarter) => quarter.state === 'current');

    for (const [index, quarter] of due.entries()) {
        const params = { quarter: quarterLabel(quarter.quarter), date: formatDayMonth(quarter.due_at) };
        const isOverdue = quarter.state === 'overdue';

        sentences.push({
            text: index
                ? (isOverdue ? t('Ще {amount} за {quarter} — прострочено з {date}.', params) : t('Ще {amount} за {quarter} — до {date}.', params))
                : (isOverdue ? t('З них {amount} за {quarter} — прострочено з {date}.', params) : t('З них {amount} за {quarter} — до {date}.', params)),
            amount: formatAmount(toPay.value.get(quarter.quarter)),
        });
    }

    if (due.length && current && compareAmounts(current.owed, '0') > 0) {
        sentences.push({ text: t('Решта — нараховане за {quarter}, строк {date}.', { quarter: quarterLabel(current.quarter), date: formatDate(current.due_at) }) });
    } else if (!due.length && current && isOwed(reconciliation.balance)) {
        sentences.push({ text: t('Нараховане за {quarter}, строк {date}.', { quarter: quarterLabel(current.quarter), date: formatDate(current.due_at) }) });
    }

    if (compareAmounts(pending.value, '0') > 0) {
        sentences.push({ text: t('Очікує підтвердження {amount}.'), amount: formatAmount(pending.value) });
    }

    return sentences;
});

function dueText(quarter: ReconciledQuarter): string {
    const days = daysBetween(today(), quarter.due_at);

    return days >= 0
        ? t('до {date} · {days}', { date: formatDayMonth(quarter.due_at), days: countOf(days, DAYS) })
        : t('прострочено з {date}', { date: formatDayMonth(quarter.due_at) });
}
</script>

<template>
    <section class="flex flex-col gap-1 rounded-card border border-line bg-surface p-4" :aria-label="t('Сальдо {tax}', { tax: name })">
        <span class="text-15 text-ink-2">{{ t('Сальдо {tax} {date}', { tax: name, date: balanceDate }) }}</span>
        <span class="num text-30 font-bold" :class="isOwed(reconciliation.balance) ? 'text-negative' : compareAmounts(reconciliation.balance, '0') > 0 ? 'text-positive' : ''">
            {{ formatMoney(reconciliation.balance, 'UAH', { signed: true }) }}
        </span>
        <p v-if="explanation.length" class="m-0 text-14 leading-normal text-ink-2">
            <template v-for="(sentence, index) in explanation" :key="index">
                {{ index ? ' ' : '' }}<Translated :text="sentence.text"><template #amount><span class="num font-semibold text-ink">{{ sentence.amount }}</span></template></Translated>
            </template>
        </p>
    </section>

    <div v-if="reconciliation.opening_balance" class="flex items-baseline justify-between px-0.5 text-14 text-ink-2">
        <span>{{ t('Вхідне сальдо на {date}', { date: formatDate(reconciliation.opening_balance.as_of) }) }}</span>
        <span class="num font-semibold" :class="compareAmounts(reconciliation.opening_balance.amount, '0') < 0 ? 'text-negative' : 'text-positive'">
            {{ formatAmount(reconciliation.opening_balance.amount, { signed: true }) }}
        </span>
    </div>

    <section
        v-for="quarter in quarters"
        :key="quarter.quarter"
        class="flex flex-col gap-3 rounded-card bg-surface p-4"
        :class="isDue(quarter) ? 'border-2 border-warning-bar' : 'border border-line'"
        :aria-label="quarterLabel(quarter.quarter)"
    >
        <div class="flex items-baseline justify-between gap-2">
            <span class="text-17 font-bold">
                {{ quarterLabel(quarter.quarter) }}
                <span v-if="quarter.state === 'current'" class="text-14 font-normal text-ink-3">{{ t('поточний') }}</span>
                <span v-else-if="quarter.state === 'future'" class="text-14 font-normal text-ink-3">{{ t('майбутній') }}</span>
            </span>
            <span v-if="isCovered(quarter)" class="flex items-center gap-1 text-13 text-positive"><Icon name="check" :size="16" /> {{ t('закрито') }}</span>
            <span v-else-if="isDue(quarter)" class="text-13 font-semibold" :class="quarter.state === 'overdue' ? 'text-negative' : 'text-warning-text'">{{ dueText(quarter) }}</span>
            <span v-else class="num text-13 text-ink-3">{{ t('до {date}', { date: formatDate(quarter.due_at) }) }}</span>
        </div>
        <div class="grid grid-cols-3 gap-2">
            <span class="flex flex-col gap-0.5">
                <span class="text-13 text-ink-3">{{ t('Нараховано') }}</span>
                <span class="num text-16 font-semibold">{{ formatAmount(quarter.owed) }}</span>
            </span>
            <span class="flex flex-col gap-0.5">
                <span class="text-13 text-ink-3">{{ t('Сплачено') }}</span>
                <span class="num text-16 font-semibold">{{ formatAmount(quarter.paid) }}</span>
            </span>
            <span class="flex flex-col gap-0.5">
                <span class="text-13 text-ink-3">{{ t('Сальдо') }}</span>
                <span class="num text-16 font-semibold" :class="isOwed(quarter.balance) ? 'text-negative' : compareAmounts(quarter.balance, '0') > 0 ? 'text-positive' : ''">
                    {{ formatAmount(quarter.balance, { signed: true }) }}
                </span>
            </span>
        </div>
        <div v-if="quarter.pending_payments.length" class="flex flex-wrap gap-1.5">
            <span v-for="payment in quarter.pending_payments" :key="payment.id" class="rounded-badge bg-warning-soft px-2.5 py-1 text-13 text-warning">
                <Translated :text="t('Очікує: {amount} надіслано {date}', { date: formatDayMonth(payment.paid_at) })">
                    <template #amount><span class="num">{{ formatAmount(payment.amount) }}</span></template>
                </Translated>
            </span>
        </div>
        <span v-if="compareAmounts(quarter.failed, '0') > 0" class="text-13 text-negative">
            <Translated :text="t('Не пройшло: {amount}')"><template #amount><span class="num">{{ formatAmount(quarter.failed) }}</span></template></Translated>
        </span>
        <RouterLink
            v-if="isDue(quarter) && can('create', 'tax-payments')"
            :to="{ name: 'tax-payment-new', query: { tax: reconciliation.tax_type, allocation: 'quarter', year: quarter.year, quarter: quarter.quarter, amount: toPay.get(quarter.quarter) } }"
            class="flex h-12 items-center justify-center rounded-control bg-accent text-16 font-semibold text-white no-underline hover:bg-accent-hover hover:text-white"
        >
            {{ t('Сплатити {amount}', { amount: formatMoney(toPay.get(quarter.quarter), 'UAH') }) }}
        </RouterLink>
    </section>

    <p class="m-0 text-13 leading-normal text-ink-3">
        <template v-if="reconciliation.tax_type === 'social_contribution'">
            {{ t('ЄСВ нараховується щомісяця незалежно від доходу. Переплата переноситься в наступні квартали.') }}
        </template>
        <template v-else>
            {{ t('Нараховано за квартал = {tax} з початку року мінус {tax} попереднього кварталу — та сама сума, що в декларації. Сальдо переноситься між кварталами й роками.', { tax: name }) }}
        </template>
    </p>
</template>
