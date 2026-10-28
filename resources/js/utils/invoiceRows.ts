import type { Invoice } from '@/api/types';
import { t } from '@/i18n';
import { today } from '@/utils/dates';
import { compareAmounts } from '@/utils/money';

/**
 * What the rows of Головна and Інвойси show of an invoice (design.md §16.5, §17.4), the mobile rows and the desktop
 * tables alike.
 */

/** A draft's number: an external one's own, a number typed by hand, else the preview the freeze would give. */
export function invoiceRowNumber(invoice: Invoice): string | null {
    if (invoice.status !== 'draft') {
        return invoice.number;
    }

    if (invoice.source === 'external') {
        return invoice.number ?? t('invoices.list.noNumber');
    }

    return invoice.number !== null && invoice.sequence_number === null
        ? invoice.number
        : t('invoices.list.nextNumber', { number: invoice.number_preview ?? invoice.number ?? '…' });
}

/** Whether a ready or sent invoice is unpaid past its due date. */
export function isOverdue(invoice: Invoice): boolean {
    const open = invoice.status === 'ready' || invoice.status === 'sent';

    return open && invoice.due_at !== null && invoice.due_at < today() && compareAmounts(invoice.paid_amount ?? '0.00', invoice.total) < 0;
}

/** Where a row leads: a draft to its editor, any other invoice to its page. */
export function invoiceRowLink(invoice: Invoice): { name: string; params: { id: number } } {
    return invoice.status === 'draft'
        ? { name: 'invoice-edit', params: { id: invoice.id } }
        : { name: 'invoice', params: { id: invoice.id } };
}
