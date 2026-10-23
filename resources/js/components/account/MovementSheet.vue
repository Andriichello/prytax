<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { api } from '@/api/client';
import type { MovementCategory, MovementDirection } from '@/api/types';
import DateField from '@/components/form/DateField.vue';
import SelectField from '@/components/form/SelectField.vue';
import TextField from '@/components/form/TextField.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { t } from '@/i18n';
import { MOVEMENT_CATEGORIES, type MovementDraft } from '@/utils/accounts';
import { today } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import { parseAmount } from '@/utils/money';

/**
 * A movement of an account, which isn't income or a transfer: a bank fee, interest, an expense or the owner's
 * withdrawal. It only keeps the account's tracked balance in line with the bank, and never counts as income.
 */
const { accountId, currency, movement = null, editable = true } = defineProps<{
    accountId: number;
    currency: string;
    /** The movement to change, or null for a new one. */
    movement?: MovementDraft | null;
    editable?: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [] }>();

const directions = computed<{ value: MovementDirection; label: string }[]>(() => [
    { value: 'out', label: t('Списання') },
    { value: 'in', label: t('Зарахування') },
]);

const { path } = useCurrentProfile();

const form = reactive({
    direction: 'out' as MovementDirection,
    category: 'bank_fee' as MovementCategory,
    amount: '',
    booked_at: today(),
    note: '',
});
const saving = ref(false);
const error = ref<unknown>(null);

/** The categories, which fit the direction: interest comes in, fees, expenses and withdrawals go out. */
const categoryOptions = computed(() => (Object.keys(MOVEMENT_CATEGORIES) as MovementCategory[])
    .filter((category) => MOVEMENT_CATEGORIES[category].directions.includes(form.direction))
    .map((category) => ({ value: category, label: MOVEMENT_CATEGORIES[category].label })));

watch(open, (isOpen) => {
    if (isOpen) {
        Object.assign(form, movement
            ? { direction: movement.direction, category: movement.category, amount: movement.amount, booked_at: movement.booked_at, note: movement.note ?? '' }
            : { direction: 'out', category: 'bank_fee', amount: '', booked_at: today(), note: '' });
        error.value = null;
    }
});

watch(() => form.direction, () => {
    if (!categoryOptions.value.some((option) => option.value === form.category)) {
        form.category = categoryOptions.value[0]?.value ?? 'other';
    }
});

const base = computed(() => path(`accounts/${accountId}/movements`));

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
    const body = {
        direction: form.direction,
        category: form.category,
        amount: parseAmount(form.amount) ?? form.amount,
        booked_at: form.booked_at,
        note: form.note.trim() || null,
    };

    void run(() => (movement ? api.patch(`${base.value}/${movement.id}`, body) : api.post(base.value, body)));
}

const confirmingRemoval = ref(false);

watch(open, () => {
    confirmingRemoval.value = false;
});

/**
 * Delete the movement on the second tap: the first one asks to confirm.
 */
function remove(): void {
    if (!confirmingRemoval.value) {
        confirmingRemoval.value = true;

        return;
    }

    void run(() => api.delete(`${base.value}/${movement?.id}`));
}

const generalError = computed(() => (error.value && !['amount', 'booked_at', 'category', 'direction', 'note'].some((field) => fieldError(error.value, field))
    ? errorMessage(error.value)
    : null));
</script>

<template>
    <BottomSheet v-model:open="open" :title="movement ? t('Рух коштів') : t('Новий рух коштів')">
        <p class="m-0 text-14 leading-normal text-ink-2">
            {{ t('Комісії банку, відсотки, витрати чи зняття — лише для балансу рахунку. Доходом ФОП вони не є.') }}
        </p>
        <SegmentedControl v-model="form.direction" :label="t('Напрям')" size="lg" :options="directions" :disabled="!editable" />
        <SelectField v-model="form.category" :label="t('Що це')" :options="categoryOptions" :disabled="!editable" :error="fieldError(error, 'category')" />
        <div class="grid grid-cols-2 gap-2.5">
            <TextField v-model="form.amount" :label="t('Сума, {currency}', { currency })" numeric :disabled="!editable" :error="fieldError(error, 'amount')" />
            <DateField v-model="form.booked_at" :label="t('Дата')" :max="today()" :disabled="!editable" :error="fieldError(error, 'booked_at')" />
        </div>
        <TextField v-model="form.note" :label="t('Примітка')" :placeholder="t('Напр., обслуговування рахунку')" :disabled="!editable" :error="fieldError(error, 'note')" />
        <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
        <template v-if="editable" #footer>
            <div class="grid gap-2.5" :class="movement ? 'grid-cols-[1fr_2fr]' : 'grid-cols-1'">
                <SecondaryButton v-if="movement" tone="negative" :busy="saving" @click="remove">
                    {{ confirmingRemoval ? t('Так, видалити') : t('Видалити') }}
                </SecondaryButton>
                <PrimaryButton :busy="saving" :disabled="parseAmount(form.amount) === null || !form.booked_at" @click="save">{{ t('Зберегти') }}</PrimaryButton>
            </div>
        </template>
    </BottomSheet>
</template>
