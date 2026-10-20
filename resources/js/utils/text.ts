/**
 * A piece of a sentence, which is either plain text or a figure to set in the mono font.
 */
export interface TextSegment {
    text: string;
    figure: boolean;
}

/**
 * Amounts with their currency ("1 240.00 USD", "8 218.96 ₴", "8 420.00 / 10 000.00 USD"), invoice numbers
 * ("2026-08-31-11") and rates, as the API writes them into its texts. Dates ("19.10.2026") stay plain: a figure
 * neither follows nor precedes another digit group.
 */
const AMOUNT = String.raw`\d{1,3}(?:[\s\u00A0\u202F,.]\d{3})*[.,]\d{2,4}(?![.,]?\d)`;
const FIGURES = new RegExp(
    String.raw`(\d{4}-\d{2}-\d{2}-\d+|(?<![\d.,])[−-]?${AMOUNT}(?:\s?\/\s?${AMOUNT})?(?:[\s\u00A0](?:[A-Z]{3}|₴))?)`,
    'g',
);

/**
 * Split a text of the API into plain parts and figures, so the figures can be set in mono (handoff §3: every number).
 */
export function splitFigures(text: string): TextSegment[] {
    const segments: TextSegment[] = [];
    let last = 0;

    for (const match of text.matchAll(FIGURES)) {
        if (match.index > last) {
            segments.push({ text: text.slice(last, match.index), figure: false });
        }

        segments.push({ text: match[0], figure: true });
        last = match.index + match[0].length;
    }

    if (last < text.length) {
        segments.push({ text: text.slice(last), figure: false });
    }

    return segments;
}
