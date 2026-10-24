import { afterEach, describe, expect, it } from 'vitest';
import { DEFAULT_LOCALE, type Locale, LOCALES, locale, t } from '@/i18n';
import en from '@/i18n/en';
import type { Messages, PluralForms } from '@/i18n/types';
import uk from '@/i18n/uk';

/**
 * The sources of the screens, which the checks below read: every component and module, but the tests and the i18n
 * module with its dictionaries.
 */
const SOURCES = import.meta.glob(['../**/*.vue', '../**/*.ts', '!../**/*.test.ts', '!./**'], {
    query: '?raw',
    import: 'default',
    eager: true,
}) as Record<string, string>;

const DICTIONARIES: Record<Locale, Messages> = { uk, en };

const CYRILLIC = /[Ѐ-ӿ]/;

/** A line, which is only a comment: of a docblock, `//` or `<!-- -->`. */
const COMMENT = /^(\*|\/\*|\/\/|<!--)/;

/**
 * The lines of a source, which are code rather than comments.
 */
const codeLines = (source: string): string[] => source.split('\n').filter((line) => !COMMENT.test(line.trim()));

/** `t('…')`, `t("…")` or `t(`…`)` with a literal key. */
const T_CALL = /\bt\(\s*(['"`])((?:\\.|(?!\1)[^\\])*)\1/g;

/**
 * The keys, which a source passes to `t()`.
 */
function keysOf(source: string): string[] {
    return codeLines(source).flatMap((line) => [...line.matchAll(T_CALL)].map((match) => match[2]!));
}

/**
 * The lines of a source with Ukrainian outside the dictionaries: neither a comment nor marked `i18n-ignore` (a line,
 * or a block between `i18n-ignore-start` and `i18n-ignore-end`).
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

        if (ignoring || line.includes('i18n-ignore') || COMMENT.test(trimmed)) {
            return;
        }

        const code = line.replace(/\s\/\/\s.*$/, '').replace(/<!--.*?-->/g, '');

        if (CYRILLIC.test(code)) {
            found.push(`${index + 1}: ${trimmed}`);
        }
    });

    return found;
}

/**
 * Whether an entry is the forms of a plural rather than a group of entries.
 */
const isPlural = (entry: Messages[string]): entry is PluralForms => typeof entry === 'object' && 'one' in entry;

/**
 * Every entry of a dictionary by its dotted key.
 */
function entriesOf(messages: Messages, prefix = ''): Map<string, string | PluralForms> {
    const entries = new Map<string, string | PluralForms>();

    for (const [name, entry] of Object.entries(messages)) {
        const key = prefix + name;

        if (typeof entry === 'string' || isPlural(entry)) {
            entries.set(key, entry);
        } else {
            entriesOf(entry, `${key}.`).forEach((value, nested) => entries.set(nested, value));
        }
    }

    return entries;
}

const ENTRIES = Object.fromEntries(LOCALES.map((code) => [code, entriesOf(DICTIONARIES[code])])) as Record<Locale, Map<string, string | PluralForms>>;

/**
 * The texts of an entry: the text, or every form of a plural.
 */
const textsOf = (entry: string | PluralForms): string[] => (typeof entry === 'string' ? [entry] : Object.values(entry) as string[]);

/**
 * The placeholders of a text, in order: "{count} з {total}" has count and total.
 */
const placeholders = (text: string): string => [...text.matchAll(/\{(\w+)\}/g)].map((match) => match[1]!).sort().join();

/**
 * The plural categories, which a language gives whole counts: one, few and many in Ukrainian, one and other in
 * English.
 */
function countCategories(code: Locale): string[] {
    const rules = new Intl.PluralRules(code);

    return [...new Set(Array.from({ length: 200 }, (_, count) => rules.select(count)))].sort();
}

describe.each(Object.keys(SOURCES).map((file) => [file.replace(/^\.\.\//, '')]))('%s', (file) => {
    const source = SOURCES[`../${file}`]!;

    it('keeps Ukrainian in the dictionaries, passing keys to t()', () => {
        expect(untranslatedLines(source)).toEqual([]);
    });

    it('passes t() keys of the dictionary', () => {
        expect(keysOf(source).filter((key) => !ENTRIES[DEFAULT_LOCALE].has(key))).toEqual([]);
    });

    it('passes literal keys only, which the dictionary can be checked against', () => {
        expect(codeLines(source).flatMap((line) => [...line.matchAll(T_CALL)])
            .filter((match) => match[1] === '`' && match[2]!.includes('${'))
            .map((match) => match[0])).toEqual([]);
    });
});

describe('the Ukrainian dictionary', () => {
    const used = new Set(Object.values(SOURCES).flatMap(keysOf));

    it('has no key, which the screens don\'t use', () => {
        expect([...ENTRIES.uk.keys()].filter((key) => !used.has(key))).toEqual([]);
    });
});

describe.each(LOCALES.map((code) => [code]))('the %s dictionary', (code) => {
    const entries = ENTRIES[code];

    it('has every key of the Ukrainian one, and no other', () => {
        expect([...ENTRIES.uk.keys()].filter((key) => !entries.has(key))).toEqual([]);
        expect([...entries.keys()].filter((key) => !ENTRIES.uk.has(key))).toEqual([]);
    });

    it('keeps the placeholders of every text', () => {
        expect([...entries].filter(([key, entry]) => {
            const source = placeholders(textsOf(ENTRIES.uk.get(key) ?? '')[0] ?? '');

            return textsOf(entry).some((text) => placeholders(text) !== source);
        }).map(([key]) => key)).toEqual([]);
    });

    it('gives a plural the forms of its language', () => {
        const categories = countCategories(code).join();

        expect([...entries].filter(([, entry]) => typeof entry === 'object' && Object.keys(entry).sort().join() !== categories)
            .map(([key]) => key)).toEqual([]);
    });
});

describe('the English dictionary', () => {
    it('is English', () => {
        expect([...ENTRIES.en].filter(([, entry]) => textsOf(entry).some((text) => CYRILLIC.test(text))).map(([key]) => key))
            .toEqual([]);
    });
});

describe('t', () => {
    afterEach(() => {
        locale.value = 'uk';
    });

    it('gives the text of the current language, and fills in the placeholders', () => {
        expect(t('shell.more.language')).toBe('Мова');
        expect(t('invoices.list.nextNumber', { number: 14 })).toBe('наст. 14');

        locale.value = 'en';

        expect(t('shell.more.language')).toBe('Language');
        expect(t('invoices.list.nextNumber', { number: 14 })).toBe('next 14');
    });

    it('takes the form of a plural by the count, by the rules of the language', () => {
        expect([1, 2, 5, 21].map((count) => t('core.counts.invoices', { count })))
            .toEqual(['1 інвойс', '2 інвойси', '5 інвойсів', '21 інвойс']);

        locale.value = 'en';

        expect([1, 2, 21].map((count) => t('core.counts.invoices', { count }))).toEqual(['1 invoice', '2 invoices', '21 invoices']);
    });

    it('shows the key, which no dictionary has', () => {
        // @ts-expect-error: a key, which the dictionaries lack
        expect(t('shell.more.nothing')).toBe('shell.more.nothing');
    });
});
