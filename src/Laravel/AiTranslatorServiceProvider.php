<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator\Laravel;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\ServiceProvider;
use TrueVs\AiTranslator\Contracts\SettingsStore;
use TrueVs\AiTranslator\Database\DatabaseConnection;
use TrueVs\AiTranslator\Dictionary;
use TrueVs\AiTranslator\Laravel\Console\InstallCommand;
use TrueVs\AiTranslator\Live\ContentResolver;
use TrueVs\AiTranslator\Live\ContentWriter;
use TrueVs\AiTranslator\Manager;
use TrueVs\AiTranslator\Storage\PdoSettingsStore;
use TrueVs\AiTranslator\Translation\FileTranslator;
use TrueVs\AiTranslator\Translation\LanguageRegistry;
use TrueVs\AiTranslator\Translation\TranslationContext;

final class AiTranslatorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/ai-translator.php', 'ai-translator');

        $this->app->singleton(DatabaseConnection::class, fn($app): DatabaseConnection => new DatabaseConnection(
            $app['db']->connection()->getPdo()
        ));
        $this->app->singleton(SettingsStore::class, fn($app): SettingsStore => new PdoSettingsStore(
            $app->make(DatabaseConnection::class),
            (string)$app['config']->get('ai-translator.storage.table', 'ai_translator_settings')
        ));
        $this->app->singleton(LanguageRegistry::class, function ($app): LanguageRegistry {
            $config = (array)$app['config']->get('ai-translator.languages', []);
            return new LanguageRegistry(
                (array)($config['supported'] ?? []),
                (string)($config['default'] ?? $app->getLocale()),
                (string)($config['fallback'] ?? 'en'),
                fn(): string => $app->getLocale(),
            );
        });
        $this->app->singleton(Dictionary::class, fn($app): Dictionary => new Dictionary(
            $app->make(SettingsStore::class),
            $app->make(LanguageRegistry::class),
        ));
        $this->app->singleton(ContentResolver::class, function ($app): ContentResolver {
            $path = $app['config']->get('ai-translator.lang_path');
            if (!is_string($path) || $path === '') {
                $path = function_exists('lang_path') ? lang_path() : resource_path('lang');
            }
            return new ContentResolver(
                $app->make(DatabaseConnection::class),
                $app->make(SettingsStore::class),
                $app->make(LanguageRegistry::class),
                $path,
                (array)$app['config']->get('ai-translator.live', []),
                fn(string $value): string => url($value),
            );
        });
        $this->app->singleton(ContentWriter::class, fn($app): ContentWriter => new ContentWriter(
            $app->make(DatabaseConnection::class),
            $app->make(SettingsStore::class),
            $app->make(LanguageRegistry::class),
            $app->make(ContentResolver::class),
        ));
        $this->app->singleton(Manager::class, fn($app): Manager => new Manager(
            $app->make(SettingsStore::class),
            $app->make(LanguageRegistry::class),
            $app->make(Dictionary::class),
            $app->make(ContentResolver::class),
            $app->make(ContentWriter::class),
            [
                'enabled' => $app['config']->get('ai-translator.enabled', true),
                'scan_limit' => $app['config']->get('ai-translator.live.scan_limit', 60),
                'ai' => (array)$app['config']->get('ai-translator.ai', []),
            ],
        ));
        $this->app->singleton(LiveTranslate::class);

        $this->app->singleton(FileTranslator::class, function ($app): FileTranslator {
            $path = $app['config']->get('ai-translator.lang_path');
            if (!is_string($path) || $path === '') {
                $path = function_exists('lang_path') ? lang_path() : resource_path('lang');
            }
            return new FileTranslator(
                $app->make(LanguageRegistry::class),
                $app->make(SettingsStore::class),
                $path,
                (string)$app['config']->get('ai-translator.live.override_prefix', 'translation.'),
            );
        });
    }

    public function boot(): void
    {
        TranslationContext::use($this->app->make(LanguageRegistry::class));

        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'ai-translator');
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');

        $this->publishes([
            __DIR__ . '/../../config/ai-translator.php' => config_path('ai-translator.php'),
        ], 'ai-translator-config');
        $this->publishes([
            __DIR__ . '/../../database/migrations' => database_path('migrations'),
        ], 'ai-translator-migrations');
        $this->publishes([
            __DIR__ . '/../../resources/views' => resource_path('views/vendor/ai-translator'),
        ], 'ai-translator-views');

        Blade::directive('aiTranslatorScripts', fn(): string => '<?php echo app(\\TrueVs\\AiTranslator\\Laravel\\LiveTranslate::class)->scripts(); ?>');
        Blade::directive('aiTranslatorButton', fn(): string => '<?php echo app(\\TrueVs\\AiTranslator\\Laravel\\LiveTranslate::class)->button(); ?>');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }

        // Make inline overrides work with Laravel's native __()/trans() calls too.
        $this->app->booted(function (): void {
            try {
                $settings = $this->app->make(SettingsStore::class);
                $languages = $this->app->make(LanguageRegistry::class);
                $prefix = (string)config('ai-translator.live.override_prefix', 'translation.');
                foreach ($languages->codes() as $locale) {
                    $base = $prefix . $locale . '.';
                    $lines = [];
                    foreach ($settings->allByPrefix($base) as $key => $value) {
                        if (is_string($value) && $value !== '') {
                            $lines[substr($key, strlen($base))] = $value;
                        }
                    }
                    if ($lines !== []) {
                        Lang::addLines($lines, $locale);
                    }
                }
            } catch (\Throwable) {
                // The first request may happen before the package migration has run.
            }
        });
    }
}
