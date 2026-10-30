<script setup lang="ts">
import { computed } from 'vue';
import { api } from '@/api/client';
import type { ApiResponse, Invoice, OutstandingTotal, UntransferredReceipt, UntransferredTotal } from '@/api/types';
import HomeNavCards from '@/components/home/HomeNavCards.vue';
import UntransferredList from '@/components/home/UntransferredList.vue';
import InvoiceRow from '@/components/invoice/InvoiceRow.vue';
import InvoiceTable from '@/components/invoice/InvoiceTable.vue';
import TabScreen from '@/components/layout/TabScreen.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { isOverdue } from '@/utils/invoiceRows';
import { formatMoney } from '@/utils/money';

/**
 * Головна (M-Home, D-Home; design.md §16.5, §17.4): only what needs attention (the owner, 2026-10-30). The quick
 * actions «Новий інвойс» and «Нове надходження»; then Інвойси — what the open ones still await and how much of it is
 * overdue, the drafts, and those invoices (overdue first, then the open ones by their due date, then the drafts) —,
 * or «Усе гаразд», when all are paid and none is a draft; then Надходження — the receipts, whose money is still on a
 * platform or a foreign bank, with how much and how much is past its deadline —, or «Усе гаразд», when all is moved.
 * On desktop the sections' cards stand above it all.
 */
const { profileId, path } = useCurrentProfile();
const { can } = useSession();
const { isDesktop } = useBreakpoint();

const { data, error, loading, reload } = useLoader(async (signal) => {
    const [invoices, outstanding, untransferred] = await Promise.all([
        api.all<Invoice>(path('invoices'), {
            filter: { status: { in: ['draft', 'ready', 'sent'] } },
            include: ['client'],
            append: ['number_preview'],
            sort: '-issued_at,-id',
        }, signal),
        api.get<ApiResponse<OutstandingTotal[]>>(path('invoices/outstanding'), undefined, signal),
        api.get<{ data: UntransferredReceipt[]; meta: { totals: UntransferredTotal[] } }>(path('payments/untransferred'), undefined, signal),
    ]);

    return { invoices, outstanding: outstanding.data, receipts: untransferred.data, receiptTotals: untransferred.meta.totals };
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
</script>

<template>
    <TabScreen :title="t('shell.nav.home')">
        <HomeNavCards v-if="isDesktop" />

        <div v-if="can('create', 'invoices') || can('create', 'payments')" class="grid grid-cols-2 gap-3" :class="isDesktop ? 'max-w-120' : ''">
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
                <SectionTitle>
                    {{ t('shell.nav.invoices') }}
                    <template #aside>
                        <RouterLink :to="{ name: 'invoices' }" class="text-14 font-medium no-underline">{{ t('invoices.home.allInvoices') }}</RouterLink>
                    </template>
                </SectionTitle>
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
                    <Icon name="checkCircle" :size="22" class="shrink-0 text-positive" />
                    {{ t('invoices.home.invoicesAllGood') }}
                </p>
            </section>

            <section v-if="data" class="flex flex-col gap-2">
                <SectionTitle>
                    {{ t('core.common.receipts') }}
                    <template v-if="data.receipts.length && can('create', 'account-transfers')" #aside>
                        <RouterLink :to="{ name: 'transfer-new', query: { from: transferFrom } }" class="text-14 font-medium no-underline">
                            {{ t('money.transferForm.newTransfer') }}
                        </RouterLink>
                    </template>
                </SectionTitle>
                <template v-if="data.receipts.length">
                    <p class="m-0 flex flex-wrap gap-x-5 gap-y-1 px-0.5 text-14 text-ink-3">
                        <span>{{ t('invoices.home.onPlatforms') }} <span class="num font-semibold text-ink">{{ onPlatforms }}</span></span>
                        <span v-if="pastDeadline" class="text-negative">{{ t('invoices.home.pastDeadline') }} <span class="num font-semibold">{{ pastDeadline }}</span></span>
                    </p>
                    <UntransferredList :receipts="data.receipts" />
                </template>
                <p v-else class="m-0 flex items-center gap-3 rounded-card border border-line bg-surface px-4 py-3.5 text-15 text-ink-2">
                    <Icon name="checkCircle" :size="22" class="shrink-0 text-positive" />
                    {{ t('invoices.home.receiptsAllGood') }}
                </p>
            </section>
        </LoadState>
    </TabScreen>
</template>
