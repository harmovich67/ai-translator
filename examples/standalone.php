<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use TrueVs\AiTranslator\AI\GeminiTranslator;
use TrueVs\AiTranslator\Storage\JsonFileSettingsStore;
use TrueVs\AiTranslator\Translation\FileTranslator;
use TrueVs\AiTranslator\Translation\LanguageRegistry;

$languages = new LanguageRegistry([
    'ar' => ['native' => 'العربية', 'direction' => 'rtl'],
    'en' => ['native' => 'English', 'direction' => 'ltr'],
], default: 'ar', fallback: 'en');

$settings = new JsonFileSettingsStore(__DIR__ . '/../storage/ai-translator.json');
$translator = new FileTranslator($languages, $settings, __DIR__ . '/../lang');

echo $translator->get('common.welcome', ['name' => 'Sara']);

$gemini = new GeminiTranslator((string)getenv('GEMINI_API_KEY'), $languages);
$translated = $gemini->translate('Welcome to our website', 'en', ['ar']);
print_r($translated);
