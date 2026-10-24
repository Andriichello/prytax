/**
 * The forms of a plural, by the categories of `Intl.PluralRules` for whole counts: Ukrainian has one (1, 21), few
 * (2–4, 22–24) and many (0, 5–20, 25…), English has one and other. `t()` picks the form by `params.count`.
 */
export type PluralForms = Partial<Record<Intl.LDMLPluralRule, string>>;

/**
 * A dictionary of the screens: texts, plurals, and groups of them, nested by area and screen.
 */
export interface Messages {
    [key: string]: string | PluralForms | Messages;
}

/**
 * A translation of a dictionary: the same keys, each with a text, or a plural with the forms of its own language.
 */
export type Translation<T> = {
    [K in keyof T]: T[K] extends string ? string : T[K] extends { one: string } ? PluralForms : Translation<T[K]>;
};

/**
 * The keys of a dictionary, dotted: 'invoices.editor.issueDate'.
 */
export type KeysOf<T> = {
    [K in keyof T & string]: T[K] extends string | { one: string } ? K : `${K}.${KeysOf<T[K]>}`;
}[keyof T & string];
