<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { api } from '@/api/client';
import type { Paginated } from '@/api/types';
import SelectField from '@/components/form/SelectField.vue';
import TextField from '@/components/form/TextField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import SwitchTile from '@/components/ui/SwitchTile.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { locale, t } from '@/i18n';
import { formatDate, weekdayNames } from '@/utils/dates';
import { errorMessage, fieldError } from '@/utils/errors';
import type { Tone } from '@/utils/statuses';

/**
 * Резервні копії (backups.md §4.8): the organization's backups for owners and accountants. The status of the latest
 * run and the next one, a manual backup and the bundle of every ФОП, the runs with a download of each ФОП's archive
 * (optionally with a password), and the policy, which owners configure.
 */
interface BackupRecord {
    id: string;
    profile_id: number;
    status: 'completed' | 'skipped_unchanged' | 'failed' | 'pruned';
    tier: 'scheduled' | 'manual' | 'automatic' | 'export';
    bytes: number | null;
    error: string | null;
    has_archive: boolean;
    documents: number | null;
}

interface BackupRun {
    id: string;
    kind: 'scheduled' | 'manual' | 'automatic' | 'export';
    status: 'queued' | 'running' | 'completed' | 'partial' | 'failed';
    snapshot_at: string | null;
    created_at: string;
    error: string | null;
    bundle_status: string | null;
    backups?: BackupRecord[];
}

interface BackupPolicy {
    enabled: boolean;
    frequency: 'daily' | 'weekly' | 'monthly';
    weekday: number | null;
    month_day: number | null;
    retention_daily: number;
    retention_weekly: number;
    retention_monthly: number;
    include_documents: boolean;
    include_audit_log: boolean;
    profiles_mode: 'all' | 'selected';
    profile_ids: number[];
    skip_unchanged: boolean;
    notify: 'failures' | 'always' | 'never';
    next_run_at: string | null;
}

/** Presets of the retention (daily / weekly / monthly), whose names are getters, which translate when read. */
const PRESETS = [
    { key: 'standard', counts: [7, 4, 12], get label() { return t('settings.backups.presetStandard'); } },
    { key: 'minimum', counts: [3, 0, 3], get label() { return t('settings.backups.presetMinimum'); } },
    { key: 'archive', counts: [7, 4, 36], get label() { return t('settings.backups.presetArchive'); } },
];

const { can } = useSession();
const { profiles } = useCurrentProfile();
const canConfigure = computed(() => can('configure', 'backup-policies'));

const { data, error, loading, reload } = useLoader(async (signal) => {
    const [policy, runs] = await Promise.all([
        api.get<{ data: BackupPolicy }>('organization/backup-policy', undefined, signal),
        api.get<Paginated<BackupRun>>('organization/backup-runs', { include: 'backups', page: { size: 20 } }, signal),
    ]);

    return { policy: policy.data, runs: runs.data.filter((run) => run.kind !== 'export') };
}, []);

const latest = computed(() => data.value?.runs.find((run) => run.status !== 'queued' && run.status !== 'running') ?? null);
const busyRun = computed(() => data.value?.runs.some((run) => run.status === 'queued' || run.status === 'running') ?? false);
const lastFailure = computed(() => data.value?.runs.find((run) => run.status === 'failed' || run.status === 'partial') ?? null);

/* While a run is in progress, its status is read again every few seconds */
let poll: ReturnType<typeof setTimeout> | null = null;

watch(busyRun, (busy) => {
    if (poll) {
        clearTimeout(poll);
        poll = null;
    }

    if (busy) {
        poll = setTimeout(() => void reload(), 3000);
    }
});

onBeforeUnmount(() => {
    if (poll) {
        clearTimeout(poll);
    }
});

const profileName = (id: number): string => profiles.value.find((profile) => profile.id === id)?.short_name ?? `#${id}`;
const sizeOf = (bytes: number | null | undefined): string => {
    const value = bytes ?? 0;
    const megabytes = value / (1024 * 1024);

    return megabytes >= 0.1
        ? t('settings.backups.megabytes', { size: megabytes.toLocaleString(locale.value, { maximumFractionDigits: 1 }) })
        : t('settings.backups.kilobytes', { size: Math.max(1, Math.round(value / 1024)).toLocaleString(locale.value) });
};
const timeOf = (moment: string | null): string => {
    if (!moment) {
        return '';
    }

    const date = new Date(moment);

    return `${formatDate(moment.slice(0, 10))} ${date.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' })}`;
};
const runSize = (run: BackupRun): number => (run.backups ?? []).reduce((sum, backup) => sum + (backup.bytes ?? 0), 0);

