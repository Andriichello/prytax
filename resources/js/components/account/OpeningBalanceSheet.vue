<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { api } from '@/api/client';
import type { AccountOpeningBalance } from '@/api/types';
import DateField from '@/components/form/DateField.vue';
import TextField from '@/components/form/TextField.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { t } from '@/i18n';
import { today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { parseAmount } from '@/utils/money';

/**
 * Залишок на дату: what the account held on a day, from which its tracked balance is counted (the latest one counts),
 * for an account opened before its records in PryTax. It may be negative.
 */
const { accountId, currency, balance = null } = defineProps<{
    accountId: number;
    currency: string;
    /** The opening balance to change, or null for a new one. */
    balance?: AccountOpeningBalance | null;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [] }>();

const { path } = useCurrentProfile();

const form = reactive({ as_of: today(), amount: '' });
const saving = ref(false);
const error = ref<unknown>(null);
const confirmingRemoval = ref(false);

watch(open, (isOpen) => {
    if (isOpen) {
        Object.assign(form, balance ? { as_of: balance.as_of, amount: balance.amount } : { as_of: today(), amount: '' });
        error.value = null;
        confirmingRemoval.value = false;
    }
});

const base = computed(() => path(`accounts/${accountId}/opening-balances`));

async function run(action: () => Promise<unknown>): Promise<void> {
    saving.value = true;
    error.value = null;

    try {
        await action();
        open.value = false;
        emit('saved');
    } catch (caught) {
        error.value = caught;
    } finally {
        saving.value = false;
    }
}

function save(): void {
    const body = { as_of: form.as_of, amount: parseAmount(form.amount) ?? form.amount };

    void run(() => (balance ? api.patch(`${base.value}/${balance.id}`, body) : api.post(base.value, body)));
}

/**
 * Delete the opening balance on the second tap: the first one asks to confirm.
 */
function remove(): void {
    if (!confirmingRemoval.value) {
        confirmingRemoval.value = true;

        return;
    }

    void run(() => api.delete(`${base.value}/${balance?.id}`));
}

const generalError = computed(() => (error.value && !fieldError(error.value, 'as_of') && !fieldError(error.value, 'amount')
    ? errorMessage(error.value)
    : null));
</script>

<template>
    <BottomSheet v-model:open="open" :title="t('Залишок на дату')">
        <p class="m-0 text-14 leading-normal text-ink-2">
            {{ t('Скільки було на рахунку того дня. Баланс рахується від останнього такого залишку.') }}
        </p>
        <div class="grid grid-cols-2 gap-2.5">
            <DateField v-model="form.as_of" :label="t('На дату')" :max="today()" :error="fieldError(error, 'as_of')" />
            <TextField v-model="form.amount" :label="t('Сума, {currency}', { currency })" numeric placeholder="0.00" :error="fieldError(error, 'amount')" />
        </div>
        <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
        <template #footer>
            <div class="grid gap-2.5" :class="balance ? 'grid-cols-[1fr_2fr]' : 'grid-cols-1'">
                <SecondaryButton v-if="balance" tone="negative" :busy="saving" @click="remove">
                    {{ confirmingRemoval ? t('Так, видалити') : t('Видалити') }}
                </SecondaryButton>
                <PrimaryButton :busy="saving" :disabled="parseAmount(form.amount) === null || !form.as_of" @click="save">{{ t('Зберегти') }}</PrimaryButton>
            </div>
        </template>
    </BottomSheet>
</template>
