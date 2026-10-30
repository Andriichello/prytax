<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { Account, ApiResponse, BackupStatusSummary, Client, Paginated } from '@/api/types';
import TabScreen from '@/components/layout/TabScreen.vue';
import Avatar from '@/components/ui/Avatar.vue';
import Icon from '@/components/ui/Icon.vue';
import type { IconName } from '@/components/ui/icons';
import LanguageSheet from '@/components/ui/LanguageSheet.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import Translated from '@/components/ui/Translated.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { LOCALE_NAMES, locale, t } from '@/i18n';
import { kyivTime, relativeDay } from '@/utils/backups';
import { styleName } from '@/utils/invoiceStyles';
import { roleName } from '@/utils/statuses';

/**
 * Налаштування (Ще on a phone; M-More, design.md §16.5, §17.2), one column: the current ФОП with switching to another
 * ФОП of the organization; the ФОП's settings (its profile, its taxes and notifications); the money (accounts,
 * transfers and fees); the clients and invoices (clients and contracts, the numbering, the document); the management
 * (users and backups, shown only to those who see either). On desktop the sidebar's Налаштування opens it; on a phone
 * the app's language and signing out end it (the sidebar's foot has them on desktop). Комісії opens from Перекази.
 */
const router = useRouter();
const { user, can, signOut } = useSession();
const { profile, profiles, profileId, path, reset, settings } = useCurrentProfile();
const { isDesktop } = useBreakpoint();

const languageOpen = ref(false);

const { data: counts } = useLoader(async (signal) => {
    const [accounts, clients] = await Promise.all([
        api.get<Paginated<Account>>(path('accounts'), { page: { size: 1 } }, signal),
        api.get<Paginated<Client>>(path('clients'), { page: { size: 1 } }, signal),
    ]);

    return { accounts: accounts.meta.total, clients: clients.meta.total };
}, [profileId]);

/** «сьогодні 02:37»: when the organization's last copy was made, beside Резервні копії. */
const { data: backupStatus } = useLoader(async (signal) => (can('viewAny', 'backup-runs')
    ? (await api.get<ApiResponse<BackupStatusSummary>>('organization/backup-status', undefined, signal)).data
    : null), []);

const lastBackup = computed(() => (backupStatus.value?.last_completed_at
    ? `${relativeDay(backupStatus.value.last_completed_at).toLocaleLowerCase()} ${kyivTime(backupStatus.value.last_completed_at)}`
    : null));

interface MoreItem {
    label: string;
    icon: IconName;
    to: string;
    count?: number | null;
    value?: string;
    visible?: boolean;
}

interface MoreSection {
    key: string;
    title: string;
    items: MoreItem[];
}

/** The groups after the ФОП, each with the items the user may see; a group without any isn't shown. */
const sections = computed<MoreSection[]>(() => {
    const all: MoreSection[] = [
        {
            key: 'fop',
            title: t('shell.more.fopSettings'),
            items: [
                { label: t('shell.nav.profileDetails'), icon: 'person', to: '/settings/profile' },
                { label: t('settings.taxes.title'), icon: 'sliders', to: '/settings/taxes' },
            ],
        },
        {
            key: 'money',
            title: t('shell.more.money'),
            items: [
                { label: t('money.accounts.title'), icon: 'account', to: '/accounts', count: counts.value?.accounts },
                { label: t('shell.more.transfersFees'), icon: 'transfer', to: '/transfers' },
            ],
        },
        {
            key: 'clients',
            title: t('shell.more.clientsInvoices'),
            items: [
                { label: t('shell.more.clientsContracts'), icon: 'briefcase', to: '/clients', count: counts.value?.clients },
                { label: t('settings.numbering.title'), icon: 'hash', to: '/settings/numbering' },
                { label: t('settings.document.title'), icon: 'invoice', to: '/settings/invoice-document', value: styleName(settings.value?.invoice.style) },
            ],
        },
        {
            key: 'management',
            title: t('shell.nav.management'),
            items: [
                { label: t('shell.more.usersRoles'), icon: 'people', to: '/users', visible: can('viewAny', 'users') },
                { label: t('settings.backups.title'), icon: 'archive', to: '/backups', value: lastBackup.value ?? undefined, visible: can('viewAny', 'backup-runs') },
            ],
        },
    ];

    return all
        .map((section) => ({ ...section, items: section.items.filter((item) => item.visible !== false) }))
        .filter((section) => section.items.length > 0);
});

