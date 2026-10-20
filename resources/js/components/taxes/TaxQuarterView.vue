<script setup lang="ts">
import { computed } from 'vue';
import type { ReconciledQuarter, TaxReconciliation } from '@/api/types';
import Icon from '@/components/ui/Icon.vue';
import { useSession } from '@/composables/useSession';
import { daysBetween, formatDate, formatDayMonth, quarterLabel, today } from '@/utils/dates';
import { compareAmounts, formatAmount, formatMoney, sumAmounts } from '@/utils/money';
import { countOf, DAYS } from '@/utils/plural';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * Податки by quarter (S-Taxes): the balance today with what makes it up, the opening balance, and a card per quarter
 * — accrued, paid and the running balance — the one due now highlighted with "Сплатити". Sent payments are shown as
 * pending; they don't lower the debt.
 */
const { reconciliation } = defineProps<{ reconciliation: TaxReconciliation }>();

const { can } = useSession();

const name = computed(() => TAX_TYPES[reconciliation.tax_type]);
const quarters = computed(() => [...reconciliation.quarters].sort((a, b) => b.quarter - a.quarter));
const pending = computed(() => sumAmounts(...reconciliation.quarters.map((quarter) => quarter.pending)));
const isOwed = (amount: string): boolean => compareAmounts(amount, '0') < 0;
const isDue = (quarter: ReconciledQuarter): boolean => (quarter.state === 'due' || quarter.state === 'overdue') && isOwed(quarter.balance);

/** "З них 765.69 за III кв. — до 19.11. Решта — нараховане за IV кв., строк 19.02.2027." */
const explanation = computed(() => {
    const parts: string[] = [];
    const due = reconciliation.quarters.find((quarter) => isDue(quarter));
    const current = reconciliation.quarters.find((quarter) => quarter.state === 'current');

    if (due) {
        parts.push(`З них ${formatAmount(due.balance.replace('-', ''))} за ${quarterLabel(due.quarter)} — ${due.state === 'overdue' ? 'прострочено з' : 'до'} ${formatDayMonth(due.due_at)}.`);

        if (current && compareAmounts(current.owed, '0') > 0) {
            parts.push(`Решта — нараховане за ${quarterLabel(current.quarter)}, строк ${formatDate(current.due_at)}.`);
        }
    } else if (current && isOwed(reconciliation.balance)) {
        parts.push(`Нараховане за ${quarterLabel(current.quarter)}, строк ${formatDate(current.due_at)}.`);
    }

    if (compareAmounts(pending.value, '0') > 0) {
        parts.push(`Очікує підтвердження ${formatAmount(pending.value)}.`);
    }

    return parts.join(' ');
});

function dueText(quarter: ReconciledQuarter): string {
    const days = daysBetween(today(), quarter.due_at);

    return days >= 0 ? `до ${formatDayMonth(quarter.due_at)} · ${countOf(days, DAYS)}` : `прострочено з ${formatDayMonth(quarter.due_at)}`;
}
</script>

<template>
    <section class="flex flex-col gap-1 rounded-card border border-line bg-surface p-4" :aria-label="`Сальдо ${name}`">
        <span class="text-15 text-ink-2">Сальдо {{ name }} на сьогодні</span>
        <span class="num text-30 font-bold" :class="isOwed(reconciliation.balance) ? 'text-negative' : compareAmounts(reconciliation.balance, '0') > 0 ? 'text-positive' : ''">
            {{ formatMoney(reconciliation.balance, 'UAH', { signed: true }) }}
        </span>
        <p v-if="explanation" class="m-0 text-14 leading-normal text-ink-2">{{ explanation }}</p>
    </section>

    <div v-if="reconciliation.opening_balance" class="flex items-baseline justify-between px-0.5 text-14 text-ink-2">
        <span>Вхідне сальдо на {{ formatDate(reconciliation.opening_balance.as_of) }}</span>
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
                <span v-if="quarter.state === 'current'" class="text-14 font-normal text-ink-3">поточний</span>
                <span v-else-if="quarter.state === 'future'" class="text-14 font-normal text-ink-3">майбутній</span>
            </span>
            <span v-if="quarter.state === 'closed'" class="flex items-center gap-1 text-13 text-positive"><Icon name="check" :size="16" /> закрито</span>
            <span v-else-if="isDue(quarter)" class="text-13 font-semibold" :class="quarter.state === 'overdue' ? 'text-negative' : 'text-warning-text'">{{ dueText(quarter) }}</span>
            <span v-else class="num text-13 text-ink-3">до {{ formatDate(quarter.due_at) }}</span>
        </div>
        <div class="grid grid-cols-3 gap-2">
            <span class="flex flex-col gap-0.5">
                <span class="text-13 text-ink-3">Нараховано</span>
                <span class="num text-16 font-semibold">{{ formatAmount(quarter.owed) }}</span>
            </span>
            <span class="flex flex-col gap-0.5">
                <span class="text-13 text-ink-3">Сплачено</span>
                <span class="num text-16 font-semibold">{{ formatAmount(quarter.paid) }}</span>
            </span>
            <span class="flex flex-col gap-0.5">
                <span class="text-13 text-ink-3">Сальдо</span>
                <span class="num text-16 font-semibold" :class="isOwed(quarter.balance) ? 'text-negative' : compareAmounts(quarter.balance, '0') > 0 ? 'text-positive' : ''">
                    {{ formatAmount(quarter.balance, { signed: true }) }}
                </span>
            </span>
        </div>
        <div v-if="quarter.pending_payments.length" class="flex flex-wrap gap-1.5">
            <span v-for="payment in quarter.pending_payments" :key="payment.id" class="rounded-badge bg-warning-soft px-2.5 py-1 text-13 text-warning">
                Очікує: <span class="num">{{ formatAmount(payment.amount) }}</span> надіслано {{ formatDayMonth(payment.paid_at) }}
            </span>
        </div>
        <span v-if="compareAmounts(quarter.failed, '0') > 0" class="text-13 text-negative">
            Не пройшло: <span class="num">{{ formatAmount(quarter.failed) }}</span>
        </span>
        <RouterLink
            v-if="isDue(quarter) && can('create', 'tax-payments')"
            :to="{ name: 'tax-payment-new', query: { tax: reconciliation.tax_type, allocation: 'quarter', year: quarter.year, quarter: quarter.quarter, amount: quarter.balance.replace('-', '') } }"
            class="flex h-12 items-center justify-center rounded-control bg-accent text-16 font-semibold text-white no-underline hover:bg-accent-hover hover:text-white"
        >
            Сплатити {{ formatMoney(quarter.balance.replace('-', ''), 'UAH') }}
        </RouterLink>
    </section>

    <p class="m-0 text-13 leading-normal text-ink-3">
        <template v-if="reconciliation.tax_type === 'social_contribution'">
            ЄСВ нараховується щомісяця незалежно від доходу. Переплата переноситься в наступні квартали.
        </template>
        <template v-else>
            Нараховано за квартал = {{ name }} з початку року мінус {{ name }} попереднього кварталу — та сама сума, що в
            декларації. Сальдо переноситься між кварталами й роками.
        </template>
    </p>
</template>
