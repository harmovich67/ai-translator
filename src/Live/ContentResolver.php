<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator\Live;

use Throwable;
use TrueVs\AiTranslator\Contracts\SettingsStore;
use TrueVs\AiTranslator\Database\DatabaseConnection;
use TrueVs\AiTranslator\Support\Text;
use TrueVs\AiTranslator\Translation\LanguageRegistry;

final class ContentResolver
{
    /** @var array<string,string[]> */
    private array $columnsCache = [];
    /** @var array<string,array<string,string>>|null */
    private ?array $languageIndex = null;

    /** @param callable(string):string|null $urlBuilder */
    public function __construct(
        private readonly DatabaseConnection $database,
        private readonly SettingsStore $settings,
        private readonly LanguageRegistry $languages,
        private readonly string $languagePath,
        private readonly array $config = [],
        private readonly mixed $urlBuilder = null,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function find(string $text, string $path = ''): array
    {
        $normalized = Text::normalize($text);
        if (!Text::isCandidate($normalized)) {
            return [];
        }
        $pattern = Text::likePattern(Text::likeNeedle($normalized));
        $found = [];
        foreach ([
            fn(): array => $this->findLanguageLines($normalized),
            fn(): array => $this->findSettings($normalized, $pattern),
            fn(): array => $this->findRows($normalized, $pattern, $path),
        ] as $search) {
            try {
                $found = array_merge($found, $search());
            } catch (Throwable $exception) {
                error_log('[ai-translator] resolver: ' . $exception->getMessage());
            }
        }
        usort($found, static function (array $left, array $right): int {
            if ($left['current'] !== $right['current']) {
                return $left['current'] ? -1 : 1;
            }
            $weights = ['setting' => 1, 'lang' => 2, 'field' => 3, 'line' => 4];
            return ($weights[$left['kind']] ?? 5) <=> ($weights[$right['kind']] ?? 5);
        });
        return array_slice($found, 0, max(1, (int)($this->config['limit'] ?? 40)));
    }

    /** @return string[] */
    public function columns(string $table): array
    {
        if (!isset($this->columnsCache[$table])) {
            try {
                $this->columnsCache[$table] = $this->database->columns($table);
            } catch (Throwable) {
                $this->columnsCache[$table] = [];
            }
        }
        return $this->columnsCache[$table];
    }

    /** @return array<string,array<string,string>> */
    public function fields(string $table): array
    {
        if (!isset(($this->config['tables'] ?? [])[$table])) {
            return [];
        }
        $fields = [];
        foreach ($this->columns($table) as $column) {
            if (preg_match('/^(.+)_([a-z]{2,12}(?:-[A-Za-z0-9]{2,8})?)$/', $column, $match)
                && in_array($match[2], $this->languages->codes(), true)) {
                $fields[$match[1]][$match[2]] = $column;
            }
        }
        return $fields;
    }

    /** @return array{values:array<string,string>,matched:string}|null */
    public function matchGroup(array $raw, string $normalized): ?array
    {
        foreach ($this->languages->codes() as $code) {
            $block = $raw[$code] ?? '';
            if (is_string($block) && strlen($block) <= 20000 && Text::isCandidate($block) && Text::normalize($block) === $normalized) {
                return ['values' => $this->decoded($raw), 'matched' => $code];
            }
        }
        return null;
    }

    /** @return array<int,array{index:int,single:bool,values:array<string,string>,matched:string}> */
    public function matchLines(array $raw, string $normalized): array
    {
        $split = [];
        foreach ($this->languages->codes() as $code) {
            $split[$code] = isset($raw[$code]) && strlen((string)$raw[$code]) <= 20000 ? Text::lines((string)$raw[$code]) : [''];
        }
        $hits = [];
        foreach ($split as $code => $lines) {
            foreach ($lines as $index => $line) {
                if (isset($hits[$index]) || !Text::isCandidate($line) || Text::normalize($line) !== $normalized) {
                    continue;
                }
                $values = [];
                foreach ($split as $other => $otherLines) {
                    $values[$other] = Text::decode(trim($otherLines[$index] ?? ''));
                }
                $hits[$index] = ['index' => $index, 'single' => count($lines) === 1, 'values' => $values, 'matched' => $code];
            }
        }
        return array_values($hits);
    }

    /** @return array{raw:array<string,string>,group:string,keys:array<string,string>} */
    public function settingGroup(string $base): array
    {
        $definition = $this->contentSettings();
        $keys = [];
        foreach ($this->languages->codes() as $code) {
            $keys[$code] = $base . '_' . $code;
        }
        if (!$definition['enabled']) {
            return ['raw' => array_fill_keys(array_keys($keys), ''), 'group' => 'general', 'keys' => $keys];
        }
        $parameters = [];
        $placeholders = [];
        foreach (array_values($keys) as $index => $key) {
            $parameters['key_' . $index] = $key;
            $placeholders[] = ':key_' . $index;
        }
        $q = fn(string $name): string => $this->database->identifier($name);
        $rows = $this->database->fetchAll(
            'SELECT ' . $q($definition['key']) . ' AS at_key, ' . $q($definition['value']) . ' AS at_value, '
            . $q($definition['group']) . ' AS at_group FROM ' . $q($definition['table'])
            . ' WHERE ' . $q($definition['key']) . ' IN (' . implode(', ', $placeholders) . ')',
            $parameters
        );
        $byKey = [];
        $group = 'general';
        foreach ($rows as $row) {
            $byKey[(string)$row['at_key']] = $row['at_value'];
            $group = (string)($row['at_group'] ?: $group);
        }
        $raw = [];
        foreach ($keys as $code => $key) {
            $raw[$code] = is_string($byKey[$key] ?? null) ? $byKey[$key] : '';
        }
        return ['raw' => $raw, 'group' => $group, 'keys' => $keys];
    }

    /** @return array<string,array<string,string>> */
    public function languageIndex(): array
    {
        if ($this->languageIndex !== null) {
            return $this->languageIndex;
        }
        $files = [];
        foreach ($this->languages->codes() as $code) {
            foreach (glob(rtrim($this->languagePath, '/\\') . DIRECTORY_SEPARATOR . $code . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
                $files[basename($file, '.php')] = true;
            }
        }
        $index = [];
        foreach (array_keys($files) as $file) {
            foreach ($this->languages->codes() as $code) {
                $path = rtrim($this->languagePath, '/\\') . DIRECTORY_SEPARATOR . $code . DIRECTORY_SEPARATOR . $file . '.php';
                $lines = is_file($path) ? require $path : [];
                $this->flatten(is_array($lines) ? $lines : [], $file, $code, $index);
            }
        }
        foreach ($index as $key => $values) {
            foreach ($this->languages->codes() as $code) {
                $override = $this->settings->get($this->overrideKey($code, $key));
                $index[$key][$code] = is_string($override) && $override !== '' ? $override : ($values[$code] ?? '');
            }
        }
        return $this->languageIndex = $index;
    }

    public function overrideKey(string $code, string $key): string
    {
        return (string)($this->config['override_prefix'] ?? 'translation.') . $code . '.' . $key;
    }

    /** @return array{enabled:bool,table:string,key:string,value:string,group:string} */
    public function contentSettings(): array
    {
        $settings = (array)($this->config['content_settings'] ?? []);
        return [
            'enabled' => (bool)($settings['enabled'] ?? false),
            'table' => (string)($settings['table'] ?? 'settings'),
            'key' => (string)($settings['key_column'] ?? 'key'),
            'value' => (string)($settings['value_column'] ?? 'value'),
            'group' => (string)($settings['group_column'] ?? 'group'),
        ];
    }

    public function clearLanguageCache(): void
    {
        $this->languageIndex = null;
    }

    /** @return array<int,array<string,mixed>> */
    private function findRows(string $normalized, string $pattern, string $path): array
    {
        $segments = array_map('rawurldecode', array_values(array_filter(explode('/', trim($path, '/')), 'strlen')));
        $result = [];
        foreach ((array)($this->config['tables'] ?? []) as $table => $information) {
            $fields = $this->fields((string)$table);
            if ($fields === []) {
                continue;
            }
            $columns = array_values(array_unique(array_merge(...array_values(array_map('array_values', $fields)))));
            $hasSlug = in_array('slug', $this->columns((string)$table), true);
            $where = [];
            $parameters = [];
            foreach ($columns as $index => $column) {
                $where[] = $this->database->identifier($column) . " LIKE :needle_{$index} ESCAPE '!'";
                $parameters['needle_' . $index] = $pattern;
            }
            $select = array_merge(['id'], $hasSlug ? ['slug'] : [], $columns);
            $rows = $this->database->fetchAll(
                'SELECT ' . implode(', ', array_map($this->database->identifier(...), $select))
                . ' FROM ' . $this->database->identifier((string)$table) . ' WHERE ' . implode(' OR ', $where) . ' LIMIT 200',
                $parameters
            );
            foreach ($rows as $row) {
                $current = $hasSlug && !empty($row['slug']) && in_array(rawurldecode((string)$row['slug']), $segments, true);
                foreach ($fields as $field => $byLanguage) {
                    $raw = [];
                    foreach ($byLanguage as $code => $column) {
                        $raw[$code] = (string)($row[$column] ?? '');
                    }
                    foreach ($this->matchLines($raw, $normalized) as $hit) {
                        $result[] = [
                            'ref' => 'line:' . $table . ':' . $row['id'] . ':' . $field . ':' . $hit['index'],
                            'kind' => $hit['single'] ? 'field' : 'line',
                            'label' => $this->rowLabel((string)$table, (array)$information, $row, (string)$field),
                            'edit_url' => $this->url(str_replace('{id}', (string)$row['id'], (string)($information['edit'] ?? ''))),
                            'current' => $current,
                            'values' => $hit['values'],
                            'matched' => $hit['matched'],
                        ];
                    }
                }
            }
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function findSettings(string $normalized, string $pattern): array
    {
        $definition = $this->contentSettings();
        if (!$definition['enabled']) {
            return [];
        }
        $q = fn(string $name): string => $this->database->identifier($name);
        $rows = $this->database->fetchAll(
            'SELECT ' . $q($definition['key']) . ' AS at_key, ' . $q($definition['value']) . ' AS at_value FROM '
            . $q($definition['table']) . ' WHERE ' . $q($definition['value']) . " LIKE :needle ESCAPE '!' LIMIT 300",
            ['needle' => $pattern]
        );
        $bases = [];
        foreach ($rows as $row) {
            $split = $this->splitSettingKey((string)$row['at_key']);
            $value = (string)$row['at_value'];
            if ($split && $value !== '' && !in_array($value[0], ['{', '['], true)) {
                $bases[$split[0]] = true;
            }
        }
        $result = [];
        foreach (array_keys($bases) as $base) {
            $group = $this->settingGroup($base);
            $match = $this->matchGroup($group['raw'], $normalized);
            if ($match) {
                $result[] = [
                    'ref' => 'setting:' . $base,
                    'kind' => 'setting',
                    'label' => $base,
                    'edit_url' => $this->url($this->settingsEditPath($base)),
                    'current' => false,
                    'values' => $match['values'],
                    'matched' => $match['matched'],
                ];
            }
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function findLanguageLines(string $normalized): array
    {
        $result = [];
        foreach ($this->languageIndex() as $key => $values) {
            $match = $this->matchGroup($values, $normalized);
            if ($match) {
                $result[] = [
                    'ref' => 'lang:' . $key,
                    'kind' => 'lang',
                    'label' => $key,
                    'edit_url' => $this->url((string)($this->config['lang_edit'] ?? '')),
                    'current' => false,
                    'values' => $match['values'],
                    'matched' => $match['matched'],
                ];
            }
        }
        return $result;
    }

    /** @return array{0:string,1:string}|null */
    private function splitSettingKey(string $key): ?array
    {
        if (preg_match('/^(.+)_([a-z]{2,12}(?:-[A-Za-z0-9]{2,8})?)$/', $key, $match)
            && in_array($match[2], $this->languages->codes(), true)) {
            return [$match[1], $match[2]];
        }
        return null;
    }

    private function rowLabel(string $table, array $information, array $row, string $field): string
    {
        $current = $this->languages->current();
        $label = (string)($information['label'][$current] ?? $information['label']['en'] ?? $table);
        $name = '';
        foreach ((array)($information['name'] ?? []) as $base) {
            foreach (array_unique([$current, $this->languages->default(), $this->languages->fallback()]) as $code) {
                $value = trim(strip_tags(Text::decode((string)($row[$base . '_' . $code] ?? ''))));
                if ($value !== '') {
                    $name = mb_strlen($value) > 48 ? mb_substr($value, 0, 48) . '…' : $value;
                    break 2;
                }
            }
        }
        return $label . ($name !== '' ? ': ' . $name : ' #' . ($row['id'] ?? '')) . ' · ' . $field;
    }

    private function settingsEditPath(string $base): string
    {
        foreach ((array)($this->config['settings_edit'] ?? []) as $prefix => $path) {
            if ($prefix !== '*' && str_starts_with($base, (string)$prefix)) {
                return (string)$path;
            }
        }
        return (string)(($this->config['settings_edit'] ?? [])['*'] ?? '');
    }

    /** @return array<string,string> */
    private function decoded(array $raw): array
    {
        $result = [];
        foreach ($this->languages->codes() as $code) {
            $result[$code] = isset($raw[$code]) && is_string($raw[$code]) ? Text::decode(trim($raw[$code])) : '';
        }
        return $result;
    }

    /** @param array<string,array<string,string>> $index */
    private function flatten(array $data, string $prefix, string $code, array &$index): void
    {
        foreach ($data as $segment => $value) {
            $key = $prefix . '.' . $segment;
            if (is_array($value)) {
                $this->flatten($value, $key, $code, $index);
            } elseif (is_string($value)) {
                $index[$key][$code] = $value;
            }
        }
    }

    private function url(string $path): string
    {
        return $path !== '' && is_callable($this->urlBuilder) ? (string)($this->urlBuilder)($path) : $path;
    }
}
