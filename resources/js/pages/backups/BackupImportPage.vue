<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, BackupImportResult } from '@/api/types';
import TextField from '@/components/form/TextField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import Icon from '@/components/ui/Icon.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import SideNote from '@/components/ui/SideNote.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { t } from '@/i18n';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * Відновити з архіву (Резервні копії): a ФОП from the archive of its backup, as downloaded (with its password, when it
 * has one), restored as a new ФОП of the organization, never over an existing one (backups.md §5.1). «Перевірити»
 * imports, verifies and rolls back, telling whose archive it is and what it holds; «Відновити» does it. Owners only.
 */
const router = useRouter();
const { loadProfiles, select } = useCurrentProfile();

const file = ref<File | null>(null);
const password = ref('');
const checked = ref<BackupImportResult | null>(null);
const restored = ref<BackupImportResult | null>(null);
const busy = ref<'check' | 'restore' | 'open' | null>(null);
const error = ref<unknown>(null);

function choose(event: Event): void {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    checked.value = null;
    error.value = null;
}

async function send(dryRun: boolean): Promise<BackupImportResult | null> {
    if (!file.value) {
        return null;
    }

    const body = new FormData();
    body.append('archive', file.value);
    body.append('dry_run', dryRun ? '1' : '0');

    if (password.value) {
        body.append('password', password.value);
    }

    error.value = null;

    try {
        const { data } = await api.post<ApiResponse<BackupImportResult>>('organization/backups/import', body);

        return data;
    } catch (caught) {
        error.value = caught;

        return null;
    }
}

async function check(): Promise<void> {
    busy.value = 'check';
    checked.value = await send(true);
    busy.value = null;
}

async function restore(): Promise<void> {
    busy.value = 'restore';
    restored.value = await send(false);
    busy.value = null;
}

/** Switch to the restored ФОП, and open it. */
async function open(): Promise<void> {
    if (!restored.value?.tenant_id) {
        return;
    }

    busy.value = 'open';
    await loadProfiles();
    await select(restored.value.tenant_id);
    await router.replace({ name: 'home' });
}

const name = (result: BackupImportResult) => result.tenant.full_name ?? '—';
</script>

<template>
    <SubScreen :title="t('settings.backups.import.title')" :back="{ name: 'backups' }">
        <template #aside>
            <SideNote :text="t('settings.backups.import.sideNote')" />
        </template>

        <section v-if="restored" class="flex flex-col gap-3 rounded-card bg-positive-soft p-4" role="status">
            <span class="text-16 font-semibold">{{ t('settings.backups.import.restored', { name: name(restored) }) }}</span>
            <span class="text-14 text-ink-2">{{ t('settings.backups.import.holds', { rows: restored.rows, documents: restored.documents }) }}</span>
            <PrimaryButton :busy="busy === 'open'" @click="open">{{ t('settings.backups.import.open') }}</PrimaryButton>
        </section>

        <template v-else>
            <p class="m-0 text-15 leading-normal text-ink-2">{{ t('settings.backups.import.explained') }}</p>

            <div class="flex flex-col gap-1.5">
                <label class="flex min-h-14 cursor-pointer items-center justify-center gap-2 rounded-control border border-dashed border-switch-off px-3 text-15 font-medium text-accent">
                    <Icon name="upload" :size="20" />
                    <span class="truncate">{{ file ? file.name : t('settings.backups.import.chooseArchive') }}</span>
                    <input type="file" accept=".zip,application/zip" class="sr-only" @change="choose">
                </label>
                <span v-if="fieldError(error, 'archive')" class="text-13 text-negative" role="alert">{{ fieldError(error, 'archive') }}</span>
            </div>

            <TextField
                v-model="password"
                :label="t('settings.backups.import.password')"
                :hint="t('settings.backups.import.passwordHint')"
                type="password"
                autocomplete="off"
                :error="fieldError(error, 'password')"
                @input="checked = null"
            />

            <p v-if="error && !fieldError(error, 'archive') && !fieldError(error, 'password')" class="m-0 text-14 text-negative" role="alert">
                {{ errorMessage(error) }}
            </p>

            <section v-if="checked" class="flex flex-col gap-1 rounded-card border border-line bg-surface p-4" :aria-label="t('settings.backups.import.checkedTitle')">
                <span class="text-13 text-ink-3">{{ t('settings.backups.import.checkedTitle') }}</span>
                <span class="text-16 font-semibold">
                    {{ name(checked) }}<template v-if="checked.tenant.tax_id_last4"> · <span class="num">•••• {{ checked.tenant.tax_id_last4 }}</span></template>
                </span>
                <span class="text-14 text-ink-2">{{ t('settings.backups.import.holds', { rows: checked.rows, documents: checked.documents }) }}</span>
            </section>

            <PrimaryButton v-if="checked" :busy="busy === 'restore'" @click="restore">{{ t('settings.backups.import.restore') }}</PrimaryButton>
            <SecondaryButton v-else :disabled="!file" :busy="busy === 'check'" @click="check">{{ t('settings.backups.import.check') }}</SecondaryButton>
        </template>
    </SubScreen>
</template>
