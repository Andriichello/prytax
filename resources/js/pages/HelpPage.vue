<script setup lang="ts">
import { computed, ref } from 'vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Icon from '@/components/ui/Icon.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { useDesktopApp } from '@/composables/useDesktopApp';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { helpSteps, helpTopics, searchTopics } from '@/utils/help';

/**
 * Довідка: what the app does and how, for its users. The first steps of setting up a ФОП (for those who may create
 * invoices), then a topic per part of the app — what it is for, its questions, each opening its answer, and a link
 * to its page. The search keeps the topics and questions, which have every word typed, and opens those questions. On
 * desktop it opens from the foot of the sidebar, on a phone from Ще.
 */
const { can } = useSession();
const { isDesktop } = useBreakpoint();
const { isDesktopApp } = useDesktopApp();

const { data: desktopApp } = useLoader(() => isDesktopApp(), []);

const query = ref('');
const searching = computed(() => query.value.trim() !== '');

const steps = computed(() => (can('create', 'invoices') ? helpSteps() : []));

const topics = computed(() => searchTopics(helpTopics({
    settings: isDesktop.value ? t('shell.nav.settings') : t('shell.more.more'),
    backups: can('viewAny', 'backup-runs'),
    desktopApp: desktopApp.value === true,
}), query.value));
</script>

<template>
    <SubScreen :title="t('help.title')" :subtitle="t('help.subtitle')" :back="{ name: 'more' }">
        <p class="m-0 text-15 leading-[1.5] text-ink-2">{{ t('help.intro') }}</p>

        <div class="relative">
            <label for="help-search" class="sr-only">{{ t('help.search') }}</label>
            <Icon name="search" :size="20" class="pointer-events-none absolute top-3.5 left-3.5 text-ink-3" />
            <input
                id="help-search"
                v-model="query"
                type="search"
                :placeholder="t('help.searchPlaceholder')"
                class="h-12 w-full rounded-control border border-line-input bg-surface pr-3.5 pl-11 text-16 placeholder:text-ink-3"
            >
        </div>

        <section v-if="!searching && steps.length" class="flex flex-col gap-2">
            <SectionTitle>{{ t('help.firstSteps.title') }}</SectionTitle>
            <div class="flex flex-col rounded-card border border-line bg-surface px-4">
                <p class="m-0 pt-3.5 pb-1 text-14 leading-[1.5] text-ink-2">{{ t('help.firstSteps.hint') }}</p>
                <ol class="m-0 flex list-none flex-col divide-y divide-divider p-0">
                    <li v-for="(step, index) in steps" :key="step.key">
                        <RouterLink :to="step.to" class="flex items-center gap-3 py-3 text-ink no-underline hover:text-ink">
                            <span class="num flex size-7 shrink-0 items-center justify-center rounded-full bg-accent-soft text-13 font-semibold text-accent-hover">
                                {{ index + 1 }}
                            </span>
                            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="text-16 font-medium">{{ step.title }}</span>
                                <span class="text-13 leading-[1.45] text-ink-3">{{ step.text }}</span>
                            </span>
                            <Icon name="chevronRight" :size="18" class="shrink-0 text-ink-2" />
                        </RouterLink>
                    </li>
                </ol>
            </div>
        </section>

        <EmptyState v-if="topics.length === 0" :text="t('help.nothingFound')" />

        <section v-for="topic in topics" :key="topic.key" class="flex flex-col gap-2">
            <SectionTitle>{{ topic.title }}</SectionTitle>
            <div class="flex flex-col divide-y divide-divider rounded-card border border-line bg-surface px-4">
                <p class="m-0 py-3.5 text-14 leading-[1.5] text-ink-2">{{ topic.lead }}</p>
                <details
                    v-for="question in topic.questions"
                    :key="question.key"
                    :open="topic.opened.includes(question.key)"
                    class="group"
                >
                    <summary class="flex min-h-13 cursor-pointer list-none items-center gap-3 py-2 text-16 text-ink [&::-webkit-details-marker]:hidden">
                        <span class="flex-1">{{ question.title }}</span>
                        <Icon name="chevronDown" :size="18" class="shrink-0 text-ink-2 transition-transform group-open:rotate-180" />
                    </summary>
                    <p class="m-0 pb-3.5 text-14 leading-[1.55] text-ink-2">{{ question.text }}</p>
                </details>
                <RouterLink
                    v-if="topic.page"
                    :to="topic.page.to"
                    class="flex min-h-12 items-center gap-1 text-14 font-medium text-accent no-underline hover:text-accent-hover"
                >
                    {{ t('help.open', { page: topic.page.name }) }}
                    <Icon name="chevronRight" :size="16" />
                </RouterLink>
            </div>
        </section>
    </SubScreen>
</template>
