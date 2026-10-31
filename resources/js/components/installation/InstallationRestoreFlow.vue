<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import type { InstallationComparison, InstallationRowChange, InstallationSide, InstallationStatus } from '@/api/types';
import InstallationBackupForm from '@/components/installation/InstallationBackupForm.vue';
import TextField from '@/components/form/TextField.vue';
import ConfirmSheet from '@/components/ui/ConfirmSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import { useInstallationBackups } from '@/composables/useInstallationBackups';
import { t } from '@/i18n';
import { formatBytes, kyivFullDate, kyivTime } from '@/utils/backups';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * Restoring the app from a full backup (builds.md §4.11): the backup chosen (in the desktop app's dialog, or uploaded
 * in pieces) and opened with its password is compared with this computer first: when each was made and changed last,
 * whether it only moves this computer forward or rewrites it, the organizations of both, and every row it adds,
 * changes or removes, by table, those this computer would lose first. This computer's state is saved before (unless
 * nobody registered here yet), then the backup takes its place, and the desktop app relaunches (a browser opens the
 * sign-in).
 */
const { status, choose, upload, compare, restore, cancel } = useInstallationBackups();

const info = ref<InstallationStatus | null>(null);
const loadError = ref<unknown>(null);
const file = ref<File | null>(null);
const chosen = ref<{ path: string; name: string } | null>(null);
const password = ref('');
const progress = ref<number | null>(null);
const comparison = ref<InstallationComparison | null>(null);
const saved = ref(false);
const confirming = ref(false);
const restored = ref<{ relaunched: boolean } | null>(null);
const busy = ref<'choose' | 'compare' | 'restore' | null>(null);
const error = ref<unknown>(null);

onMounted(async () => {
    try {
        info.value = await status();
    } catch (caught) {
        loadError.value = caught;
    }
});

const available = computed(() => Boolean(info.value?.enabled && info.value.supported));
const sourceName = computed(() => (info.value?.desktop ? chosen.value?.name : file.value?.name) ?? null);
const counted = computed(() => comparison.value?.tables.filter((table) => table.counted) ?? []);
const history = computed(() => comparison.value?.tables.filter((table) => !table.counted) ?? []);
const canRestore = computed(() => Boolean(comparison.value && comparison.value.schema !== 'newer' && (!comparison.value.needs_current_backup || saved.value)));

/** «27.10.2026, 14:12», or that there's no data. */
function moment(timestamp: string | null): string {
    return timestamp ? `${kyivFullDate(timestamp)}, ${kyivTime(timestamp)}` : t('settings.fullRestore.noData');
}

function pick(event: Event): void {
    file.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    error.value = null;
}

async function chooseFile(): Promise<void> {
    busy.value = 'choose';
    error.value = null;

    try {
        const choice = await choose();

        if (choice.path && choice.name) {
            chosen.value = { path: choice.path, name: choice.name };
        }
    } catch (caught) {
        error.value = caught;
    } finally {
        busy.value = null;
    }
}

async function compareBackup(): Promise<void> {
    busy.value = 'compare';
    error.value = null;

    try {
        const source = info.value?.desktop && chosen.value
            ? { path: chosen.value.path }
            : { upload: await upload(file.value as File, (share) => (progress.value = Math.round(share * 100))) };

        comparison.value = await compare(source, password.value);
    } catch (caught) {
        error.value = caught;
    } finally {
        busy.value = null;
        progress.value = null;
    }
}

async function restoreBackup(): Promise<void> {
    if (!comparison.value) {
        return;
    }

    busy.value = 'restore';
    error.value = null;

    try {
        restored.value = await restore(comparison.value.token);
        confirming.value = false;

        // The session ended with the old data: the sign-in opens at once, before a request finds it ended, and says so
        if (!restored.value.relaunched) {
            window.location.assign('/login?restored');
        }
    } catch (caught) {
        error.value = caught;
        confirming.value = false;
    } finally {
        busy.value = null;
    }
}

/** Drop the opened backup, and choose another. */
async function startOver(): Promise<void> {
    const token = comparison.value?.token;

    comparison.value = null;
    saved.value = false;
    password.value = '';
    error.value = null;

    if (token) {
        await cancel(token).catch(() => undefined);
    }
}

const kindLabel = (row: InstallationRowChange): string =>
    row.kind === 'added' ? t('settings.fullRestore.added') : row.kind === 'changed' ? t('settings.fullRestore.changed') : t('settings.fullRestore.removed');
const sides = computed<{ title: string; side: InstallationSide }[]>(() =>
    comparison.value
        ? [
            { title: t('settings.fullRestore.inBackup'), side: comparison.value.backup },
            { title: t('settings.fullRestore.here'), side: comparison.value.current },
        ]
        : [],
);
</script>

