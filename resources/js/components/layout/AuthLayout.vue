<script setup lang="ts">
import { ref } from 'vue';
import Icon from '@/components/ui/Icon.vue';
import LanguageOptions from '@/components/ui/LanguageOptions.vue';
import LanguageSheet from '@/components/ui/LanguageSheet.vue';
import Popover from '@/components/ui/Popover.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { LOCALE_NAMES, locale, t } from '@/i18n';

/**
 * The frame of the signed-out pages: the language pill top right (M-LoginLanguage, design.md §16.5), which opens the
 * language sheet, the PryTax logo and tagline, the form and a footnote. On desktop (D-Login, §17.4) it is split: the
 * ink side with the logo, the headline and its sub-line on the left, the form in a 400 px column on the right, and
 * the pill's choices in a popover.
 */
const languageOpen = ref(false);
const pill = ref<HTMLElement | null>(null);

const { isDesktop } = useBreakpoint();
</script>

<template>
    <div v-if="isDesktop" class="fixed inset-0 flex bg-paper">
        <div class="flex flex-1 flex-col justify-between bg-ink p-16 text-white">
            <div class="flex items-center gap-3">
                <span class="flex size-11 items-center justify-center rounded-control bg-white text-ink">
                    <Icon name="logo" :size="24" />
                </span>
                <span class="text-28 font-bold tracking-[-0.03em]">Pry<span class="text-accent-line">Tax</span></span>
            </div>
            <div class="flex max-w-120 flex-col gap-4">
                <h1 class="m-0 text-[40px] leading-[1.1] font-semibold tracking-[-0.02em]">{{ t('shell.more.tagline') }}</h1>
                <p class="m-0 text-16 leading-normal text-on-ink-3">{{ t('shell.more.desktopTagline') }}</p>
            </div>
            <span class="text-13 text-tick">© 2026 PryTax</span>
        </div>
        <div class="relative flex w-140 shrink-0 items-center justify-center overflow-y-auto p-12">
            <button
                ref="pill"
                type="button"
                :aria-label="t('shell.more.appLanguage')"
                class="absolute top-6 right-6 flex h-10 items-center gap-1.5 rounded-full border border-line-input bg-surface px-3 text-14 font-medium text-ink"
                @click="languageOpen = !languageOpen"
            >
                <Icon name="globe" :size="18" />
                {{ LOCALE_NAMES[locale] }}
                <Icon name="chevronDown" :size="16" class="text-ink-3" />
            </button>
            <div class="flex w-100 flex-col gap-6">
                <slot />
                <p v-if="$slots.footer" class="m-0 text-13 leading-normal text-ink-3">
                    <slot name="footer" />
                </p>
            </div>
            <Popover v-model:open="languageOpen" :anchor="pill" placement="bottom-end" :width="300" :label="t('shell.more.appLanguage')">
                <div class="p-3">
                    <LanguageOptions :note="t('shell.more.appLanguageBrowserNote')" @chosen="languageOpen = false" />
                </div>
            </Popover>
        </div>
    </div>

    <div v-else class="flex min-h-dvh flex-col gap-10 px-6 pt-3 pb-8">
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
