<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator\Live;

use Throwable;
use TrueVs\AiTranslator\Contracts\SettingsStore;
use TrueVs\AiTranslator\Database\DatabaseConnection;
use TrueVs\AiTranslator\Support\Text;
use TrueVs\AiTranslator\Translation\LanguageRegistry;

final class ContentWriter
{
    private const STALE = 'The text was changed by someone else. Reload the page.';

    public function __construct(
        private readonly DatabaseConnection $database,
        private readonly SettingsStore $settings,
        private readonly LanguageRegistry $languages,
        private readonly ContentResolver $resolver,
    ) {
    }

    /** @param array<string,string> $values @return array{ok:bool,message:string,matched?:string} */
    public function write(string $reference, array $values, string $normalized): array
    {
        $parts = explode(':', $reference, 5);
        try {
            return match ($parts[0] ?? '') {
                'line' => $this->writeLine((string)($parts[1] ?? ''), (int)($parts[2] ?? 0), (string)($parts[3] ?? ''), (int)($parts[4] ?? -1), $values, $normalized),
                'setting' => $this->writeSetting((string)($parts[1] ?? ''), $values, $normalized),
                'lang' => $this->writeLanguageLine((string)($parts[1] ?? ''), $values, $normalized),
                default => $this->failure('Unknown target.'),
            };
        } catch (Throwable $exception) {
            error_log('[ai-translator] writer ' . $reference . ': ' . $exception->getMessage());
            return $this->failure('Could not save. Check the server log.');
        }
    }

    /** @param array<string,string> $values */
    private function writeLine(string $table, int $id, string $field, int $index, array $values, string $normalized): array
    {
        $byLanguage = $this->resolver->fields($table)[$field] ?? null;
        if (!$byLanguage || $id < 1 || $index < 0) {
            return $this->failure('Unknown field.');
        }
        $idColumn = $this->database->identifier('id');
        $row = $this->database->fetch(
            'SELECT ' . implode(', ', array_map($this->database->identifier(...), array_values($byLanguage)))
            . ' FROM ' . $this->database->identifier($table) . " WHERE {$idColumn} = :id LIMIT 1",
            ['id' => $id]
        );
        if (!$row) {
            return $this->failure('The record no longer exists.');
        }
        $raw = [];
        foreach ($byLanguage as $code => $column) {
            $raw[$code] = (string)($row[$column] ?? '');
        }
        $hit = null;
        foreach ($this->resolver->matchLines($raw, $normalized) as $candidate) {
            if ($candidate['index'] === $index) {
                $hit = $candidate;
                break;
            }
        }
        if (!$hit) {
            return $this->failure(self::STALE);
        }
        $data = [];
        foreach ($byLanguage as $code => $column) {
            $value = trim((string)preg_replace('/\s*[\r\n]+\s*/', ' ', Text::clean($values[$code] ?? '')));
            if ($value === '') {
                continue;
            }
            $lines = Text::lines($raw[$code]);
            if ($index >= count($lines)) {
                continue;
            }
            $lines[$index] = Text::forStorage($value, $raw, false);
            $data[$column] = implode(str_contains($raw[$code], "\r\n") ? "\r\n" : "\n", $lines);
        }
        if ($data === []) {
            return $this->failure('Nothing to save for this record.');
        }
        if (in_array('updated_at', $this->resolver->columns($table), true)) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }
        $this->database->update($table, $data, $idColumn . ' = :id', ['id' => $id]);
        return $this->success((string)$hit['matched']);
    }

    /** @param array<string,string> $values */
    private function writeSetting(string $base, array $values, string $normalized): array
    {
        if ($base === '' || preg_match('/^[A-Za-z0-9_.-]+$/', $base) !== 1) {
            return $this->failure('Unknown setting.');
        }
        $group = $this->resolver->settingGroup($base);
        $match = $this->resolver->matchGroup($group['raw'], $normalized);
        if (!$match) {
            return $this->failure(self::STALE);
        }
        $definition = $this->resolver->contentSettings();
        if (!$definition['enabled']) {
            return $this->failure('Content settings integration is disabled.');
        }
        $written = 0;
        foreach ($group['keys'] as $code => $key) {
            $value = Text::clean($values[$code] ?? '');
            if ($value === '') {
                continue;
            }
            $this->putContentSetting($definition, $key, Text::forStorage($value, $group['raw'], true), $group['group']);
            $written++;
        }
        return $written ? $this->success((string)$match['matched']) : $this->failure('Nothing to save for this setting.');
    }

    /** @param array<string,string> $values */
    private function writeLanguageLine(string $key, array $values, string $normalized): array
    {
        $current = $this->resolver->languageIndex()[$key] ?? null;
        if (!$current) {
            return $this->failure('Unknown translation line.');
        }
        $match = $this->resolver->matchGroup($current, $normalized);
        if (!$match) {
            return $this->failure(self::STALE);
        }
        $written = 0;
        foreach ($this->languages->codes() as $code) {
            $value = Text::clean($values[$code] ?? '');
            if ($value !== '') {
                $this->settings->put($this->resolver->overrideKey($code, $key), $value, 'translations');
                $written++;
            }
        }
        $this->resolver->clearLanguageCache();
        return $written ? $this->success((string)$match['matched']) : $this->failure('Nothing to save for this translation line.');
    }

    /** @param array{table:string,key:string,value:string,group:string} $definition */
    private function putContentSetting(array $definition, string $key, string $value, string $group): void
    {
        $q = fn(string $name): string => $this->database->identifier($name);
        $columns = $this->resolver->columns($definition['table']);
        $exists = $this->database->fetch(
            'SELECT ' . $q($definition['key']) . ' FROM ' . $q($definition['table']) . ' WHERE ' . $q($definition['key']) . ' = :key',
            ['key' => $key]
        );
        $data = [$definition['value'] => $value, $definition['group'] => $group];
        if (in_array('updated_at', $columns, true)) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }
        if ($exists) {
            $this->database->update($definition['table'], $data, $q($definition['key']) . ' = :key', ['key' => $key]);
            return;
        }
        $data[$definition['key']] = $key;
        if (in_array('created_at', $columns, true)) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        $this->database->insert($definition['table'], $data);
    }

    /** @return array{ok:false,message:string} */
    private function failure(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }

    /** @return array{ok:true,message:string,matched:string} */
    private function success(string $matched): array
    {
        return ['ok' => true, 'message' => 'ok', 'matched' => $matched];
    }
}
