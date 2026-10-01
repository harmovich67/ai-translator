<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator;

use InvalidArgumentException;
use TrueVs\AiTranslator\Contracts\SettingsStore;
use TrueVs\AiTranslator\Support\Text;
use TrueVs\AiTranslator\Translation\LanguageRegistry;

final class Dictionary
{
    /** @var array<string,array<string,mixed>>|null */
    private ?array $entries = null;
    /** @var array<string,string>|null */
    private ?array $index = null;

    public function __construct(
        private readonly SettingsStore $settings,
        private readonly LanguageRegistry $languages,
        private readonly string $settingKey = 'dictionary',
    ) {
    }

    /** @return array<string,array<string,mixed>> */
    public function entries(): array
    {
        if ($this->entries === null) {
            $stored = $this->settings->get($this->settingKey, []);
            $this->entries = is_array($stored) ? $stored : [];
        }
        return $this->entries;
    }

    public static function entryId(string $normalized): string
    {
        return substr(sha1($normalized), 0, 16);
    }

    public function find(string $text): ?array
    {
        $normalized = Text::normalize($text);
        $id = $this->index()[$normalized] ?? null;
        if ($id === null) {
            return null;
        }
        return ['id' => $id] + (array)$this->entries()[$id];
    }

    /** @param array<string,string> $values */
    public function save(string $source, array $values): string
    {
        $entries = $this->entries();
        $normalized = Text::normalize($source);
        if ($normalized === '') {
            throw new InvalidArgumentException('A dictionary source cannot be empty.');
        }
        $existing = $this->find($source);
        $id = (string)($existing['id'] ?? self::entryId($normalized));
        $entry = $entries[$id] ?? ['source' => $source, 'aliases' => [], 'values' => []];
        $entry['aliases'] = array_values(array_unique(array_merge((array)($entry['aliases'] ?? []), [$normalized])));
        foreach ($values as $language => $value) {
            if (in_array($language, $this->languages->codes(), true) && trim($value) !== '') {
                $entry['values'][$language] = Text::clean($value);
            }
        }
        $entry['updated'] = time();

        $owned = array_map([Text::class, 'normalize'], self::fromTexts($entry));
        foreach ($entries as $otherId => $other) {
            if ((string)$otherId !== $id && !empty($other['aliases'])) {
                $entries[$otherId]['aliases'] = array_values(array_diff((array)$other['aliases'], $owned));
            }
        }
        $entries[$id] = $entry;
        $this->store($entries);
        return $id;
    }

    public function delete(string $id): void
    {
        $entries = $this->entries();
        unset($entries[$id]);
        $this->store($entries);
    }

    public function import(array $incoming): int
    {
        $entries = $this->entries();
        $count = 0;
        foreach ($incoming as $entry) {
            if (!is_array($entry) || !is_array($entry['values'] ?? null)) {
                continue;
            }
            $values = [];
            foreach ($this->languages->codes() as $code) {
                $value = Text::clean($entry['values'][$code] ?? '');
                if ($value !== '') {
                    $values[$code] = $value;
                }
            }
            $aliases = [];
            foreach ((array)($entry['aliases'] ?? []) as $alias) {
                $alias = Text::clean($alias);
                if ($alias !== '') {
                    $aliases[] = Text::normalize($alias);
                }
            }
            $firstValue = $values === [] ? '' : (string)reset($values);
            $source = Text::clean((string)($entry['source'] ?? ($aliases[0] ?? $firstValue)));
            if ($source === '' || $values === []) {
                continue;
            }
            $id = self::entryId(Text::normalize($source));
            if (isset($entries[$id]) && (int)($entries[$id]['updated'] ?? 0) > (int)($entry['updated'] ?? 0)) {
                continue;
            }
            $entries[$id] = [
                'source' => $source,
                'aliases' => array_values(array_unique(array_merge($aliases, [Text::normalize($source)]))),
                'values' => $values,
                'updated' => (int)($entry['updated'] ?? time()),
            ];
            $count++;
        }
        if ($count > 0) {
            $this->store($entries);
        }
        return $count;
    }

    /** @return array<string,string> */
    public function runtimeMap(string $language): array
    {
        $map = [];
        foreach ($this->entries() as $entry) {
            $target = trim((string)($entry['values'][$language] ?? ''));
            if ($target === '') {
                continue;
            }
            foreach (self::fromTexts($entry) as $source) {
                $normalized = Text::normalize((string)$source);
                if ($normalized !== '' && $normalized !== Text::normalize($target)) {
                    $map[$normalized] = $target;
                }
            }
        }
        return $map;
    }

    /** @return array<string,string> */
    private function index(): array
    {
        if ($this->index === null) {
            $this->index = [];
            foreach ($this->entries() as $id => $entry) {
                foreach (self::fromTexts($entry) as $text) {
                    $normalized = Text::normalize((string)$text);
                    if ($normalized !== '') {
                        $this->index[$normalized] = (string)$id;
                    }
                }
            }
        }
        return $this->index;
    }

    /** @return array<int,mixed> */
    private static function fromTexts(array $entry): array
    {
        return array_merge((array)($entry['aliases'] ?? []), array_values((array)($entry['values'] ?? [])));
    }

    /** @param array<string,array<string,mixed>> $entries */
    private function store(array $entries): void
    {
        $this->settings->put($this->settingKey, $entries, 'dictionary');
        $this->entries = $entries;
        $this->index = null;
    }
}
