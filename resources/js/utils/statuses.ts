import type { AlertSeverity, DeclarationStatus, Invoice, InvoiceStatus, TaxPaymentStatus, TaxType } from '@/api/types';
import { t } from '@/i18n';
import { formatDayMonth } from '@/utils/dates';

/**
 * The colours of status badges. The labels are getters, which translate when they are read, so they follow the
 * language of the screens.
 */
export type Tone = 'neutral' | 'info' | 'warning' | 'positive' | 'negative';

export interface StatusLabel {
    label: string;
    tone: Tone;
}

export const INVOICE_STATUSES: Record<InvoiceStatus, StatusLabel> = {
    draft: { get label() { return t('core.invoiceStatus.draft'); }, tone: 'neutral' },
    ready: { get label() { return t('core.invoiceStatus.ready'); }, tone: 'info' },
    sent: { get label() { return t('core.invoiceStatus.sent'); }, tone: 'warning' },
    paid: { get label() { return t('core.invoiceStatus.paid'); }, tone: 'positive' },
    cancelled: { get label() { return t('core.invoiceStatus.cancelled'); }, tone: 'negative' },
};

/**
 * An invoice's status with the date it took it, as the lists show it: «Надіслано 15.10», «Оплачено 24.10»,
 * «Скасовано 20.08»; a draft and a ready invoice have no date.
 */
export function datedInvoiceStatus(invoice: Pick<Invoice, 'status' | 'sent_at' | 'paid_at' | 'cancelled_at'>): StatusLabel {
    const status = INVOICE_STATUSES[invoice.status];
    const date = { sent: invoice.sent_at, paid: invoice.paid_at, cancelled: invoice.cancelled_at }[invoice.status as 'sent' | 'paid' | 'cancelled'];

    return date ? { label: `${status.label} ${formatDayMonth(date)}`, tone: status.tone } : status;
}

/**
 * The status, to which a paid invoice returns once its receipts no longer cover it: sent, or ready when it was paid
 * without being sent.
 */
export function unpaidStatusOf(invoice: Pick<Invoice, 'sent_at'>): StatusLabel {
    return invoice.sent_at ? INVOICE_STATUSES.sent : INVOICE_STATUSES.ready;
}

export const ALERT_SEVERITIES: Record<AlertSeverity, StatusLabel> = {
    info: { get label() { return t('core.alertSeverity.info'); }, tone: 'info' },
    warning: { get label() { return t('core.alertSeverity.warning'); }, tone: 'warning' },
    error: { get label() { return t('core.alertSeverity.error'); }, tone: 'negative' },
};

export const DECLARATION_STATUSES: Record<DeclarationStatus, StatusLabel> = {
    none: { get label() { return t('core.declarationStatus.none'); }, tone: 'neutral' },
    filled: { get label() { return t('core.declarationStatus.filled'); }, tone: 'info' },
    submitted: { get label() { return t('core.declarationStatus.submitted'); }, tone: 'warning' },
    accepted: { get label() { return t('core.declarationStatus.accepted'); }, tone: 'positive' },
    rejected: { get label() { return t('core.declarationStatus.rejected'); }, tone: 'negative' },
    /** A write into the declared quarter (or an earlier one of the year) needs an уточнююча декларація. */
    corrected: { get label() { return t('core.declarationStatus.corrected'); }, tone: 'warning' },
};

export const TAX_PAYMENT_STATUSES: Record<TaxPaymentStatus, StatusLabel> = {
    sent: { get label() { return t('core.taxPaymentStatus.sent'); }, tone: 'warning' },
    paid: { get label() { return t('core.taxPaymentStatus.paid'); }, tone: 'positive' },
    failed: { get label() { return t('core.taxPaymentStatus.failed'); }, tone: 'negative' },
};

/**
 * The short names of the taxes: ЄП (єдиний податок), ВЗ (військовий збір) and ЄСВ, which English keeps as they are
 * (design.md §16.5).
 */
export const TAX_TYPES: Record<TaxType, string> = {
    get single_tax() {
        return t('core.taxes.singleTax');
    },
    get military_levy() {
        return t('core.taxes.militaryLevy');
    },
    get social_contribution() {
        return t('core.taxes.socialContribution');
    },
};
