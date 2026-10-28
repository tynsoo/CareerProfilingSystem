<?php

/**
 * The level for the low-similarity monitoring flag (security_policies monitoring.lowConfidenceThreshold). A student is
 * flagged when the highest cosine similarity of the latest result is below it (CBFData::saveRecommendation). This is
 * separate from a career mismatch (Preferred Course not a Best RIASEC Match), which never flags a student.
 *
 * DEFAULT is the 10th percentile, rounded down, of the highest similarity of the 314 study students with their RIASEC
 * counts (0-10) as given in the study file (Table 24: 0.7932). It was not computed from ProfilePath's own results, so it
 * should be rechecked on them. FLOOR is the lowest highest similarity any valid set of scores can
 * give (raw R17 I5 A17 S21 E5 C25), so a level at or below it can never flag anyone. The Administrator sets the level
 * in Security Configuration; it should be recomputed from ProfilePath's own results each school year.
 */
class MonitoringLevel
{
    public const KEY = 'monitoring.lowConfidenceThreshold';
    public const DEFAULT = 0.793;
    public const FLOOR = 0.5844;
    public const MAX = 1.0;

    /** A saved value is used only when it is a number above FLOOR and at most MAX; anything else is DEFAULT. */
    public static function fromValue(mixed $value): float
    {
        if (!is_numeric($value)) {
            return self::DEFAULT;
        }
        $level = (float) $value;
        return $level > self::FLOOR && $level <= self::MAX ? $level : self::DEFAULT;
    }

    public static function get(PDO $pdo): float
    {
        $stmt = $pdo->prepare('SELECT value FROM security_policies WHERE key = ?');
        $stmt->execute([self::KEY]);
        return self::fromValue($stmt->fetchColumn());
    }

    /**
     * Checks a level typed by the Administrator: a number with at most three decimals, above FLOOR and at most MAX.
     *
     * @return array{level: ?float, error: ?string}
     */
    public static function validate(mixed $value): array
    {
        $text = is_string($value) || is_int($value) || is_float($value) ? trim((string) $value) : '';
        if (!preg_match('/^(0(\.\d{1,3})?|1(\.0{1,3})?)$/', $text)) {
            return ['level' => null, 'error' => 'Enter a number from 0.585 to 1.000 with up to three decimals.'];
        }
        $level = (float) $text;
        if ($level <= self::FLOOR) {
            return ['level' => null, 'error' => 'The level must be above ' . self::FLOOR . ': no student can score below that, so nobody would be flagged.'];
        }
        return ['level' => $level, 'error' => null];
    }
}