<template>
    <LoadState :loading="!info && !loadError" :error="loadError">
        <p v-if="info && !available" class="m-0 text-15 leading-normal text-ink-2">
            {{ info.enabled ? t('settings.backups.full.needsSqlite') : t('settings.fullRestore.unavailable') }}
        </p>
        <p v-else-if="info && !info.allowed" class="m-0 text-15 leading-normal text-ink-2">{{ t('settings.fullRestore.ownersOnly') }}</p>

        <section v-else-if="restored?.relaunched" class="flex items-center gap-3 rounded-card bg-positive-soft p-4" role="status">
            <Icon name="check" :size="22" />
            <span class="text-16 font-semibold">{{ t('settings.fullRestore.relaunching') }}</span>
        </section>

        <template v-else-if="!comparison">
            <p class="m-0 text-15 leading-normal text-ink-2">{{ t('settings.fullRestore.explained') }}</p>

            <div class="flex flex-col gap-1.5">
                <SecondaryButton v-if="info?.desktop" :busy="busy === 'choose'" @click="chooseFile">
                    <Icon name="upload" :size="18" /> <span class="truncate">{{ chosen?.name ?? t('settings.fullRestore.choose') }}</span>
                </SecondaryButton>
                <label v-else class="flex min-h-14 cursor-pointer items-center justify-center gap-2 rounded-control border border-dashed border-switch-off px-3 text-15 font-medium text-accent">
                    <Icon name="upload" :size="20" />
                    <span class="truncate">{{ file ? file.name : t('settings.fullRestore.chooseZip') }}</span>
                    <input type="file" accept=".zip,application/zip" class="sr-only" @change="pick">
                </label>
                <span v-if="fieldError(error, 'archive')" class="text-13 text-negative" role="alert">{{ fieldError(error, 'archive') }}</span>
            </div>

            <TextField v-model="password" :label="t('settings.backups.full.password')" type="password" autocomplete="off" :error="fieldError(error, 'password')" />

            <div v-if="progress !== null" class="flex flex-col gap-1.5" role="status">
                <span class="text-13 text-ink-2">{{ t('settings.fullRestore.uploading', { percent: progress }) }}</span>
                <ProgressBar :percent="progress" />
            </div>

            <p v-if="error && !fieldError(error, 'archive') && !fieldError(error, 'password')" class="m-0 text-14 text-negative" role="alert">
                {{ errorMessage(error) }}
            </p>

            <PrimaryButton :disabled="!sourceName || !password" :busy="busy === 'compare'" @click="compareBackup">{{ t('settings.fullRestore.compare') }}</PrimaryButton>
        </template>

        <template v-else>
            <section class="flex flex-col gap-3 rounded-card border border-line bg-surface p-4">
                <span class="truncate text-13 text-ink-3">{{ sourceName }}</span>
                <dl class="m-0 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="flex flex-col gap-0.5">
                        <dt class="text-13 text-ink-3">{{ t('settings.fullRestore.madeAt') }}</dt>
                        <dd class="m-0 text-15 font-semibold">{{ moment(comparison.made_at) }}</dd>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <dt class="text-13 text-ink-3">{{ t('settings.fullRestore.backupChanged') }}</dt>
                        <dd class="m-0 text-15 font-semibold">{{ moment(comparison.backup.last_change_at) }}</dd>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <dt class="text-13 text-ink-3">{{ t('settings.fullRestore.hereChanged') }}</dt>
                        <dd class="m-0 text-15 font-semibold">{{ moment(comparison.current.last_change_at) }}</dd>
                    </div>
                </dl>
                <span v-if="comparison.documents && comparison.archives" class="text-13 text-ink-2">
                    {{ t('settings.fullRestore.files', {
                        documents: comparison.documents.count,
                        archives: comparison.archives.count,
                        size: formatBytes(comparison.documents.bytes + comparison.archives.bytes),
                    }) }}
                </span>
            </section>

            <p
                class="m-0 rounded-card p-4 text-15 leading-normal font-medium"
                :class="comparison.verdict === 'rewrite' ? 'bg-negative-soft text-negative' : 'bg-positive-soft text-ink'"
                role="status"
            >
                {{ comparison.verdict === 'identical'
                    ? t('settings.fullRestore.identical')
                    : comparison.verdict === 'fast_forward'
                        ? t('settings.fullRestore.fastForward')
                        : t('settings.fullRestore.rewrite', { lost: comparison.lost }) }}
            </p>

            <p v-if="comparison.schema === 'older'" class="m-0 rounded-card bg-warning-soft p-4 text-14 leading-normal text-warning">{{ t('settings.fullRestore.older') }}</p>
            <p v-else-if="comparison.schema === 'newer'" class="m-0 rounded-card bg-negative-soft p-4 text-14 leading-normal text-negative" role="alert">{{ t('settings.fullRestore.newer') }}</p>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <section v-for="{ title, side } in sides" :key="title" class="flex flex-col gap-2 rounded-card border border-line bg-surface p-4">
                    <h2 class="m-0 text-13 font-medium text-ink-3">{{ title }}</h2>
                    <span v-if="side.organizations.length === 0" class="text-14 text-ink-3">{{ t('settings.fullRestore.noOrganizations') }}</span>
                    <span v-for="organization in side.organizations" :key="organization.id" class="flex items-baseline justify-between gap-3">
                        <span class="min-w-0 truncate text-15 font-semibold">{{ organization.name }}</span>
                        <span class="num shrink-0 text-13 text-ink-2">{{ t('settings.fullRestore.fop', { count: organization.profiles }) }}</span>
                    </span>
                </section>
            </div>

            <section v-if="counted.length || history.length" class="flex flex-col gap-2">
                <h2 class="m-0 text-16 font-semibold">{{ t('settings.fullRestore.changes') }}</h2>
                <template v-for="(group, groupIndex) in [counted, history]" :key="groupIndex">
                    <span v-if="groupIndex === 1 && history.length" class="mt-2 text-13 text-ink-3">{{ t('settings.fullRestore.history') }}</span>
                    <details
                        v-for="table in group"
                        :key="table.table"
                        class="rounded-card border border-line bg-surface"
                        :class="table.counted ? '' : 'opacity-75'"
                    >
                        <summary class="flex min-h-12 cursor-pointer list-none flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5">
                            <span class="num min-w-0 flex-1 truncate text-14 font-semibold">{{ table.table }}</span>
                            <span v-if="table.added" class="num text-13 text-positive">+{{ table.added }}</span>
                            <span v-if="table.changed" class="num text-13 text-ink-2">~{{ table.changed }}</span>
                            <span v-if="table.removed" class="num text-13 text-negative">−{{ table.removed }}</span>
                            <span v-if="table.counted && table.removed + table.newer_here" class="text-13 font-medium text-negative">
                                {{ t('settings.fullRestore.lost', { count: table.removed + table.newer_here }) }}
                            </span>
                            <Icon name="chevronDown" :size="18" />
                        </summary>
                        <ul class="m-0 flex list-none flex-col divide-y divide-line border-t border-line p-0">
                            <li v-for="(row, index) in table.rows" :key="index" class="flex flex-col gap-1 px-4 py-2.5">
                                <span class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                    <span class="text-13 font-medium" :class="row.kind === 'added' ? 'text-positive' : row.kind === 'removed' ? 'text-negative' : 'text-ink-2'">
                                        {{ kindLabel(row) }}
                                    </span>
                                    <span v-if="row.id !== null" class="num text-13 text-ink-3">#{{ row.id }}</span>
                                    <span v-if="row.label" class="min-w-0 truncate text-14">{{ row.label }}</span>
                                    <span v-if="row.newer_here && table.counted" class="text-12 font-medium text-negative">{{ t('settings.fullRestore.newerHere') }}</span>
                                </span>
                                <span v-if="row.here_at || row.backup_at" class="text-12 text-ink-3">
                                    <template v-if="row.here_at">{{ t('settings.fullRestore.here') }}: {{ moment(row.here_at) }}</template>
                                    <template v-if="row.here_at && row.backup_at"> · </template>
                                    <template v-if="row.backup_at">{{ t('settings.fullRestore.inBackup') }}: {{ moment(row.backup_at) }}</template>
                                </span>
                                <span v-for="change in row.columns" :key="change.column" class="font-mono text-12 break-all text-ink-2">
                                    {{ change.column }}: <span class="text-negative line-through">{{ change.here ?? '∅' }}</span> → <span class="text-ink">{{ change.backup ?? '∅' }}</span>
                                </span>
                            </li>
                            <li v-if="table.rows.length < table.added + table.changed + table.removed" class="px-4 py-2.5 text-12 text-ink-3">
                                {{ t('settings.fullRestore.shownFirst', { count: table.rows.length }) }}
                            </li>
                        </ul>
                    </details>
                </template>
            </section>

            <section v-if="comparison.needs_current_backup" class="flex flex-col gap-3 rounded-card border border-line bg-surface p-4">
                <h2 class="m-0 text-16 font-semibold">{{ t('settings.fullRestore.saveCurrentTitle') }}</h2>
                <p class="m-0 text-14 leading-normal text-ink-2">{{ t('settings.fullRestore.saveCurrentText') }}</p>
                <InstallationBackupForm :label="t('settings.fullRestore.saveCurrent')" :restore="comparison.token" @saved="saved = true" />
            </section>

            <p v-if="error" class="m-0 text-14 text-negative" role="alert">{{ fieldError(error, 'current_backup') ?? fieldError(error, 'archive') ?? errorMessage(error) }}</p>

            <div class="flex flex-col gap-2">
                <PrimaryButton :disabled="!canRestore" @click="confirming = true">{{ t('settings.fullRestore.restore') }}</PrimaryButton>
                <SecondaryButton @click="startOver">{{ t('settings.fullRestore.another') }}</SecondaryButton>
            </div>

            <ConfirmSheet
                v-model:open="confirming"
                :title="t('settings.fullRestore.confirmTitle')"
                :confirm-label="t('settings.fullRestore.confirm')"
                :busy="busy === 'restore'"
                destructive
                @confirm="restoreBackup"
            >
                {{ t('settings.fullRestore.confirmText', { date: moment(comparison.made_at) }) }}
            </ConfirmSheet>
        </template>
    </LoadState>
</template>
