<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { api } from '@/api/client';
import type { Paginated, RoleName } from '@/api/types';
import TextField from '@/components/form/TextField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import Avatar from '@/components/ui/Avatar.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import LoadState from '@/components/ui/LoadState.vue';
import NewPill from '@/components/ui/NewPill.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import SideNote from '@/components/ui/SideNote.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { locale, t } from '@/i18n';
import { errorMessage, fieldError } from '@/utils/errors';
import type { Tone } from '@/utils/statuses';

/**
 * Користувачі (S-Users): the organization's users with their roles; managers invite people, change roles, send an
 * invitation again or remove a user. Every user sees every ФОП (per-ФОП access is for a later version).
 */
interface OrganizationUser {
    id: number;
    name: string;
    email: string;
    roles: string[];
    email_verified_at: string | null;
    password_updated_at: string | null;
}

/** The roles, whose names and descriptions are getters, which translate when they are read. */
const ROLES: { value: RoleName; readonly label: string; readonly text: string; tone: Tone }[] = [
    {
        value: 'owner',
        get label() { return t('settings.users.owner'); },
        get text() { return t('settings.users.ownerRights'); },
        tone: 'neutral',
    },
    {
        value: 'accountant',
        get label() { return t('settings.users.accountant'); },
        get text() { return t('settings.users.accountantRights'); },
        tone: 'info',
    },
    {
        value: 'viewer',
        get label() { return t('settings.users.viewer'); },
        get text() { return t('settings.users.viewerRights'); },
        tone: 'neutral',
    },
];

const { user: me, can } = useSession();
const { isDesktop } = useBreakpoint();

const { data: users, error, loading, reload } = useLoader(async (signal) => {
    const { data } = await api.get<Paginated<OrganizationUser>>('users', { page: { size: 50 } }, signal);

    return data;
}, []);

const roleOf = (user: OrganizationUser) => ROLES.find((role) => user.roles.includes(role.value)) ?? null;
const initials = (name: string): string => name.split(/\s+/).filter(Boolean).slice(0, 2).map((word) => word[0]).join('').toUpperCase();
const isPending = (user: OrganizationUser): boolean => user.email_verified_at === null;

/* Inviting, and managing one user */
const sheetOpen = ref(false);
const editing = ref<OrganizationUser | null>(null);
const draft = reactive({ name: '', email: '', role: 'accountant' as RoleName });
const busy = ref(false);
const sheetError = ref<unknown>(null);
/** The notice of a done action, translated when it is shown. */
const notice = ref<(() => string) | null>(null);

function open(user: OrganizationUser | null): void {
    editing.value = user;
    Object.assign(draft, user ? { name: user.name, email: user.email, role: roleOf(user)?.value ?? 'viewer' } : { name: '', email: '', role: 'accountant' });
    sheetError.value = null;
    notice.value = null;
    confirmingRemoval.value = false;
    sheetOpen.value = true;
}

async function run(action: () => Promise<unknown>, done: (() => string) | null = null): Promise<void> {
    busy.value = true;
    sheetError.value = null;

    try {
        await action();

        if (done) {
            notice.value = done;
        } else {
            sheetOpen.value = false;
        }

        await reload();
    } catch (caught) {
        sheetError.value = caught;
    } finally {
        busy.value = false;
    }
}

const save = () => run(() => (editing.value
    ? api.patch(`users/${editing.value.id}`, { name: draft.name.trim(), roles: [draft.role] })
    : api.post('users', { name: draft.name.trim(), email: draft.email.trim(), roles: [draft.role], locale: locale.value })));
const resend = () => run(() => api.post(`users/${editing.value?.id}/invitation`), () => t('settings.users.invitationSentAgain'));
const confirmingRemoval = ref(false);

/**
 * Remove the user on the second tap: the first one asks to confirm.
 */
function remove(): void {
    if (!confirmingRemoval.value) {
        confirmingRemoval.value = true;

        return;
    }

    void run(() => api.delete(`users/${editing.value?.id}`));
}

const isSelf = computed(() => editing.value?.id === me.value?.id);
</script>

