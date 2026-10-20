<script setup lang="ts">
import type { InvoiceStatus } from '@/api/types';
import Stepper from '@/components/ui/Stepper.vue';

/**
 * Чернетка → Готовий → Надіслано → Оплачено.
 */
const STEPS = [
    { key: 'draft', label: 'Чернетка' },
    { key: 'ready', label: 'Готовий' },
    { key: 'sent', label: 'Надіслано' },
    { key: 'paid', label: 'Оплачено' },
];

const { status } = defineProps<{ status: InvoiceStatus }>();
</script>

<template>
    <Stepper :steps="STEPS" :current="status" label="Статус інвойсу" />
</template>
