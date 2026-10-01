<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator\Translation;

use InvalidArgumentException;

final class LanguageRegistry
{
    private ?string $forcedLocale = null;

    /**
     * @param array<string,array<string,mixed>> $supported
     * @param callable():string|null $localeResolver
     */
    public function __construct(
        private readonly array $supported,
        private readonly string $default,
        private readonly string $fallback = 'en',
        private readonly mixed $localeResolver = null,
    ) {
        if ($supported === [] || !isset($supported[$default])) {
            throw new InvalidArgumentException('The default language must exist in the supported language map.');
        }
    }

    /** @return string[] */
    public function codes(): array
    {
        return array_keys($this->supported);
    }

    public function default(): string
    {
        return $this->default;
    }

    public function fallback(): string
    {
        return isset($this->supported[$this->fallback]) ? $this->fallback : $this->default;
    }

    public function current(): string
    {
        if ($this->forcedLocale !== null) {
            return $this->forcedLocale;
        }
        $resolved = is_callable($this->localeResolver) ? (string)($this->localeResolver)() : $this->default;
        $code = strtolower(str_replace('_', '-', $resolved));
        $short = explode('-', $code)[0];
        return isset($this->supported[$code]) ? $code : (isset($this->supported[$short]) ? $short : $this->default);
    }

    public function setCurrent(string $locale): void
    {
        if (!isset($this->supported[$locale])) {
            throw new InvalidArgumentException("Unsupported language: {$locale}");
        }
        $this->forcedLocale = $locale;
    }

    public function clearForcedLocale(): void
    {
        $this->forcedLocale = null;
    }

    public function isRtl(?string $locale = null): bool
    {
        return ($this->supported[$locale ?? $this->current()]['direction'] ?? 'ltr') === 'rtl';
    }

    /** @return array<int,array{code:string,name:string,flag:string,rtl:bool}> */
    public function describe(): array
    {
        $result = [];
        foreach ($this->supported as $code => $data) {
            $result[] = [
                'code' => $code,
                'name' => (string)($data['native'] ?? $data['name'] ?? $code),
                'flag' => (string)($data['flag'] ?? ''),
                'rtl' => ($data['direction'] ?? 'ltr') === 'rtl',
            ];
        }
        return $result;
    }
}
