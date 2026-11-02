import type { AccountRequisiteSet, RequisiteField } from '@/api/types';
import { orderedRequisites, REQUISITE_FIELDS, type Requisite, requisiteOrder } from '@/utils/accounts';

/**
 * A row of a set's form: a field (the receiver, the bank, IBAN, the account number, SWIFT / BIC), whose label is its
 * own («Beneficiary name»; '' prints the invoice's language's), or one of its own (label and value), in the order the
 * invoices print them, each printed or not.
 */
export type RequisiteRow = Requisite;

/**
 * The fields of a set of an account's requisites, as its form edits them (and a new account's form its first set):
 * its name, printed in an invoice's parentheses, and its rows.
 */
export interface RequisiteSetForm {
    name: string;
    rows: RequisiteRow[];
}

let nextKey = 1;

/** A row of one's own, empty. */
export function ownRow(): RequisiteRow {
    return { key: `own-${nextKey++}`, field: null, label: '', value: '', shown: true };
}

/** An empty set: every field, in the built-in order, each printed. */
export function blankRequisiteSet(): RequisiteSetForm {
    return {
        name: '',
        rows: (Object.keys(REQUISITE_FIELDS) as RequisiteField[]).map((field) => ({ key: field, field, label: '', value: '', shown: true })),
    };
}

/** The form of a stored set: every field (those without a value too) and its own, in the order invoices print them. */
export function requisiteSetFormOf(set: AccountRequisiteSet): RequisiteSetForm {
    return {
        name: set.name ?? '',
        rows: orderedRequisites(set, { empty: true }).map((row) => ({ ...row, key: row.field ?? `own-${nextKey++}` })),
    };
}

/**
 * The set's fields, as the API takes them: the fields' values (blank as null; IBAN and the code upper case without
 * spaces), its own rows with a label and a value, the labels it gave its fields, and the order of them all.
 */
export function requisiteSetBody(form: RequisiteSetForm): Record<string, unknown> {
    const compact = (value: string): string | null => value.replace(/\s/g, '').toUpperCase() || null;
    const rows = form.rows
        .map((row) => ({ ...row, label: row.label.trim(), value: row.value.trim() }))
        .filter((row) => row.field !== null || (row.label && row.value));
    const values = Object.fromEntries((Object.keys(REQUISITE_FIELDS) as RequisiteField[]).map((field) => {
        const value = rows.find((row) => row.field === field)?.value ?? '';

        return [field, field === 'iban' || field === 'swift' ? compact(value) : value || null];
    }));
    const labels = Object.fromEntries(rows.filter((row) => row.field !== null && row.label).map((row) => [row.field, row.label]));

    return {
        name: form.name.trim() || null,
        ...values,
        details: rows.filter((row) => row.field === null).map((row) => ({ label: row.label, value: row.value })),
        labels: Object.keys(labels).length ? labels : null,
        invoice_requisites: requisiteOrder(rows),
    };
}

/** Whether the set holds no requisite: a new account without requisites gets no set. */
export function isBlankRequisiteSet(form: RequisiteSetForm): boolean {
    return form.rows.every((row) => !row.value.trim());
}
