<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';

/**
 * The 📖 | 🔔 capsule of the root screens' top bar (design.md §16.2): one white capsule with the book, which opens
 * Книги обліку (its Податки tab from Податки, Доходи elsewhere), and the bell with the unread count, which opens
 * Сповіщення.
 */
const { unread = 0 } = defineProps<{ unread?: number }>();

const route = useRoute();

const booksTab = computed(() => (route.meta.tab === 'taxes' ? 'taxes' : 'income'));
</script>

<template>
    <nav :aria-label="t('shell.nav.barActions')" class="flex h-11 shrink-0 items-center rounded-full border border-line bg-surface">
        <RouterLink
            :to="{ name: 'books', query: { tab: booksTab } }"
            :aria-label="t('shell.nav.books')"
            class="flex h-10.5 w-11.5 items-center justify-center text-ink hover:text-ink"
        >
            <Icon name="book" :size="22" />
        </RouterLink>
        <span class="h-5.5 w-px bg-line" aria-hidden="true" />
        <RouterLink
            :to="{ name: 'alerts' }"
            :aria-label="unread ? t('shell.nav.notificationsUnread', { count: unread }) : t('shell.alerts.notifications')"
            class="relative flex h-10.5 w-11.5 items-center justify-center text-ink hover:text-ink"
        >
            <Icon name="bell" :size="22" />
            <span
                v-if="unread"
                class="absolute top-1 right-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-negative px-1 text-10 font-semibold text-white"
            >
                {{ unread }}
            </span>
        </RouterLink>
    </nav>
</template>
