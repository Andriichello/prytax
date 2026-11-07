<script setup lang="ts">
import { computed } from 'vue';
import Icon from '@/components/ui/Icon.vue';
import { useSession } from '@/composables/useSession';
import { type Theme, THEME_ICONS, theme, themeName } from '@/composables/useTheme';
import { t } from '@/i18n';

/**
 * The themes of the screens (the design canvas's *-AppTheme, *-LoginTheme): a radio row per theme, its icon, its name
 * and what it does, the chosen one accent-soft with a check, and the `note` under them. A choice applies at once
 * (`chosen` follows); signed in, it becomes the user's own theme (`switchTheme`). The theme sheet and the desktop
 * popover show it.
 */
const { note } = defineProps<{ note: string }>();

const emit = defineEmits<{ chosen: [] }>();

const { switchTheme } = useSession();

const options = computed(() => ([
    ['light', t('shell.more.themeLightHint')],
    ['dark', t('shell.more.themeDarkHint')],
    ['system', t('shell.more.themeSystemHint')],
] as const).map(([value, hint]) => ({ value, hint, icon: THEME_ICONS[value], name: themeName(value) })));

function choose(value: Theme): void {
    // The screens switch at once; should saving it to the account fail, the account's theme returns next time
    void switchTheme(value).catch(() => undefined);
    emit('chosen');
}
</script>

<template>
    <div class="flex flex-col gap-3.5">
        <div role="radiogroup" :aria-label="t('shell.more.appTheme')" class="flex flex-col gap-2">
            <button
                v-for="option in options"
                :key="option.value"
                type="button"
                role="radio"
                :aria-checked="option.value === theme ? 'true' : 'false'"
                class="flex min-h-14 items-center gap-4 rounded-button border px-4 py-2.5 text-left"
                :class="option.value === theme ? 'border-accent-soft bg-accent-soft' : 'border-line bg-surface'"
                @click="choose(option.value)"
            >
                <Icon :name="option.icon" :size="22" class="w-6 shrink-0 text-ink-3" />
                <span class="flex min-w-0 flex-1 flex-col">
                    <span class="text-16 font-semibold">{{ option.name }}</span>
                    <span class="text-13 text-ink-3">{{ option.hint }}</span>
                </span>
                <Icon v-if="option.value === theme" name="check" :size="20" class="text-accent" />
            </button>
        </div>
        <p class="m-0 text-13 leading-[1.45] text-ink-2">{{ note }}</p>
    </div>
</template>
