<script setup lang="ts">
import { computed } from 'vue';
import { api } from '@/api/client';
import type { ApiResponse, Invoice, OutstandingTotal, Paginated, Payment, UntransferredReceipt, UntransferredTotal } from '@/api/types';
import UntransferredList from '@/components/home/UntransferredList.vue';
import InvoiceRow from '@/components/invoice/InvoiceRow.vue';
import InvoiceTable from '@/components/invoice/InvoiceTable.vue';
import TabScreen from '@/components/layout/TabScreen.vue';
import Icon from '@/components/ui/Icon.vue';
import type { IconName } from '@/components/ui/icons';
import LoadState from '@/components/ui/LoadState.vue';
import NewPill from '@/components/ui/NewPill.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { isOverdue } from '@/utils/invoiceRows';
import { formatMoney } from '@/utils/money';

/**
 * Головна (M-Home, D-Home; design.md §16.5, §17.4): only what needs attention (the owner, 2026-10-30). Інвойси —
 * what the open ones still await and how much of it is overdue, the drafts, and those invoices (overdue first, then
 * the open ones by their due date, then the drafts) —; then Надходження — the receipts, whose money is still on a
 * platform or a foreign bank, with how much and how much is past its deadline. A section with nothing to show says
 * «… ще немає», while the ФОП has none at all, or else «Усе гаразд». On desktop «+ Новий інвойс» and «+ Нове
 * надходження» end their sections' header rows; on a phone they stand side by side above the sections.
 */
const { profileId, path } = useCurrentProfile();
const { can } = useSession();
const { isDesktop } = useBreakpoint();

const { data, error, loading, reload } = useLoader(async (signal) => {
    const [invoices, outstanding, untransferred, allInvoices, allReceipts] = await Promise.all([
        api.all<Invoice>(path('invoices'), {
            filter: { status: { in: ['draft', 'ready', 'sent'] } },
            include: ['client'],
            append: ['number_preview'],
            sort: '-issued_at,-id',
        }, signal),
        api.get<ApiResponse<OutstandingTotal[]>>(path('invoices/outstanding'), undefined, signal),
        api.get<{ data: UntransferredReceipt[]; meta: { totals: UntransferredTotal[] } }>(path('payments/untransferred'), undefined, signal),
        api.get<Paginated<Invoice>>(path('invoices'), { page: { size: 1 } }, signal),
        api.get<Paginated<Payment>>(path('payments'), { page: { size: 1 } }, signal),
    ]);

    return {
        invoices,
        outstanding: outstanding.data,
        receipts: untransferred.data,
        receiptTotals: untransferred.meta.totals,
        invoiceCount: allInvoices.meta.total,
        receiptCount: allReceipts.meta.total,
    };
}, [profileId]);

/** The order of attention: overdue, then open, then drafts. */
const rank = (invoice: Invoice): number => (isOverdue(invoice) ? 0 : invoice.status === 'draft' ? 2 : 1);

const invoices = computed(() => [...(data.value?.invoices ?? [])]
    .sort((a, b) => rank(a) - rank(b) || (a.due_at ?? a.period_end).localeCompare(b.due_at ?? b.period_end) || a.id - b.id));

/** Amounts per currency, «2 450.00 USD + 800.00 EUR»; empty when there are none. */
const sum = (totals: { amount: string; currency: string }[]): string => totals
    .filter((total) => Number(total.amount) > 0)
    .map((total) => formatMoney(total.amount, total.currency))
    .join(' + ');

const toPay = computed(() => sum((data.value?.outstanding ?? []).map((total) => ({ amount: total.remaining, currency: total.currency }))));
const overdue = computed(() => sum((data.value?.outstanding ?? []).map((total) => ({ amount: total.overdue, currency: total.currency }))));
const drafts = computed(() => invoices.value.filter((invoice) => invoice.status === 'draft').length);

const onPlatforms = computed(() => sum(data.value?.receiptTotals ?? []));
const pastDeadline = computed(() => sum((data.value?.receiptTotals ?? []).map((total) => ({ amount: total.overdue, currency: total.currency }))));
/** A transfer starts from the account of the oldest receipt still waiting. */
const transferFrom = computed(() => data.value?.receipts[0]?.account.id);

/** What a section without anything to show says: that there's none yet, or that all is well. */
const quiet = (count: number, none: string, allGood: string): { icon: IconName; positive: boolean; text: string } => (count === 0
    ? { icon: 'info', positive: false, text: none }
    : { icon: 'checkCircle', positive: true, text: allGood });

const invoicesQuiet = computed(() => quiet(data.value?.invoiceCount ?? 0, t('invoices.home.invoicesNone'), t('invoices.home.invoicesAllGood')));
const receiptsQuiet = computed(() => quiet(data.value?.receiptCount ?? 0, t('invoices.home.receiptsNone'), t('invoices.home.receiptsAllGood')));
</script>

