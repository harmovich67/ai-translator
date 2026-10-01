<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use TrueVs\AiTranslator\Database\DatabaseConnection;
use TrueVs\AiTranslator\Dictionary;
use TrueVs\AiTranslator\Live\ContentResolver;
use TrueVs\AiTranslator\Live\ContentWriter;
use TrueVs\AiTranslator\Storage\JsonFileSettingsStore;
use TrueVs\AiTranslator\Storage\PdoSettingsStore;
use TrueVs\AiTranslator\Support\Text;
use TrueVs\AiTranslator\Translation\FileTranslator;
use TrueVs\AiTranslator\Translation\HasTranslations;
use TrueVs\AiTranslator\Translation\LanguageRegistry;
use TrueVs\AiTranslator\Translation\TranslationContext;

$failures = 0;
$checks = 0;
$expect = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
};

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'truevs-ai-translator-' . bin2hex(random_bytes(5));
mkdir($temporary, 0775, true);
mkdir($temporary . '/lang/ar', 0775, true);
mkdir($temporary . '/lang/en', 0775, true);
file_put_contents($temporary . '/lang/ar/common.php', "<?php return ['welcome' => 'أهلاً :name'];\n");
file_put_contents($temporary . '/lang/en/common.php', "<?php return ['welcome' => 'Welcome :name'];\n");

$languages = new LanguageRegistry([
    'ar' => ['native' => 'العربية', 'direction' => 'rtl'],
    'en' => ['native' => 'English', 'direction' => 'ltr'],
], 'ar', 'en');
TranslationContext::use($languages);
$store = new JsonFileSettingsStore($temporary . '/settings.json');

$expect(Text::normalize('  Hello&nbsp;— world… ') === 'Hello - world...', 'text normalization matches the browser runtime');
$expect($languages->current() === 'ar' && $languages->isRtl(), 'language registry default and RTL metadata');

$translator = new FileTranslator($languages, $store, $temporary . '/lang');
$expect($translator->get('common.welcome', ['name' => 'نور']) === 'أهلاً نور', 'file translation with placeholder');
$store->put($translator->overrideKey('ar', 'common.welcome'), 'مرحبًا :name', 'translations');
$expect($translator->get('common.welcome', ['name' => 'نور']) === 'مرحبًا نور', 'stored translation override wins');

$dictionary = new Dictionary($store, $languages);
$id = $dictionary->save('Hello', ['ar' => 'مرحبًا', 'en' => 'Hello']);
$expect(strlen($id) === 16, 'dictionary creates a stable id');
$expect(($dictionary->runtimeMap('ar')['Hello'] ?? null) === 'مرحبًا', 'dictionary runtime map translates source');
$expect(($dictionary->find('مرحبًا')['id'] ?? null) === $id, 'dictionary resolves in both directions');

$model = new class {
    use HasTranslations;
    public string $title_ar = 'عنوان';
    public string $title_en = 'Title';
    public string $features_ar = "أول\nثان";
};
$expect($model->translated('title') === 'عنوان', 'translated attribute uses the active locale');
$expect($model->translatedArray('features') === ['أول', 'ثان'], 'translated array supports newline storage');

if (extension_loaded('pdo_sqlite')) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE ai_translator_settings (`key` TEXT PRIMARY KEY, `value` TEXT, `group` TEXT, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, slug TEXT, title_ar TEXT, title_en TEXT, updated_at TEXT)');
    $pdo->exec("INSERT INTO posts (id, slug, title_ar, title_en) VALUES (1, 'first', 'أول مقال', 'First post')");
    $database = new DatabaseConnection($pdo);
    $pdoStore = new PdoSettingsStore($database);
    $resolver = new ContentResolver($database, $pdoStore, $languages, $temporary . '/lang', [
        'tables' => ['posts' => ['label' => ['ar' => 'مقال', 'en' => 'Post'], 'name' => ['title'], 'edit' => '/posts/{id}']],
    ]);
    $writer = new ContentWriter($database, $pdoStore, $languages, $resolver);
    $found = $resolver->find('أول مقال', '/posts/first');
    $expect(count($found) === 1 && $found[0]['current'] === true, 'resolver finds and prioritizes the current database row');
    $saved = $writer->write($found[0]['ref'], ['ar' => 'المقال الأول', 'en' => 'The first post'], Text::normalize('أول مقال'));
    $row = $database->fetch('SELECT title_ar, title_en FROM posts WHERE id = 1');
    $expect($saved['ok'] && $row['title_ar'] === 'المقال الأول' && $row['title_en'] === 'The first post', 'writer safely updates localized columns');
} else {
    fwrite(STDOUT, "SKIP: PDO SQLite integration test (extension not loaded)\n");
}

$removeTree = static function (string $directory) use (&$removeTree): void {
    if (!is_dir($directory)) {
        return;
    }
    foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $item) {
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? $removeTree($path) : unlink($path);
    }
    rmdir($directory);
};
$removeTree($temporary);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of {$checks} checks failed.\n");
    exit(1);
}
fwrite(STDOUT, "OK: {$checks} checks passed.\n");
