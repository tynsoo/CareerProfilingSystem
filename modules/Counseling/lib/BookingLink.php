<?php

/**
 * The CGC's existing counseling booking page. The Head of Guidance saves its address on the Staff Help Center
 * (CGC Counseling Booking Link, saved through api/security-config.php). Avail Counseling on the Student Help Center
 * opens it for the student, and Invite to counseling sends it with the invitation. Empty means "not set".
 */
class BookingLink
{
    public const KEY = 'booking.url';
    public const MAX_LENGTH = 300;

    /**
     * Checks an address typed by staff.
     *
     * @return array{error:?string,url:string} url is '' when it was left empty (which clears the link)
     */
    public static function validate(string $input, ?string $ownHost = null): array
    {
        $url = trim($input);
        if ($url === '') {
            return ['error' => null, 'url' => ''];
        }
        if (mb_strlen($url) > self::MAX_LENGTH) {
            return ['error' => 'The link must be ' . self::MAX_LENGTH . ' characters or fewer.', 'url' => ''];
        }
        $parts = filter_var($url, FILTER_VALIDATE_URL) !== false ? parse_url($url) : false;
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            return ['error' => 'Enter a full web address that starts with https://', 'url' => ''];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['error' => 'The link cannot contain a user name or password.', 'url' => ''];
        }
        // A link back to ProfilePath itself would send the student round in a loop instead of to the CGC booking page.
        if ($ownHost !== null && $ownHost !== '' && strcasecmp((string) $parts['host'], $ownHost) === 0) {
            return ['error' => 'Enter the CGC booking page, not a ProfilePath address.', 'url' => ''];
        }
        return ['error' => null, 'url' => $url];
    }

    /** The host name ProfilePath is being served on (without the port), or null outside a web request. */
    public static function requestHost(): ?string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return null;
        }
        $name = parse_url('https://' . $host, PHP_URL_HOST);
        return is_string($name) && $name !== '' ? $name : null;
    }
}
