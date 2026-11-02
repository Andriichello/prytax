import type { AccountRequisiteSet } from '@/api/types';

/**
 * The fields of a set of an account's requisites, as its form edits them (and a new account's form its first set):
 * its name, printed in an invoice's parentheses, and the requisites.
 */
export interface RequisiteSetForm {
    name: string;
    holder_name: string;
    bank_name: string;
    account_number: string;
    iban: string;
    swift: string;
    bic: string;
    details: { label: string; value: string }[];
}

/** An empty set. */
export function blankRequisiteSet(): RequisiteSetForm {
    return { name: '', holder_name: '', bank_name: '', account_number: '', iban: '', swift: '', bic: '', details: [] };
}

/** The form of a stored set. */
export function requisiteSetFormOf(set: AccountRequisiteSet): RequisiteSetForm {
    return {
        name: set.name ?? '',
        holder_name: set.holder_name ?? '',
        bank_name: set.bank_name ?? '',
        account_number: set.account_number ?? '',
        iban: set.iban ?? '',
        swift: set.swift ?? '',
        bic: set.bic ?? '',
        details: (set.details ?? []).map((detail) => ({ ...detail })),
    };
}

/** The set's fields, as the API takes them: blanks as null, the codes upper case without spaces, filled details only. */
export function requisiteSetBody(form: RequisiteSetForm): Record<string, unknown> {
    const compact = (value: string): string | null => value.replace(/\s/g, '').toUpperCase() || null;

    return {
        name: form.name.trim() || null,
        holder_name: form.holder_name.trim() || null,
        bank_name: form.bank_name.trim() || null,
        account_number: form.account_number.trim() || null,
        iban: compact(form.iban),
        swift: compact(form.swift),
        bic: compact(form.bic),
        details: form.details.filter((detail) => detail.label.trim() && detail.value.trim())
            .map((detail) => ({ label: detail.label.trim(), value: detail.value.trim() })),
    };
}

/** Whether the set holds no requisite: a new account without requisites gets no set. */
export function isBlankRequisiteSet(form: RequisiteSetForm): boolean {
    const body = requisiteSetBody(form);

    return Object.entries(body).every(([key, value]) => key === 'name' || value === null || (Array.isArray(value) && value.length === 0));
}
