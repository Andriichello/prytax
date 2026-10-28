<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { Account, Client, Paginated } from '@/api/types';
import ProfileSwitchList from '@/components/profile/ProfileSwitchList.vue';
import Avatar from '@/components/ui/Avatar.vue';
import Icon from '@/components/ui/Icon.vue';
import type { IconName } from '@/components/ui/icons';
import LanguageOptions from '@/components/ui/LanguageOptions.vue';
import NewPill from '@/components/ui/NewPill.vue';
import Popover from '@/components/ui/Popover.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { LOCALE_NAMES, locale, t } from '@/i18n';
import { tabPaths } from '@/router';
import { roleName } from '@/utils/statuses';

/**
 * The sidebar of the desktop layout (D-Home, design.md §17.2), 248 px: the logo; the ФОП card, whose «Змінити ФОП»
 * opens the ФОП popover; the main sections, money and counterparties (with counts) and the settings; and at the foot
 * the language row (its popover), the user with the role, and «Вийти». It replaces the tab bar and Ще. The active
 * item is the route's section; it fits 900 px, below which the sections scroll and the foot stays.
 */
interface NavItem {
    key: string;
    label: string;
    icon: IconName;
    to: string;
    /** The route names, which make the item current. */
    names: string[];
    count?: number | null;
    visible?: boolean;
}

const route = useRoute();
const router = useRouter();
const { user, can, signOut } = useSession();
const { profile, profiles, profileId, path, reset } = useCurrentProfile();

const profilesOpen = ref(false);
const languageOpen = ref(false);
const switchButton = ref<HTMLElement | null>(null);
const languageButton = ref<HTMLElement | null>(null);
const signingOut = ref(false);

const { data: counts } = useLoader(async (signal) => {
    if (profileId.value === null) {
        return null;
    }

    const [accounts, clients] = await Promise.all([
        api.get<Paginated<Account>>(path('accounts'), { page: { size: 1 } }, signal),
        api.get<Paginated<Client>>(path('clients'), { page: { size: 1 } }, signal),
    ]);

    return { accounts: accounts.meta.total, clients: clients.meta.total };
}, [profileId]);

const groups = computed<{ key: string; title?: string; items: NavItem[] }[]>(() => [
    {
        key: 'main',
        items: [
            { key: 'home', label: t('shell.nav.home'), icon: 'home', to: tabPaths.home ?? '/', names: ['home', 'alerts'] },
            { key: 'invoices', label: t('shell.nav.invoices'), icon: 'invoice', to: tabPaths.invoices ?? '/invoices', names: ['invoices', 'invoice', 'invoice-new', 'invoice-edit'] },
            { key: 'accounting', label: t('shell.nav.accounting'), icon: 'ledger', to: tabPaths.accounting ?? '/accounting', names: ['accounting', 'payment', 'payment-new', 'payment-edit', 'payment-pick'] },
            { key: 'taxes', label: t('shell.nav.taxes'), icon: 'bank', to: tabPaths.taxes ?? '/taxes', names: ['taxes', 'tax-payment', 'tax-payment-new', 'esv-payment', 'esv-payment-new'] },
            { key: 'books', label: t('shell.nav.booksShort'), icon: 'book', to: '/books', names: ['books'] },
        ],
    },
    {
        key: 'money',
        title: t('shell.more.moneyCounterparties'),
        items: [
            { key: 'accounts', label: t('money.accounts.title'), icon: 'account', to: '/accounts', names: ['accounts', 'account', 'account-new', 'account-edit'], count: counts.value?.accounts },
            { key: 'transfers', label: t('shell.more.transfersFees'), icon: 'transfer', to: '/transfers', names: ['transfers', 'transfer', 'transfer-new', 'transfer-edit', 'fees'] },
            { key: 'clients', label: t('shell.more.clientsContracts'), icon: 'briefcase', to: '/clients', names: ['clients', 'client', 'client-new', 'client-edit', 'contract', 'contract-new'], count: counts.value?.clients },
        ],
    },
    {
        key: 'settings',
        title: t('shell.nav.settings'),
        items: [
            { key: 'profile', label: t('shell.nav.profileDetails'), icon: 'person', to: '/settings/profile', names: ['settings-profile'] },
            { key: 'taxes-settings', label: t('settings.taxes.title'), icon: 'sliders', to: '/settings/taxes', names: ['settings-taxes'] },
            { key: 'numbering', label: t('settings.numbering.title'), icon: 'hash', to: '/settings/numbering', names: ['settings-numbering'] },
            { key: 'document', label: t('settings.document.title'), icon: 'invoice', to: '/settings/invoice-document', names: ['settings-invoice-document'] },
            { key: 'users', label: t('shell.more.usersRoles'), icon: 'people', to: '/users', names: ['users'], visible: can('viewAny', 'users') },
            { key: 'backups', label: t('settings.backups.title'), icon: 'download', to: '/settings/backups', names: ['settings-backups'], visible: can('viewAny', 'backup-runs') },
        ],
    },
]);

const activeName = computed(() => String(route.name ?? ''));

async function leave(): Promise<void> {
    signingOut.value = true;

    try {
        await signOut();
    } finally {
        reset();
        signingOut.value = false;
        await router.replace({ name: 'login' });
    }
}
</script>

