<?php

/**
 * The CGC's existing counseling booking page. The Head of Guidance saves its address on the Staff Help Center
 * (CGC Counseling Booking Link, saved through api/security-config.php). Avail Counseling on the Student Help Center
 * emails it to the student, and Invite to counseling sends it with the invitation. Empty means "not set".
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
    public static function validate(string $input): array
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
        return ['error' => null, 'url' => $url];
    }
}
