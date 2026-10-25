<script setup lang="ts">
import { computed } from 'vue';
import type { LimitPeriod } from '@/api/types';
import type { AccountForm } from '@/components/account/AccountFormModel';
import SwitchCard from '@/components/form/SwitchCard.vue';
import TextField from '@/components/form/TextField.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { t } from '@/i18n';

/**
 * The limit of an account (design.md §3): a switch, the amount and the calendar month, quarter or year, over which the
 * client receipts into the account are counted.
 */
const { error = undefined } = defineProps<{ error?: string }>();

const form = defineModel<AccountForm>({ required: true });

const periodOptions = computed<{ value: LimitPeriod; label: string }[]>(() => [
    { value: 'month', label: t('core.common.month') },
    { value: 'quarter', label: t('core.common.quarter') },
    { value: 'year', label: t('core.common.year') },
]);

const missing = computed(() => form.value.hasLimit && form.value.limit_amount.trim() === '');
</script>

<template>
    <SwitchCard v-model="form.hasLimit" :title="t('money.accountForm.limitEnabled')" :sub="t('money.accountForm.limitEnabledHint')" />
    <template v-if="form.hasLimit">
        <TextField
            v-model="form.limit_amount"
            :label="t('core.common.amountInCurrency', { currency: form.currency })"
            numeric
            :error="error ?? (missing ? t('money.accountForm.enterLimitAmount') : undefined)"
        />
        <div class="flex flex-col gap-1.5">
            <span class="text-13 font-medium text-ink-2">{{ t('core.common.period') }}</span>
            <SegmentedControl v-model="form.limit_period" :label="t('money.accountForm.limitPeriod')" size="lg" :options="periodOptions" />
        </div>
    </template>
</template>
