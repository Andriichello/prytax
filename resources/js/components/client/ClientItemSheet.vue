<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api } from '@/api/client';
import type { ChargeType, Project } from '@/api/types';
import TextField from '@/components/form/TextField.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { t } from '@/i18n';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * A project (code and name) or a work type (name) of a client, new or edited, with its active flag.
 */
const { kind, clientId, item = null } = defineProps<{
    kind: 'project' | 'chargeType';
    clientId: number;
    item?: Project | ChargeType | null;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [] }>();

const { path } = useCurrentProfile();

const code = ref('');
const name = ref('');
const active = ref(true);
const saving = ref(false);
const error = ref<unknown>(null);

watch(open, (isOpen) => {
    if (isOpen) {
        code.value = kind === 'project' ? (item as Project | null)?.code ?? '' : '';
        name.value = item?.name ?? '';
        active.value = item?.is_active ?? true;
        error.value = null;
    }
}, { immediate: true });

const resource = computed(() => `clients/${clientId}/${kind === 'project' ? 'projects' : 'charge-types'}`);
const title = computed(() => (kind === 'project' ? (item ? t('core.common.project') : t('clients.item.newProject')) : (item ? t('core.common.workType') : t('clients.item.newWorkType'))));

async function save(): Promise<void> {
    saving.value = true;
    error.value = null;

    const body = {
        ...(kind === 'project' ? { code: code.value.trim().toUpperCase() } : {}),
        name: name.value.trim(),
        is_active: active.value,
    };

    try {
        if (item) {
            await api.patch(path(`${resource.value}/${item.id}`), body);
        } else {
            await api.post(path(resource.value), body);
        }

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
    <BottomSheet v-model:open="open" :title="title">
        <TextField
            v-if="kind === 'project'"
            v-model="code"
            :label="t('clients.item.code')"
            placeholder="PROTO"
            class="num uppercase"
            :hint="t('clients.item.codeHint')"
            :error="fieldError(error, 'code')"
        />
        <TextField
            v-model="name"
            :label="kind === 'project' ? t('core.common.name') : t('core.common.workType')"
            :placeholder="kind === 'project' ? 'Prototype platform' : 'Billable'"
            :error="fieldError(error, 'name')"
        />
        <label class="flex min-h-13 items-center justify-between rounded-control border border-line bg-surface px-4 text-15">
            {{ t('core.common.active') }}
            <input v-model="active" type="checkbox" class="size-5 accent-accent">
        </label>
        <p v-if="error && !fieldError(error, 'code') && !fieldError(error, 'name')" class="m-0 text-14 text-negative" role="alert">{{ errorMessage(error) }}</p>

        <template #footer>
            <PrimaryButton :busy="saving" :disabled="!name.trim() || (kind === 'project' && !code.trim())" @click="save">{{ t('core.common.save') }}</PrimaryButton>
        </template>
    </BottomSheet>
</template>
