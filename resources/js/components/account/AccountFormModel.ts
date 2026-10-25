import type { AccountKind, LimitPeriod } from '@/api/types';

/**
 * The fields of an account's form (M-AccountEdit), which its groups edit: main, requisites printed on invoices, and
 * the limit.
 */
export interface AccountForm {
    name: string;
    kind: AccountKind;
    currency: string;
    holder_name: string;
    bank_name: string;
    account_number: string;
    swift: string;
    iban: string;
    details: { label: string; value: string }[];
    hasLimit: boolean;
    limit_amount: string;
    limit_period: LimitPeriod;
}
