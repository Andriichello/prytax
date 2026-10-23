import accounting from '@/i18n/en/accounting';
import clients from '@/i18n/en/clients';
import core from '@/i18n/en/core';
import invoices from '@/i18n/en/invoices';
import money from '@/i18n/en/money';
import shell from '@/i18n/en/shell';

/**
 * The English dictionary: Ukrainian texts of the screens mapped to their English, one file per area of the app.
 */
export const DICTIONARIES: Record<string, Record<string, string>> = { core, invoices, money, accounting, clients, shell };

export const EN: Record<string, string> = Object.assign({}, ...Object.values(DICTIONARIES));
