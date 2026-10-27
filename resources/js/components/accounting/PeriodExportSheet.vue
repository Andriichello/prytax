<script setup lang="ts">
import { ref, watch } from 'vue';
import { api } from '@/api/client';
import DateField from '@/components/form/DateField.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { t } from '@/i18n';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * Експорт за період (backups.md §2.7): the ФОП's archive of a range of days, such as the quarter Облік shows, for an
 * accountant or a tax audit. The export is built by a job; the sheet waits for it, then downloads it.
 */
const { from: initialFrom, to: initialTo } = defineProps<{ from: string; to: string }>();
const open = defineModel<boolean>('open', { required: true });

const { path } = useCurrentProfile();
const from = ref(initialFrom);
const to = ref(initialTo);
const busy = ref(false);
const failure = ref<unknown>(null);

watch(open, (isOpen) => {
    if (isOpen) {
        from.value = initialFrom;
        to.value = initialTo;
        failure.value = null;
    }
});

/**
 * Start the export, wait for its archive, and download it.
 */
async function exportPeriod(): Promise<void> {
    busy.value = true;
    failure.value = null;

    try {
        const { data: run } = await api.post<{ data: { id: string } }>(path('exports'), { from: from.value, to: to.value });

        for (let attempt = 0; attempt < 90; attempt++) {
            const { data: status } = await api.get<{ data: { status: string; url?: string } }>(path(`exports/${run.id}`));

            if (status.url) {
                open.value = false;
                window.location.href = status.url;

                return;
            }

            if (status.status === 'failed') {
                throw new Error(t('accounting.export.failed'));
            }

            await new Promise((resolve) => setTimeout(resolve, 2000));
        }
    } catch (caught) {
        failure.value = caught;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <BottomSheet v-model:open="open" :title="t('accounting.export.title')">
        <p class="m-0 text-14 text-ink-2">{{ t('accounting.export.note') }}</p>
        <div class="grid grid-cols-2 gap-2">
            <DateField v-model="from" :label="t('accounting.export.from')" :error="fieldError(failure, 'from')" />
            <DateField v-model="to" :label="t('accounting.export.to')" :min="from" :error="fieldError(failure, 'to')" />
        </div>
        <p v-if="failure && !fieldError(failure, 'from') && !fieldError(failure, 'to')" class="m-0 text-14 text-negative" role="alert">
            {{ errorMessage(failure) }}
        </p>
        <template #footer>
            <PrimaryButton :busy="busy" :disabled="!from || !to" @click="exportPeriod">
                <Icon name="download" :size="18" /> {{ t('accounting.export.download') }}
            </PrimaryButton>
        </template>
    </BottomSheet>
</template>
