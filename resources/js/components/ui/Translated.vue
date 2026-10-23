<script setup lang="ts">
import { computed } from 'vue';

/**
 * A translated sentence with markup inside it: each `{name}` of the text is replaced by the slot of that name, so the
 * words keep the order of each language —
 * `<Translated :text="t('Останній номер {number}.')"><template #number><b>13</b></template></Translated>`.
 */
const { text } = defineProps<{
    /** The sentence, already translated with `t()`. */
    text: string;
}>();

const parts = computed(() => text.split(/(\{\w+\})/).filter(Boolean).map((part) => ({
    slot: /^\{(\w+)\}$/.exec(part)?.[1] ?? null,
    text: part,
})));
</script>

<template>
    <template v-for="(part, index) in parts" :key="index">
        <slot v-if="part.slot" :name="part.slot" />
        <template v-else>{{ part.text }}</template>
    </template>
</template>
