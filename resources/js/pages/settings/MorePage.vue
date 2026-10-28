<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { Account, Client, Paginated } from '@/api/types';
import TabScreen from '@/components/layout/TabScreen.vue';
import Avatar from '@/components/ui/Avatar.vue';
import Icon from '@/components/ui/Icon.vue';
import type { IconName } from '@/components/ui/icons';
import LanguageSheet from '@/components/ui/LanguageSheet.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import Translated from '@/components/ui/Translated.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { LOCALE_NAMES, locale, t } from '@/i18n';
import { styleName } from '@/utils/invoiceStyles';
import { roleName } from '@/utils/statuses';

/**
 * Ще (M-More, design.md §16.5): the «ФОП» card, which switches to another ФОП of the organization; money and
 * counterparties, the ФОП's settings, the invoices' numbering and document, the organization (users, backups, the
 * app's language) with signing out. Комісії opens from Перекази.
 */
const router = useRouter();
const { user, can, signOut } = useSession();
const { profile, profiles, profileId, path, reset, settings } = useCurrentProfile();

const languageOpen = ref(false);

const { data: counts } = useLoader(async (signal) => {
    const [accounts, clients] = await Promise.all([
        api.get<Paginated<Account>>(path('accounts'), { page: { size: 1 } }, signal),
        api.get<Paginated<Client>>(path('clients'), { page: { size: 1 } }, signal),
    ]);

    return { accounts: accounts.meta.total, clients: clients.meta.total };
}, [profileId]);

const sections = computed<{ key: string; title: string; items: { label: string; icon: IconName; to: string; count?: number | null; value?: string; visible?: boolean }[] }[]>(() => [
    {
        key: 'money',
        title: t('shell.more.moneyCounterparties'),
        items: [
            { label: t('money.accounts.title'), icon: 'account', to: '/accounts', count: counts.value?.accounts },
            { label: t('shell.more.transfersFees'), icon: 'transfer', to: '/transfers' },
            { label: t('shell.more.clientsContracts'), icon: 'briefcase', to: '/clients', count: counts.value?.clients },
        ],
    },
    {
        key: 'settings',
        title: t('shell.more.fopSettings'),
        items: [
            { label: t('shell.more.fopProfileDetails'), icon: 'person', to: '/settings/profile' },
            { label: t('settings.taxes.title'), icon: 'sliders', to: '/settings/taxes' },
        ],
    },
    {
        key: 'invoices',
        title: t('shell.nav.invoices'),
        items: [
            { label: t('settings.numbering.title'), icon: 'hash', to: '/settings/numbering' },
            { label: t('settings.document.title'), icon: 'invoice', to: '/settings/invoice-document', value: styleName(settings.value?.invoice.style) },
        ],
    },
    {
        key: 'organization',
        title: t('shell.more.organization'),
        items: [
            { label: t('shell.more.usersRoles'), icon: 'people', to: '/users', visible: can('viewAny', 'users') },
            { label: t('settings.backups.title'), icon: 'download', to: '/settings/backups', visible: can('viewAny', 'backup-runs') },
        ],
    },
]);

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
    <TabScreen :title="t('shell.more.more')">
        <section v-if="profile" class="flex flex-col gap-2">
            <SectionTitle>{{ t('shell.profiles.fop') }}</SectionTitle>
            <div class="flex flex-col rounded-card border border-line bg-surface px-4">
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
                <RouterLink :to="{ name: 'profiles' }" class="flex min-h-13 items-center gap-2.5 border-t border-divider text-accent no-underline hover:text-accent-hover">
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
                <template v-for="item in section.items" :key="item.to">
                    <RouterLink
                        v-if="item.visible !== false"
                        :to="item.to"
                        class="flex min-h-14 items-center gap-3.5 text-ink no-underline hover:text-ink"
                    >
                        <Icon :name="item.icon" :size="22" />
                        <span class="flex-1 text-16">{{ item.label }}</span>
                        <span v-if="item.count" class="num text-14 text-ink-3">{{ item.count }}</span>
                        <span v-else-if="item.value" class="text-14 text-ink-3">{{ item.value }}</span>
                        <Icon name="chevronRight" :size="18" class="text-ink-2" />
                    </RouterLink>
                </template>
                <button
                    v-if="section.key === 'organization'"
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
                    v-if="section.key === 'organization'"
                    type="button"
                    class="flex min-h-14 items-center gap-3.5 text-left text-negative"
                    :disabled="signingOut"
                    @click="leave"
                >
                    <Icon name="signOut" :size="22" />
                    <span class="flex-1 text-16">{{ t('shell.more.signOut') }}</span>
                </button>
            </div>
        </section>


        <p v-if="user" class="m-0 text-center text-13 text-ink-3">
            PryTax · {{ user.email }} · {{ user.roles.map(roleName).join(', ') }}
        </p>

        <LanguageSheet v-model:open="languageOpen" :note="t('shell.more.appLanguageNote')" />
    </TabScreen>
</template>
