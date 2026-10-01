<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator\Laravel;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use TrueVs\AiTranslator\Manager;
use TrueVs\AiTranslator\Support\UiStrings;

final class LiveTranslate
{
    public function __construct(private readonly Manager $manager)
    {
    }

    public function canUse(): bool
    {
        if (!$this->manager->enabled() || !Auth::check()) {
            return false;
        }
        $ability = config('ai-translator.gate');
        return !is_string($ability) || $ability === '' || Gate::allows($ability);
    }

    public function button(): string
    {
        if (!$this->canUse()) {
            return '';
        }
        $language = $this->manager->languages()->current();
        $label = htmlspecialchars(UiStrings::get('topbar', $language), ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars(UiStrings::get('title', $language), ENT_QUOTES, 'UTF-8');
        return '<button type="button" id="lt-admin-toggle" data-lt-skip title="' . $title . '" '
            . 'style="display:inline-flex;align-items:center;gap:.45rem;padding:.55rem .8rem;border:1px solid #d9dee8;border-radius:.65rem;background:#fff;color:#374151;font:600 13px/1 system-ui;cursor:pointer">'
            . '<span aria-hidden="true">⚡</span><span>' . $label . '</span></button>';
    }

    public function scripts(): string
    {
        if (!$this->manager->enabled()) {
            return '';
        }
        $language = $this->manager->languages()->current();
        $map = $this->manager->dictionary()->runtimeMap($language);
        $canUse = $this->canUse();
        if ($map === [] && !$canUse) {
            return '';
        }
        $runtime = $this->json(['map' => (object)$map, 'lang' => $language]);
        $html = '<script>window.LT_RUNTIME=' . $runtime . ';</script>' . PHP_EOL
            . '<script src="' . htmlspecialchars($this->assetUrl('runtime.js'), ENT_QUOTES, 'UTF-8') . '"></script>' . PHP_EOL;
        if ($canUse) {
            $payload = [
                'endpoint' => url(trim((string)config('ai-translator.route_prefix', 'ai-translator'), '/')),
                'csrf' => csrf_token(),
                'lang' => $language,
                'defaultLang' => $this->manager->languages()->default(),
                'languages' => $this->manager->languages()->describe(),
                'ai' => ['enabled' => $this->manager->aiEnabled(), 'model' => $this->manager->aiModel()],
                'scanLimit' => $this->manager->scanLimit(),
                'isAdmin' => request()->routeIs((string)config('ai-translator.route_name', 'ai-translator.') . '*'),
                'rtl' => $this->manager->languages()->isRtl($language),
                'strings' => UiStrings::all($language),
            ];
            $html .= '<script>window.LT_EDITOR=' . $this->json($payload) . ';</script>' . PHP_EOL
                . '<script src="' . htmlspecialchars($this->assetUrl('editor.js'), ENT_QUOTES, 'UTF-8') . '"></script>' . PHP_EOL;
        }
        return $html;
    }

    private function assetUrl(string $file): string
    {
        $name = (string)config('ai-translator.route_name', 'ai-translator.') . 'asset';
        $path = dirname(__DIR__, 2) . '/resources/js/' . $file;
        return route($name, ['file' => $file, 'v' => is_file($path) ? filemtime($path) : 1]);
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
