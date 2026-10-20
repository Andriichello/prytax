import type { ContractRate } from '@/api/types';

/**
 * The contract's hourly rate on a date for a work type, like the API's Contract::rateFor(): the latest rate of the
 * type in effect on the date, else the latest general one.
 */
export function rateOn(rates: ContractRate[], date: string, chargeTypeId: number | null): ContractRate | null {
    const effective = rates
        .filter((rate) => rate.effective_from <= date)
        .sort((a, b) => b.effective_from.localeCompare(a.effective_from));

    return (chargeTypeId === null ? undefined : effective.find((rate) => rate.charge_type_id === chargeTypeId))
        ?? effective.find((rate) => rate.charge_type_id === null)
        ?? null;
}
