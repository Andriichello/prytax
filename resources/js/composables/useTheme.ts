import { computed, ref, watch } from 'vue';
import type { IconName } from '@/components/ui/icons';
import { t } from '@/i18n';

/**
 * The theme of the screens (the design canvas's TH-Spec): Світла, Темна or Як у системі, which a user who chose nothing
 * follows. It is kept on this device, so the script of app.blade.php draws the first frame in it, and on the signed-in
 * user (`switchTheme` of `useSession`), so it follows them to their other devices. `<html data-theme>` holds the look
 * it comes to, which theme-dark.css colours.
 */
export const THEMES = ['light', 'dark', 'system'] as const;

export type Theme = (typeof THEMES)[number];

const STORAGE_KEY = 'prytax.theme';

/** The browser's own bars, in the colour of the page: also in app.blade.php. */
const BAR_COLORS = { light: '#F4F3EE', dark: '#191A1C' };

export const THEME_ICONS: Record<Theme, IconName> = { light: 'sun', dark: 'moon', system: 'monitor' };

export function isTheme(value: unknown): value is Theme {
    return THEMES.includes(value as Theme);
}

/**
 * The name of a theme on the screens.
 */
export function themeName(value: Theme): string {
    return { light: t('shell.more.themeLight'), dark: t('shell.more.themeDark'), system: t('shell.more.themeSystem') }[value];
}

function storedTheme(): Theme {
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);

        return isTheme(stored) ? stored : 'system';
    } catch {
        return 'system';
    }
}

const systemQuery = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
const systemIsDark = ref(systemQuery?.matches ?? false);

systemQuery?.addEventListener('change', (event) => {
    systemIsDark.value = event.matches;
});

/** The theme chosen. */
export const theme = ref<Theme>(storedTheme());

/** The look it comes to. */
export const look = computed<'light' | 'dark'>(() => (theme.value === 'system' ? (systemIsDark.value ? 'dark' : 'light') : theme.value));

/** «Тема: Темна», for the buttons that show only the theme's icon. */
export const themeLabel = computed(() => `${t('shell.more.appTheme')}: ${themeName(theme.value)}`);

/**
 * Choose the theme on this device, where it applies at once.
 */
export function setTheme(value: Theme): void {
    theme.value = value;

    try {
        window.localStorage.setItem(STORAGE_KEY, value);
    } catch {
        // Without storage the theme lasts until the page reloads.
    }
}

watch(look, (value) => {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.dataset.theme = value;
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', BAR_COLORS[value]);
}, { immediate: true, flush: 'sync' });
