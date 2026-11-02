<script setup lang="ts">
import { ref, watch } from 'vue';
import { api } from '@/api/client';
import type { Account } from '@/api/types';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import ToggleSwitch from '@/components/ui/ToggleSwitch.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { t } from '@/i18n';
import { isMaskedRequisite, maskNumber, orderedRequisites, REQUISITE_FIELDS, type Requisite, requisiteOrder } from '@/utils/accounts';
import { errorMessage } from '@/utils/errors';

/**
 * Реквізити в інвойсах: the order, in which an account's requisites print in an invoice's payment details, each moved
 * up or down, and a switch for each, whether it prints (on, until switched off). Invoices frozen already keep theirs.
 */
const { account } = defineProps<{ account: Account }>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [] }>();

const { path } = useCurrentProfile();

const requisites = ref<Requisite[]>([]);
const saving = ref(false);
const error = ref<unknown>(null);

watch(open, (isOpen) => {
    if (isOpen) {
        requisites.value = orderedRequisites(account);
        error.value = null;
    }
}, { immediate: true });

/**
 * Move the requisite at the index one place up (-1) or down (1).
 */
function move(index: number, by: -1 | 1): void {
    const list = [...requisites.value];
    const [requisite] = list.splice(index, 1);

    list.splice(index + by, 0, requisite!);
    requisites.value = list;
}

async function save(): Promise<void> {
    saving.value = true;
    error.value = null;

    try {
        await api.patch(path(`accounts/${account.id}`), { invoice_requisites: requisiteOrder(requisites.value) });
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
    <BottomSheet v-model:open="open" :title="t('money.account.requisitesTitle')">
        <p class="m-0 text-14 leading-normal text-ink-2">{{ t('money.account.requisitesHint') }}</p>
        <ul class="m-0 flex list-none flex-col divide-y divide-divider rounded-card border border-line bg-surface p-0 pr-2 pl-4">
            <li v-for="(requisite, index) in requisites" :key="requisite.key" class="flex items-center gap-1 py-1.5">
                <span class="flex min-w-0 flex-1 flex-col gap-0.5 transition-opacity" :class="requisite.shown ? '' : 'opacity-45'">
                    <span class="text-12 text-ink-3">{{ requisite.label }}</span>
                    <span class="truncate text-15" :class="!requisite.field || REQUISITE_FIELDS[requisite.field].figure ? 'num' : ''">
                        {{ isMaskedRequisite(requisite) ? maskNumber(requisite.value) : requisite.value }}
                    </span>
                </span>
                <button
                    type="button"
                    class="flex size-11 shrink-0 items-center justify-center text-ink-2 disabled:text-ink-4"
                    :aria-label="t('money.account.moveUp', { label: requisite.label })"
                    :disabled="index === 0"
                    @click="move(index, -1)"
                >
                    <Icon name="chevronUp" :size="20" />
                </button>
                <button
                    type="button"
                    class="flex size-11 shrink-0 items-center justify-center text-ink-2 disabled:text-ink-4"
                    :aria-label="t('money.account.moveDown', { label: requisite.label })"
                    :disabled="index === requisites.length - 1"
                    @click="move(index, 1)"
                >
                    <Icon name="chevronDown" :size="20" />
                </button>
                <ToggleSwitch v-model="requisite.shown" class="ml-1" :label="t('money.account.printOnInvoices', { label: requisite.label })" />
            </li>
        </ul>
        <p v-if="error" class="m-0 text-14 text-negative" role="alert">{{ errorMessage(error) }}</p>
        <template #footer>
            <PrimaryButton :busy="saving" @click="save">{{ t('core.common.save') }}</PrimaryButton>
        </template>
    </BottomSheet>
</template>
