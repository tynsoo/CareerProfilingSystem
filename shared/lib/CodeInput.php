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

    /** Digits only (6-digit email codes): " 123 456 " -> "123456". */
    public static function digits(string $raw): string
    {
        return (string) preg_replace('/\D/', '', self::clean($raw));
    }
}
