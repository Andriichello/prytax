import { computed, ref, watch } from 'vue';
import { useRoute, useRouter, type LocationQuery } from 'vue-router';
import type { Query } from '@/api/client';
import type { InvoiceStatus } from '@/api/types';
import { t } from '@/i18n';
import { formatDate, monthName, quarterLabel } from '@/utils/dates';

/**
 * The filters of Інвойси, kept in the URL (design §16: "reflected in the URL"): periods as tokens `2026`, `2026-Q4`,
 * `2026-10`, or a range of issue dates, and lists of clients, statuses and currencies.
 */
export interface InvoiceFilters {
    periods: string[];
    from: string;
    to: string;
    clients: number[];
    statuses: InvoiceStatus[];
    currencies: string[];
}

const STATUSES: InvoiceStatus[] = ['draft', 'ready', 'sent', 'paid', 'cancelled'];

function list(value: LocationQuery[string]): string[] {
    const text = Array.isArray(value) ? value.join(',') : value ?? '';

    return text.split(',').map((item) => item.trim()).filter(Boolean);
}

export function emptyFilters(): InvoiceFilters {
    return { periods: [], from: '', to: '', clients: [], statuses: [], currencies: [] };
}

export function filtersFromQuery(query: LocationQuery): InvoiceFilters {
    return {
        periods: list(query.period).filter((token) => /^\d{4}(-Q[1-4]|-\d{2})?$/.test(token)),
        from: typeof query.from === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(query.from) ? query.from : '',
        to: typeof query.to === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(query.to) ? query.to : '',
        clients: list(query.client).map(Number).filter((id) => Number.isInteger(id) && id > 0),
        statuses: list(query.status).filter((status): status is InvoiceStatus => STATUSES.includes(status as InvoiceStatus)),
        currencies: list(query.currency).filter((currency) => /^[A-Z]{3}$/.test(currency)),
    };
}

export function filtersToQuery(filters: InvoiceFilters): Record<string, string> {
    const query: Record<string, string> = {
        period: filters.periods.join(','),
        from: filters.from,
        to: filters.to,
        client: filters.clients.join(','),
        status: filters.statuses.join(','),
        currency: filters.currencies.join(','),
    };

    return Object.fromEntries(Object.entries(query).filter(([, value]) => value !== ''));
}

/**
 * The `filter` parameter of the API for the filters.
 */
export function filtersToApi(filters: InvoiceFilters): Query {
    return {
        period: filters.periods.length ? { in: filters.periods } : undefined,
        issued_at: filters.from || filters.to ? { gte: filters.from || undefined, lte: filters.to || undefined } : undefined,
        client_id: filters.clients.length ? { in: filters.clients } : undefined,
        status: filters.statuses.length ? { in: filters.statuses } : undefined,
        currency: filters.currencies.length ? { in: filters.currencies } : undefined,
    };
}

/**
 * The label of a period token: "2026", "IV кв. 2026", "Жовтень 2026".
 */
export function periodLabel(token: string): string {
    const [year, part] = token.split('-');

    if (!part) {
        return year ?? token;
    }

    return part.startsWith('Q') ? `${quarterLabel(Number(part.slice(1)))} ${year}` : `${monthName(Number(part))} ${year}`;
}

export function countFilters(filters: InvoiceFilters): number {
    return (filters.periods.length || filters.from || filters.to ? 1 : 0)
        + (filters.clients.length ? 1 : 0)
        + (filters.statuses.length ? 1 : 0)
        + (filters.currencies.length ? 1 : 0);
}

export function useInvoiceFilters() {
    const route = useRoute();
    const router = useRouter();

    // The filters follow the URL only while the list is shown: kept alive behind an invoice, it keeps its own
    const listRoute = route.name;
    const filters = ref(filtersFromQuery(route.query));

    watch(() => route.query, (query) => {
        const next = filtersFromQuery(query);

        if (route.name === listRoute && JSON.stringify(next) !== JSON.stringify(filters.value)) {
            filters.value = next;
        }
    });

    const periodSummary = computed(() => {
        const { periods, from, to } = filters.value;

        if (from || to) {
            return `${from ? formatDate(from) : '…'} – ${to ? formatDate(to) : '…'}`;
        }

        if (periods.length === 1) {
            return periodLabel(periods[0]!);
        }

        return periods.length ? t('Період · {count}', { count: periods.length }) : null;
    });

    function apply(next: InvoiceFilters): void {
        void router.replace({ query: filtersToQuery(next) });
    }

    return { filters, periodSummary, apply };
}
