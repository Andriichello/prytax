<script setup lang="ts">
import { computed } from 'vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useSession } from '@/composables/useSession';
import { type Locale, LOCALE_NAMES, LOCALES, locale, t } from '@/i18n';

/**
 * The language of the screens: Українська or English, each named in itself. Signed in, the choice becomes the user's
 * own, so the API, the e-mails and the user's other devices follow it.
 */
const { size = 'md' } = defineProps<{
    size?: 'md' | 'lg';
}>();

const { switchLocale } = useSession();

const OPTIONS = LOCALES.map((value) => ({ value, label: LOCALE_NAMES[value] }));

const chosen = computed({
    get: () => locale.value,
    set: (value: Locale) => {
        // The screens switch at once; should saving it to the account fail, the account's language returns next time
        void switchLocale(value).catch(() => undefined);
    },
});
</script>

<template>
    <SegmentedControl v-model="chosen" :label="t('Мова')" :size="size" :options="OPTIONS" />
</template>
