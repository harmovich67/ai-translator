<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = (string)config('ai-translator.storage.table', 'ai_translator_settings');
        if (Schema::hasTable($table)) {
            return;
        }
        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->string('key')->primary();
            $blueprint->longText('value')->nullable();
            $blueprint->string('group')->default('general')->index();
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string)config('ai-translator.storage.table', 'ai_translator_settings'));
    }
};
