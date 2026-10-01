<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator\Storage;

use TrueVs\AiTranslator\Contracts\SettingsStore;
use TrueVs\AiTranslator\Database\DatabaseConnection;

final class PdoSettingsStore implements SettingsStore
{
    /** @var array<string,mixed> */
    private array $cache = [];

    public function __construct(
        private readonly DatabaseConnection $database,
        private readonly string $table = 'ai_translator_settings',
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        $id = $this->database->identifier('key');
        $row = $this->database->fetch(
            'SELECT ' . $this->database->identifier('value') . ' FROM ' . $this->database->identifier($this->table) . " WHERE {$id} = :key",
            ['key' => $key]
        );
        if (!$row) {
            return $default;
        }
        return $this->cache[$key] = $this->decode($row['value'] ?? null);
    }

    public function put(string $key, mixed $value, string $group = 'general'): void
    {
        $now = date('Y-m-d H:i:s');
        $encoded = $this->encode($value);
        $keyColumn = $this->database->identifier('key');
        $exists = $this->database->fetch(
            'SELECT ' . $keyColumn . ' FROM ' . $this->database->identifier($this->table) . " WHERE {$keyColumn} = :key",
            ['key' => $key]
        );
        if ($exists) {
            $this->database->update($this->table, [
                'value' => $encoded,
                'group' => $group,
                'updated_at' => $now,
            ], $keyColumn . ' = :key', ['key' => $key]);
        } else {
            $this->database->insert($this->table, [
                'key' => $key,
                'value' => $encoded,
                'group' => $group,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->cache[$key] = $value;
    }

    public function forget(string $key): void
    {
        $id = $this->database->identifier('key');
        $this->database->execute(
            'DELETE FROM ' . $this->database->identifier($this->table) . " WHERE {$id} = :key",
            ['key' => $key]
        );
        unset($this->cache[$key]);
    }

    public function allByPrefix(string $prefix): array
    {
        $id = $this->database->identifier('key');
        $value = $this->database->identifier('value');
        $rows = $this->database->fetchAll(
            "SELECT {$id}, {$value} FROM " . $this->database->identifier($this->table) . " WHERE {$id} LIKE :prefix",
            ['prefix' => strtr($prefix, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%']
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['key']] = $this->decode($row['value'] ?? null);
        }
        return $result;
    }

    private function encode(mixed $value): string
    {
        return json_encode(['value' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decode(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) && array_key_exists('value', $decoded) ? $decoded['value'] : $value;
    }
}
