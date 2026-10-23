<script setup lang="ts">
import { computed } from 'vue';
import type { Invoice } from '@/api/types';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import { t } from '@/i18n';
import { formatPeriod } from '@/utils/dates';
import { formatMoney } from '@/utils/money';

/**
 * "Зафіксувати інвойс?" (S-InvoiceFreeze): what the invoice becomes, and what freezing does — the number (the one the
 * freeze gives: a draft frozen before may get a new date in it), the snapshot, the PDF — and that it can go back to
 * its draft until money arrives.
 */
const { invoice, busy = false, error = null, counterScope = 'per_client' } = defineProps<{
    invoice: Invoice;
    busy?: boolean;
    error?: string | null;
    /** The profile's numbering scope: one counter per ФОП (`global`) or per client. */
    counterScope?: string;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ confirm: [] }>();

const isExternal = computed(() => invoice.source === 'external');
const isTyped = computed(() => invoice.number !== null && invoice.sequence_number === null);
const number = computed(() => (isExternal.value ? invoice.number : invoice.number_preview ?? invoice.number) ?? '—');
</script>

<template>
    <BottomSheet v-model:open="open" :title="t('Зафіксувати інвойс?')">
        <template #lead>
            <span class="flex size-12 items-center justify-center rounded-full bg-accent-soft text-accent">
                <Icon name="lock" :size="24" />
            </span>
        </template>

        <dl class="m-0 flex flex-col gap-2.5 rounded-card border border-line bg-surface p-4 text-15">
            <div class="flex items-baseline justify-between gap-3">
                <dt class="text-ink-2">{{ t('Номер') }}</dt>
                <dd class="num m-0 text-18 font-bold">{{ number }}</dd>
            </div>
            <div class="flex items-baseline justify-between gap-3">
                <dt class="text-ink-2">{{ t('Клієнт') }}</dt>
                <dd class="m-0 truncate">{{ invoice.client?.name }}</dd>
            </div>
            <div class="flex items-baseline justify-between gap-3">
                <dt class="text-ink-2">{{ t('Період') }}</dt>
                <dd class="num m-0">{{ formatPeriod(invoice.period_start, invoice.period_end) }}</dd>
            </div>
            <div v-if="invoice.receivingAccount" class="flex items-baseline justify-between gap-3">
                <dt class="text-ink-2">{{ t('На рахунок') }}</dt>
                <dd class="m-0">{{ invoice.receivingAccount.name }}</dd>
            </div>
            <div class="flex items-baseline justify-between gap-3 border-t border-divider pt-3">
                <dt class="font-semibold text-ink">{{ t('Разом') }}</dt>
                <dd class="num m-0 text-18 font-bold">{{ formatMoney(invoice.total, invoice.currency) }}</dd>
            </div>
        </dl>

        <ul class="m-0 flex list-none flex-col gap-3 p-0 text-15 leading-normal text-ink-2">
            <li v-if="!isExternal" class="flex gap-3">
                <Icon name="check" :size="20" class="mt-0.5 text-positive" />
                <span v-if="isTyped">{{ t('Номер введено вручну — лічильник його не змінить') }}</span>
                <span v-else-if="counterScope === 'per_client'">
                    {{ t('Номер буде присвоєно з лічильника клієнта {client}', { client: invoice.client?.name ?? '' }) }}
                </span>
                <span v-else>{{ t('Номер буде присвоєно з лічильника ФОП') }}</span>
            </li>
            <li class="flex gap-3">
                <Icon name="check" :size="20" class="mt-0.5 text-positive" />
                <span>{{ t('Дані ФОП, клієнта, договору й реквізити збережуться в знімку — пізніші зміни не вплинуть на документ') }}</span>
            </li>
            <li class="flex gap-3">
                <Icon name="check" :size="20" class="mt-0.5 text-positive" />
                <span>{{ isExternal ? t('Основним документом стане завантажений PDF') : t('Буде створено PDF') }}</span>
            </li>
            <li class="flex gap-3">
                <Icon name="info" :size="20" class="mt-0.5 text-ink-2" />
                <span>{{ t('Повернути в чернетку можна, поки немає надходжень') }}</span>
            </li>
        </ul>

        <p v-if="error" class="m-0 text-14 text-negative" role="alert">{{ error }}</p>

        <div class="grid grid-cols-[1fr_1.4fr] gap-2.5">
            <SecondaryButton @click="open = false">{{ t('Скасувати') }}</SecondaryButton>
            <PrimaryButton :busy="busy" @click="emit('confirm')">{{ t('Зафіксувати') }}</PrimaryButton>
        </div>
    </BottomSheet>
</template>
