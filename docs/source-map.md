# Source extraction map

The package consolidates the original project's two translation systems without retaining project-specific class dependencies.

| Original area | Package replacement |
|---|---|
| `core/Translation/Translator.php` | `Translation/FileTranslator.php` |
| `core/Translation/Translatable.php` | `Translation/HasTranslations.php` |
| `app/LiveTranslate/Text.php` | `Support/Text.php` |
| `app/LiveTranslate/Languages.php` | `Translation/LanguageRegistry.php` |
| `app/LiveTranslate/Dictionary.php` | `Dictionary.php` + `SettingsStore` |
| `app/LiveTranslate/Resolver.php` | `Live/ContentResolver.php` + `DatabaseConnection` |
| `app/LiveTranslate/Writer.php` | `Live/ContentWriter.php` |
| `app/LiveTranslate/Gemini.php` | `AI/GeminiTranslator.php` |
| `app/LiveTranslate/LiveTranslate.php` | `Manager.php` + Laravel `LiveTranslate.php` |
| `LiveTranslateController` | Laravel `AiTranslatorController.php` |
| `lt-runtime.js`, `lt-editor.js` | `resources/js/runtime.js`, `resources/js/editor.js` |
| custom admin routes and view | package routes + self-contained Blade admin view |
| project `settings` dependency | JSON/PDO `SettingsStore` implementations and package migration |

The original table map is intentionally not hard-coded. Reusable defaults are empty, while `examples/truevs-laravel-config.php` restores the source project's table configuration when desired.
