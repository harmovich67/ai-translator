# TrueVs AI Translator

A reusable PHP 8.1+ package that combines:

- PHP translation files with database overrides and fallback locales.
- Localized model fields (`title_ar`, `title_en`) and JSON translations.
- Live inline editing: click visible text, review every locale, and save it where it is stored.
- Browser dictionary translations for text written directly in templates.
- Gemini translation for one text or every missing text on the current page.
- Laravel auto-discovery, routes, migration, admin UI, Blade directives, and native `__()` overrides.

The core has no framework dependency. The Laravel bridge is loaded only inside Laravel.

## التثبيت السريع في Laravel

لو الحزمة في فولدر محلي بجانب مشروعك، أضفها كـ path repository:

```json
{
  "repositories": [
    {"type": "path", "url": "../truevs-ai-translator", "options": {"symlink": true}}
  ],
  "require": {
    "truevs/ai-translator": "@dev"
  }
}
```

ثم نفّذ:

```bash
composer update truevs/ai-translator
php artisan ai-translator:install
php artisan migrate
```

بعد نشر الإعدادات عدّل `config/ai-translator.php`، ثم أضف قبل `</body>` في كل Blade layout:

```blade
@aiTranslatorScripts
```

وفي الـ admin header، أو أي مكان مناسب للمشرف:

```blade
@aiTranslatorButton
```

صفحة الإدارة الافتراضية: `/ai-translator`. هي محمية بـ `web` و`auth`. غيّر `middleware` أو عرّف `gate` في الإعدادات لو مشروعك يستخدم guard/permission مختلفًا.

فعّل Gemini في `.env`:

```dotenv
AI_TRANSLATOR_GEMINI_ENABLED=true
AI_TRANSLATOR_GEMINI_KEY=your_server_side_key
AI_TRANSLATOR_GEMINI_MODEL=gemini-3.6-flash
```

المفتاح لا يصل إلى المتصفح. يمكن أيضًا حفظه من صفحة الإدارة، لكن متغير البيئة أفضل في الإنتاج.

## Configure content tables

Live editing only writes to explicitly allowed tables. A table needs an integer `id` and locale-suffixed columns such as `title_ar`, `title_en`, `features_ar`, and `features_en`:

```php
'live' => [
    'tables' => [
        'posts' => [
            'label' => ['ar' => 'مقال', 'en' => 'Post'],
            'edit' => '/admin/posts/{id}/edit',
            'name' => ['title'],
        ],
    ],
],
```

[`examples/truevs-laravel-config.php`](examples/truevs-laravel-config.php) contains the ready mapping for the source True Ventures schema, including its `settings` table.

## Localized models

The trait works with Eloquent models and plain PHP objects:

```php
use TrueVs\AiTranslator\Translation\HasTranslations;

class Post extends Model
{
    use HasTranslations;
}

$post->translated('title');
$post->trans('title');              // compatibility alias
$post->translatedArray('features');
```

Resolution order is `field_currentLocale`, a locale map stored in the base field, then the fallback-locale column.

## Translation files

Laravel continues to use its native helper:

```php
__('common.welcome', ['name' => $user->name]);
```

Overrides saved by the package are injected into Laravel's translator during boot. For standalone PHP, use `FileTranslator` directly:

```php
$translator->get('common.welcome', ['name' => 'Sara'], 'ar');
```

## Standalone Composer usage

Install from Packagist after publishing:

```bash
composer require truevs/ai-translator
```

Or use the same Composer `path` repository shown above. A minimal framework-free example is in [`examples/standalone.php`](examples/standalone.php).

Use `JsonFileSettingsStore` for a zero-database setup. For PDO storage:

```php
$database = new DatabaseConnection($pdo);
$settings = new PdoSettingsStore($database, 'ai_translator_settings');
```

The PDO table needs these columns: `key` (primary string), `value` (text), `group` (string), `created_at`, and `updated_at`. The full live resolver uses the same `DatabaseConnection`, `ContentResolver`, `ContentWriter`, `Dictionary`, and `Manager` classes, so a custom framework only needs to expose five authenticated POST endpoints matching the included JavaScript:

- `resolve`
- `resolve-many`
- `ai`
- `ai-many`
- `save`

Laravel registers these automatically.

## How live translation stores data

| Visible text source | Storage |
|---|---|
| `field_ar` / `field_en` content | The exact localized row columns |
| Key/value content setting | The configured host settings table |
| `lang/<locale>/*.php` line | A package override; source files remain unchanged |
| Text written directly in a template | The package dictionary, applied by `runtime.js` |

Matching is exact after safe whitespace/entity normalization. Saving re-reads the source and refuses stale changes. Empty locale values never blank existing content. Client-supplied database references are accepted only if the resolver independently finds them again.

## Full-page translation

When Gemini is enabled, the editor adds **Translate page**. It scans visible text, resolves where each item lives, skips complete items, translates missing locales in batches of 20, and opens a review dialog. Nothing is stored until the administrator chooses rows and saves them. `live.scan_limit` caps one page scan (default: 60).

## Security checklist

- Keep the package routes behind authentication and an authorization gate.
- Put `AI_TRANSLATOR_GEMINI_KEY` in server environment/config in production.
- Only add trusted database tables to `live.tables`.
- Keep CSRF middleware enabled; the editor sends Laravel's CSRF token.
- Review Gemini output before saving. Source text is sent to Google's Gemini API when AI translation is used.

## Development

```bash
composer validate --strict
composer test
```

The test suite is dependency-free and also runs the PDO resolver/writer integration test when `pdo_sqlite` is installed.
