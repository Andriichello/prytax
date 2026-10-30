import type { InvoiceDocumentSettings } from '@/api/types';

/**
 * The fields of a client's form (M-ClientEdit), which its groups edit.
 */
export interface ClientForm {
    name: string;
    abbreviation: string;
    legal_name: string;
    legal_name_uk: string;
    country: string;
    currency: string | null;
    /** The address as the invoices print it, line by line. */
    address: string;
    tax_id: string;
    vat_number: string;
    email: string;
    notes: string;
    /** The client's level of the invoice settings, each null to take the ФОП's. */
    document: InvoiceDocumentSettings;
}

/** The currencies a client's invoices are offered in. */
export const CLIENT_CURRENCIES = ['USD', 'EUR', 'GBP', 'PLN', 'CHF', 'CAD', 'UAH'];