function kindLabel(kind: BackupRun['kind']): string {
    switch (kind) {
        case 'scheduled':
            return t('settings.backups.kindScheduled');
        case 'manual':
            return t('settings.backups.kindManual');
        default:
            return t('settings.backups.kindAutomatic');
    }
}

function statusOf(backup: BackupRecord): { label: string; tone: Tone } {
    switch (backup.status) {
        case 'completed':
            return { label: t('settings.backups.statusCompleted'), tone: 'positive' };
        case 'skipped_unchanged':
            return { label: t('settings.backups.statusUnchanged'), tone: 'neutral' };
        case 'pruned':
            return { label: t('settings.backups.statusPruned'), tone: 'neutral' };
        default:
            return { label: t('settings.backups.statusFailed'), tone: 'negative' };
    }
}

/* Actions: a manual backup, the bundle of a run, a download, a delete */
const actionError = ref<unknown>(null);
const starting = ref(false);
const bundling = ref<string | null>(null);

async function startNow(): Promise<void> {
    starting.value = true;
    actionError.value = null;

    try {
        await api.post('organization/backup-runs', { profiles: 'all' });
        await reload();
    } catch (caught) {
        actionError.value = caught;
    } finally {
        starting.value = false;
    }
}

/**
 * Build the run's bundle, wait for it, and download it.
 */
async function downloadBundle(run: BackupRun): Promise<void> {
    bundling.value = run.id;
    actionError.value = null;

    try {
        await api.post(`organization/backup-runs/${run.id}/bundle`);

        for (let attempt = 0; attempt < 120; attempt++) {
            const { data: bundle } = await api.get<{ data: { status: string | null; url?: string } }>(`organization/backup-runs/${run.id}/bundle`);

            if (bundle.status === 'ready' && bundle.url) {
                window.location.href = bundle.url;

                return;
            }

            if (bundle.status === 'failed') {
                throw new Error(t('settings.backups.bundleFailed'));
            }

            await new Promise((resolve) => setTimeout(resolve, 2000));
        }
    } catch (caught) {
        actionError.value = caught;
    } finally {
        bundling.value = null;
    }
}

const downloadSheet = ref(false);
const downloading = ref<BackupRecord | null>(null);
const password = ref('');
const sheetError = ref<unknown>(null);
const sheetBusy = ref(false);
const confirmingDelete = ref(false);

function openDownload(backup: BackupRecord): void {
    downloading.value = backup;
    password.value = '';
    sheetError.value = null;
    confirmingDelete.value = false;
    downloadSheet.value = true;
}

async function download(): Promise<void> {
    sheetBusy.value = true;
    sheetError.value = null;

    try {
        const { data: link } = await api.post<{ data: { url: string } }>(`organization/backups/${downloading.value?.id}/download`, {
            password: password.value || null,
        });
        downloadSheet.value = false;
        window.location.href = link.url;
    } catch (caught) {
        sheetError.value = caught;
    } finally {
        sheetBusy.value = false;
    }
}

/**
 * Delete the manual backup on the second tap: the first one asks to confirm.
 */
async function remove(): Promise<void> {
    if (!confirmingDelete.value) {
        confirmingDelete.value = true;

        return;
    }

    sheetBusy.value = true;

    try {
        await api.delete(`organization/backups/${downloading.value?.id}`);
        downloadSheet.value = false;
        await reload();
    } catch (caught) {
        sheetError.value = caught;
    } finally {
        sheetBusy.value = false;
    }
}

/* The policy */
const policySheet = ref(false);
const draft = reactive({
    enabled: true,
    frequency: 'daily' as BackupPolicy['frequency'],
    weekday: 1,
    month_day: 1,
    retention_daily: '7',
    retention_weekly: '4',
    retention_monthly: '12',
    include_documents: true,
    include_audit_log: false,
    profiles_mode: 'all' as BackupPolicy['profiles_mode'],
    profile_ids: [] as number[],
    skip_unchanged: true,
    notify: 'failures' as BackupPolicy['notify'],
});
const policyError = ref<unknown>(null);
const savingPolicy = ref(false);

