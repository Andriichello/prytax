<script setup lang="ts">
import { computed, onMounted } from 'vue';
import Avatar from '@/components/ui/Avatar.vue';
import Icon from '@/components/ui/Icon.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { t } from '@/i18n';

/**
 * The top bar of the root screens: the current ФОП, which is one per session (design.md §16.1), so its chip is static,
 * and the bell with the unread notifications, which opens Сповіщення. The count is read again on each root screen, as
 * notifications come and go with every write and the nightly check.
 */
const { profile, loadProfiles } = useCurrentProfile();

const unread = computed(() => profile.value?.unread_alerts_count ?? 0);

onMounted(() => void loadProfiles().catch(() => undefined));
</script>

<template>
    <header class="flex items-center justify-between gap-2 px-4 pt-3 pb-1">
        <div class="flex min-h-11 min-w-0 items-center gap-2.5 rounded-3xl border border-line bg-surface py-1 pr-3.5 pl-1 text-ink">
            <Avatar :initials="profile?.initials ?? '?'" variant="dark" :size="34" />
            <span class="flex min-w-0 flex-col leading-[1.2]">
                <span class="truncate text-14 font-semibold">{{ profile?.short_name }}</span>
                <span class="text-11 text-ink-3">{{ t('shell.nav.fopGroup', { group: profile?.tax_group ?? 3 }) }}</span>
            </span>
        </div>
        <RouterLink
            :to="{ name: 'alerts' }"
            :aria-label="unread ? t('shell.nav.notificationsUnread', { count: unread }) : t('shell.alerts.notifications')"
            class="relative flex size-11 shrink-0 items-center justify-center rounded-full border border-line bg-surface text-ink hover:text-ink"
        >
            <Icon name="bell" :size="22" />
            <span
                v-if="unread"
                class="absolute -top-0.5 -right-0.5 flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-negative px-1 text-11 font-semibold text-white"
            >
                {{ unread }}
            </span>
        </RouterLink>
    </header>
</template>
