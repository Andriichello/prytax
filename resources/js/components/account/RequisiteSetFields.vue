<script setup lang="ts">
import { ownRow, type RequisiteSetForm } from '@/components/account/RequisiteSetFormModel';
import Icon from '@/components/ui/Icon.vue';
import ToggleSwitch from '@/components/ui/ToggleSwitch.vue';
import { t } from '@/i18n';
import { REQUISITE_FIELDS } from '@/utils/accounts';

/**
 * The requisites of a set of an account's, in the order invoices print them: each a row of its label and its value,
 * moved up or down, and printed or not. A field (the receiver, the bank, IBAN and the account number, stored
 * encrypted, SWIFT / BIC) keeps what it is, so the app knows the receiver, while its label is the owner's to print
 * («Beneficiary name»; empty, the invoice's language's); fields of one's own, such as a routing number or the
 * receiver's address, are added and removed.
 */
const { errors = {} } = defineProps<{ errors?: Partial<Record<'holder_name' | 'bank_name' | 'iban' | 'account_number' | 'swift', string>> }>();

const form = defineModel<RequisiteSetForm>({ required: true });

/** What a row is: its field's name on the screens, or «Власне поле». */
const nameOf = (index: number): string => {
    const row = form.value.rows[index]!;

    return row.field ? REQUISITE_FIELDS[row.field].label : row.label.trim() || t('money.requisiteSets.ownField');
};

function move(index: number, by: -1 | 1): void {
    const [row] = form.value.rows.splice(index, 1);

    form.value.rows.splice(index + by, 0, row!);
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <p class="m-0 text-13 leading-normal text-ink-3">{{ t('money.requisiteSets.rowsHint') }}</p>
        <div class="flex flex-col divide-y divide-divider rounded-control border border-line bg-surface px-3.5">
            <div v-for="(row, index) in form.rows" :key="row.key" class="flex flex-col gap-2 py-2.5">
                <div class="flex items-center gap-1">
                    <span class="min-w-0 flex-1 truncate text-13 font-medium" :class="row.shown ? 'text-ink-2' : 'text-ink-4'">
                        {{ row.field ? REQUISITE_FIELDS[row.field].label : t('money.requisiteSets.ownField') }}
                    </span>
                    <button
                        type="button"
                        class="flex size-10 shrink-0 items-center justify-center text-ink-2 disabled:text-ink-4"
                        :aria-label="t('money.account.moveUp', { label: nameOf(index) })"
                        :disabled="index === 0"
                        @click="move(index, -1)"
                    >
                        <Icon name="chevronUp" :size="18" />
                    </button>
                    <button
                        type="button"
                        class="flex size-10 shrink-0 items-center justify-center text-ink-2 disabled:text-ink-4"
                        :aria-label="t('money.account.moveDown', { label: nameOf(index) })"
                        :disabled="index === form.rows.length - 1"
                        @click="move(index, 1)"
                    >
                        <Icon name="chevronDown" :size="18" />
                    </button>
                    <ToggleSwitch v-model="row.shown" class="mx-1" :label="t('money.account.printOnInvoices', { label: nameOf(index) })" />
                    <button
                        v-if="!row.field"
                        type="button"
                        :aria-label="t('money.accountForm.removeField')"
                        class="flex size-10 shrink-0 items-center justify-center text-ink-3"
                        @click="form.rows.splice(index, 1)"
                    >
                        <Icon name="close" :size="18" />
                    </button>
                </div>
                <div class="grid items-center gap-1.5 transition-opacity sm:grid-cols-[2fr_3fr] sm:gap-2" :class="row.shown ? '' : 'opacity-50'">
                    <input
                        v-model="row.label"
                        :aria-label="t('money.requisiteSets.labelOf', { label: nameOf(index) })"
                        :placeholder="row.field ? t('money.requisiteSets.labelDefault') : t('money.accountForm.fieldName')"
                        class="h-9 min-w-0 rounded-control bg-paper px-3 text-13 text-ink-2"
                    >
                    <input
                        v-model="row.value"
                        :aria-label="t('money.requisiteSets.valueOf', { label: nameOf(index) })"
                        :placeholder="row.field === 'holder_name' ? t('money.accountForm.holderHint') : '—'"
                        class="h-11 min-w-0 rounded-control bg-paper px-3 text-15"
                        :class="!row.field || REQUISITE_FIELDS[row.field].figure ? 'num' : ''"
                    >
                </div>
                <p v-if="row.field && errors[row.field]" class="m-0 text-13 text-negative" role="alert">{{ errors[row.field] }}</p>
            </div>
            <button type="button" class="flex min-h-12 items-center text-15 font-medium text-accent" @click="form.rows.push(ownRow())">
                {{ t('money.accountForm.addField') }}
            </button>
        </div>
        <p class="m-0 text-12 text-ink-3">{{ t('money.accountForm.encryptedNote') }}</p>
    </div>
</template>
