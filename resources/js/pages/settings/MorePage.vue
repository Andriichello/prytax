<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { Account, Client, Paginated } from '@/api/types';
import TabScreen from '@/components/layout/TabScreen.vue';
import Icon from '@/components/ui/Icon.vue';
import type { IconName } from '@/components/ui/icons';
import LanguageSwitch from '@/components/ui/LanguageSwitch.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { styleName } from '@/utils/invoiceStyles';

/**
 * Ще (M-More, design.md §16.5): money and counterparties, the ФОП's settings, the invoices' numbering and document,
 * the organization with signing out, and the language. Комісії opens from Перекази.
 */

/**
 * A role as the footnote names it: власник, бухгалтер, перегляд.
 */
function roleName(role: string): string {
    switch (role) {
        case 'owner':
            return t('core.roles.owner');
        case 'accountant':
            return t('core.roles.accountant');
        case 'viewer':
            return t('core.roles.viewer');
        default:
            return role;
    }
}

const router = useRouter();
const { user, can, signOut } = useSession();
const { profileId, path, reset, settings } = useCurrentProfile();

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
                    class="flex min-h-14 items-center gap-3.5 text-left text-negative"
                    :disabled="signingOut"
                    @click="leave"
                >
                    <Icon name="signOut" :size="22" />
                    <span class="flex-1 text-16">{{ t('shell.more.signOut') }}</span>
                </button>
            </div>
        </section>

        <section class="flex flex-col gap-2">
            <SectionTitle>{{ t('shell.more.language') }}</SectionTitle>
            <LanguageSwitch size="lg" />
        </section>

        <p v-if="user" class="m-0 text-center text-13 text-ink-3">
            PryTax · {{ user.email }} · {{ user.roles.map(roleName).join(', ') }}
        </p>
    </TabScreen>
</template>
