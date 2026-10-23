import type { AlertSeverity, DeclarationStatus, Invoice, InvoiceStatus, TaxPaymentStatus, TaxType } from '@/api/types';
import { t } from '@/i18n';

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
    draft: { get label() { return t('Чернетка'); }, tone: 'neutral' },
    ready: { get label() { return t('Готовий'); }, tone: 'info' },
    sent: { get label() { return t('Надіслано'); }, tone: 'warning' },
    paid: { get label() { return t('Оплачено'); }, tone: 'positive' },
    cancelled: { get label() { return t('Скасовано'); }, tone: 'negative' },
};

/**
 * The status, to which a paid invoice returns once its receipts no longer cover it: sent, or ready when it was paid
 * without being sent.
 */
export function unpaidStatusOf(invoice: Pick<Invoice, 'sent_at'>): StatusLabel {
    return invoice.sent_at ? INVOICE_STATUSES.sent : INVOICE_STATUSES.ready;
}

export const ALERT_SEVERITIES: Record<AlertSeverity, StatusLabel> = {
    info: { get label() { return t('Інфо'); }, tone: 'info' },
    warning: { get label() { return t('Увага'); }, tone: 'warning' },
    error: { get label() { return t('Терміново'); }, tone: 'negative' },
};

export const DECLARATION_STATUSES: Record<DeclarationStatus, StatusLabel> = {
    none: { get label() { return t('Не подано'); }, tone: 'neutral' },
    filled: { get label() { return t('Заповнено'); }, tone: 'info' },
    submitted: { get label() { return t('Подано'); }, tone: 'warning' },
    accepted: { get label() { return t('Прийнято'); }, tone: 'positive' },
    rejected: { get label() { return t('Відхилено'); }, tone: 'negative' },
    /** A write into the declared quarter (or an earlier one of the year) needs an уточнююча декларація. */
    corrected: { get label() { return t('Потрібна уточнююча'); }, tone: 'warning' },
};

export const TAX_PAYMENT_STATUSES: Record<TaxPaymentStatus, StatusLabel> = {
    sent: { get label() { return t('Надіслано'); }, tone: 'warning' },
    paid: { get label() { return t('Сплачено'); }, tone: 'positive' },
    failed: { get label() { return t('Не пройшов'); }, tone: 'negative' },
};

/**
 * The short names of the taxes: ЄП (єдиний податок), ВЗ (військовий збір), ЄСВ — in English "Single tax", "Military
 * levy" and "SSC" (the single social contribution).
 */
export const TAX_TYPES: Record<TaxType, string> = {
    get single_tax() {
        return t('ЄП');
    },
    get military_levy() {
        return t('ВЗ');
    },
    get social_contribution() {
        return t('ЄСВ');
    },
};
