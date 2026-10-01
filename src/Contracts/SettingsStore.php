<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator\Contracts;

interface SettingsStore
{
    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value, string $group = 'general'): void;

    public function forget(string $key): void;

    /** @return array<string, mixed> */
    public function allByPrefix(string $prefix): array;
}