<template>
    <aside :aria-label="t('shell.nav.sidebar')" class="flex h-dvh w-62 shrink-0 flex-col gap-3 border-r border-line bg-surface px-4 pt-5 pb-4">
        <div class="flex items-center gap-2.5 px-1 pt-1">
            <span class="flex size-8 items-center justify-center rounded-[9px] bg-ink text-white">
                <Icon name="invoice" :size="18" />
            </span>
            <span class="text-20 font-bold tracking-[-0.02em]">Pry<span class="text-accent">Tax</span></span>
        </div>

        <div v-if="profile" class="flex flex-col rounded-button border border-line bg-paper px-3">
            <RouterLink :to="{ name: 'settings-profile' }" class="flex items-center gap-2.5 py-2.5 text-ink no-underline hover:text-ink">
                <Avatar :initials="profile.initials" variant="dark" :size="36" />
                <span class="flex min-w-0 flex-1 flex-col leading-[1.25]">
                    <span class="truncate text-14 font-semibold">{{ profile.short_name }}</span>
                    <span class="text-12 text-ink-3">{{ t('shell.nav.fopGroup', { group: profile.tax_group }) }}</span>
                </span>
            </RouterLink>
            <button
                ref="switchButton"
                type="button"
                :aria-expanded="profilesOpen ? 'true' : 'false'"
                class="flex min-h-10 items-center gap-2 border-t border-divider text-13 font-medium text-accent"
                @click="profilesOpen = !profilesOpen"
            >
                <Icon name="transfer" :size="16" />
                <span class="flex-1 text-left">{{ t('shell.profiles.change') }}</span>
                <span class="num text-12 font-normal text-ink-3">{{ profiles.length }}</span>
                <Icon name="chevronRight" :size="16" class="text-ink-3" />
            </button>
        </div>

        <nav :aria-label="t('shell.nav.mainNavigation')" class="scrollbar-none -mx-1 flex min-h-0 flex-1 flex-col gap-px overflow-y-auto px-1">
            <template v-for="group in groups" :key="group.key">
                <span v-if="group.title" class="px-3 pt-3 pb-1 text-12 font-semibold tracking-[0.05em] text-ink-3 uppercase">{{ group.title }}</span>
                <template v-for="item in group.items" :key="item.key">
                    <RouterLink
                        v-if="item.visible !== false"
                        :to="item.to"
                        :aria-current="item.names.includes(activeName) ? 'page' : undefined"
                        class="flex h-9 shrink-0 items-center gap-3 rounded-[10px] px-3 text-14 no-underline"
                        :class="item.names.includes(activeName) ? 'bg-accent-soft font-semibold text-accent-hover hover:text-accent-hover' : 'font-medium text-ink hover:bg-paper hover:text-ink'"
                    >
                        <Icon :name="item.icon" :size="20" />
                        <span class="flex-1 truncate">{{ item.label }}</span>
                        <span v-if="item.count" class="num text-12 font-normal text-ink-3">{{ item.count }}</span>
                    </RouterLink>
                </template>
            </template>
        </nav>

        <div class="flex flex-col gap-0.5 border-t border-divider pt-3">
            <button
                ref="languageButton"
                type="button"
                :aria-label="`${t('shell.more.appLanguage')}: ${LOCALE_NAMES[locale]}`"
                :aria-expanded="languageOpen ? 'true' : 'false'"
                class="flex min-h-9 items-center gap-3 rounded-[10px] px-3 text-14 text-ink hover:bg-paper"
                @click="languageOpen = !languageOpen"
            >
                <Icon name="globe" :size="20" />
                <span class="flex-1 text-left">{{ LOCALE_NAMES[locale] }}</span>
                <span class="num text-12 text-ink-3 uppercase">{{ locale }}</span>
                <Icon name="chevronDown" :size="16" class="text-ink-3" />
            </button>
            <p v-if="user" class="m-0 truncate px-3 py-1 text-12 text-ink-3">{{ user.email }} · {{ user.roles.map(roleName).join(', ') }}</p>
            <button
                type="button"
                class="flex min-h-9 items-center gap-3 rounded-[10px] px-3 text-14 font-medium text-negative hover:bg-paper"
                :disabled="signingOut"
                @click="leave"
            >
                <Icon name="signOut" :size="20" />
                <span class="flex-1 text-left">{{ t('shell.more.signOut') }}</span>
            </button>
        </div>

        <Popover v-model:open="profilesOpen" :anchor="switchButton" :width="380" :label="t('shell.profiles.title')">
            <div class="flex flex-col gap-3 p-4">
                <div class="flex items-start justify-between gap-3">
                    <h2 class="m-0 text-17 font-semibold">{{ t('shell.profiles.title') }}</h2>
                    <button type="button" :aria-label="t('shell.ui.close')" class="-mt-1 -mr-1 flex size-8 items-center justify-center text-ink" @click="profilesOpen = false">
                        <Icon name="close" :size="18" />
                    </button>
                </div>
                <ProfileSwitchList @chosen="profilesOpen = false" />
                <NewPill v-if="can('create', 'profiles')" :to="{ name: 'profile-new' }" :label="t('shell.profiles.newProfile')" class="justify-center" @click="profilesOpen = false" />
            </div>
        </Popover>

        <Popover v-model:open="languageOpen" :anchor="languageButton" placement="top-start" :width="280" :label="t('shell.more.appLanguage')">
            <div class="flex flex-col gap-3 p-3">
                <span class="px-1 text-12 font-semibold tracking-[0.04em] text-ink-3 uppercase">{{ t('shell.more.appLanguage') }}</span>
                <LanguageOptions :note="t('shell.more.appLanguageShortNote')" @chosen="languageOpen = false" />
            </div>
        </Popover>
    </aside>
</template>