const signingOut = ref(false);

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
    <TabScreen :title="isDesktop ? t('shell.nav.settings') : t('shell.more.more')">
        <section v-if="profile" class="flex flex-col gap-2">
            <SectionTitle>{{ t('shell.profiles.fop') }}</SectionTitle>
            <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                <div class="flex items-center gap-3 py-3.5">
                    <Avatar :initials="profile.initials" variant="dark" :size="44" />
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="truncate text-17 font-semibold">{{ profile.full_name }}</span>
                        <span class="text-13 text-ink-3">
                            <Translated :text="t('shell.profiles.fopLine', { group: profile.tax_group })">
                                <template #taxId>
                                    <Translated :text="profile.tax_id_kind === 'passport' ? t('shell.profiles.passportMasked') : t('shell.profiles.taxIdMasked')">
                                        <template #masked><span class="num">{{ profile.tax_id_masked }}</span></template>
                                    </Translated>
                                </template>
                            </Translated>
                        </span>
                    </span>
                </div>
                <RouterLink :to="{ name: 'profiles' }" class="flex min-h-13 items-center gap-2.5 text-accent no-underline hover:text-accent-hover">
                    <Icon name="transfer" :size="20" />
                    <span class="flex-1 text-16 font-medium">{{ t('shell.profiles.change') }}</span>
                    <span class="text-14 text-ink-3">{{ t('shell.profiles.inOrganization', { count: profiles.length }) }}</span>
                    <Icon name="chevronRight" :size="18" class="text-ink-2" />
                </RouterLink>
            </div>
        </section>

        <section v-for="section in sections" :key="section.key" class="flex flex-col gap-2">
            <SectionTitle>{{ section.title }}</SectionTitle>
            <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                <RouterLink
                    v-for="item in section.items"
                    :key="item.to"
                    :to="item.to"
                    class="flex min-h-14 items-center gap-3.5 text-ink no-underline hover:text-ink"
                >
                    <Icon :name="item.icon" :size="22" />
                    <span class="flex-1 text-16">{{ item.label }}</span>
                    <span v-if="item.count" class="num text-14 text-ink-3">{{ item.count }}</span>
                    <span v-else-if="item.value" class="text-14 text-ink-3">{{ item.value }}</span>
                    <Icon name="chevronRight" :size="18" class="text-ink-2" />
                </RouterLink>
            </div>
        </section>

        <template v-if="!isDesktop">
            <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                <button
                    type="button"
                    class="flex min-h-14 items-center gap-3.5 text-left text-ink"
                    @click="languageOpen = true"
                >
                    <Icon name="globe" :size="22" />
                    <span class="flex-1 text-16">{{ t('shell.more.appLanguage') }}</span>
                    <span class="text-14 text-ink-3">{{ LOCALE_NAMES[locale] }}</span>
                    <Icon name="chevronRight" :size="18" class="text-ink-2" />
                </button>
                <button
                    type="button"
                    class="flex min-h-14 items-center gap-3.5 text-left text-negative"
                    :disabled="signingOut"
                    @click="leave"
                >
                    <Icon name="signOut" :size="22" />
                    <span class="flex-1 text-16">{{ t('shell.more.signOut') }}</span>
                </button>
            </div>

            <p v-if="user" class="m-0 text-center text-13 text-ink-3">
                PryTax · {{ user.email }} · {{ user.roles.map(roleName).join(', ') }}
            </p>

            <LanguageSheet v-model:open="languageOpen" :note="t('shell.more.appLanguageNote')" />
        </template>
    </TabScreen>
</template>
