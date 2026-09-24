<?php

/**
 * LRN (Learner Reference Number) rules, kept in one place so registration,
 * roster upload and the client-side hint stay in agreement.
 *
 * The official DepEd LRN is 12 digits; the school also accepts 10-12 digits.
 * Internally the value is still stored in the `school_id` / `username`
 * columns — only what users see is called "LRN".
 */
class Lrn
{
    public const MIN_DIGITS = 10;
    public const MAX_DIGITS = 12;
    public const INVALID_MESSAGE = 'LRN must be 10 to 12 digits (numbers only, no spaces or symbols).';

    public static function isValid(string $value): bool
    {
        return (bool) preg_match('/^[0-9]{' . self::MIN_DIGITS . ',' . self::MAX_DIGITS . '}$/', $value);
    }
}
