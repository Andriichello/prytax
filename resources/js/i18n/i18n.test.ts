import { afterEach, describe, expect, it } from 'vitest';
import { locale, t } from '@/i18n';
import { DICTIONARIES, EN } from '@/i18n/en';

/**
 * The sources of the screens, which the checks below read: every component and module, but the tests, `t()` itself
 * and the dictionaries.
 */
const SOURCES = import.meta.glob(['../**/*.vue', '../**/*.ts', '!../**/*.test.ts', '!./index.ts', '!./en/**'], {
    query: '?raw',
    import: 'default',
    eager: true,
}) as Record<string, string>;

const CYRILLIC = /[\u0400-\u04FF]/;

/** A line, which is only a comment: of a docblock, `//` or `<!-- -->`. */
const COMMENT = /^(\*|\/\*|\/\/|<!--)/;

/**
 * The lines of a source, which are code rather than comments.
 */
const codeLines = (source: string): string[] => source.split('\n').filter((line) => !COMMENT.test(line.trim()));

/** `t('…')`, `t("…")` or `t(`…`)` with a literal text. */
const T_CALL = /\bt\(\s*(['"`])((?:\\.|(?!\1)[^\\])*)\1/g;

/**
 * The texts, which a source passes to `t()`, with escapes such as `\'` undone.
 */
function textsOf(source: string): string[] {
    return codeLines(source).flatMap((line) => [...line.matchAll(T_CALL)].map((match) => match[2]!.replace(/\\(.)/g, '$1')));
}

/**
 * The lines of a source with Ukrainian outside `t()`: neither a comment nor marked `i18n-ignore` (a line, or a block
 * between `i18n-ignore-start` and `i18n-ignore-end`), nor the Ukrainian forms of a plural (`uk: [...]`).
 */
function untranslatedLines(source: string): string[] {
    const found: string[] = [];
    let ignoring = false;

    source.split('\n').forEach((line, index) => {
        if (line.includes('i18n-ignore-start')) {
            ignoring = true;
        }

        if (line.includes('i18n-ignore-end')) {
            ignoring = false;

            return;
        }

        const trimmed = line.trim();

        if (ignoring || line.includes('i18n-ignore') || COMMENT.test(trimmed) || /\buk: \[/.test(line)) {
            return;
        }

        const code = line.replace(T_CALL, '').replace(/\s\/\/\s.*$/, '').replace(/<!--.*?-->/g, '');

        if (CYRILLIC.test(code)) {
            found.push(`${index + 1}: ${trimmed}`);
        }
    });

    return found;
}

/**
 * The placeholders of a text, in order: "{count} з {total}" has count and total.
 */
const placeholders = (text: string): string[] => [...text.matchAll(/\{(\w+)\}/g)].map((match) => match[1]!).sort();

describe.each(Object.keys(SOURCES).map((file) => [file.replace(/^\.\.\//, '')]))('%s', (file) => {
    const source = SOURCES[`../${file}`]!;

    it('passes every Ukrainian text of the screens through t()', () => {
        expect(untranslatedLines(source)).toEqual([]);
    });

    it('has the English of every text it translates', () => {
        expect(textsOf(source).filter((text) => !(text in EN))).toEqual([]);
    });

    it('translates literal texts only, which the dictionary can be checked against', () => {
        expect(codeLines(source).flatMap((line) => [...line.matchAll(T_CALL)])
            .filter((match) => match[1] === '`' && match[2]!.includes('${'))
            .map((match) => match[0])).toEqual([]);
    });
});

describe('the English dictionary', () => {
    const used = new Set(Object.values(SOURCES).flatMap(textsOf));

    it('has no text, which the screens don\'t use', () => {
        expect(Object.keys(EN).filter((text) => !used.has(text))).toEqual([]);
    });

    it('gives a text one English, whichever area has it', () => {
        const english = new Map<string, string>();
        const conflicts: string[] = [];

        for (const [area, dictionary] of Object.entries(DICTIONARIES)) {
            for (const [text, translation] of Object.entries(dictionary)) {
                if (english.has(text) && english.get(text) !== translation) {
                    conflicts.push(`${area}: ${text}`);
                }

                english.set(text, translation);
            }
        }

        expect(conflicts).toEqual([]);
    });

    it('keeps the placeholders of every text', () => {
        expect(Object.entries(EN)
            .filter(([text, translation]) => placeholders(text).join() !== placeholders(translation).join())
            .map(([text]) => text)).toEqual([]);
    });

    it('is English', () => {
        expect(Object.entries(EN).filter(([, translation]) => CYRILLIC.test(translation)).map(([text]) => text)).toEqual([]);
    });
});

describe('t', () => {
    afterEach(() => {
        locale.value = 'uk';
    });

    it('keeps Ukrainian, and fills in the placeholders', () => {
        expect(t('Мова')).toBe('Мова');
        expect(t('Наступний {n}', { n: 14 })).toBe('Наступний 14');
    });

    it('translates into English, falling back to Ukrainian without a translation', () => {
        locale.value = 'en';

        expect(t('Мова')).toBe('Language');
        expect(t('Немає такого тексту')).toBe('Немає такого тексту');
        expect(t('Немає такого тексту||у контексті')).toBe('Немає такого тексту');
    });

    it('shows no context of a text in Ukrainian', () => {
        expect(t('Немає такого тексту||у контексті')).toBe('Немає такого тексту');
    });
});
