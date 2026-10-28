<script setup lang="ts">
import { ref } from 'vue';
import Icon from '@/components/ui/Icon.vue';
import LanguageSheet from '@/components/ui/LanguageSheet.vue';
import { LOCALE_NAMES, locale, t } from '@/i18n';

/**
 * The frame of the signed-out pages: the language pill top right (M-LoginLanguage, design.md §16.5), which opens the
 * language sheet, the PryTax logo and tagline, the form and a footnote.
 */
const languageOpen = ref(false);
</script>

<template>
    <div class="flex min-h-dvh flex-col gap-10 px-6 pt-3 pb-8">
        <button
            type="button"
            :aria-label="t('shell.more.appLanguage')"
            class="-mr-2 flex h-11 items-center gap-2 self-end rounded-full border border-line bg-surface pr-3 pl-3.5 text-14 font-medium text-ink"
            @click="languageOpen = true"
        >
            <Icon name="globe" :size="18" />
            {{ LOCALE_NAMES[locale] }}
            <Icon name="chevronDown" :size="16" class="text-ink-3" />
        </button>
        <div class="-mt-6 flex flex-col gap-3.5">
            <div class="flex items-center gap-3">
                <span class="flex size-12 items-center justify-center rounded-button bg-ink text-on-ink">
                    <Icon name="logo" :size="26" />
                </span>
                <span class="text-[32px] font-bold tracking-[-0.03em]">Pry<span class="text-accent">Tax</span></span>
            </div>
            <p class="m-0 max-w-75 text-17 leading-[1.45] text-ink-2">{{ t('shell.more.tagline') }}</p>
        </div>

        <slot />

        <div class="mt-auto flex flex-col gap-4">
            <p v-if="$slots.footer" class="m-0 text-center text-13 leading-normal text-ink-3">
                <slot name="footer" />
            </p>
        </div>
        <LanguageSheet v-model:open="languageOpen" :note="t('shell.more.appLanguageGuestNote')" />
    </div>
</template>
