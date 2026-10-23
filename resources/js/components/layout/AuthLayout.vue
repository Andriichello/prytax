<script setup lang="ts">
import Icon from '@/components/ui/Icon.vue';
import LanguageSwitch from '@/components/ui/LanguageSwitch.vue';
import { t } from '@/i18n';

/**
 * The frame of the signed-out pages: the PryTax logo and tagline, the form, a footnote and the language switch at the
 * bottom.
 */
</script>

<template>
    <div class="flex min-h-dvh flex-col gap-10 px-6 pt-18 pb-8">
        <div class="flex flex-col gap-3.5">
            <div class="flex items-center gap-3">
                <span class="flex size-12 items-center justify-center rounded-button bg-ink text-on-ink">
                    <Icon name="logo" :size="26" />
                </span>
                <span class="text-[32px] font-bold tracking-[-0.03em]">Pry<span class="text-accent">Tax</span></span>
            </div>
            <p class="m-0 max-w-75 text-17 leading-[1.45] text-ink-2">{{ t('Облік доходів, інвойси та податки для ФОП 3 групи') }}</p>
        </div>

        <slot />

        <div class="mt-auto flex flex-col gap-4">
            <p v-if="$slots.footer" class="m-0 text-center text-13 leading-normal text-ink-3">
                <slot name="footer" />
            </p>
            <LanguageSwitch />
        </div>
    </div>
</template>
