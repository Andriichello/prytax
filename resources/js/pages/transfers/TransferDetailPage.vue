<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError, api } from '@/api/client';
import type { AccountTransfer, ApiResponse } from '@/api/types';
import TextAreaField from '@/components/form/TextAreaField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import ConfirmSheet from '@/components/ui/ConfirmSheet.vue';
import InfoRow from '@/components/ui/InfoRow.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { accountLabel } from '@/utils/accounts';
import { formatDate } from '@/utils/dates';
import { errorMessage } from '@/utils/errors';
import { formatAmount, formatMoney, sumAmounts } from '@/utils/money';

/**
 * Переказ: the route, what was sent and what arrived, its cost, and the receipts whose money it moved. Deleting a sale
 * with an FX gain in a declared quarter asks for a correction note.
 */
const route = useRoute();
const router = useRouter();
const { profileId, path } = useCurrentProfile();
const { can } = useSession();

const id = computed(() => Number(route.params.id));

const { data: transfer, error, loading, reload } = useLoader(async (signal) => {
    const { data } = await api.get<ApiResponse<AccountTransfer>>(path(`transfers/${id.value}`), {
        include: ['fromAccount', 'toAccount', 'allocations.payment.invoice'],
        append: ['fx_spread', 'effective_rate'],
    }, signal);

    return data;
}, [profileId, id]);

const isSale = computed(() => !!transfer.value && transfer.value.currency_sent !== transfer.value.currency_received);
const allocated = computed(() => sumAmounts(...(transfer.value?.allocations ?? []).map((allocation) => allocation.amount)));

const deleteOpen = ref(false);
const deleting = ref(false);
const deleteError = ref<string | null>(null);

/* A declared quarter answers 409: the delete then needs a correction note */
const correctionNote = ref('');
const needsNote = ref(false);

function openDelete(): void {
    deleteError.value = null;
    needsNote.value = false;
    correctionNote.value = '';
    deleteOpen.value = true;
}

async function remove(): Promise<void> {
    deleting.value = true;
    deleteError.value = null;

    try {
        const note = correctionNote.value.trim();

        await api.delete(path(`transfers/${id.value}`), note ? { correction_note: note } : undefined);
        deleteOpen.value = false;
        await router.replace({ name: 'transfers' });
    } catch (caught) {
        needsNote.value ||= caught instanceof ApiError && caught.status === 409;
        deleteError.value = errorMessage(caught);
    } finally {
        deleting.value = false;
    }
}
</script>

<template>
    <SubScreen title="Переказ" muted :back="{ name: 'transfers' }">
        <template #action>
            <RouterLink
                v-if="transfer && can('update', 'account-transfers')"
                :to="{ name: 'transfer-edit', params: { id } }"
                class="flex min-h-11 items-center px-3 text-15 font-medium no-underline"
            >
                Змінити
            </RouterLink>
        </template>

        <LoadState :loading="loading && !transfer" :error="error" @retry="reload">
            <template v-if="transfer">
                <div class="flex flex-col gap-1.5">
                    <StatusBadge v-if="isSale" label="продаж валюти" tone="info" class="self-start" />
                    <span class="num text-30 font-semibold tracking-[-0.02em]">{{ formatMoney(transfer.amount_sent, transfer.currency_sent) }}</span>
                    <span class="num text-16 font-light text-ink-2">→ {{ formatMoney(transfer.amount_received, transfer.currency_received) }}</span>
                </div>

                <p class="m-0 text-16">
                    {{ accountLabel(transfer.fromAccount?.name, transfer.currency_sent) }}
                    <span class="text-ink-3">→</span>
                    {{ accountLabel(transfer.toAccount?.name, transfer.currency_received) }}
                </p>

                <section class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                    <InfoRow v-if="transfer.sent_at" label="Відправлено" figure>{{ formatDate(transfer.sent_at) }}</InfoRow>
                    <InfoRow label="Зараховано" figure>{{ formatDate(transfer.received_at) }}</InfoRow>
                    <InfoRow v-if="transfer.fee_amount" :label="isSale ? 'Комісія банку' : 'Комісія'" figure>
                        {{ formatMoney(transfer.fee_amount, transfer.fee_currency ?? transfer.currency_sent) }}
                    </InfoRow>
                    <template v-if="isSale">
                        <InfoRow v-if="transfer.effective_rate" label="Курс переказу" figure>{{ formatAmount(transfer.effective_rate) }}</InfoRow>
                        <InfoRow v-if="transfer.nbu_rate" :label="transfer.rate_is_manual ? 'Курс (вручну)' : 'Курс НБУ'" figure>{{ formatAmount(transfer.nbu_rate) }}</InfoRow>
                        <InfoRow v-if="transfer.fx_spread" label="Курсовий спред" figure>{{ formatMoney(transfer.fx_spread, 'UAH') }}</InfoRow>
                    </template>
                    <InfoRow v-if="transfer.reference" label="Референс">{{ transfer.reference }}</InfoRow>
                </section>

                <section v-if="transfer.allocations?.length" class="flex flex-col gap-2">
                    <SectionTitle>
                        Перенесені надходження
                        <template #aside><span class="num">{{ formatAmount(allocated) }} / {{ formatAmount(transfer.amount_sent) }}</span></template>
                    </SectionTitle>
                    <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                        <RouterLink
                            v-for="allocation in transfer.allocations"
                            :key="allocation.id"
                            :to="{ name: 'payment', params: { id: allocation.payment_id } }"
                            class="flex items-center justify-between gap-3 py-3 text-ink no-underline hover:text-ink"
                        >
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="num text-15 font-semibold text-accent">{{ allocation.payment?.invoice?.number ?? `Надходження ${allocation.payment_id}` }}</span>
                                <span class="text-12 text-ink-3">від {{ formatDate(allocation.payment?.received_at) }}</span>
                            </span>
                            <span class="num text-15 font-semibold">{{ formatMoney(allocation.amount, transfer.currency_sent) }}</span>
                        </RouterLink>
                    </div>
                </section>

                <p v-if="transfer.note" class="m-0 text-14 text-ink-2">{{ transfer.note }}</p>

                <SecondaryButton v-if="can('delete', 'account-transfers')" tone="negative" @click="openDelete">
                    Видалити переказ
                </SecondaryButton>
            </template>
        </LoadState>

        <ConfirmSheet
            v-model:open="deleteOpen"
            title="Видалити переказ?"
            confirm-label="Видалити"
            destructive
            :busy="deleting"
            :error="deleteError"
            @confirm="remove"
        >
            <p class="m-0">Кошти знову рахуватимуться на рахунку «{{ transfer?.fromAccount?.name }}». Якщо їх уже переказано далі, видалити не вийде.</p>
            <TextAreaField v-if="needsNote" v-model="correctionNote" label="Квартал уже задекларовано — що уточнюєте?" :rows="2" />
        </ConfirmSheet>
    </SubScreen>
</template>
