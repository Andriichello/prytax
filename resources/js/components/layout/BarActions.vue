<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import AlertList, { type Shown } from '@/components/alert/AlertList.vue';
import Icon from '@/components/ui/Icon.vue';
import Popover from '@/components/ui/Popover.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { t } from '@/i18n';

/**
 * The 📖 | 🔔 capsule of the root screens' top bar and of the desktop page header (design.md §16.2, §17.2): one
 * white capsule with the book, which opens Книги обліку (its Податки tab from Податки, Доходи elsewhere), and the
 * bell with the unread count, which opens Сповіщення: the page on mobile, a popover under it on desktop. The count is
 * read again on each screen, as notifications come and go with every write and the nightly check.
 */
const route = useRoute();
const { profile, loadProfiles } = useCurrentProfile();
const { isDesktop } = useBreakpoint();

const unread = computed(() => profile.value?.unread_alerts_count ?? 0);
const booksTab = computed(() => (route.meta.tab === 'taxes' ? 'taxes' : 'income'));

const bell = ref<HTMLElement | null>(null);
const alertsOpen = ref(false);
const shown = ref<Shown>('all');

const bellLabel = computed(() => (unread.value ? t('shell.nav.notificationsUnread', { count: unread.value }) : t('shell.alerts.notifications')));

onMounted(() => void loadProfiles().catch(() => undefined));
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
        <button
            v-if="isDesktop"
            ref="bell"
            type="button"
            :aria-label="bellLabel"
            :aria-expanded="alertsOpen ? 'true' : 'false'"
            class="relative flex h-10.5 w-11.5 items-center justify-center text-ink"
            @click="alertsOpen = !alertsOpen"
        >
            <Icon name="bell" :size="22" />
            <span v-if="unread" class="absolute top-1 right-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-negative px-1 text-10 font-semibold text-white">
                {{ unread }}
            </span>
        </button>
        <RouterLink
            v-else
            :to="{ name: 'alerts' }"
            :aria-label="bellLabel"
            class="relative flex h-10.5 w-11.5 items-center justify-center text-ink hover:text-ink"
        >
            <Icon name="bell" :size="22" />
            <span v-if="unread" class="absolute top-1 right-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-negative px-1 text-10 font-semibold text-white">
                {{ unread }}
            </span>
        </RouterLink>
        <Popover v-if="isDesktop" v-model:open="alertsOpen" :anchor="bell" placement="bottom-end" :width="420" :label="t('shell.alerts.notifications')">
            <div class="flex flex-col gap-3 p-4">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="m-0 text-17 font-semibold">{{ t('shell.alerts.notifications') }}</h2>
                    <span class="flex items-center">
                        <RouterLink
                            :to="{ name: 'settings-taxes' }"
                            :aria-label="t('shell.alerts.notificationSettings')"
                            class="flex size-8 items-center justify-center text-ink hover:text-ink"
                            @click="alertsOpen = false"
                        >
                            <Icon name="sliders" :size="18" />
                        </RouterLink>
                        <button type="button" :aria-label="t('shell.ui.close')" class="flex size-8 items-center justify-center text-ink" @click="alertsOpen = false">
                            <Icon name="close" :size="18" />
                        </button>
                    </span>
                </div>
                <AlertList v-model:shown="shown" compact />
            </div>
        </Popover>
    </nav>
</template>
