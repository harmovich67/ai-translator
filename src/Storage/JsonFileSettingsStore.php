<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator\Storage;

use RuntimeException;
use TrueVs\AiTranslator\Contracts\SettingsStore;

final class JsonFileSettingsStore implements SettingsStore
{
    /** @var array<string,mixed>|null */
    private ?array $items = null;

    public function __construct(private readonly string $path)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items()[$key]['value'] ?? $default;
    }

    public function put(string $key, mixed $value, string $group = 'general'): void
    {
        $items = $this->items();
        $items[$key] = ['value' => $value, 'group' => $group, 'updated_at' => time()];
        $this->write($items);
    }

    public function forget(string $key): void
    {
        $items = $this->items();
        unset($items[$key]);
        $this->write($items);
    }

    public function allByPrefix(string $prefix): array
    {
        $result = [];
        foreach ($this->items() as $key => $item) {
            if (str_starts_with($key, $prefix)) {
                $result[$key] = $item['value'] ?? null;
            }
        }
        return $result;
    }

    /** @return array<string,array{value:mixed,group?:string,updated_at?:int}> */
    private function items(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }
        if (!is_file($this->path)) {
            return $this->items = [];
        }
        $decoded = json_decode((string)file_get_contents($this->path), true);
        return $this->items = is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $items */
    private function write(array $items): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create settings directory: {$directory}");
        }
        $json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write settings file: {$this->path}");
        }
        $this->items = $items;
    }
}
