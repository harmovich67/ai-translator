<?php

declare(strict_types=1);

namespace TrueVs\AiTranslator\Laravel\Console;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'ai-translator:install';
    protected $description = 'Publish the AI Translator configuration and migration';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'ai-translator-config', '--force' => true]);
        $this->call('vendor:publish', ['--tag' => 'ai-translator-migrations', '--force' => true]);
        $this->newLine();
        $this->components->info('AI Translator is ready. Run php artisan migrate.');
        $this->line('Add @aiTranslatorScripts before </body> and @aiTranslatorButton where administrators should toggle edit mode.');
        return self::SUCCESS;
    }
}
