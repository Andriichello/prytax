<script setup lang="ts">
import { computed } from 'vue';
import { api } from '@/api/client';
import type { ApiResponse, Invoice, OutstandingTotal } from '@/api/types';
import InvoiceRow from '@/components/invoice/InvoiceRow.vue';
import TabScreen from '@/components/layout/TabScreen.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import LoadState from '@/components/ui/LoadState.vue';
import Translated from '@/components/ui/Translated.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { currentQuarter, quarterName, quarterOf } from '@/utils/dates';
import { formatMoney } from '@/utils/money';

/**
 * Головна (M-Home, design.md §16.5): the invoices of the current and the previous quarter by their issue date, of
 * every status and the payments without an invoice among them, newest first. The subtitle tells what the open
 * invoices (ready and sent) still await, per currency.
 */
const { profileId, path } = useCurrentProfile();
const { can } = useSession();

const now = currentQuarter();
const quarters = [now, now.quarter === 1 ? { year: now.year - 1, quarter: 4 } : { year: now.year, quarter: now.quarter - 1 }];

/** The first day of a quarter. */
const startOf = ({ year, quarter }: { year: number; quarter: number }): string => `${year}-${String((quarter - 1) * 3 + 1).padStart(2, '0')}-01`;

const { data, error, loading, reload } = useLoader(async (signal) => {
    const [dated, drafts, outstanding] = await Promise.all([
        api.all<Invoice>(path('invoices'), {
            filter: { issued_at: { gte: startOf(quarters[1]!) } },
            include: ['client'],
            append: ['number_preview'],
            sort: '-issued_at,-id',
        }, signal),
        api.all<Invoice>(path('invoices'), { filter: { status: 'draft' }, include: ['client'], append: ['number_preview'] }, signal),
        api.get<ApiResponse<OutstandingTotal[]>>(path('invoices/outstanding'), undefined, signal),
    ]);
    // A draft without an issue date takes its period's end
    const undated = drafts.filter((invoice) => invoice.issued_at === null && invoice.period_end >= startOf(quarters[1]!));

    return { invoices: [...dated, ...undated], outstanding: outstanding.data };
}, [profileId]);

/** The date that places an invoice in a quarter: its issue date, or a draft's period end. */
const dateOf = (invoice: Invoice): string => invoice.issued_at ?? invoice.period_end;

const sections = computed(() => quarters.map(({ year, quarter }) => {
    const invoices = (data.value?.invoices ?? [])
        .filter((invoice) => Number(dateOf(invoice).slice(0, 4)) === year && quarterOf(dateOf(invoice)) === quarter)
        .sort((a, b) => dateOf(b).localeCompare(dateOf(a)) || b.id - a.id);
    const hasVirtual = invoices.some((invoice) => invoice.source === 'virtual');

    return {
        key: `${year}-${quarter}`,
        title: `${quarterName(quarter)} ${year}`,
        count: hasVirtual ? t('core.counts.records', { count: invoices.length }) : t('core.counts.invoices', { count: invoices.length }),
        invoices,
    };
}));

const toPay = computed(() => (data.value?.outstanding ?? [])
    .filter((total) => Number(total.remaining) > 0)
    .map((total) => formatMoney(total.remaining, total.currency))
    .join(' + '));
</script>

<template>
    <TabScreen :title="t('shell.nav.home')">
        <template #subtitle>
            <p v-if="data" class="m-0 text-14 text-ink-3">
                <Translated v-if="toPay" :text="t('invoices.home.openInvoicesOutstanding')">
                    <template #amount><span class="num">{{ toPay }}</span></template>
                </Translated>
                <template v-else>{{ t('invoices.home.openInvoices') }}</template>
            </p>
        </template>

        <LoadState :loading="loading && !data" :error="error" @retry="reload">
            <section v-for="section in sections" :key="section.key" :aria-labelledby="`quarter-${section.key}`" class="flex flex-col gap-2">
                <div class="flex items-baseline justify-between px-0.5">
                    <h2 :id="`quarter-${section.key}`" class="m-0 text-13 font-semibold tracking-[0.04em] text-ink-3 uppercase">
                        {{ section.title }}
                    </h2>
                    <span class="num text-13 text-ink-2">{{ section.count }}</span>
                </div>
                <div v-if="section.invoices.length" class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                    <InvoiceRow v-for="invoice in section.invoices" :key="invoice.id" :invoice="invoice" />
                </div>
                <EmptyState v-else :text="t('invoices.home.noInvoicesQuarter')">
                    <RouterLink
                        v-if="can('create', 'invoices') && section.key === sections[0]!.key"
                        :to="{ name: 'invoice-new' }"
                        class="flex min-h-11 items-center text-14 font-medium no-underline"
                    >
                        {{ t('invoices.home.createInvoice') }}
                    </RouterLink>
                </EmptyState>
            </section>

            <RouterLink :to="{ name: 'invoices' }" class="flex min-h-11 items-center self-center text-14 font-medium no-underline">
                {{ t('invoices.home.allInvoices') }}
            </RouterLink>
        </LoadState>
    </TabScreen>
</template>
