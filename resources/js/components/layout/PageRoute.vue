<script setup lang="ts">
import { provide, shallowReactive } from 'vue';
import { routeLocationKey, START_LOCATION, useRouter, type RouteLocationNormalizedLoaded } from 'vue-router';

/**
 * The route of the page under the record panel (design.md §17.1) for the page and what it holds: there `useRoute()`
 * (and the links' active state) is the page's own route, `route`, not the panel's, so the page keeps its period and
 * its filters while a record is open over it (Облік's quarter is in its path). Without `route`, the current one.
 */
const { route = null } = defineProps<{ route?: RouteLocationNormalizedLoaded | null }>();

const router = useRouter();
const shown = {} as RouteLocationNormalizedLoaded;

for (const key of Object.keys(START_LOCATION) as (keyof RouteLocationNormalizedLoaded)[]) {
    Object.defineProperty(shown, key, { enumerable: true, get: () => (route ?? router.currentRoute.value)[key] });
}

provide(routeLocationKey, shallowReactive(shown));
</script>

<template>
    <slot />
</template>
