<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { api } from '@/api/client';
import type { Paginated, RoleName } from '@/api/types';
import TextField from '@/components/form/TextField.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import Avatar from '@/components/ui/Avatar.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
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

const ROLES: { value: RoleName; label: string; text: string; tone: Tone }[] = [
    { value: 'owner', label: 'Власник', text: 'Усе, включно з ФОП і користувачами', tone: 'neutral' },
    { value: 'accountant', label: 'Бухгалтер', text: 'Усі фінансові операції в кожному ФОП', tone: 'info' },
    { value: 'viewer', label: 'Перегляд', text: 'Лише читання', tone: 'neutral' },
];

const { user: me, can } = useSession();

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
const notice = ref<string | null>(null);

function open(user: OrganizationUser | null): void {
    editing.value = user;
    Object.assign(draft, user ? { name: user.name, email: user.email, role: roleOf(user)?.value ?? 'viewer' } : { name: '', email: '', role: 'accountant' });
    sheetError.value = null;
    notice.value = null;
    confirmingRemoval.value = false;
    sheetOpen.value = true;
}

async function run(action: () => Promise<unknown>, done: string | null = null): Promise<void> {
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
    : api.post('users', { name: draft.name.trim(), email: draft.email.trim(), roles: [draft.role] })));
const resend = () => run(() => api.post(`users/${editing.value?.id}/invitation`), 'Запрошення надіслано знову.');
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
    <SubScreen title="" muted :back="{ name: 'more' }">
        <div class="-mt-1 flex flex-col gap-1">
            <div class="flex items-center justify-between gap-3">
                <h1 class="m-0 text-28 font-semibold tracking-[-0.02em]">Користувачі</h1>
                <button
                    v-if="can('create', 'users')"
                    type="button"
                    class="flex h-11 items-center gap-1.5 rounded-full bg-accent pr-4 pl-3 text-15 font-semibold text-white hover:bg-accent-hover"
                    @click="open(null)"
                >
                    <Icon name="plus" :size="20" />
                    Запросити
                </button>
            </div>
            <p class="m-0 text-14 leading-[1.45] text-ink-3">Кожен користувач бачить усі ФОП організації. Доступ до окремих ФОП — у наступній версії.</p>
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
                        <span class="truncate text-16">{{ user.name }}<span v-if="user.id === me?.id" class="text-13 text-ink-3"> (ви)</span></span>
                        <span class="truncate text-13 text-ink-3">{{ isPending(user) ? `запрошено · ${user.email}` : user.email }}</span>
                    </span>
                    <span
                        v-if="roleOf(user)?.value === 'owner'"
                        class="shrink-0 rounded-badge bg-ink px-2 py-[3px] text-12 font-semibold text-white"
                    >
                        Власник
                    </span>
                    <StatusBadge v-else-if="roleOf(user)" :label="roleOf(user)!.label" :tone="roleOf(user)!.tone" />
                </button>
            </div>
        </LoadState>

        <section class="flex flex-col gap-2">
            <SectionTitle>Ролі</SectionTitle>
            <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-3.5">
                <div v-for="role in ROLES" :key="role.value" class="flex flex-col gap-0.5 py-3">
                    <span class="text-16 font-semibold">{{ role.label }}</span>
                    <span class="text-14 text-ink-2">{{ role.text }}</span>
                </div>
            </div>
        </section>

        <BottomSheet v-model:open="sheetOpen" :title="editing ? editing.name : 'Запросити користувача'">
            <template v-if="!editing">
                <TextField v-model="draft.name" label="Ім'я" :error="fieldError(sheetError, 'name')" />
                <TextField v-model="draft.email" label="Email" type="email" :error="fieldError(sheetError, 'email')" />
            </template>
            <div class="flex flex-col gap-1.5">
                <span id="user-role" class="text-13 font-medium text-ink-2">Роль</span>
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
            <p v-if="!editing" class="m-0 text-13 text-ink-3">Людина отримає лист і сама задасть пароль.</p>
            <p v-if="notice" class="m-0 text-14 text-positive" role="status">{{ notice }}</p>
            <p v-if="sheetError && !fieldError(sheetError, 'name') && !fieldError(sheetError, 'email')" class="m-0 text-14 text-negative" role="alert">
                {{ errorMessage(sheetError) }}
            </p>
            <template #footer>
                <div class="flex flex-col gap-2">
                    <PrimaryButton :busy="busy" :disabled="!draft.name.trim() || (!editing && !draft.email.trim())" @click="save">
                        {{ editing ? 'Зберегти роль' : 'Надіслати запрошення' }}
                    </PrimaryButton>
                    <div v-if="editing && !isSelf" class="grid grid-cols-2 gap-2">
                        <SecondaryButton v-if="isPending(editing)" :busy="busy" @click="resend">Запросити знову</SecondaryButton>
                        <SecondaryButton v-if="can('delete', 'users')" tone="negative" :busy="busy" :class="isPending(editing) ? '' : 'col-span-2'" @click="remove">
                            {{ confirmingRemoval ? 'Так, видалити' : 'Видалити' }}
                        </SecondaryButton>
                    </div>
                </div>
            </template>
        </BottomSheet>
    </SubScreen>
</template>
