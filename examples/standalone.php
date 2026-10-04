<?php

declare(strict_types=1);

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../../vendor/autoload.php')) {
    require __DIR__ . '/../../../vendor/autoload.php';
} else {
    require __DIR__ . '/../tests/bootstrap.php';
}

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

$apiKey = (string)getenv('GEMINI_API_KEY');
if ($apiKey !== '') {
    $gemini = new GeminiTranslator($apiKey, $languages);
    $translated = $gemini->translate('Welcome to our website', 'en', ['ar']);
    print_r($translated);
} else {
    echo "\n(Set GEMINI_API_KEY environment variable to test live Gemini AI translation)\n";
}
