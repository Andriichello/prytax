<script setup lang="ts">
import Icon from '@/components/ui/Icon.vue';
import { useSession } from '@/composables/useSession';
import { type Locale, LOCALE_NAMES, LOCALE_OTHER_NAMES, LOCALES, locale, t } from '@/i18n';

/**
 * The languages of the screens (design.md §16.6): a radio row per language, its code, its name in itself and in
 * another language, the chosen one accent-soft with a check, and the `note` under them. A choice applies at once
 * (`chosen` follows); signed in, it becomes the user's own language (`switchLocale`). The language sheet and the
 * desktop popover show it.
 */
const { note } = defineProps<{ note: string }>();

const emit = defineEmits<{ chosen: [] }>();

const { switchLocale } = useSession();

function choose(value: Locale): void {
    // The screens switch at once; should saving it to the account fail, the account's language returns next time
    void switchLocale(value).catch(() => undefined);
    emit('chosen');
}
</script>

<template>
    <div class="flex flex-col gap-3.5">
        <div role="radiogroup" :aria-label="t('shell.more.appLanguage')" class="flex flex-col gap-2">
            <button
                v-for="code in LOCALES"
                :key="code"
                type="button"
                role="radio"
                :aria-checked="code === locale ? 'true' : 'false'"
                :lang="code"
                class="flex min-h-14 items-center gap-4 rounded-button border px-4 py-2.5 text-left"
                :class="code === locale ? 'border-accent-soft bg-accent-soft' : 'border-line bg-surface'"
                @click="choose(code)"
            >
                <span class="num w-6 text-12 text-ink-3 uppercase">{{ code }}</span>
                <span class="flex min-w-0 flex-1 flex-col">
                    <span class="text-16 font-semibold">{{ LOCALE_NAMES[code] }}</span>
                    <span class="text-13 text-ink-3">{{ LOCALE_OTHER_NAMES[code] }}</span>
                </span>
                <Icon v-if="code === locale" name="check" :size="20" class="text-accent" />
            </button>
        </div>
        <p class="m-0 text-13 leading-[1.45] text-ink-2">{{ note }}</p>
    </div>
</template>
