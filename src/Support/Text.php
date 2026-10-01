<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator\Support;

final class Text
{
    public static function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strtr($text, [
            "\xE2\x80\x98" => "'", "\xE2\x80\x99" => "'", "\xE2\x80\x9C" => '"', "\xE2\x80\x9D" => '"',
            "\xE2\x80\x93" => '-', "\xE2\x80\x94" => '-', "\xE2\x80\xA6" => '...', "\xC2\xA0" => ' ',
        ]);
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    public static function isCandidate(string $text): bool
    {
        return $text !== ''
            && strlen($text) <= 4000
            && preg_match('/<\/?[a-z][^>]*>/i', $text) !== 1
            && preg_match('/\p{L}/u', $text) === 1;
    }

    public static function likeNeedle(string $normalized): string
    {
        $best = '';
        foreach ((array)preg_split('/[^\p{L}\p{N} ]+/u', $normalized) as $part) {
            $part = trim((string)$part);
            if (mb_strlen($part) > mb_strlen($best)) {
                $best = $part;
            }
        }
        return mb_strlen($best) >= 2 ? $best : $normalized;
    }

    public static function likePattern(string $needle): string
    {
        return '%' . strtr($needle, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    /** @return string[] */
    public static function lines(string $value): array
    {
        return preg_split('/\r\n|\r|\n/', $value) ?: [''];
    }

    public static function hasEntities(string $stored): bool
    {
        return $stored !== '' && html_entity_decode($stored, ENT_QUOTES | ENT_HTML5, 'UTF-8') !== $stored;
    }

    /** @param string[] $existing */
    public static function forStorage(string $value, array $existing, bool $encodeByDefault): string
    {
        $encode = $encodeByDefault;
        foreach ($existing as $stored) {
            if (is_string($stored) && self::hasEntities($stored)) {
                $encode = true;
                break;
            }
        }
        return $encode ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
    }

    public static function decode(string $stored): string
    {
        return html_entity_decode($stored, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function clean(mixed $value, int $limit = 4000): string
    {
        return is_string($value) ? mb_substr(trim(strip_tags($value)), 0, $limit) : '';
    }
}
