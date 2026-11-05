<?php

/**
 * Cleans a code someone typed or pasted (assessment access code, password-change code, password-reset code, staff
 * activation code) before it is compared.
 *
 * Codes copied from an email or a chat often carry extra characters: a space before or after, a line break, a tab, a
 * non-breaking space (U+00A0) or an invisible zero-width character that PHP's trim() does not remove, or a dash typed
 * to group the characters ("AB12-CD"). None of these are ever part of a ProfilePath code (codes are letters and
 * digits only), so they are all dropped here. This is what made a correct code read as "invalid" during the demo.
 */
class CodeInput
{
    /** Letters and digits only, upper case: " ab12\u{00A0}cd\n" -> "AB12CD". */
    public static function clean(string $raw): string
    {
        // Unicode-aware first (non-breaking and zero-width spaces), then anything that is not a letter or digit.
        $noSpaces = preg_replace('/[\s\x{00A0}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+/u', '', $raw);
        if ($noSpaces === null) {
            // Not valid UTF-8: fall back to bytes, which still drops every non-alphanumeric byte.
            $noSpaces = $raw;
        }
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $noSpaces));
    }

    /**
     * A code of a known length picked out of whatever was pasted: "Access code: 7C3E91" -> "7C3E91".
     *
     * clean() alone glues the words around a code onto it ("ACCESSCODE7C3E91"), which then never matches. When the
     * cleaned text is not exactly $length characters long, the pasted words are looked at one by one and the last one
     * of exactly $length letters and digits is used, preferring one that has a digit in it (every access code so far
     * does; a label word such as "ACCESS" does not). A code typed in groups ("7C3 E91") is still handled by clean().
     */
    public static function pick(string $raw, int $length): string
    {
        $cleaned = self::clean($raw);
        if (strlen($cleaned) === $length || $length <= 0) {
            return $cleaned;
        }
        $noInvisible = preg_replace('/[\x{00A0}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', ' ', $raw) ?? $raw;
        $fits = [];
        foreach (preg_split('/[^A-Za-z0-9]+/', $noInvisible, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (strlen($word) === $length) {
                $fits[] = strtoupper($word);
            }
        }
        $withDigit = array_values(array_filter($fits, static fn ($w) => preg_match('/\d/', $w) === 1));
        if ($withDigit) {
            return end($withDigit);
        }
        return $fits ? end($fits) : $cleaned;
    }

    /** Digits only (6-digit email codes): " 123 456 " -> "123456". */
    public static function digits(string $raw): string
    {
        return (string) preg_replace('/\D/', '', self::clean($raw));
    }
}
