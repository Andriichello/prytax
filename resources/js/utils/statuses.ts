import type { AlertSeverity, DeclarationStatus, InvoiceStatus, TaxPaymentStatus, TaxType } from '@/api/types';

/**
 * The colours of status badges.
 */
export type Tone = 'neutral' | 'info' | 'warning' | 'positive' | 'negative';

export interface StatusLabel {
    label: string;
    tone: Tone;
}

export const INVOICE_STATUSES: Record<InvoiceStatus, StatusLabel> = {
    draft: { label: 'Чернетка', tone: 'neutral' },
    ready: { label: 'Готовий', tone: 'info' },
    sent: { label: 'Надіслано', tone: 'warning' },
    paid: { label: 'Оплачено', tone: 'positive' },
    cancelled: { label: 'Скасовано', tone: 'negative' },
};

export const ALERT_SEVERITIES: Record<AlertSeverity, StatusLabel> = {
    info: { label: 'Інфо', tone: 'info' },
    warning: { label: 'Увага', tone: 'warning' },
    error: { label: 'Терміново', tone: 'negative' },
};

export const DECLARATION_STATUSES: Record<DeclarationStatus, StatusLabel> = {
    none: { label: 'Не подано', tone: 'neutral' },
    filled: { label: 'Заповнено', tone: 'info' },
    submitted: { label: 'Подано', tone: 'warning' },
    accepted: { label: 'Прийнято', tone: 'positive' },
    rejected: { label: 'Відхилено', tone: 'negative' },
    /** A write into the declared quarter (or an earlier one of the year) needs an уточнююча декларація. */
    corrected: { label: 'Потрібна уточнююча', tone: 'warning' },
};

export const TAX_PAYMENT_STATUSES: Record<TaxPaymentStatus, StatusLabel> = {
    sent: { label: 'Надіслано', tone: 'warning' },
    paid: { label: 'Сплачено', tone: 'positive' },
    failed: { label: 'Не пройшов', tone: 'negative' },
};

/**
 * The short names of the taxes: ЄП (єдиний податок), ВЗ (військовий збір), ЄСВ.
 */
export const TAX_TYPES: Record<TaxType, string> = {
    single_tax: 'ЄП',
    military_levy: 'ВЗ',
    social_contribution: 'ЄСВ',
};
