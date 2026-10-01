<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator\Laravel\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;
use TrueVs\AiTranslator\AI\TranslationException;
use TrueVs\AiTranslator\Manager;
use TrueVs\AiTranslator\Support\Text;
use TrueVs\AiTranslator\Support\UiStrings;

final class AiTranslatorController extends Controller
{
    public function __construct(private readonly Manager $manager)
    {
    }

    public function asset(string $file): Response
    {
        abort_unless(in_array($file, ['runtime.js', 'editor.js'], true), 404);
        $path = dirname(__DIR__, 3) . '/resources/js/' . $file;
        abort_unless(is_file($path), 404);
        return response((string)file_get_contents($path), 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function index(): View
    {
        $this->authorizeGate();
        return view('ai-translator::index', [
            'enabled' => $this->manager->enabled(),
            'aiEnabled' => $this->manager->aiEnabled(),
            'aiModel' => $this->manager->aiModel(),
            'maskedKey' => $this->manager->maskedAiKey(),
            'entries' => $this->manager->dictionary()->entries(),
            'languages' => $this->manager->languages()->describe(),
            'translationLines' => $this->manager->resolver()->languageIndex(),
            'rtl' => $this->manager->languages()->isRtl(),
        ]);
    }

    public function settings(Request $request): RedirectResponse
    {
        $this->authorizeGate();
        $this->manager->setEnabled($request->boolean('enabled'));
        $this->manager->saveAiSettings(
            $request->boolean('ai_enabled'),
            (string)$request->input('ai_model', ''),
            $request->filled('ai_key') ? (string)$request->input('ai_key') : null,
            $request->boolean('remove_ai_key'),
        );
        return back()->with('status', 'AI Translator settings saved.');
    }

    public function translations(Request $request): RedirectResponse
    {
        $this->authorizeGate();
        foreach ((array)$request->input('translations', []) as $encodedKey => $values) {
            $key = base64_decode(strtr((string)$encodedKey, '-_', '+/'), true);
            if (!is_string($key) || !isset($this->manager->resolver()->languageIndex()[$key]) || !is_array($values)) {
                continue;
            }
            foreach ($this->manager->languages()->codes() as $code) {
                $value = Text::clean($values[$code] ?? '');
                if ($value !== '') {
                    app(\TrueVs\AiTranslator\Contracts\SettingsStore::class)->put(
                        $this->manager->resolver()->overrideKey($code, $key),
                        $value,
                        'translations'
                    );
                }
            }
        }
        $this->manager->resolver()->clearLanguageCache();
        return back()->with('status', 'File translations saved.');
    }

    public function resolve(Request $request): JsonResponse
    {
        $this->guardApi();
        $text = $this->postedText($request);
        if (Text::normalize($text) === '') {
            return $this->error('empty', 422);
        }
        return response()->json(['success' => true, 'data' => $this->manager->resolve($text, $this->postedPath($request)) + ['lang' => $this->postedLanguage($request)]]);
    }

    public function resolveMany(Request $request): JsonResponse
    {
        $this->guardApi();
        return response()->json(['success' => true, 'data' => [
            'items' => $this->manager->resolveMany((array)$request->input('texts', []), $this->postedPath($request)),
            'lang' => $this->postedLanguage($request),
        ]]);
    }

    public function translate(Request $request): JsonResponse
    {
        $this->guardApi();
        $language = $this->postedLanguage($request);
        try {
            $values = $this->manager->translate(
                $this->postedText($request),
                (string)$request->input('from', ''),
                (array)$request->input('to', []),
            );
            return response()->json(['success' => true, 'data' => ['values' => (object)$values]]);
        } catch (TranslationException $exception) {
            return $this->error($this->aiMessage($exception, $language), 502);
        }
    }

    public function translateMany(Request $request): JsonResponse
    {
        $this->guardApi();
        $items = [];
        foreach ((array)$request->input('items', []) as $id => $text) {
            if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) === 1 && is_string($text) && trim($text) !== '') {
                $items[$id] = mb_substr(trim($text), 0, 4000);
            }
        }
        try {
            $result = $this->manager->translateMany($items, (string)$request->input('from', ''), (array)$request->input('to', []));
            if (is_string($result['message']) && $result['message'] !== '') {
                [$reason, $detail] = array_pad(explode(': ', $result['message'], 2), 2, '');
                $result['message'] = UiStrings::get('ai_' . $reason, $this->postedLanguage($request)) . ($detail !== '' ? ' (' . $detail . ')' : '');
            }
            return response()->json(['success' => true, 'data' => $result]);
        } catch (TranslationException $exception) {
            return $this->error($this->aiMessage($exception, $this->postedLanguage($request)), 502);
        }
    }

    public function save(Request $request): JsonResponse
    {
        $this->guardApi();
        try {
            $result = $this->manager->save(
                $this->postedText($request),
                $this->postedPath($request),
                (array)$request->input('values', []),
                array_values(array_filter((array)$request->input('targets', []), 'is_string')),
                $request->boolean('dictionary'),
                $this->postedLanguage($request),
            );
            return response()->json(['success' => true, 'data' => $result]);
        } catch (InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
    }

    public function delete(string $id): RedirectResponse
    {
        $this->authorizeGate();
        $this->manager->dictionary()->delete($id);
        return back()->with('status', 'Dictionary entry deleted.');
    }

    public function export(): Response
    {
        $this->authorizeGate();
        $json = json_encode(array_values($this->manager->dictionary()->entries()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return response($json, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ai-translator-' . date('Y-m-d') . '.json"',
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $this->authorizeGate();
        $json = $request->hasFile('file') ? (string)file_get_contents($request->file('file')->getRealPath()) : (string)$request->input('json', '');
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return back()->withErrors(['import' => 'The import must be a valid exported JSON file.']);
        }
        $count = $this->manager->dictionary()->import($data);
        return back()->with('status', "Imported {$count} dictionary entries.");
    }

    private function authorizeGate(): void
    {
        $ability = config('ai-translator.gate');
        if (is_string($ability) && $ability !== '') {
            Gate::authorize($ability);
        }
    }

    private function guardApi(): void
    {
        abort_unless($this->manager->enabled(), 403);
        $this->authorizeGate();
    }

    private function postedText(Request $request): string
    {
        $text = $request->input('text', '');
        return is_string($text) ? mb_substr(trim($text), 0, 4000) : '';
    }

    private function postedPath(Request $request): string
    {
        $path = $request->input('path', '');
        return is_string($path) ? mb_substr($path, 0, 500) : '';
    }

    private function postedLanguage(Request $request): string
    {
        $language = $request->input('lang');
        return is_string($language) && in_array($language, $this->manager->languages()->codes(), true)
            ? $language
            : $this->manager->languages()->current();
    }

    private function aiMessage(TranslationException $exception, string $language): string
    {
        $message = UiStrings::get('ai_' . $exception->reason, $language);
        return $exception->hasDetail() ? $message . ' (' . mb_substr($exception->getMessage(), 0, 200) . ')' : $message;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'data' => ['message' => $message]], $status);
    }
}
