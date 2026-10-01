<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator\Translation;

trait HasTranslations
{
    public function trans(string $attribute, ?string $locale = null, ?string $fallback = null): string
    {
        return $this->translated($attribute, $locale, $fallback);
    }

    public function translated(string $attribute, ?string $locale = null, ?string $fallback = null): string
    {
        $locale ??= TranslationContext::locale();
        $fallback ??= TranslationContext::fallback();

        foreach ([$attribute . '_' . $locale, $attribute] as $name) {
            $value = $this->translationAttribute($name);
            if ($name === $attribute) {
                $map = $this->translationMap($value);
                if ($map !== null) {
                    $value = $map[$locale] ?? $map[$fallback] ?? null;
                }
            }
            if ($value !== null && $value !== '') {
                return (string)$value;
            }
        }

        $value = $this->translationAttribute($attribute . '_' . $fallback);
        return $value !== null ? (string)$value : '';
    }

    /** @return string[] */
    public function translatedArray(string $attribute, ?string $locale = null, ?string $fallback = null): array
    {
        $locale ??= TranslationContext::locale();
        $fallback ??= TranslationContext::fallback();
        $value = $this->translationAttribute($attribute . '_' . $locale)
            ?? $this->translationAttribute($attribute)
            ?? $this->translationAttribute($attribute . '_' . $fallback);
        $map = $this->translationMap($value);
        if ($map !== null && (isset($map[$locale]) || isset($map[$fallback]))) {
            $value = $map[$locale] ?? $map[$fallback];
        }
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded)
            ? array_values(array_map('strval', $decoded))
            : array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value) ?: [])));
    }

    /** @return string[] */
    public function transArray(string $attribute, ?string $locale = null, ?string $fallback = null): array
    {
        return $this->translatedArray($attribute, $locale, $fallback);
    }

    private function translationAttribute(string $name): mixed
    {
        if (method_exists($this, 'getAttribute')) {
            return $this->getAttribute($name);
        }
        return $this->{$name} ?? null;
    }

    /** @return array<string,mixed>|null */
    private function translationMap(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || !in_array(substr(ltrim($value), 0, 1), ['{', '['], true)) {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
