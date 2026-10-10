<?php

/**
 * The CGC's existing counseling booking page. When the Head of Guidance saves its address in Security Configuration,
 * "Schedule Advising Session" on a student's results opens that page instead of sending a counseling request.
 * Empty means "not set": the button keeps sending a request.
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
