<script setup lang="ts">
import { computed } from 'vue';
import type { InvoiceStatus } from '@/api/types';
import Stepper from '@/components/ui/Stepper.vue';
import { t } from '@/i18n';
import { INVOICE_STATUSES } from '@/utils/statuses';

/**
 * Чернетка → Готовий → Надіслано → Оплачено.
 */
const STEPS: InvoiceStatus[] = ['draft', 'ready', 'sent', 'paid'];

const { status } = defineProps<{ status: InvoiceStatus }>();

const steps = computed(() => STEPS.map((key) => ({ key, label: INVOICE_STATUSES[key].label })));
</script>

<template>
    <Stepper :steps="steps" :current="status" :label="t('invoices.detail.invoiceStatus')" />
</template>
