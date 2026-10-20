<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import type { Alert } from '@/api/types';
import Icon from '@/components/ui/Icon.vue';
import ProgressBar from '@/components/ui/ProgressBar.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { ALERT_SEVERITIES } from '@/utils/statuses';
import { splitFigures } from '@/utils/text';

/**
 * A notification: an unread dot, its severity, a context line and ✓; the title, the text and its action. Tapping the
 * card, its action or ✓ marks it read (handoff §6.4); nothing else does.
 */
const { alert } = defineProps<{ alert: Alert }>();
const emit = defineEmits<{ read: [alert: Alert] }>();

const router = useRouter();

const isRead = computed(() => alert.read_at !== null);
const context = computed(() => {
    const text = typeof alert.payload?.context === 'string' ? alert.payload.context : '';

    return isRead.value ? [text, 'Прочитано'].filter(Boolean).join(' · ') : text;
});
const percent = computed(() => (['limit', 'cap'].includes(alert.type) && typeof alert.payload?.percent === 'number'
    ? alert.payload.percent
    : null));
/** Actions that ask to do something are buttons; "Відкрити …" ones are links. */
const isCallToAction = computed(() => !!alert.action_label && !alert.action_label.startsWith('Відкрити'));

function markRead(): void {
    if (!isRead.value) {
        emit('read', alert);
    }
}

async function act(): Promise<void> {
    markRead();

    if (alert.action_route) {
        await router.push(alert.action_route);
    }
}
</script>

<template>
    <article
        class="flex flex-col gap-3 rounded-card border bg-surface pt-3 pr-2 pb-4 pl-4"
        :class="isRead ? 'border-divider text-ink-3' : 'border-line'"
        @click="markRead"
    >
        <div class="flex items-center gap-2.5">
            <span v-if="!isRead" role="img" aria-label="Непрочитане" class="size-2 shrink-0 rounded-full bg-accent" />
            <span v-if="isRead" class="rounded-badge bg-paper px-2 py-[3px] text-12 font-medium text-ink-3">
                {{ ALERT_SEVERITIES[alert.severity].label }}
            </span>
            <StatusBadge v-else v-bind="ALERT_SEVERITIES[alert.severity]" />
            <span class="min-w-0 flex-1 truncate text-12 text-ink-3">{{ context }}</span>
            <button
                type="button"
                :aria-label="isRead ? 'Прочитано' : 'Позначити прочитаним'"
                :aria-pressed="isRead ? 'true' : 'false'"
                class="flex size-11 shrink-0 items-center justify-center text-ink-2"
                @click.stop="markRead"
            >
                <svg v-if="isRead" width="22" height="22" viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" class="fill-ink-4" />
                    <path d="m8 12 3 3 5-6" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <Icon v-else name="checkCircle" :size="22" />
            </button>
        </div>

        <div class="flex flex-col gap-3 pr-2">
            <h2 class="m-0 text-16" :class="isRead ? 'font-normal text-ink-2' : 'font-bold'">
                <template v-for="(segment, index) in splitFigures(alert.title)" :key="index">
                    <span v-if="segment.figure" class="num">{{ segment.text }}</span>
                    <template v-else>{{ segment.text }}</template>
                </template>
            </h2>
            <ProgressBar v-if="percent !== null && !isRead" :percent="percent" :warn-from="80" :height="8" />
            <p v-if="alert.body" class="m-0 text-14 leading-normal" :class="isRead ? 'text-ink-3' : 'text-ink-2'">
                <template v-for="(segment, index) in splitFigures(alert.body)" :key="index">
                    <span v-if="segment.figure" class="num font-semibold" :class="isRead ? '' : 'text-ink'">{{ segment.text }}</span>
                    <template v-else>{{ segment.text }}</template>
                </template>
            </p>
            <template v-if="alert.action_label && alert.action_route">
                <button
                    v-if="isCallToAction && !isRead"
                    type="button"
                    class="flex h-11 items-center justify-center rounded-control bg-accent text-14 font-semibold text-white hover:bg-accent-hover"
                    @click.stop="act"
                >
                    {{ alert.action_label }}
                </button>
                <button
                    v-else
                    type="button"
                    class="flex min-h-11 items-center self-start text-14 font-medium text-accent hover:text-accent-hover"
                    @click.stop="act"
                >
                    {{ alert.action_label }}
                </button>
            </template>
        </div>
    </article>
</template>
