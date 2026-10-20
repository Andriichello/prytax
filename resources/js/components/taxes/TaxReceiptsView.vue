<script setup lang="ts">
import { api } from '@/api/client';
import type { ApiResponse, QuarterTaxPayments, QuarterTaxRow, ReceiptTax } from '@/api/types';
import LoadState from '@/components/ui/LoadState.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { formatDate, formatDayMonth, monthName, quarterLabel } from '@/utils/dates';
import { compareAmounts, formatAmount } from '@/utils/money';

/**
 * Податки by receipt (S-TaxesByPayment): ЄП and ВЗ of each receipt of a quarter with how they are paid, and the
 * quarter's check of what is owed against the sum of the hints.
 */
const { year, quarter } = defineProps<{ year: number; quarter: number }>();

const { profileId, path } = useCurrentProfile();
const { can } = useSession();

const { data, error, loading, reload } = useLoader(async (signal) => {
    const { data: payments } = await api.get<ApiResponse<QuarterTaxPayments>>(path(`taxes/${year}/${quarter}/payments`), undefined, signal);

    return payments;
}, [profileId, () => year, () => quarter]);

const TAXES = [{ key: 'single_tax', label: 'ЄП' }, { key: 'military_levy', label: 'ВЗ' }] as const;

function state(tax: ReceiptTax): { text: string; class: string } {
    const last = tax.payments[tax.payments.length - 1];

    switch (tax.status) {
        case 'paid':
            return { text: `сплачено ${formatDayMonth(last?.paid_at)}`.trim(), class: 'rounded-badge bg-positive-soft px-2 py-0.5 font-semibold text-positive' };
        case 'pending':
            return { text: `надіслано ${formatDayMonth(last?.paid_at)}`.trim(), class: 'rounded-badge bg-warning-soft px-2 py-0.5 font-semibold text-warning' };
        case 'covered':
            return { text: 'покрито сплатою періоду', class: 'text-positive' };
        default:
            return { text: 'не сплачено', class: 'text-ink-3' };
    }
}

const unpaid = (row: QuarterTaxRow): boolean => row.type === 'payment' && (row.single_tax.status === 'unpaid' || row.military_levy.status === 'unpaid');
</script>

<template>
    <LoadState :loading="loading && !data" :error="error" @retry="reload">
        <template v-if="data">
            <EmptyState v-if="data.rows.length === 0" :text="`У ${quarterLabel(quarter)} ${year} надходжень немає.`" />

            <section
                v-for="row in data.rows"
                :key="row.type === 'refund' ? `r${row.refund_id}` : `p${row.payment_id}`"
                class="flex flex-col divide-y divide-divider overflow-hidden rounded-card border border-line bg-surface"
            >
                <RouterLink :to="{ name: 'payment', params: { id: row.payment_id } }" class="flex flex-col gap-1 px-4 py-3 text-ink no-underline hover:text-ink">
                    <span class="flex items-baseline justify-between gap-3">
                        <span class="min-w-0 truncate">
                            <span class="num text-13 text-ink-3">{{ formatDayMonth(row.received_at) }}</span>
                            <span class="num ml-2 text-16 font-bold">{{ row.type === 'refund' ? 'Повернення' : row.invoice_number ?? row.description }}</span>
                        </span>
                        <span class="num text-16 font-bold" :class="row.type === 'refund' ? 'text-negative' : ''">{{ formatAmount(row.uah_amount) }}</span>
                    </span>
                    <span class="flex items-baseline justify-between gap-3 text-13 text-ink-3">
                        <span class="truncate">{{ row.client_name ?? row.description }}</span>
                        <span v-if="row.currency !== 'UAH'" class="num">{{ row.currency }} {{ formatAmount(row.amount) }} @ {{ formatAmount(row.nbu_rate) }}</span>
                    </span>
                </RouterLink>
                <div v-for="tax in TAXES" :key="tax.key" class="flex min-h-12 items-center justify-between gap-3 px-4">
                    <span class="flex items-baseline gap-4">
                        <span class="w-6 text-15 font-semibold">{{ tax.label }}</span>
                        <span class="num text-15">{{ formatAmount(row[tax.key].hint) }}</span>
                    </span>
                    <span v-if="row.type === 'payment'" class="text-13" :class="state(row[tax.key]).class">{{ state(row[tax.key]).text }}</span>
                </div>
                <RouterLink
                    v-if="unpaid(row) && can('create', 'tax-payments')"
                    :to="{ name: 'tax-payment-new', query: { allocation: 'payment', payment: row.payment_id, year, quarter } }"
                    class="flex min-h-13 items-center justify-center text-15 font-medium no-underline"
                >
                    Сплатити за цим надходженням
                </RouterLink>
            </section>

            <section v-if="data.covering.length" class="flex flex-col gap-2">
                <SectionTitle>Сплати за період</SectionTitle>
                <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                    <div v-for="payment in data.covering" :key="payment.id" class="flex items-center justify-between gap-3 py-3 text-14">
                        <span class="flex flex-col gap-0.5">
                            <span class="font-medium">
                                {{ payment.tax_type === 'single_tax' ? 'ЄП' : 'ВЗ' }} за {{ payment.allocation === 'month' && payment.period_month ? monthName(payment.period_month).toLowerCase() : quarterLabel(quarter) }}
                            </span>
                            <span class="text-12 text-ink-3">{{ formatDate(payment.paid_at) }} · покриває {{ payment.covers }}</span>
                        </span>
                        <span class="num font-semibold">{{ formatAmount(payment.amount) }}</span>
                    </div>
                </div>
            </section>

            <section class="flex flex-col gap-2 rounded-card border border-line bg-surface p-4" :aria-label="`Звірка ${quarterLabel(quarter)}`">
                <SectionTitle as="span">Звірка {{ quarterLabel(quarter) }}</SectionTitle>
                <div class="grid grid-cols-[auto_1fr_1fr] items-baseline gap-x-3 gap-y-1.5 text-14">
                    <span />
                    <span class="text-right text-ink-2">Нараховано</span>
                    <span class="text-right text-ink-2">Сума підказок</span>
                    <template v-for="tax in TAXES" :key="tax.key">
                        <span class="font-semibold">{{ tax.label }}</span>
                        <span class="num text-right font-semibold">{{ formatAmount(data.check[tax.key].owed) }}</span>
                        <span class="num text-right">
                            {{ formatAmount(data.check[tax.key].hints_sum) }}
                            <span :class="compareAmounts(data.check[tax.key].delta, '0') === 0 ? '' : 'text-warning-text'">Δ {{ formatAmount(data.check[tax.key].delta) }}</span>
                        </span>
                    </template>
                </div>
                <p class="m-0 text-13 leading-normal text-ink-3">Різниця в копійку — наслідок округлення по кожному надходженню. До сплати береться «Нараховано».</p>
            </section>
        </template>
    </LoadState>
</template>
