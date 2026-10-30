<?php

namespace PryTax\Helpers;

use PDO;
use Pdo\Sqlite;

/**
 * Class SqliteFunctions.
 *
 * Ukrainian text on SQLite as on MySQL (builds.md §3.6): SQLite's own `LOWER()`, `UPPER()` and `LIKE`
 * only know ASCII's cases, so `LOWER('ОЛЕНА')` stays `ОЛЕНА` and `'олена' LIKE 'Олена'` is false, while MySQL's
 * collation ignores the case of every letter. These replace them on every SQLite connection (AppServiceProvider binds
 * a connector, which registers them) with Unicode-aware ones, which api-core's case-insensitive filters
 * (`LOWER(column) LIKE LOWER(?)`) and plain `LIKE` filters rely on.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Helpers
 */
final class SqliteFunctions
{
    /**
     * Regular expressions of the LIKE patterns, by pattern and escape character.
     *
     * @var array<string, string>
     */
    private static array $patterns = [];

    /**
     * Patterns kept at most, so a long-running worker's searches don't grow the memory.
     *
     * @var int
     */
    private const int PATTERNS_KEPT = 500;

    /**
     * Replace the functions on the SQLite connection.
     *
     * @param PDO $pdo
     *
     * @return void
     */
    public static function register(PDO $pdo): void
    {
        if (!$pdo instanceof Sqlite) {
            return;
        }

        $pdo->createFunction('lower', fn (mixed $value) => is_string($value) ? mb_strtolower($value) : $value, 1, Sqlite::DETERMINISTIC);
        $pdo->createFunction('upper', fn (mixed $value) => is_string($value) ? mb_strtoupper($value) : $value, 1, Sqlite::DETERMINISTIC);
        // SQLite calls `value LIKE pattern [ESCAPE character]` as like(pattern, value[, character])
        $pdo->createFunction('like', fn (mixed $pattern, mixed $value) => self::like($pattern, $value), 2, Sqlite::DETERMINISTIC);
        $pdo->createFunction('like', fn (mixed $pattern, mixed $value, mixed $escape) => self::like($pattern, $value, $escape), 3, Sqlite::DETERMINISTIC);
    }

    /**
     * Determine whether the value matches the LIKE pattern, ignoring the case of any letter: `%` is any text, `_` any
     * one character, and the escape character makes the next one literal.
     *
     * @param mixed $pattern
     * @param mixed $value
     * @param mixed $escape
     *
     * @return int|null
     */
    public static function like(mixed $pattern, mixed $value, mixed $escape = null): ?int
    {
        if ($pattern === null || $value === null) {
            return null;
        }

        $key = $escape . "\0" . $pattern;

        if (!isset(self::$patterns[$key])) {
            if (count(self::$patterns) >= self::PATTERNS_KEPT) {
                self::$patterns = [];
            }

            $regex = '';
            $characters = mb_str_split((string) $pattern);

            for ($i = 0; $i < count($characters); $i++) {
                $character = $characters[$i];

                if ($escape !== null && $character === $escape && isset($characters[$i + 1])) {
                    $regex .= preg_quote($characters[++$i], '/');
                } else {
                    $regex .= match ($character) {
                        '%' => '.*',
                        '_' => '.',
                        default => preg_quote($character, '/'),
                    };
                }
            }

            self::$patterns[$key] = '/^' . $regex . '$/isu';
        }

        return (int) preg_match(self::$patterns[$key], (string) $value);
    }
}
