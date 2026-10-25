<script setup lang="ts">
import type { AccountForm } from '@/components/account/AccountFormModel';
import TextField from '@/components/form/TextField.vue';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';

/**
 * The requisites of an account, which invoices print: the holder, the bank, the numbers (IBAN and the account number
 * are stored encrypted) and fields of its own, such as a routing number.
 */
const { errors = {} } = defineProps<{ errors?: { account_number?: string; swift?: string; iban?: string } }>();

const form = defineModel<AccountForm>({ required: true });
</script>

<template>
    <TextField v-model="form.holder_name" :label="t('money.accountForm.holder')" :placeholder="t('money.accountForm.holderHint')" />
    <TextField v-model="form.bank_name" :label="t('money.account.bank')" />
    <div class="grid grid-cols-2 gap-2.5">
        <TextField v-model="form.account_number" :label="t('money.accountForm.accountNumber')" class="num" :error="errors.account_number" />
        <TextField v-model="form.swift" label="SWIFT" class="num" placeholder="—" :error="errors.swift" />
    </div>
    <TextField
        v-model="form.iban"
        label="IBAN"
        class="num"
        :placeholder="t('money.accountForm.none')"
        :hint="t('money.accountForm.encryptedNote')"
        :error="errors.iban"
    />
    <div class="flex flex-col divide-y divide-divider rounded-control border border-line bg-surface px-3.5">
        <div v-for="(detail, index) in form.details" :key="index" class="flex items-center gap-2 py-2">
            <input v-model="detail.label" :aria-label="t('money.accountForm.fieldName')" placeholder="Routing (ABA)" class="h-11 min-w-0 flex-1 rounded-control bg-paper px-3 text-15">
            <input v-model="detail.value" :aria-label="t('money.accountForm.value')" class="num h-11 min-w-0 flex-1 rounded-control bg-paper px-3 text-right text-15">
            <button type="button" :aria-label="t('money.accountForm.removeField')" class="flex size-11 shrink-0 items-center justify-center text-ink-3" @click="form.details.splice(index, 1)">
                <Icon name="close" :size="18" />
            </button>
        </div>
        <button type="button" class="flex min-h-12 items-center text-15 font-medium text-accent" @click="form.details.push({ label: '', value: '' })">
            {{ t('money.accountForm.addField') }}
        </button>
    </div>
</template>
