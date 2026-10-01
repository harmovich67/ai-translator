<?php

use Illuminate\Support\Facades\Route;
use TrueVs\AiTranslator\Laravel\Controllers\AiTranslatorController;

$prefix = trim((string)config('ai-translator.route_prefix', 'ai-translator'), '/');
$name = (string)config('ai-translator.route_name', 'ai-translator.');

Route::get($prefix . '/assets/{file}', [AiTranslatorController::class, 'asset'])
    ->where('file', 'runtime\.js|editor\.js')
    ->name($name . 'asset');

Route::prefix($prefix)
    ->name($name)
    ->middleware((array)config('ai-translator.middleware', ['web', 'auth']))
    ->group(function (): void {
        Route::get('/', [AiTranslatorController::class, 'index'])->name('index');
        Route::post('/settings', [AiTranslatorController::class, 'settings'])->name('settings');
        Route::post('/translations', [AiTranslatorController::class, 'translations'])->name('translations');
        Route::post('/dictionary/{id}/delete', [AiTranslatorController::class, 'delete'])->name('dictionary.delete');
        Route::get('/export', [AiTranslatorController::class, 'export'])->name('export');
        Route::post('/import', [AiTranslatorController::class, 'import'])->name('import');

        Route::post('/resolve', [AiTranslatorController::class, 'resolve'])->name('resolve');
        Route::post('/resolve-many', [AiTranslatorController::class, 'resolveMany'])->name('resolve-many');
        Route::post('/ai', [AiTranslatorController::class, 'translate'])->name('ai');
        Route::post('/ai-many', [AiTranslatorController::class, 'translateMany'])->name('ai-many');
        Route::post('/save', [AiTranslatorController::class, 'save'])->name('save');
    });
