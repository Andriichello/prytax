<script setup lang="ts">
import BottomSheet from '@/components/ui/BottomSheet.vue';
import { t } from '@/i18n';
import { PURPOSE_PLACEHOLDERS, type PurposePlaceholder, type PurposeValues } from '@/utils/invoiceDocument';

/**
 * Підстановка: the placeholders of the payment purpose, each with what it is and its value for the sample invoice
 * of the preview. A tap inserts the placeholder at the caret of the field (the parent does) and closes the sheet.
 */
const { values } = defineProps<{
    /** The placeholders' values for the sample invoice. */
    values: PurposeValues;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ insert: [token: string] }>();

/** What a placeholder is: "Предмет договору", "Номер інвойсу". */
function placeholderName(name: PurposePlaceholder): string {
    switch (name) {
        case 'services':
            return t('invoices.document.placeholderServices');
        case 'invoice.number':
            return t('invoices.document.placeholderInvoiceNumber');
        case 'invoice.date':
            return t('invoices.document.placeholderInvoiceDate');
        case 'period.start':
            return t('invoices.document.placeholderPeriodStart');
        case 'period.end':
            return t('invoices.document.placeholderPeriodEnd');
        case 'contract.name':
            return t('invoices.document.placeholderContractName');
        case 'contract.number':
            return t('invoices.document.placeholderContractNumber');
        case 'contract.date':
            return t('invoices.document.placeholderContractDate');
        case 'client.name':
            return t('invoices.document.placeholderClientName');
        default:
            return t('invoices.document.placeholderProfileName');
    }
}

/** A placeholder as it is typed: `{services}`. */
const token = (name: PurposePlaceholder): string => `{${name}}`;

function insert(name: PurposePlaceholder): void {
    emit('insert', token(name));
    open.value = false;
}
</script>

<template>
    <BottomSheet v-model:open="open" :title="t('invoices.document.placeholder')">
        <p class="m-0 text-15 leading-normal text-ink-2">{{ t('invoices.document.placeholderHint') }}</p>
        <div class="flex flex-col divide-y divide-divider">
            <button
                v-for="name in PURPOSE_PLACEHOLDERS"
                :key="name"
                type="button"
                class="flex min-h-15 items-center justify-between gap-3 py-2.5 text-left"
                @click="insert(name)"
            >
                <span class="flex min-w-0 flex-col gap-0.5">
                    <span class="num text-15 text-ink">{{ token(name) }}</span>
                    <span class="text-13 text-ink-3">{{ placeholderName(name) }}</span>
                </span>
                <span class="max-w-[50%] truncate text-15 text-ink-2">{{ values[name] ?? '—' }}</span>
            </button>
        </div>
    </BottomSheet>
</template>
