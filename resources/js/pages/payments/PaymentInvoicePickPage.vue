<script setup lang="ts">
import { computed, ref } from 'vue';
import { api } from '@/api/client';
import type { Invoice } from '@/api/types';
import VirtualMarker from '@/components/invoice/VirtualMarker.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { t } from '@/i18n';
import { today } from '@/utils/dates';
import { formatMoney, subtractAmounts } from '@/utils/money';

/**
 * Інвойс для надходження (M-VPickInvoice, design.md §16.5): the first step of a receipt from Облік. The open invoices
 * (ready and sent), found by their number or client, each with what is left of it, or «Без інвойсу», a payment
 * under the contract, which is recorded with a virtual invoice (§4.2).
 */
const { profileId, path } = useCurrentProfile();

const invoices = ref<Invoice[]>([]);
const search = ref('');

const { error, loading, reload } = useLoader(async (signal) => {
    invoices.value = await api.all<Invoice>(path('invoices'), {
        filter: { status: { in: ['ready', 'sent'] } },
        include: ['client'],
        sort: '-issued_at,-id',
    }, signal);

    return true;
}, [profileId]);

const shown = computed(() => {
    const query = search.value.trim().toLowerCase();

    return query === ''
        ? invoices.value
        : invoices.value.filter((invoice) => `${invoice.number ?? ''} ${invoice.client?.name ?? ''}`.toLowerCase().includes(query));
});

const isOverdue = (invoice: Invoice): boolean => invoice.due_at !== null && invoice.due_at < today();
const remainderOf = (invoice: Invoice): string => formatMoney(subtractAmounts(invoice.total, invoice.paid_amount ?? '0'), invoice.currency);
</script>

<template>
    <SubScreen :title="t('money.invoicePicker.title')" closable :back="{ name: 'accounting' }">
        <div class="relative">
            <label for="receipt-invoice-search" class="sr-only">{{ t('money.invoicePicker.search') }}</label>
            <Icon name="search" :size="20" class="pointer-events-none absolute top-3.5 left-3.5 text-ink-3" />
            <input
                id="receipt-invoice-search"
                v-model="search"
                type="search"
                :placeholder="t('money.invoicePicker.search')"
                class="h-12 w-full rounded-control border border-line-input bg-surface pr-3.5 pl-11 text-16 placeholder:text-ink-3"
            >
        </div>

        <LoadState :loading="loading && invoices.length === 0" :error="error" @retry="reload">
            <section class="flex flex-col gap-2.5">
                <SectionTitle>{{ t('money.invoicePicker.openInvoices') }}</SectionTitle>
                <RouterLink
                    v-for="invoice in shown"
                    :key="invoice.id"
                    :to="{ name: 'payment-new', query: { invoice: invoice.id } }"
                    class="flex flex-col gap-1 rounded-card border border-line bg-surface px-3.5 py-3 text-ink no-underline hover:text-ink"
                >
                    <span class="num text-16 font-semibold">{{ invoice.number }}</span>
                    <span class="flex items-baseline justify-between gap-3">
                        <span class="min-w-0 truncate text-14 text-ink-2">{{ invoice.client?.name }}</span>
                        <span v-if="isOverdue(invoice)" class="num shrink-0 text-13 text-negative">
                            {{ t('money.invoicePicker.overdueAmount', { amount: remainderOf(invoice) }) }}
                        </span>
                        <span v-else class="num shrink-0 text-13 text-ink-2">
                            {{ t('money.invoicePicker.remainder', { amount: remainderOf(invoice) }) }}
                        </span>
                    </span>
                </RouterLink>
                <EmptyState
                    v-if="shown.length === 0"
                    :text="invoices.length ? t('money.invoicePicker.nothingFound') : t('money.invoicePicker.noOpenInvoices')"
                />
            </section>

            <div class="flex items-center gap-3 text-14 text-ink-3" role="separator">
                <span class="h-px flex-1 bg-line" />
                {{ t('money.invoicePicker.or') }}
                <span class="h-px flex-1 bg-line" />
            </div>

            <RouterLink
                :to="{ name: 'payment-new', query: { without: 1 } }"
                class="flex items-start gap-3 rounded-card border border-dashed border-ink-3 bg-surface px-3.5 py-3.5 text-ink no-underline hover:text-ink"
            >
                <span class="flex min-w-0 flex-1 flex-col gap-1.5">
                    <VirtualMarker />
                    <span class="text-16 font-semibold">{{ t('money.invoicePicker.withoutTitle') }}</span>
                    <span class="text-14 leading-[1.45] text-ink-2">{{ t('money.invoicePicker.withoutExplained') }}</span>
                </span>
                <Icon name="chevronRight" :size="20" class="mt-0.5 text-ink-3" />
            </RouterLink>
        </LoadState>
    </SubScreen>
</template>
