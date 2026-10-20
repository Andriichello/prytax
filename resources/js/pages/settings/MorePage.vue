<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { Account, Client, Paginated } from '@/api/types';
import TabScreen from '@/components/layout/TabScreen.vue';
import Icon from '@/components/ui/Icon.vue';
import type { IconName } from '@/components/ui/icons';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';

/**
 * Ще (S-More): money and counterparties, the ФОП's settings, the organization, and signing out.
 */
const ROLES: Record<string, string> = { owner: 'власник', accountant: 'бухгалтер', viewer: 'перегляд' };

const router = useRouter();
const { user, can, signOut } = useSession();
const { profileId, path, reset } = useCurrentProfile();

const { data: counts } = useLoader(async (signal) => {
    const [accounts, clients] = await Promise.all([
        api.get<Paginated<Account>>(path('accounts'), { page: { size: 1 } }, signal),
        api.get<Paginated<Client>>(path('clients'), { page: { size: 1 } }, signal),
    ]);

    return { accounts: accounts.meta.total, clients: clients.meta.total };
}, [profileId]);

const sections = computed<{ title: string; items: { label: string; icon: IconName; to: string; count?: number | null; visible?: boolean }[] }[]>(() => [
    {
        title: 'Гроші й контрагенти',
        items: [
            { label: 'Рахунки', icon: 'account', to: '/accounts', count: counts.value?.accounts },
            { label: 'Перекази та комісії', icon: 'transfer', to: '/transfers' },
            { label: 'Клієнти й договори', icon: 'briefcase', to: '/clients', count: counts.value?.clients },
        ],
    },
    {
        title: 'Налаштування ФОП',
        items: [
            { label: 'Профіль і реквізити ФОП', icon: 'person', to: '/settings/profile' },
            { label: 'Нумерація інвойсів', icon: 'hash', to: '/settings/numbering' },
            { label: 'Податки й сповіщення', icon: 'sliders', to: '/settings/taxes' },
        ],
    },
    {
        title: 'Організація',
        items: [
            { label: 'Огляд усіх ФОП', icon: 'overview', to: '/overview' },
            { label: 'Користувачі й ролі', icon: 'people', to: '/users', visible: can('viewAny', 'users') },
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
    <TabScreen title="Ще">
        <section v-for="section in sections" :key="section.title" class="flex flex-col gap-2">
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
                        <Icon name="chevronRight" :size="18" class="text-ink-2" />
                    </RouterLink>
                </template>
                <button
                    v-if="section.title === 'Організація'"
                    type="button"
                    class="flex min-h-14 items-center gap-3.5 text-left text-negative"
                    :disabled="signingOut"
                    @click="leave"
                >
                    <Icon name="signOut" :size="22" />
                    <span class="flex-1 text-16">Вийти</span>
                </button>
            </div>
        </section>

        <p v-if="user" class="m-0 text-center text-13 text-ink-3">
            PryTax · {{ user.email }} · {{ user.roles.map((role) => ROLES[role] ?? role).join(', ') }}
        </p>
    </TabScreen>
</template>
