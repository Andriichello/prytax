<script setup lang="ts">
import TabBar from '@/components/layout/TabBar.vue';
import TopHeader from '@/components/layout/TopHeader.vue';

/**
 * A top-level screen (Головна, Інвойси, Облік, Податки, Ще): the profile header, the page title with an optional
 * subtitle, the content, and the tab bar at the bottom.
 */
const { title } = defineProps<{ title: string }>();
</script>

<template>
    <div class="flex min-h-dvh flex-col">
        <TopHeader>
            <template #action>
                <slot name="action" />
            </template>
        </TopHeader>
        <main class="flex flex-1 flex-col gap-4 px-4 pt-3 pb-6">
            <div class="flex flex-col gap-0.5">
                <div class="flex items-center justify-between gap-3">
                    <h1 class="m-0 text-28 font-semibold tracking-[-0.02em]">{{ title }}</h1>
                    <slot name="title-action" />
                </div>
                <slot name="subtitle" />
            </div>
            <slot />
        </main>
        <TabBar />
    </div>
</template>
