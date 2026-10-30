<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ProfileSwitchList from '@/components/profile/ProfileSwitchList.vue';
import Avatar from '@/components/ui/Avatar.vue';
import ConfirmSheet from '@/components/ui/ConfirmSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import type { IconName } from '@/components/ui/icons';
import LanguageOptions from '@/components/ui/LanguageOptions.vue';
import NewPill from '@/components/ui/NewPill.vue';
import Popover from '@/components/ui/Popover.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useOverlay } from '@/composables/useOverlay';
import { useSession } from '@/composables/useSession';
import { LOCALE_NAMES, locale, t } from '@/i18n';
import { tabPaths } from '@/router';
import { roleName } from '@/utils/statuses';

/**
 * The sidebar of the desktop layout (D-Home, design.md §17.2), 248 px: the ФОП card, whose «Змінити ФОП» opens the
 * ФОП popover; the sections — Головна, Інвойси, Облік, Податки, Книги and Налаштування, whose page holds the money,
 * the clients, the ФОП's and the invoices' settings and the management, and which stays current on each of them —;
 * and at the foot Довідка, the language row (its popover), the user with the role, and «Вийти», which asks first. It
 * replaces the tab bar and Ще. Below 1024 px it folds into a 64 px rail (☰, the ФОП's avatar and the sections' icons,
 * and at its foot ?, 🌐 and «Вийти»), whose ☰ opens the whole sidebar over the page; a section chosen, ✕, Escape or
 * a click beside it closes it.
 */
interface NavItem {
    key: string;
    label: string;
    icon: IconName;
    to: string;
    /** The route names, which make the item current. */
    names: string[];
}

const route = useRoute();
const router = useRouter();
const { user, can, signOut } = useSession();
const { profile, profiles, reset } = useCurrentProfile();

const { isCompact } = useBreakpoint();

/* Below 1024 px: the sidebar opened over the page from the rail */
const expanded = ref(false);
const drawer = ref<HTMLElement | null>(null);
const drawerOpen = computed(() => isCompact.value && expanded.value);
const { layer } = useOverlay(drawerOpen, () => {
    expanded.value = false;
}, drawer);

watch([() => route.fullPath, isCompact], () => {
    expanded.value = false;
});

const profilesOpen = ref(false);
const languageOpen = ref(false);
const switchButton = ref<HTMLElement | null>(null);
const languageButton = ref<HTMLElement | null>(null);
const railLanguageButton = ref<HTMLElement | null>(null);
/** The language popover hangs from the rail's 🌐 while the rail shows, else from the sidebar's language row. */
const languageAnchor = computed(() => (isCompact.value && !expanded.value ? railLanguageButton.value : languageButton.value));
const signOutOpen = ref(false);
const signingOut = ref(false);

