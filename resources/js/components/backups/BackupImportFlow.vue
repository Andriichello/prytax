<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, BackupImportedProfile, BackupImportResult } from '@/api/types';
import { uploadInPieces } from '@/api/uploads';
import TextField from '@/components/form/TextField.vue';
import Icon from '@/components/ui/Icon.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { t } from '@/i18n';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * Restoring ФОП from a backup's archive (backups.md §5.1): the ФОП of a backup's archive, as downloaded (with its
 * password, when it has one), or every ФОП of a backup's archive of all of them, restored as new ФОП of the
 * organization, never over an existing one. «Перевірити» imports, verifies and rolls back, telling whose ФОП it holds
 * and what; «Відновити» does it, all of them or none; then it opens the first. Owners only. Резервні копії opens it in
 * place on its ФОП tab, Новий ФОП as a page of its own. The archive goes up once, in pieces (a server takes small
 * requests), on «Перевірити», and «Відновити» restores that upload; it goes up again, when the server lost it.
 */
const router = useRouter();
const { loadProfiles, select } = useCurrentProfile();

const file = ref<File | null>(null);
/** The id of the chosen file's upload, once it's sent. */
const upload = ref<string | null>(null);
/** The share of the file sent, in percent, while it goes up. */
const progress = ref<number | null>(null);
const password = ref('');
const checked = ref<BackupImportResult | null>(null);
const restored = ref<BackupImportResult | null>(null);
const busy = ref<'check' | 'restore' | 'open' | null>(null);
const error = ref<unknown>(null);

function choose(event: Event): void {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    upload.value = null;
    checked.value = null;
    error.value = null;
}

async function send(dryRun: boolean): Promise<BackupImportResult | null> {
    const chosen = file.value;

    if (!chosen) {
        return null;
    }

    error.value = null;

    try {
        if (!upload.value) {
            progress.value = 0;
            upload.value = await uploadInPieces('organization/backups/uploads', chosen, (share) => (progress.value = Math.round(share * 100)));
        }

        const { data } = await api.post<ApiResponse<BackupImportResult>>('organization/backups/import', {
            upload: upload.value,
            name: chosen.name,
            dry_run: dryRun,
            password: password.value || null,
        });

        return data;
    } catch (caught) {
        error.value = caught;

        // Pieces, which the server lost (pruned after a day), go up again on the next try
        if (fieldError(caught, 'upload')) {
            upload.value = null;
        }

        return null;
    } finally {
        progress.value = null;
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

/** Switch to the (first) restored ФОП, and open it. */
async function open(): Promise<void> {
    const id = restored.value?.tenants[0]?.tenant_id;

    if (!id) {
        return;
    }

    busy.value = 'open';
    await loadProfiles();
    await select(id);
    await router.replace({ name: 'home' });
}

const name = (tenant: BackupImportedProfile) => tenant.tenant.full_name ?? '—';
</script>

<template>
    <div class="flex flex-col gap-4">
    <section v-if="restored" class="flex flex-col gap-3 rounded-card bg-positive-soft p-4" role="status">
        <span class="text-16 font-semibold">
            {{ restored.tenants.length === 1
                ? t('settings.backups.import.restored', { name: name(restored.tenants[0]) })
                : t('settings.backups.import.restoredMany', { count: restored.tenants.length }) }}
        </span>
        <span v-for="(tenant, index) in restored.tenants" :key="index" class="flex flex-col">
            <span v-if="restored.tenants.length > 1" class="text-15 font-medium">{{ name(tenant) }}</span>
            <span class="text-14 text-ink-2">{{ t('settings.backups.import.holds', { rows: tenant.rows, documents: tenant.documents }) }}</span>
        </span>
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

        <div v-if="progress !== null" class="flex flex-col gap-1.5" role="status">
            <span class="text-13 text-ink-2">{{ t('settings.backups.import.uploading', { percent: progress }) }}</span>
            <ProgressBar :percent="progress" />
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

        <section v-if="checked" class="flex flex-col gap-3 rounded-card border border-line bg-surface p-4" :aria-label="t('settings.backups.import.checkedTitle')">
            <span class="text-13 text-ink-3">{{ t('settings.backups.import.checkedTitle') }}</span>
            <span v-for="(tenant, index) in checked.tenants" :key="index" class="flex flex-col gap-1">
                <span class="text-16 font-semibold">
                    {{ name(tenant) }}<template v-if="tenant.tenant.tax_id_last4"> · <span class="num whitespace-nowrap">•••• {{ tenant.tenant.tax_id_last4 }}</span></template>
                </span>
                <span class="text-14 text-ink-2">{{ t('settings.backups.import.holds', { rows: tenant.rows, documents: tenant.documents }) }}</span>
            </span>
        </section>

        <PrimaryButton v-if="checked" :busy="busy === 'restore'" @click="restore">
            {{ checked.tenants.length === 1 ? t('settings.backups.import.restore') : t('settings.backups.import.restoreMany', { count: checked.tenants.length }) }}
        </PrimaryButton>
        <SecondaryButton v-else :disabled="!file" :busy="busy === 'check'" @click="check">{{ t('settings.backups.import.check') }}</SecondaryButton>
    </template>
    </div>
</template>