<template>
    <SubScreen
        :title="isDesktop ? t('settings.users.users') : ''"
        :muted="!isDesktop"
        :subtitle="isDesktop ? t('settings.users.accessNote') : undefined"
        :back="{ name: 'more' }"
    >
        <template #aside>
            <SideNote :text="t('settings.sideNotes.users')" :accordion="false" />
        </template>
        <template v-if="isDesktop && can('create', 'users')" #action>
            <NewPill :label="t('settings.users.invite')" @click="open(null)" />
        </template>

        <div v-if="!isDesktop" class="-mt-1 flex flex-col gap-1">
            <div class="flex items-center justify-between gap-3">
                <h1 class="m-0 text-28 font-semibold tracking-[-0.02em]">{{ t('settings.users.users') }}</h1>
                <NewPill v-if="can('create', 'users')" :label="t('settings.users.invite')" @click="open(null)" />
            </div>
            <p class="m-0 text-14 leading-[1.45] text-ink-3">{{ t('settings.users.accessNote') }}</p>
        </div>

        <LoadState :loading="loading && !users" :error="error" @retry="reload">
            <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-3.5">
                <button
                    v-for="user in users ?? []"
                    :key="user.id"
                    type="button"
                    class="flex items-center gap-3 py-3.5 text-left"
                    :disabled="!can('update', 'users') || user.id === me?.id"
                    @click="open(user)"
                >
                    <Avatar :initials="initials(user.name)" :variant="user.id === me?.id ? 'dark' : 'light'" />
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="truncate text-16">{{ user.name }}<span v-if="user.id === me?.id" class="text-13 text-ink-3">{{ ' ' + t('settings.users.you') }}</span></span>
                        <span class="truncate text-13 text-ink-3">{{ isPending(user) ? t('settings.users.invitedEmail', { email: user.email }) : user.email }}</span>
                    </span>
                    <span
                        v-if="roleOf(user)?.value === 'owner'"
                        class="shrink-0 rounded-badge bg-ink px-2 py-[3px] text-12 font-semibold text-white"
                    >
                        {{ t('settings.users.owner') }}
                    </span>
                    <StatusBadge v-else-if="roleOf(user)" :label="roleOf(user)!.label" :tone="roleOf(user)!.tone" />
                </button>
            </div>
        </LoadState>

        <section class="flex flex-col gap-2">
            <SectionTitle>{{ t('settings.users.roles') }}</SectionTitle>
            <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-3.5">
                <div v-for="role in ROLES" :key="role.value" class="flex flex-col gap-0.5 py-3">
                    <span class="text-16 font-semibold">{{ role.label }}</span>
                    <span class="text-14 text-ink-2">{{ role.text }}</span>
                </div>
            </div>
        </section>

        <BottomSheet v-model:open="sheetOpen" :title="editing ? editing.name : t('settings.users.inviteUser')">
            <template v-if="!editing">
                <TextField v-model="draft.name" :label="t('settings.users.name')" :error="fieldError(sheetError, 'name')" />
                <TextField v-model="draft.email" label="Email" type="email" :error="fieldError(sheetError, 'email')" />
            </template>
            <div class="flex flex-col gap-1.5">
                <span id="user-role" class="text-13 font-medium text-ink-2">{{ t('settings.users.role') }}</span>
                <div role="radiogroup" aria-labelledby="user-role" class="flex flex-col gap-2">
                    <button
                        v-for="role in ROLES"
                        :key="role.value"
                        type="button"
                        role="radio"
                        :aria-checked="draft.role === role.value ? 'true' : 'false'"
                        :disabled="isSelf"
                        class="flex items-start gap-3 rounded-control bg-surface p-3 text-left"
                        :class="draft.role === role.value ? 'border-2 border-accent' : 'border border-line-input'"
                        @click="draft.role = role.value"
                    >
                        <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border-2" :class="draft.role === role.value ? 'border-accent' : 'border-switch-off'">
                            <span v-if="draft.role === role.value" class="size-2.5 rounded-full bg-accent" />
                        </span>
                        <span class="flex flex-col gap-0.5">
                            <span class="text-15 font-semibold">{{ role.label }}</span>
                            <span class="text-13 text-ink-2">{{ role.text }}</span>
                        </span>
                    </button>
                </div>
            </div>
            <p v-if="!editing" class="m-0 text-13 text-ink-3">{{ t('settings.users.inviteHint') }}</p>
            <p v-if="notice" class="m-0 text-14 text-positive" role="status">{{ notice() }}</p>
            <p v-if="sheetError && !fieldError(sheetError, 'name') && !fieldError(sheetError, 'email')" class="m-0 text-14 text-negative" role="alert">
                {{ errorMessage(sheetError) }}
            </p>
            <template #footer>
                <div class="flex flex-col gap-2">
                    <PrimaryButton :busy="busy" :disabled="!draft.name.trim() || (!editing && !draft.email.trim())" @click="save">
                        {{ editing ? t('settings.users.saveRole') : t('settings.users.sendInvitation') }}
                    </PrimaryButton>
                    <div v-if="editing && !isSelf" class="grid grid-cols-2 gap-2">
                        <SecondaryButton v-if="isPending(editing)" :busy="busy" @click="resend">{{ t('settings.users.inviteAgain') }}</SecondaryButton>
                        <SecondaryButton v-if="can('delete', 'users')" tone="negative" :busy="busy" :class="isPending(editing) ? '' : 'col-span-2'" @click="remove">
                            {{ confirmingRemoval ? t('core.common.yesDelete') : t('core.common.delete') }}
                        </SecondaryButton>
                    </div>
                </div>
            </template>
        </BottomSheet>
    </SubScreen>
</template>