function openPolicy(): void {
    const policy = data.value?.policy;

    if (policy) {
        Object.assign(draft, {
            ...policy,
            weekday: policy.weekday ?? 1,
            month_day: policy.month_day ?? 1,
            retention_daily: String(policy.retention_daily),
            retention_weekly: String(policy.retention_weekly),
            retention_monthly: String(policy.retention_monthly),
            profile_ids: [...policy.profile_ids],
        });
    }

    policyError.value = null;
    policySheet.value = true;
}

const preset = computed({
    get: () => PRESETS.find((item) => item.counts.join('/') === [draft.retention_daily, draft.retention_weekly, draft.retention_monthly].join('/'))?.key ?? 'custom',
    set: (key: string) => {
        const chosen = PRESETS.find((item) => item.key === key);

        if (chosen) {
            [draft.retention_daily, draft.retention_weekly, draft.retention_monthly] = chosen.counts.map(String);
        }
    },
});

const toggleProfile = (id: number): void => {
    draft.profile_ids = draft.profile_ids.includes(id) ? draft.profile_ids.filter((item) => item !== id) : [...draft.profile_ids, id];
};

async function savePolicy(): Promise<void> {
    savingPolicy.value = true;
    policyError.value = null;

    try {
        await api.put('organization/backup-policy', {
            ...draft,
            weekday: draft.frequency === 'weekly' ? draft.weekday : null,
            month_day: draft.frequency === 'monthly' ? draft.month_day : null,
            retention_daily: Number(draft.retention_daily),
            retention_weekly: Number(draft.retention_weekly),
            retention_monthly: Number(draft.retention_monthly),
            profile_ids: draft.profiles_mode === 'selected' ? draft.profile_ids : [],
        });
        policySheet.value = false;
        await reload();
    } catch (caught) {
        policyError.value = caught;
    } finally {
        savingPolicy.value = false;
    }
}

const scheduleText = computed(() => {
    const policy = data.value?.policy;

    if (!policy) {
        return '';
    }

    if (!policy.enabled) {
        return t('settings.backups.scheduleOff');
    }

    const frequency = policy.frequency === 'weekly'
        ? t('settings.backups.weeklyOn', { day: weekdayNames()[(policy.weekday ?? 1) - 1] })
        : policy.frequency === 'monthly'
            ? t('settings.backups.monthlyOn', { day: policy.month_day ?? 1 })
            : t('settings.backups.daily');

    return `${frequency} · ${t('settings.backups.keeps', { daily: policy.retention_daily, weekly: policy.retention_weekly, monthly: policy.retention_monthly })}`;
});
</script>

