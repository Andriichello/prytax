import type { AccountKind, LimitPeriod } from '@/api/types';
import type { RequisiteSetForm } from '@/components/account/RequisiteSetFormModel';

/**
 * The fields of an account's form (M-AccountEdit), which its groups edit: main and the limit, and a new account's
 * first set of requisites printed on invoices.
 */
export interface AccountForm {
    name: string;
    kind: AccountKind;
    currency: string;
    requisites: RequisiteSetForm;
    hasLimit: boolean;
    limit_amount: string;
    limit_period: LimitPeriod;
}
