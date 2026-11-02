<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { Account, AccountRequisiteSet, ApiResponse } from '@/api/types';
import RequisiteSetFields from '@/components/account/RequisiteSetFields.vue';
import { blankRequisiteSet, requisiteSetBody, requisiteSetFormOf, type RequisiteSetForm } from '@/components/account/RequisiteSetFormModel';
import SwitchCard from '@/components/form/SwitchCard.vue';
import TextField from '@/components/form/TextField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { requisiteSetName } from '@/utils/accounts';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * Реквізити рахунку — нові або редагування: a set of the account's requisites, which invoices print as «Payment
 * details (…)» (invoice.md §4): its name in the parentheses, the requisites in the order invoices print them, each
 * under its label and printed or not (`RequisiteSetFields`), whether it's the
 * default (another set made the default takes it over; the default can't be made none, switched off or deleted while
 * there are others) and whether new invoices are offered it.
 */
const route = useRoute();
const router = useRouter();
const { can } = useSession();
const { profileId, path } = useCurrentProfile();

const accountId = computed(() => Number(route.params.id));
const setId = computed(() => (route.params.setId ? Number(route.params.setId) : null));
const isNew = computed(() => setId.value === null);
const base = computed(() => path(`accounts/${accountId.value}/requisite-sets`));

const form = ref<RequisiteSetForm>(blankRequisiteSet());
const isDefault = ref(false);
const isActive = ref(true);

const { data, error: loadError, loading, reload } = useLoader(async (signal) => {
    const [account, set] = await Promise.all([
        api.get<ApiResponse<Account>>(path(`accounts/${accountId.value}`), {}, signal),
        setId.value === null ? Promise.resolve(null) : api.get<ApiResponse<AccountRequisiteSet>>(`${base.value}/${setId.value}`, {}, signal),
    ]);

    form.value = set ? requisiteSetFormOf(set.data) : blankRequisiteSet();
    isDefault.value = set?.data.is_default ?? false;
    isActive.value = set?.data.is_active ?? true;

    return { account: account.data, set: set?.data ?? null };
}, [profileId, accountId, setId]);

const canSave = computed(() => can(isNew.value ? 'create' : 'update', 'account-requisite-sets'));
const wasDefault = computed(() => data.value?.set?.is_default ?? false);

const saving = ref(false);
const saveError = ref<unknown>(null);
const confirmingRemoval = ref(false);

const backTo = computed(() => ({ name: 'account', params: { id: accountId.value } }));

async function save(): Promise<void> {
    saving.value = true;
    saveError.value = null;

    try {
        const body = { ...requisiteSetBody(form.value), is_default: isDefault.value, is_active: isDefault.value || isActive.value };

        await (isNew.value ? api.post(base.value, body) : api.patch(`${base.value}/${setId.value}`, body));
        await router.replace(backTo.value);
    } catch (caught) {
        saveError.value = caught;
    } finally {
        saving.value = false;
    }
}

/**
 * Delete the set on the second tap: the first one asks to confirm.
 */
async function remove(): Promise<void> {
    if (!confirmingRemoval.value) {
        confirmingRemoval.value = true;

        return;
    }

    saving.value = true;
    saveError.value = null;

    try {
        await api.delete(`${base.value}/${setId.value}`);
        await router.replace(backTo.value);
    } catch (caught) {
        saveError.value = caught;
        confirmingRemoval.value = false;
    } finally {
        saving.value = false;
    }
}

const errors = computed(() => ({
    account_number: fieldError(saveError.value, 'account_number'),
    swift: fieldError(saveError.value, 'swift'),
    iban: fieldError(saveError.value, 'iban'),
}));
const generalError = computed(() => {
    const names = ['name', 'iban', 'swift', 'account_number'];

    return saveError.value && !names.some((name) => fieldError(saveError.value, name)) ? errorMessage(saveError.value) : null;
});

const title = computed(() => (isNew.value || !data.value?.set ? t('money.requisiteSets.newTitle') : requisiteSetName(data.value.set)));
</script>

<template>
    <SubScreen :title="title" :subtitle="data?.account.name" :back="backTo">
        <LoadState :loading="loading && !data" :error="loadError" @retry="reload">
            <template v-if="data">
                <section class="flex flex-col gap-3.5 rounded-card border border-line bg-surface p-4">
                    <TextField
                        v-model="form.name"
                        :label="t('money.requisiteSets.name')"
                        placeholder="SEPA"
                        :hint="t('money.requisiteSets.nameHint')"
                        :error="fieldError(saveError, 'name')"
                    />
                    <RequisiteSetFields v-model="form" :errors="errors" />
                </section>
                <SwitchCard
                    v-model="isDefault"
                    :title="t('money.requisiteSets.default')"
                    :sub="t('money.requisiteSets.defaultHint')"
                    :disabled="wasDefault || !canSave"
                />
                <SwitchCard
                    :model-value="isDefault || isActive"
                    :title="t('money.requisiteSets.active')"
                    :sub="t('money.requisiteSets.activeHint')"
                    :disabled="isDefault || !canSave"
                    @update:model-value="isActive = $event"
                />
                <p v-if="generalError" class="m-0 text-14 text-negative" role="alert">{{ generalError }}</p>
            </template>
        </LoadState>

        <template v-if="data && canSave" #footer>
            <div class="grid gap-2.5" :class="isNew ? 'grid-cols-1' : 'grid-cols-[1fr_2fr]'">
                <SecondaryButton v-if="!isNew && can('delete', 'account-requisite-sets')" tone="negative" :busy="saving" @click="remove">
                    {{ confirmingRemoval ? t('core.common.yesDelete') : t('core.common.delete') }}
                </SecondaryButton>
                <PrimaryButton :busy="saving" @click="save">{{ t('core.common.save') }}</PrimaryButton>
            </div>
        </template>
    </SubScreen>
</template>
