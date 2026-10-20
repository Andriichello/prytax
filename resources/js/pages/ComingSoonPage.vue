<script setup lang="ts">
import { useRoute } from 'vue-router';
import SubScreen from '@/components/layout/SubScreen.vue';
import TabScreen from '@/components/layout/TabScreen.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

/**
 * A screen, which isn't built yet: its frame (tab or sub-page) with a note, so navigation can be tried end to end.
 */
const route = useRoute();
</script>

<template>
    <TabScreen v-if="route.meta.tab" :title="route.meta.title ?? ''">
        <EmptyState text="Цей екран ще в розробці." />
    </TabScreen>
    <SubScreen v-else :title="route.meta.title ?? ''">
        <EmptyState text="Цей екран ще в розробці." />
    </SubScreen>
</template>
