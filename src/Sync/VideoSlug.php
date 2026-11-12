<?php

namespace RichmondSunlight\VideoProcessor\Sync;

/**
 * Video slugs: the short, random, permanent IDs in video pages' URLs on the
 * website (e.g., /video/k7qm/). Every files row must have one (files.slug,
 * which has a unique index).
 *
 * The website makes them the same way, in Video::new_slug() (htdocs/includes/
 * class.Video.php in richmondsunlight.com); keep the two alike.
 */
final class VideoSlug
{
    /** No vowels, so a slug can't spell a word, and nothing easily misread (0/o, 1/l/i). */
    public const ALPHABET = 'bcdfghjkmnpqrstvwxz23456789';
    public const LENGTH = 4;

    /** How many slugs to try, when the ones made are already taken, before giving up. */
    public const MAX_ATTEMPTS = 10;

    /**
     * A new, random slug. Every slug has at least one letter, so that it can't
     * be mistaken for a numeric video ID.
     */
    public static function generate(): string
    {
        $last = strlen(self::ALPHABET) - 1;
        do {
            $slug = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $slug .= self::ALPHABET[random_int(0, $last)];
            }
        } while (ctype_digit($slug));

        return $slug;
    }

    public static function isValid(string $slug): bool
    {
        return preg_match('/^[' . self::ALPHABET . ']{' . self::LENGTH . '}$/', $slug) === 1
            && !ctype_digit($slug);
    }

    /**
     * Whether a failed write failed only because its slug was taken. Works
     * with MySQL ("Duplicate entry 'k7qm' for key 'slug'") and SQLite
     * ("UNIQUE constraint failed: files.slug").
     *
     * @param array<int,mixed>|null $errorInfo PDO's errorInfo
     */
    public static function isCollision(?array $errorInfo): bool
    {
        return $errorInfo !== null
            && (string) ($errorInfo[0] ?? '') === '23000'
            && stripos((string) ($errorInfo[2] ?? ''), 'slug') !== false;
    }
}
