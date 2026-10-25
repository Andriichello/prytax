import type { Contract } from '@/api/types';
import { parseAmount } from '@/utils/money';
import { today } from '@/utils/dates';

/**
 * The fields of a contract's form (M-ContractEdit), which its groups edit: the main fields, the template of the
 * invoices' item description, and the hourly rates by work type, each from a date.
 */
export interface RateDraft {
    key: number;
    charge_type_id: number | null;
    effective_from: string;
    hourly_rate: string;
}

export interface ContractForm {
    name: string;
    number: string;
    subject: string;
    started_at: string;
    ends_at: string;
    currency: string;
    payment_terms_days: string;
    description_template: string;
    rates: RateDraft[];
}

export type ContractGroup = 'main' | 'item' | 'rates';

export const CONTRACT_CURRENCIES = ['USD', 'EUR', 'GBP', 'PLN', 'CHF', 'CAD', 'UAH'];

let nextKey = 1;

/** A key for a rate, which the list and the sheet tell it by. */
export const rateKey = (): number => nextKey++;

export function contractForm(contract: Contract | null, currency: string): ContractForm {
    return {
        name: contract?.name ?? '',
        number: contract?.number ?? '',
        subject: contract?.subject ?? '',
        started_at: contract?.started_at ?? today(),
        ends_at: contract?.ends_at ?? '',
        currency: contract?.currency ?? currency,
        payment_terms_days: contract ? (contract.payment_terms_days === null ? '' : String(contract.payment_terms_days)) : '14',
        description_template: contract ? contract.description_template ?? '' : 'IT services in accordance to the {name} dated {started_at:F j, Y}',
        rates: (contract?.rates ?? []).map((rate) => ({
            key: rateKey(),
            charge_type_id: rate.charge_type_id,
            effective_from: rate.effective_from,
            hourly_rate: rate.hourly_rate,
        })),
    };
}

/** The fields of a group (or of all of them), as the API takes them. */
export function contractFields(form: ContractForm, group: ContractGroup | 'all'): Record<string, unknown> {
    const parts: Record<ContractGroup, Record<string, unknown>> = {
        main: {
            name: form.name.trim(),
            number: form.number.trim() || null,
            subject: form.subject.trim() || null,
            started_at: form.started_at,
            ends_at: form.ends_at || null,
            currency: form.currency,
            payment_terms_days: form.payment_terms_days === '' ? null : Number(form.payment_terms_days),
        },
        item: { description_template: form.description_template.trim() || null },
        rates: {
            rates: form.rates.map((rate) => ({
                charge_type_id: rate.charge_type_id,
                effective_from: rate.effective_from,
                hourly_rate: parseAmount(rate.hourly_rate) ?? rate.hourly_rate,
            })),
        },
    };

    return group === 'all' ? { ...parts.main, ...parts.item, ...parts.rates } : parts[group];
}
