<script setup lang="ts">
/**
 * A month card (design.md §16.4): a dark header with the month, its count («3 надходження», «2 сплати») and its UAH
 * total on the right, then the month's cards, 6 px apart.
 */
const { title, count, total } = defineProps<{
    title: string;
    count: string;
    total: string;
}>();
</script>

<template>
    <section class="flex flex-col overflow-hidden rounded-card border border-line bg-line" :aria-label="title">
        <header class="flex items-center justify-between gap-3 bg-ink px-4 py-3 text-white">
            <span class="flex flex-col">
                <span class="text-17 font-semibold">{{ title }}</span>
                <span class="text-13 text-on-ink-2">{{ count }}</span>
            </span>
            <span class="num text-18 font-semibold">{{ total }}</span>
        </header>
        <div class="flex flex-col gap-1.5">
            <slot />
        </div>
    </section>
</template>
