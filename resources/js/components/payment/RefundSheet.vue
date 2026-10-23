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
import Translated from '@/components/ui/Translated.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { t } from '@/i18n';
import { today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { compareAmounts, parseAmount, subtractAmounts } from '@/utils/money';
import { unpaidStatusOf } from '@/utils/statuses';

/**
 * Повернення клієнту: money of a receipt sent back, from an account in its currency. It lowers the income of the
 * period it is made in, at the NBU rate of its day, or at a rate typed in when the NBU has none. A refund, which
 * leaves the receipt's paid invoice uncovered, returns the invoice to unpaid, and the sheet says so first.
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

/** The status, to which the refund returns the receipt's paid invoice, or null when the invoice stays paid. */
const reopenedStatus = computed(() => {
    const invoice = payment.invoice;
    const refunded = parseAmount(amount.value);

    if (!invoice || invoice.status !== 'paid' || refunded === null || compareAmounts(subtractAmounts(invoice.paid_amount ?? '0', refunded), invoice.total) >= 0) {
        return null;
    }

    return unpaidStatusOf(invoice).label;
});

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
    <BottomSheet v-model:open="open" :title="t('Повернення клієнту')">
        <p class="m-0 text-14 leading-normal text-ink-2">
            {{ t('Повернення зменшує дохід періоду, в якому його зроблено. Кошти йдуть з рахунку в {currency}.', { currency: payment.currency }) }}
        </p>
        <div class="grid grid-cols-2 gap-2.5">
            <TextField v-model="amount" :label="t('Сума, {currency}', { currency: payment.currency })" numeric :error="fieldError(error, 'amount')" />
            <DateField v-model="refundedAt" :label="t('Дата')" :min="payment.received_at" :max="today()" :error="fieldError(error, 'refunded_at')" />
        </div>
        <SelectField
            v-model="fromAccountId"
            :label="t('З рахунку')"
            :placeholder="t('Оберіть рахунок')"
            :options="options"
            :error="fieldError(error, 'from_account_id')"
        />
        <TextField
            v-if="needsRate"
            v-model="nbuRate"
            :label="t('Курс, ₴ за 1 {currency}', { currency: payment.currency })"
            numeric
            placeholder="41.8710"
            :hint="t('Вручну — лише поки НБУ не опублікував курс на цю дату.')"
            :error="fieldError(error, 'nbu_rate')"
        />
        <TextAreaField v-model="reason" :label="t('Причина')" :rows="2" :placeholder="t('Напр., переплата')" />
        <TextAreaField v-if="locked" v-model="correctionNote" :label="t('Квартал уже задекларовано — що уточнюєте?')" :rows="2" />
        <p v-if="reopenedStatus" class="m-0 rounded-button bg-warning-soft p-3.5 text-14 leading-[1.45] text-warning">
            <Translated :text="t('Інвойс {number} більше не буде покрито повністю й повернеться до статусу «{status}».', { status: reopenedStatus })">
                <template #number><span class="num">{{ payment.invoice?.number }}</span></template>
            </Translated>
        </p>
        <p v-if="error && !fieldError(error, 'amount') && !fieldError(error, 'refunded_at') && !fieldError(error, 'from_account_id') && !fieldError(error, 'nbu_rate')" class="m-0 text-14 text-negative" role="alert">
            {{ errorMessage(error) }}
        </p>

        <template #footer>
            <PrimaryButton :busy="saving" :disabled="parseAmount(amount) === null || fromAccountId === null" @click="save">
                {{ t('Записати повернення') }}
            </PrimaryButton>
        </template>
    </BottomSheet>
</template>