<template>
    <TabScreen :title="t('shell.nav.home')">
        <div v-if="!isDesktop && (can('create', 'invoices') || can('create', 'payments'))" class="grid grid-cols-2 gap-3">
            <RouterLink
                v-if="can('create', 'invoices')"
                :to="{ name: 'invoice-new' }"
                class="flex h-13 items-center justify-center gap-2 rounded-button bg-accent px-3 text-15 font-semibold text-white no-underline hover:bg-accent-hover hover:text-white"
            >
                <Icon name="plus" :size="20" />
                {{ t('invoices.home.quickInvoice') }}
            </RouterLink>
            <RouterLink
                v-if="can('create', 'payments')"
                :to="{ name: 'payment-pick' }"
                class="flex h-13 items-center justify-center gap-2 rounded-button border border-line-input bg-surface px-3 text-15 font-semibold text-accent no-underline hover:text-accent-hover"
            >
                <Icon name="plus" :size="20" />
                {{ t('invoices.home.quickReceipt') }}
            </RouterLink>
        </div>

        <LoadState :loading="loading && !data" :error="error" @retry="reload">
            <section v-if="data" class="flex flex-col gap-2">
                <div class="flex items-center justify-between gap-3">
                    <SectionTitle>{{ t('shell.nav.invoices') }}</SectionTitle>
                    <div class="flex items-center gap-4">
                        <RouterLink :to="{ name: 'invoices' }" class="text-14 font-medium no-underline">{{ t('invoices.home.allInvoices') }}</RouterLink>
                        <NewPill v-if="isDesktop && can('create', 'invoices')" :to="{ name: 'invoice-new' }" :label="t('invoices.home.quickInvoice')" />
                    </div>
                </div>
                <template v-if="invoices.length">
                    <p class="m-0 flex flex-wrap gap-x-5 gap-y-1 px-0.5 text-14 text-ink-3">
                        <span v-if="toPay">{{ t('invoices.home.toPay') }} <span class="num font-semibold text-ink">{{ toPay }}</span></span>
                        <span v-if="overdue" class="text-negative">{{ t('invoices.home.overdue') }} <span class="num font-semibold">{{ overdue }}</span></span>
                        <span v-if="drafts">{{ t('invoices.home.drafts') }} <span class="num font-semibold text-ink">{{ drafts }}</span></span>
                    </p>
                    <InvoiceTable v-if="isDesktop" :invoices="invoices" variant="home" />
                    <div v-else class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                        <InvoiceRow v-for="invoice in invoices" :key="invoice.id" :invoice="invoice" />
                    </div>
                </template>
                <p v-else class="m-0 flex items-center gap-3 rounded-card border border-line bg-surface px-4 py-3.5 text-15 text-ink-2">
                    <Icon :name="invoicesQuiet.icon" :size="22" class="shrink-0" :class="invoicesQuiet.positive ? 'text-positive' : 'text-ink-3'" />
                    {{ invoicesQuiet.text }}
                </p>
            </section>

            <section v-if="data" class="flex flex-col gap-2">
                <div class="flex items-center justify-between gap-3">
                    <SectionTitle>{{ t('core.common.receipts') }}</SectionTitle>
                    <div class="flex items-center gap-4">
                        <RouterLink
                            v-if="data.receipts.length && can('create', 'account-transfers')"
                            :to="{ name: 'transfer-new', query: { from: transferFrom } }"
                            class="text-14 font-medium no-underline"
                        >
                            {{ t('money.transferForm.newTransfer') }}
                        </RouterLink>
                        <NewPill v-if="isDesktop && can('create', 'payments')" :to="{ name: 'payment-pick' }" :label="t('invoices.home.quickReceipt')" />
                    </div>
                </div>
                <template v-if="data.receipts.length">
                    <p class="m-0 flex flex-wrap gap-x-5 gap-y-1 px-0.5 text-14 text-ink-3">
                        <span>{{ t('invoices.home.onPlatforms') }} <span class="num font-semibold text-ink">{{ onPlatforms }}</span></span>
                        <span v-if="pastDeadline" class="text-negative">{{ t('invoices.home.pastDeadline') }} <span class="num font-semibold">{{ pastDeadline }}</span></span>
                    </p>
                    <UntransferredList :receipts="data.receipts" />
                </template>
                <p v-else class="m-0 flex items-center gap-3 rounded-card border border-line bg-surface px-4 py-3.5 text-15 text-ink-2">
                    <Icon :name="receiptsQuiet.icon" :size="22" class="shrink-0" :class="receiptsQuiet.positive ? 'text-positive' : 'text-ink-3'" />
                    {{ receiptsQuiet.text }}
                </p>
            </section>
        </LoadState>
    </TabScreen>
</template>
