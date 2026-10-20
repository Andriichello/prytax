<script setup lang="ts">
import { computed } from 'vue';
import type { InvoiceStatus } from '@/api/types';

/**
 * Чернетка → Готовий → Надіслано → Оплачено: a bar per step, cobalt up to the current one, whose label is bold.
 */
const STEPS: { status: InvoiceStatus; label: string }[] = [
    { status: 'draft', label: 'Чернетка' },
    { status: 'ready', label: 'Готовий' },
    { status: 'sent', label: 'Надіслано' },
    { status: 'paid', label: 'Оплачено' },
];

const { status } = defineProps<{ status: InvoiceStatus }>();

const current = computed(() => STEPS.findIndex((step) => step.status === status));
</script>

<template>
    <ol class="m-0 grid list-none grid-cols-4 gap-1 p-0" aria-label="Статус інвойсу">
        <li
            v-for="(step, index) in STEPS"
            :key="step.status"
            class="flex flex-col gap-1.5"
            :aria-current="index === current ? 'step' : undefined"
        >
            <span class="h-1 rounded-full" :class="index <= current ? 'bg-accent' : 'bg-muted'" />
            <span class="text-12" :class="index === current ? 'font-semibold text-ink' : 'text-ink-3'">{{ step.label }}</span>
        </li>
    </ol>
</template>
