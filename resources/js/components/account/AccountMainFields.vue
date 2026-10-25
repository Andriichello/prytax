<script setup lang="ts">
import type { AccountKind } from '@/api/types';
import type { AccountForm } from '@/components/account/AccountFormModel';
import TextField from '@/components/form/TextField.vue';
import { t } from '@/i18n';
import { ACCOUNT_KINDS } from '@/utils/accounts';

/**
 * The main fields of an account: its name, kind and currency, which is fixed once the account exists.
 */
const { isNew, nameError = undefined } = defineProps<{ isNew: boolean; nameError?: string }>();

const form = defineModel<AccountForm>({ required: true });

const KINDS: AccountKind[] = ['platform', 'fop_bank', 'foreign_bank', 'personal'];
const CURRENCIES = ['USD', 'EUR', 'UAH', 'GBP', 'PLN', 'CHF', 'CAD'];

const tile = 'flex min-h-12 items-center justify-center rounded-control px-2 text-center text-15';
</script>

<template>
    <TextField v-model="form.name" :label="t('core.common.name')" :placeholder="t('money.accountForm.namePlaceholder')" :error="nameError" />

    <div class="flex flex-col gap-1.5">
        <span id="account-kind" class="text-13 font-medium text-ink-2">{{ t('money.accountForm.type') }}</span>
        <div role="radiogroup" aria-labelledby="account-kind" class="grid grid-cols-2 gap-2">
            <button
                v-for="kind in KINDS"
                :key="kind"
                type="button"
                role="radio"
                :aria-checked="form.kind === kind ? 'true' : 'false'"
                :class="[tile, form.kind === kind ? 'border-2 border-accent bg-accent-soft font-semibold text-accent-hover' : 'border border-line-input bg-surface']"
                @click="form.kind = kind"
            >
                {{ ACCOUNT_KINDS[kind].label }}
            </button>
        </div>
        <span class="text-12 text-ink-3">{{ t('money.accountForm.fopBankHint') }}</span>
    </div>

    <div class="flex flex-col gap-1.5">
        <label for="account-currency" class="text-13 font-medium text-ink-2">{{ t('core.common.currency') }}</label>
        <select
            id="account-currency"
            v-model="form.currency"
            :disabled="!isNew"
            class="num h-13 w-full appearance-none rounded-control border border-line-input bg-surface px-3.5 text-16 disabled:bg-muted disabled:text-ink-3"
        >
            <option v-for="currency in CURRENCIES" :key="currency" :value="currency">{{ currency }}</option>
        </select>
        <span class="text-12 text-ink-3">{{ t('money.accountForm.currencyHint') }}</span>
    </div>
</template>
