<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator\Translation;

final class TranslationContext
{
    private static ?LanguageRegistry $languages = null;

    public static function use(LanguageRegistry $languages): void
    {
        self::$languages = $languages;
    }

    public static function locale(): string
    {
        return self::$languages?->current() ?? 'en';
    }

    public static function fallback(): string
    {
        return self::$languages?->fallback() ?? 'en';
    }
}
