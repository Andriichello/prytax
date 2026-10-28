<script setup lang="ts">
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Popover from '@/components/ui/Popover.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';

/**
 * Where a filter is chosen (design.md §16.2, §17.4): the drawer «Фільтри» on mobile, with «Скинути» (the `action`
 * slot) in its header and «Застосувати» (`footer`) at its foot; on desktop, with an `anchor` (the chip clicked), a
 * popover under that chip, one at a time, with «Скинути» and «Застосувати» side by side at its foot. The sheet's
 * draft logic stays its own: Застосувати commits it, closing discards it.
 */
const { title, anchor = null, width = 360 } = defineProps<{
    title: string;
    anchor?: HTMLElement | null;
    width?: number;
}>();

const open = defineModel<boolean>('open', { required: true });

const { isDesktop } = useBreakpoint();
</script>

<template>
    <Popover v-if="isDesktop && anchor" v-model:open="open" :anchor="anchor" :width="width" :label="title">
        <div class="flex flex-col gap-4 p-4">
            <slot />
        </div>
        <div class="sticky bottom-0 flex items-center justify-between gap-3 border-t border-line bg-surface px-4 py-3 [&_button]:min-h-10! [&_button]:h-10! [&_button]:text-14!">
            <slot name="action" />
            <div class="w-40"><slot name="footer" /></div>
        </div>
    </Popover>
    <BottomSheet v-else v-model:open="open" :title="title">
        <template #action><slot name="action" /></template>
        <slot />
        <template #footer><slot name="footer" /></template>
    </BottomSheet>
</template>
