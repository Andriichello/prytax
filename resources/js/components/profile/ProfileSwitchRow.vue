<script setup lang="ts">
import { computed } from 'vue';
import type { Profile } from '@/api/types';
import Avatar from '@/components/ui/Avatar.vue';
import Icon from '@/components/ui/Icon.vue';
import Translated from '@/components/ui/Translated.vue';
import { t } from '@/i18n';
import { formatDate, formatDayMonth } from '@/utils/dates';
import { compareAmounts, formatAmount } from '@/utils/money';

/**
 * A ФОП on «ФОП організації» (design.md §16.5): its avatar (a blue check on the current one, a dashed outline on an
 * inactive one), the full name, the masked tax number and a status line. An overdue invoice makes the line red, with
 * the next ЄСВ deadline; else it tells the unread notifications and the taxes to pay, green once there are neither.
 * The current row has a soft accent background and an accent edge, and no chevron.
 */
const { profile, current = false, disabled = false } = defineProps<{
    profile: Profile;
    current?: boolean;
    disabled?: boolean;
}>();

const emit = defineEmits<{ choose: [] }>();

const taxNumber = computed(() => (profile.tax_id_kind === 'passport' ? t('shell.profiles.passportMasked') : t('shell.profiles.taxIdMasked')));

/** The status line and its tone. */
const line = computed<{ text: string; tone: 'neutral' | 'positive' | 'negative' }>(() => {
    if (!profile.is_active) {
        return {
            text: profile.closed_at ? t('shell.profiles.closedOn', { date: formatDate(profile.closed_at) }) : t('shell.profiles.inactiveShort'),
            tone: 'neutral',
        };
    }

    const status = profile.status;
    const unread = profile.unread_alerts_count ?? 0;

    if (status && status.overdue_invoices > 0) {
        return {
            text: [
                t('core.counts.overdueInvoices', { count: status.overdue_invoices }),
                ...(status.esv_due_at ? [t('shell.profiles.esvDue', { date: formatDayMonth(status.esv_due_at) })] : []),
            ].join(' · '),
            tone: 'negative',
        };
    }

    const toPay = status ? compareAmounts(status.taxes_to_pay, '0') > 0 : false;

    return {
        text: [
            unread ? t('core.counts.notifications', { count: unread }) : t('shell.profiles.noAlerts'),
            toPay && status ? t('shell.profiles.taxesToPay', { amount: formatAmount(status.taxes_to_pay) }) : t('shell.profiles.taxesPaid'),
        ].join(' · '),
        tone: !unread && !toPay ? 'positive' : 'neutral',
    };
});

const toneClass = computed(() => ({ neutral: 'text-ink-3', positive: 'text-positive', negative: 'text-negative' })[line.value.tone]);
</script>

<template>
    <button
        type="button"
        :disabled="disabled"
        :aria-current="current ? 'true' : undefined"
        class="relative flex min-h-19 w-full items-center gap-3 px-4 py-3 text-left"
        :class="current ? 'bg-accent-soft' : 'bg-surface'"
        @click="emit('choose')"
    >
        <span v-if="current" class="absolute inset-y-0 left-0 w-1 bg-accent" aria-hidden="true" />
        <span class="relative shrink-0">
            <Avatar :initials="profile.initials" :variant="!profile.is_active ? 'dashed' : current ? 'dark' : 'light'" :size="40" />
            <span
                v-if="current"
                class="absolute -right-1 -bottom-1 flex size-5 items-center justify-center rounded-full border-2 border-surface bg-accent text-white"
            >
                <Icon name="check" :size="12" />
                <span class="sr-only">{{ t('shell.profiles.current') }}</span>
            </span>
        </span>
        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span class="truncate text-16 font-semibold" :class="profile.is_active ? 'text-ink' : 'text-ink-2'">{{ profile.full_name }}</span>
            <span class="text-13 text-ink-3">
                <Translated :text="taxNumber"><template #masked><span class="num">{{ profile.tax_id_masked }}</span></template></Translated>
            </span>
            <span class="text-13 leading-[1.35]" :class="toneClass">{{ line.text }}</span>
        </span>
        <Icon v-if="!current" name="chevronRight" :size="18" class="shrink-0 text-ink-2" />
    </button>
</template>