/** The sections; Налаштування is current on every page its page leads to. */
const items = computed<NavItem[]>(() => [
    { key: 'home', label: t('shell.nav.home'), icon: 'home', to: tabPaths.home ?? '/', names: ['home', 'alerts'] },
    { key: 'invoices', label: t('shell.nav.invoices'), icon: 'invoice', to: tabPaths.invoices ?? '/invoices', names: ['invoices', 'invoice', 'invoice-new', 'invoice-edit'] },
    { key: 'accounting', label: t('shell.nav.accounting'), icon: 'ledger', to: tabPaths.accounting ?? '/accounting', names: ['accounting', 'payment', 'payment-new', 'payment-edit', 'payment-pick'] },
    { key: 'taxes', label: t('shell.nav.taxes'), icon: 'bank', to: tabPaths.taxes ?? '/taxes', names: ['taxes', 'tax-payment', 'tax-payment-new', 'esv-payment', 'esv-payment-new'] },
    { key: 'books', label: t('shell.nav.booksShort'), icon: 'book', to: '/books', names: ['books'] },
    {
        key: 'settings',
        label: t('shell.nav.settings'),
        icon: 'settings',
        to: '/settings',
        names: [
            'more', 'profiles', 'profile-new', 'settings-profile', 'settings-taxes', 'settings-numbering', 'settings-invoice-document',
            'accounts', 'account', 'account-new', 'account-edit', 'transfers', 'transfer', 'transfer-new', 'transfer-edit', 'fees',
            'clients', 'client', 'client-new', 'client-edit', 'contract', 'contract-new',
            'users', 'backups', 'backup-policy', 'backup-import',
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
    <div>
        <nav v-if="isCompact" :aria-label="t('shell.nav.sidebar')" class="flex h-dvh w-16 shrink-0 flex-col items-center gap-2 border-r border-line bg-surface pt-4 pb-4">
            <button
                type="button"
                :aria-label="t('shell.nav.openMenu')"
                :aria-expanded="expanded ? 'true' : 'false'"
                class="flex size-10 shrink-0 items-center justify-center rounded-[10px] text-ink hover:bg-paper"
                @click="expanded = true"
            >
                <Icon name="menu" :size="22" />
            </button>
            <RouterLink v-if="profile" :to="{ name: 'settings-profile' }" :aria-label="profile.short_name" :title="profile.short_name" class="mb-1 shrink-0 no-underline">
                <Avatar :initials="profile.initials" variant="dark" :size="36" />
            </RouterLink>
            <div class="scrollbar-none flex min-h-0 w-full flex-1 flex-col items-center gap-px overflow-y-auto">
                <RouterLink
                    v-for="item in items"
                    :key="item.key"
                    :to="item.to"
                    :aria-label="item.label"
                    :title="item.label"
                    :aria-current="item.names.includes(activeName) ? 'page' : undefined"
                    class="flex size-10 shrink-0 items-center justify-center rounded-[10px] no-underline"
                    :class="item.names.includes(activeName) ? 'bg-accent-soft text-accent-hover hover:text-accent-hover' : 'text-ink hover:bg-paper hover:text-ink'"
                >
                    <Icon :name="item.icon" :size="20" />
                </RouterLink>
            </div>
            <div class="flex w-10 shrink-0 flex-col items-center gap-1 border-t border-divider pt-2">
                <RouterLink
                    :to="{ name: 'help' }"
                    :aria-label="t('help.title')"
                    :title="t('help.title')"
                    :aria-current="activeName === 'help' ? 'page' : undefined"
                    class="flex size-10 items-center justify-center rounded-[10px] no-underline"
                    :class="activeName === 'help' ? 'bg-accent-soft text-accent-hover hover:text-accent-hover' : 'text-ink hover:bg-paper hover:text-ink'"
                >
                    <Icon name="help" :size="20" />
                </RouterLink>
                <button
                    ref="railLanguageButton"
                    type="button"
                    :aria-label="`${t('shell.more.appLanguage')}: ${LOCALE_NAMES[locale]}`"
                    :title="`${t('shell.more.appLanguage')}: ${LOCALE_NAMES[locale]}`"
                    :aria-expanded="languageOpen ? 'true' : 'false'"
                    class="flex size-10 items-center justify-center rounded-[10px] text-ink hover:bg-paper"
                    @click="languageOpen = !languageOpen"
                >
                    <Icon name="globe" :size="20" />
                </button>
                <button
                    type="button"
                    :aria-label="t('shell.more.signOut')"
                    :title="t('shell.more.signOut')"
                    class="flex size-10 items-center justify-center rounded-[10px] text-negative hover:bg-paper"
                    :disabled="signingOut"
                    @click="signOutOpen = true"
                >
                    <Icon name="signOut" :size="20" />
                </button>
            </div>
        </nav>

        <Teleport to="body" :disabled="!isCompact">
            <div v-if="drawerOpen" class="fixed inset-0 bg-[rgb(23_25_31/0.38)]" :style="{ zIndex: 40 + layer * 10 }" aria-hidden="true" @click="expanded = false" />
            <aside
                v-if="!isCompact || expanded"
                ref="drawer"
                :aria-label="t('shell.nav.sidebar')"
                :role="isCompact ? 'dialog' : undefined"
                :aria-modal="isCompact ? 'true' : undefined"
                :tabindex="isCompact ? -1 : undefined"
                class="flex h-dvh w-62 shrink-0 flex-col gap-3 border-r border-line bg-surface px-3.5 pt-5 pb-4 outline-none"
                :class="isCompact ? 'fixed inset-y-0 left-0 shadow-[16px_0_40px_rgb(23_25_31/0.2)]' : ''"
                :style="isCompact ? { zIndex: 50 + layer * 10 } : undefined"
            >
                <button
                    v-if="isCompact"
                    type="button"
                    :aria-label="t('shell.ui.close')"
                    class="-mt-1 -mb-1 flex size-10 shrink-0 items-center justify-center self-end rounded-[10px] text-ink hover:bg-paper"
                    @click="expanded = false"
                >
                    <Icon name="close" :size="20" />
                </button>
                <div v-if="profile" class="flex flex-col rounded-button border border-line bg-paper px-2.5">
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
                    <RouterLink
                        v-for="item in items"
                        :key="item.key"
                        :to="item.to"
                        :aria-current="item.names.includes(activeName) ? 'page' : undefined"
                        class="flex h-9 shrink-0 items-center gap-2.5 rounded-[10px] px-2.5 text-14 no-underline"
                        :class="item.names.includes(activeName) ? 'bg-accent-soft font-semibold text-accent-hover hover:text-accent-hover' : 'font-medium text-ink hover:bg-paper hover:text-ink'"
                    >
                        <Icon :name="item.icon" :size="20" />
                        <span class="flex-1 truncate">{{ item.label }}</span>
                    </RouterLink>
                </nav>

                <div class="flex flex-col gap-0.5 border-t border-divider pt-3">
                    <RouterLink
                        :to="{ name: 'help' }"
                        :aria-current="activeName === 'help' ? 'page' : undefined"
                        class="flex min-h-9 items-center gap-2.5 rounded-[10px] px-2.5 text-14 no-underline"
                        :class="activeName === 'help' ? 'bg-accent-soft font-semibold text-accent-hover hover:text-accent-hover' : 'text-ink hover:bg-paper hover:text-ink'"
                    >
                        <Icon name="help" :size="20" />
                        <span class="flex-1">{{ t('help.title') }}</span>
                    </RouterLink>
                    <button
                        ref="languageButton"
                        type="button"
                        :aria-label="`${t('shell.more.appLanguage')}: ${LOCALE_NAMES[locale]}`"
                        :aria-expanded="languageOpen ? 'true' : 'false'"
                        class="flex min-h-9 items-center gap-2.5 rounded-[10px] px-2.5 text-14 text-ink hover:bg-paper"
                        @click="languageOpen = !languageOpen"
                    >
                        <Icon name="globe" :size="20" />
                        <span class="flex-1 text-left">{{ LOCALE_NAMES[locale] }}</span>
                        <span class="num text-12 text-ink-3 uppercase">{{ locale }}</span>
                        <Icon name="chevronDown" :size="16" class="text-ink-3" />
                    </button>
                    <p v-if="user" class="m-0 truncate px-2.5 py-1 text-12 text-ink-3">{{ user.email }} · {{ user.roles.map(roleName).join(', ') }}</p>
                    <button
                        type="button"
                        class="flex min-h-9 items-center gap-2.5 rounded-[10px] px-2.5 text-14 font-medium text-negative hover:bg-paper"
                        :disabled="signingOut"
                        @click="signOutOpen = true"
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

            </aside>
        </Teleport>

        <Popover v-model:open="languageOpen" :anchor="languageAnchor" placement="top-start" :width="280" :label="t('shell.more.appLanguage')">
            <div class="flex flex-col gap-3 p-3">
                <span class="px-1 text-12 font-semibold tracking-[0.04em] text-ink-3 uppercase">{{ t('shell.more.appLanguage') }}</span>
                <LanguageOptions :note="t('shell.more.appLanguageShortNote')" @chosen="languageOpen = false" />
            </div>
        </Popover>

        <ConfirmSheet
            v-model:open="signOutOpen"
            :title="t('shell.more.signOutQuestion')"
            :confirm-label="t('shell.more.signOut')"
            :busy="signingOut"
            destructive
            @confirm="leave"
        >
            <p class="m-0">{{ t('shell.more.signOutExplained') }}</p>
        </ConfirmSheet>
    </div>
</template>
