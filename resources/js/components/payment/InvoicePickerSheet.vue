<script setup lang="ts">
import type { Invoice } from '@/api/types';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import { formatAmount, formatMoney, subtractAmounts } from '@/utils/money';

/**
 * The open invoices to record a receipt for: the number in cobalt, the client on a light second line, and what is
 * still to be received.
 */
const { invoices, selectedId } = defineProps<{
    invoices: Invoice[];
    selectedId: number | null;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ select: [invoice: Invoice] }>();

function choose(invoice: Invoice): void {
    emit('select', invoice);
    open.value = false;
}
</script>

<template>
    <BottomSheet v-model:open="open" title="Оберіть інвойс">
        <div v-if="invoices.length" class="flex flex-col divide-y divide-divider overflow-hidden rounded-card border border-line bg-surface">
            <button
                v-for="invoice in invoices"
                :key="invoice.id"
                type="button"
                class="flex items-center gap-3 px-3.5 py-3 text-left"
                :class="invoice.id === selectedId ? 'bg-accent-soft' : ''"
                @click="choose(invoice)"
            >
                <span class="flex min-w-0 flex-1 flex-col gap-[3px]">
                    <span class="num text-16 font-semibold text-accent">{{ invoice.number }}</span>
                    <span class="truncate text-15 font-light">{{ invoice.client?.name }}</span>
                    <span class="text-12 text-ink-3">
                        Залишок <span class="num">{{ formatMoney(subtractAmounts(invoice.total, invoice.paid_amount ?? '0'), invoice.currency) }}</span>
                        з <span class="num">{{ formatAmount(invoice.total) }}</span>
                    </span>
                </span>
                <Icon v-if="invoice.id === selectedId" name="check" :size="22" class="text-accent" />
            </button>
        </div>
        <p v-else class="m-0 text-15 text-ink-2">Відкритих інвойсів немає. Надходження записують для зафіксованих інвойсів: готових або надісланих.</p>
    </BottomSheet>
</template>
