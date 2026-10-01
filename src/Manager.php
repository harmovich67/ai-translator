<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator;

use TrueVs\AiTranslator\AI\GeminiTranslator;
use TrueVs\AiTranslator\AI\TranslationException;
use TrueVs\AiTranslator\Contracts\SettingsStore;
use TrueVs\AiTranslator\Live\ContentResolver;
use TrueVs\AiTranslator\Live\ContentWriter;
use TrueVs\AiTranslator\Support\Text;
use TrueVs\AiTranslator\Translation\LanguageRegistry;

final class Manager
{
    public function __construct(
        private readonly SettingsStore $settings,
        private readonly LanguageRegistry $languages,
        private readonly Dictionary $dictionary,
        private readonly ContentResolver $resolver,
        private readonly ContentWriter $writer,
        private readonly array $config = [],
    ) {
    }

    public function enabled(): bool
    {
        return (bool)$this->settings->get('enabled', (bool)($this->config['enabled'] ?? true));
    }

    public function setEnabled(bool $enabled): void
    {
        $this->settings->put('enabled', $enabled, 'system');
    }

    public function aiEnabled(): bool
    {
        return (bool)$this->settings->get('ai.enabled', (bool)($this->config['ai']['enabled'] ?? false))
            && $this->aiKey() !== '';
    }

    public function aiModel(): string
    {
        $model = GeminiTranslator::cleanModel((string)$this->settings->get('ai.model', $this->config['ai']['model'] ?? ''));
        return $model !== '' ? $model : GeminiTranslator::DEFAULT_MODEL;
    }

    public function aiKey(): string
    {
        return trim((string)$this->settings->get('ai.key', $this->config['ai']['key'] ?? ''));
    }

    public function saveAiSettings(bool $enabled, string $model, ?string $key = null, bool $removeKey = false): void
    {
        $this->settings->put('ai.enabled', $enabled, 'ai');
        $this->settings->put('ai.model', GeminiTranslator::cleanModel($model) ?: GeminiTranslator::DEFAULT_MODEL, 'ai');
        if ($removeKey) {
            $this->settings->forget('ai.key');
        } elseif ($key !== null && trim($key) !== '') {
            $this->settings->put('ai.key', trim($key), 'ai');
        }
    }

    public function maskedAiKey(): string
    {
        $key = $this->aiKey();
        return $key === '' ? '' : (strlen($key) > 12 ? substr($key, 0, 4) . str_repeat('•', 8) . substr($key, -4) : str_repeat('•', 8));
    }

    /** @return array{candidates:array,dictionary:?array} */
    public function resolve(string $text, string $path = ''): array
    {
        $entry = $this->dictionary->find($text);
        return [
            'candidates' => $this->resolver->find($text, $path),
            'dictionary' => $entry ? ['values' => (object)($entry['values'] ?? [])] : null,
        ];
    }

    /** @param string[] $texts @return array<int,array<string,mixed>> */
    public function resolveMany(array $texts, string $path = ''): array
    {
        $result = [];
        $seen = [];
        foreach ($texts as $text) {
            if (!is_string($text)) {
                continue;
            }
            $text = mb_substr(trim($text), 0, 4000);
            $normalized = Text::normalize($text);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $result[] = ['text' => $text] + $this->resolve($text, $path);
            if (count($result) >= $this->scanLimit()) {
                break;
            }
        }
        return $result;
    }

    /**
     * @param array<string,string> $values
     * @param string[] $targets
     * @return array{results:array,replacement:?string,runtime:?object}
     */
    public function save(string $text, string $path, array $values, array $targets, bool $saveToDictionary, ?string $pageLanguage = null): array
    {
        $normalized = Text::normalize($text);
        $clean = [];
        foreach ($this->languages->codes() as $code) {
            $clean[$code] = Text::clean($values[$code] ?? '');
        }
        if ($normalized === '' || !array_filter($clean, 'strlen') || ($targets === [] && !$saveToDictionary)) {
            throw new \InvalidArgumentException('nothing-to-save');
        }
        $allowed = [];
        foreach ($this->resolver->find($text, $path) as $candidate) {
            $allowed[$candidate['ref']] = true;
        }
        $results = [];
        $replacement = null;
        foreach (array_values(array_unique($targets)) as $reference) {
            if (!is_string($reference) || !isset($allowed[$reference])) {
                $results[] = ['ref' => $reference, 'ok' => false, 'message' => 'The text is no longer stored there. Reload the page.'];
                continue;
            }
            $result = $this->writer->write($reference, $clean, $normalized);
            $results[] = ['ref' => $reference, 'ok' => $result['ok'], 'message' => $result['message']];
            if ($result['ok'] && $replacement === null && ($clean[$result['matched']] ?? '') !== '') {
                $replacement = $clean[$result['matched']];
            }
        }
        $runtime = null;
        if ($saveToDictionary) {
            $language = in_array($pageLanguage, $this->languages->codes(), true) ? $pageLanguage : $this->languages->current();
            $this->dictionary->save($text, array_filter($clean, 'strlen'));
            $results[] = ['ref' => 'dictionary', 'ok' => true, 'message' => 'ok'];
            $replacement ??= $clean[$language] !== '' ? $clean[$language] : null;
            $runtime = (object)$this->dictionary->runtimeMap($language);
        }
        if (!array_filter($results, static fn(array $result): bool => (bool)$result['ok'])) {
            throw new \RuntimeException('failed');
        }
        return compact('results', 'replacement', 'runtime');
    }

    /** @param string[] $targets @return array<string,string> */
    public function translate(string $text, string $source, array $targets): array
    {
        return $this->gemini()->translate($text, $source, $targets);
    }

    /**
     * @param array<string,string> $items
     * @param string[] $targets
     * @return array{values:array<string,array<string,string>>,failed:string[],message:?string}
     */
    public function translateMany(array $items, string $source, array $targets): array
    {
        $values = [];
        $message = null;
        foreach (array_chunk(array_slice($items, 0, $this->scanLimit(), true), GeminiTranslator::MAX_BATCH, true) as $chunk) {
            try {
                $values += $this->gemini()->translateBatch($chunk, $source, $targets);
            } catch (TranslationException $exception) {
                $message = $exception->reason . ($exception->hasDetail() ? ': ' . mb_substr($exception->getMessage(), 0, 200) : '');
                break;
            }
        }
        return ['values' => $values, 'failed' => array_values(array_diff(array_keys($items), array_keys($values))), 'message' => $message];
    }

    public function scanLimit(): int
    {
        return max(1, (int)($this->config['scan_limit'] ?? 60));
    }

    public function languages(): LanguageRegistry
    {
        return $this->languages;
    }

    public function dictionary(): Dictionary
    {
        return $this->dictionary;
    }

    public function resolver(): ContentResolver
    {
        return $this->resolver;
    }

    private function gemini(): GeminiTranslator
    {
        if (!$this->aiEnabled()) {
            throw new TranslationException('off');
        }
        return new GeminiTranslator(
            $this->aiKey(),
            $this->languages,
            $this->aiModel(),
            (string)($this->config['ai']['endpoint'] ?? 'https://generativelanguage.googleapis.com/v1beta/models/'),
            (int)($this->config['ai']['timeout'] ?? 40),
        );
    }
}
