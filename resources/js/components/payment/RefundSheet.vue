<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ApiError, api, newIdempotencyKey } from '@/api/client';
import type { Account, Payment } from '@/api/types';
import DateField from '@/components/form/DateField.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextAreaField from '@/components/form/TextAreaField.vue';
import TextField from '@/components/form/TextField.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { parseAmount } from '@/utils/money';

/**
 * Повернення клієнту: money of a receipt sent back, from an account in its currency. It lowers the income of the
 * period it is made in, at the NBU rate of its day, or at a rate typed in when the NBU has none.
 */
const { payment, accounts } = defineProps<{
    payment: Payment;
    accounts: Account[];
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [] }>();

const { path } = useCurrentProfile();

const amount = ref('');
const refundedAt = ref(today());
const fromAccountId = ref<number | null>(null);
const reason = ref('');
const correctionNote = ref('');
const nbuRate = ref('');
const saving = ref(false);
const error = ref<unknown>(null);
let idempotencyKey = newIdempotencyKey();

const options = computed(() => accounts
    .filter((account) => account.currency === payment.currency && account.is_active)
    .map((account) => ({ value: account.id, label: account.name })));

watch(open, (isOpen) => {
    if (isOpen) {
        amount.value = '';
        refundedAt.value = today();
        fromAccountId.value = options.value.some((option) => option.value === payment.received_to_account_id)
            ? payment.received_to_account_id
            : options.value[0]?.value ?? null;
        reason.value = '';
        correctionNote.value = '';
        nbuRate.value = '';
        error.value = null;
        locked.value = false;
        idempotencyKey = newIdempotencyKey();
    }
});

/** A declared quarter answers 409: the refund then needs a correction note. */
const locked = ref(false);

/** The NBU has no rate of the day: the sheet asks for one. */
const needsRate = computed(() => !!fieldError(error.value, 'nbu_rate') || nbuRate.value !== '');

watch(error, (caught) => {
    locked.value ||= caught instanceof ApiError && caught.status === 409;
});

async function save(): Promise<void> {
    saving.value = true;
    error.value = null;

    try {
        await api.post(path(`payments/${payment.id}/refunds`), {
            amount: parseAmount(amount.value) ?? amount.value,
            refunded_at: refundedAt.value,
            from_account_id: fromAccountId.value,
            reason: reason.value.trim() || null,
            nbu_rate: parseAmount(nbuRate.value),
            correction_note: correctionNote.value.trim() || null,
        }, { 'Idempotency-Key': idempotencyKey });
        open.value = false;
        emit('saved');
    } catch (caught) {
        error.value = caught;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <BottomSheet v-model:open="open" title="Повернення клієнту">
        <p class="m-0 text-14 leading-normal text-ink-2">
            Повернення зменшує дохід періоду, в якому його зроблено. Кошти йдуть з рахунку в {{ payment.currency }}.
        </p>
        <div class="grid grid-cols-2 gap-2.5">
            <TextField v-model="amount" :label="`Сума, ${payment.currency}`" numeric :error="fieldError(error, 'amount')" />
            <DateField v-model="refundedAt" label="Дата" :min="payment.received_at" :max="today()" :error="fieldError(error, 'refunded_at')" />
        </div>
        <SelectField
            v-model="fromAccountId"
            label="З рахунку"
            placeholder="Оберіть рахунок"
            :options="options"
            :error="fieldError(error, 'from_account_id')"
        />
        <TextField
            v-if="needsRate"
            v-model="nbuRate"
            :label="`Курс, ₴ за 1 ${payment.currency}`"
            numeric
            placeholder="41.8710"
            hint="Вручну — лише поки НБУ не опублікував курс на цю дату."
            :error="fieldError(error, 'nbu_rate')"
        />
        <TextAreaField v-model="reason" label="Причина" :rows="2" placeholder="Напр., переплата" />
        <TextAreaField v-if="locked" v-model="correctionNote" label="Квартал уже задекларовано — що уточнюєте?" :rows="2" />
        <p v-if="error && !fieldError(error, 'amount') && !fieldError(error, 'refunded_at') && !fieldError(error, 'from_account_id') && !fieldError(error, 'nbu_rate')" class="m-0 text-14 text-negative" role="alert">
            {{ errorMessage(error) }}
        </p>

        <template #footer>
            <PrimaryButton :busy="saving" :disabled="parseAmount(amount) === null || fromAccountId === null" @click="save">
                Записати повернення
            </PrimaryButton>
        </template>
    </BottomSheet>
</template>
