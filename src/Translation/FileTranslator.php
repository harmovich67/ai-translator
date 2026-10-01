<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator\Translation;

use TrueVs\AiTranslator\Contracts\SettingsStore;

final class FileTranslator
{
    /** @var array<string,array<string,mixed>> */
    private array $loaded = [];

    public function __construct(
        private readonly LanguageRegistry $languages,
        private readonly SettingsStore $settings,
        private readonly string $languagePath,
        private readonly string $overridePrefix = 'translation.',
    ) {
    }

    public function get(string $key, array $replace = [], ?string $locale = null): mixed
    {
        $locale ??= $this->languages->current();
        $override = $this->settings->get($this->overrideKey($locale, $key));
        $line = is_string($override) && $override !== '' ? $override : $this->fromFile($locale, $key);

        if ($line === null && $locale !== $this->languages->fallback()) {
            $fallback = $this->languages->fallback();
            $override = $this->settings->get($this->overrideKey($fallback, $key));
            $line = is_string($override) && $override !== '' ? $override : $this->fromFile($fallback, $key);
        }
        if ($line === null) {
            return $key;
        }
        if (!is_string($line)) {
            return $line;
        }
        foreach ($replace as $placeholder => $value) {
            $line = str_replace([':' . $placeholder, '{' . $placeholder . '}'], (string)$value, $line);
        }
        return $line;
    }

    public function overrideKey(string $locale, string $key): string
    {
        return $this->overridePrefix . $locale . '.' . $key;
    }

    private function fromFile(string $locale, string $key): mixed
    {
        $parts = explode('.', $key);
        $file = array_shift($parts);
        $cache = $locale . '.' . $file;
        if (!array_key_exists($cache, $this->loaded)) {
            $path = rtrim($this->languagePath, '/\\') . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $file . '.php';
            $lines = is_file($path) ? require $path : [];
            $this->loaded[$cache] = is_array($lines) ? $lines : [];
        }
        $value = $this->loaded[$cache];
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