<template>
    <SubScreen title="" muted :back="{ name: 'more' }" :heading="t('settings.backups.title')">
        <p class="-mt-2 m-0 text-14 leading-[1.45] text-ink-3">{{ t('settings.backups.note') }}</p>

        <LoadState :loading="loading && !data" :error="error" @retry="reload">
            <template v-if="data">
                <section class="flex flex-col gap-1.5 rounded-card border border-line bg-surface p-4">
                    <span class="text-13 text-ink-3">{{ t('settings.backups.lastBackup') }}</span>
                    <span v-if="latest" class="text-16 font-semibold">
                        {{ t('settings.backups.lastBackupLine', { time: timeOf(latest.snapshot_at ?? latest.created_at), count: latest.backups?.length ?? 0, size: sizeOf(runSize(latest)) }) }}
                    </span>
                    <span v-else class="text-16 font-semibold">{{ t('settings.backups.none') }}</span>
                    <span v-if="busyRun" class="flex items-center gap-1.5 text-14 text-accent-hover">
                        <Icon name="spinner" :size="16" class="animate-spin" /> {{ t('settings.backups.inProgress') }}
                    </span>
                    <span v-if="data.policy.enabled && data.policy.next_run_at" class="text-14 text-ink-2">
                        {{ t('settings.backups.nextRun', { time: timeOf(data.policy.next_run_at) }) }}
                    </span>
                    <span v-if="lastFailure" class="text-14 text-negative">
                        {{ t('settings.backups.lastFailure', { time: timeOf(lastFailure.created_at) }) }}
                    </span>
                </section>

                <div class="grid grid-cols-2 gap-2">
                    <PrimaryButton v-if="can('create', 'backup-runs')" :busy="starting" :disabled="busyRun" @click="startNow">
                        {{ t('settings.backups.backUpNow') }}
                    </PrimaryButton>
                    <SecondaryButton v-if="latest && can('bundle', 'backup-runs')" :busy="bundling === latest.id" @click="downloadBundle(latest)">
                        {{ t('settings.backups.downloadAll') }}
                    </SecondaryButton>
                </div>
                <p v-if="actionError" class="m-0 text-14 text-negative" role="alert">{{ errorMessage(actionError) }}</p>

                <section class="flex flex-col gap-2">
                    <SectionTitle>{{ t('settings.backups.schedule') }}</SectionTitle>
                    <button
                        type="button"
                        class="flex min-h-14 items-center gap-3 rounded-card border border-line bg-surface px-4 text-left"
                        :disabled="!canConfigure"
                        @click="openPolicy"
                    >
                        <Icon name="calendar" :size="22" />
                        <span class="flex-1 text-15">{{ scheduleText }}</span>
                        <Icon v-if="canConfigure" name="chevronRight" :size="18" class="text-ink-2" />
                    </button>
                </section>

                <section class="flex flex-col gap-2">
                    <SectionTitle>{{ t('settings.backups.history') }}</SectionTitle>
                    <p v-if="data.runs.length === 0" class="m-0 text-14 text-ink-3">{{ t('settings.backups.none') }}</p>
                    <div v-for="run in data.runs" :key="run.id" class="flex flex-col rounded-card border border-line bg-surface px-4 py-3">
                        <div class="flex items-center gap-2">
                            <span class="flex-1 text-15 font-semibold">{{ timeOf(run.snapshot_at ?? run.created_at) }}</span>
                            <StatusBadge :label="kindLabel(run.kind)" tone="info" size="sm" />
                        </div>
                        <span v-if="run.status === 'queued' || run.status === 'running'" class="pt-2 text-14 text-ink-3">{{ t('settings.backups.inProgress') }}</span>
                        <div class="flex flex-col divide-y divide-divider">
                            <button
                                v-for="backup in run.backups ?? []"
                                :key="backup.id"
                                type="button"
                                class="flex min-h-12 items-center gap-3 py-2 text-left"
                                :disabled="!backup.has_archive || !can('download', 'backups')"
                                @click="openDownload(backup)"
                            >
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span class="truncate text-15">{{ profileName(backup.profile_id) }}</span>
                                    <span v-if="backup.status === 'failed' && backup.error" class="truncate text-12 text-negative">{{ backup.error }}</span>
                                    <span v-else-if="backup.has_archive" class="text-13 text-ink-3">{{ sizeOf(backup.bytes) }}</span>
                                </span>
                                <StatusBadge :label="statusOf(backup).label" :tone="statusOf(backup).tone" size="sm" />
                                <Icon v-if="backup.has_archive && can('download', 'backups')" name="download" :size="18" class="text-ink-2" />
                            </button>
                        </div>
                    </div>
                </section>
            </template>
        </LoadState>

        <BottomSheet v-model:open="downloadSheet" :title="downloading ? profileName(downloading.profile_id) : ''">
            <p class="m-0 text-14 text-ink-2">{{ t('settings.backups.downloadNote') }}</p>
            <TextField v-model="password" type="password" autocomplete="new-password" :label="t('settings.backups.password')" :hint="t('settings.backups.passwordHint')" :error="fieldError(sheetError, 'password')" />
            <p v-if="sheetError && !fieldError(sheetError, 'password')" class="m-0 text-14 text-negative" role="alert">{{ errorMessage(sheetError) }}</p>
            <template #footer>
                <div class="flex flex-col gap-2">
                    <PrimaryButton :busy="sheetBusy" @click="download">
                        <Icon name="download" :size="18" /> {{ t('settings.backups.download') }}
                    </PrimaryButton>
                    <SecondaryButton v-if="downloading?.tier === 'manual' && can('delete', 'backups')" tone="negative" :busy="sheetBusy" @click="remove">
                        {{ confirmingDelete ? t('core.common.yesDelete') : t('core.common.delete') }}
                    </SecondaryButton>
                </div>
            </template>
        </BottomSheet>

        <BottomSheet v-model:open="policySheet" :title="t('settings.backups.schedule')">
            <SwitchTile v-model="draft.enabled" :label="t('settings.backups.enabled')" />
            <SegmentedControl
                v-model="draft.frequency"
                :label="t('settings.backups.frequency')"
                :options="[
                    { value: 'daily', label: t('settings.backups.frequencyDaily') },
                    { value: 'weekly', label: t('settings.backups.frequencyWeekly') },
                    { value: 'monthly', label: t('settings.backups.frequencyMonthly') },
                ]"
            />
            <SelectField
                v-if="draft.frequency === 'weekly'"
                v-model="draft.weekday"
                :label="t('settings.backups.weekday')"
                :options="weekdayNames().map((name, index) => ({ value: index + 1, label: name }))"
            />
            <SelectField
                v-if="draft.frequency === 'monthly'"
                v-model="draft.month_day"
                :label="t('settings.backups.monthDay')"
                :options="Array.from({ length: 28 }, (_, index) => ({ value: index + 1, label: String(index + 1) }))"
            />
            <div class="flex flex-col gap-1.5">
                <span class="text-13 font-medium text-ink-2">{{ t('settings.backups.retention') }}</span>
                <SegmentedControl
                    v-model="preset"
                    :label="t('settings.backups.retention')"
                    :options="[...PRESETS.map((item) => ({ value: item.key, label: item.label })), { value: 'custom', label: t('settings.backups.presetCustom') }]"
                />
                <div class="grid grid-cols-3 gap-2">
                    <TextField v-model="draft.retention_daily" numeric inputmode="numeric" :label="t('settings.backups.days')" :error="fieldError(policyError, 'retention_daily')" />
                    <TextField v-model="draft.retention_weekly" numeric inputmode="numeric" :label="t('settings.backups.weeks')" :error="fieldError(policyError, 'retention_weekly')" />
                    <TextField v-model="draft.retention_monthly" numeric inputmode="numeric" :label="t('settings.backups.months')" :error="fieldError(policyError, 'retention_monthly')" />
                </div>
                <span class="text-13 text-ink-3">{{ t('settings.backups.retentionHint') }}</span>
            </div>
            <SwitchTile v-model="draft.include_documents" :label="t('settings.backups.includeDocuments')" />
            <SwitchTile v-model="draft.include_audit_log" :label="t('settings.backups.includeAuditLog')" />
            <SwitchTile v-model="draft.skip_unchanged" :label="t('settings.backups.skipUnchanged')" />
            <SegmentedControl
                v-model="draft.profiles_mode"
                :label="t('settings.backups.profiles')"
                :options="[{ value: 'all', label: t('settings.backups.allProfiles') }, { value: 'selected', label: t('settings.backups.selectedProfiles') }]"
            />
            <div v-if="draft.profiles_mode === 'selected'" class="flex flex-col gap-2">
                <SwitchTile
                    v-for="profile in profiles"
                    :key="profile.id"
                    :model-value="draft.profile_ids.includes(profile.id)"
                    :label="profile.short_name"
                    @update:model-value="toggleProfile(profile.id)"
                />
            </div>
            <SelectField
                v-model="draft.notify"
                :label="t('settings.backups.notify')"
                :options="[
                    { value: 'failures', label: t('settings.backups.notifyFailures') },
                    { value: 'always', label: t('settings.backups.notifyAlways') },
                    { value: 'never', label: t('settings.backups.notifyNever') },
                ]"
            />
            <p v-if="policyError" class="m-0 text-14 text-negative" role="alert">{{ errorMessage(policyError) }}</p>
            <template #footer>
                <PrimaryButton :busy="savingPolicy" @click="savePolicy">{{ t('core.common.save') }}</PrimaryButton>
            </template>
        </BottomSheet>
    </SubScreen>
</template>
