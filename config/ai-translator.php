<?php

return [
    'enabled' => env('AI_TRANSLATOR_ENABLED', true),

    'languages' => [
        'default' => env('AI_TRANSLATOR_DEFAULT_LOCALE', 'en'),
        'fallback' => env('AI_TRANSLATOR_FALLBACK_LOCALE', 'en'),
        'supported' => [
            'ar' => ['name' => 'Arabic', 'native' => 'العربية', 'direction' => 'rtl', 'flag' => '🇸🇦'],
            'en' => ['name' => 'English', 'native' => 'English', 'direction' => 'ltr', 'flag' => '🇬🇧'],
        ],
    ],

    // Set to an absolute path outside Laravel. In Laravel, null means lang_path().
    'lang_path' => null,

    'ai' => [
        'enabled' => env('AI_TRANSLATOR_GEMINI_ENABLED', false),
        'key' => env('AI_TRANSLATOR_GEMINI_KEY', ''),
        'model' => env('AI_TRANSLATOR_GEMINI_MODEL', 'gemini-3.6-flash'),
        'endpoint' => env('AI_TRANSLATOR_GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/models/'),
        'timeout' => (int)env('AI_TRANSLATOR_GEMINI_TIMEOUT', 40),
    ],

    'storage' => [
        'table' => 'ai_translator_settings',
    ],

    'live' => [
        'limit' => 40,
        'scan_limit' => 60,
        'override_prefix' => 'translation.',

        /*
         * Models/content tables that use field_locale columns. Example:
         *
         * 'tables' => [
         *     'posts' => [
         *         'label' => ['ar' => 'مقال', 'en' => 'Post'],
         *         'edit' => '/admin/posts/{id}/edit',
         *         'name' => ['title'],
         *     ],
         * ],
         */
        'tables' => [],

        // Enable this only when the host application has a key/value settings table.
        'content_settings' => [
            'enabled' => false,
            'table' => 'settings',
            'key_column' => 'key',
            'value_column' => 'value',
            'group_column' => 'group',
        ],

        'settings_edit' => ['*' => '/admin/settings'],
        'lang_edit' => '/ai-translator#file-translations',
    ],

    'route_prefix' => 'ai-translator',
    'route_name' => 'ai-translator.',

    // Change auth to auth:admin, add can:..., or replace this list for your application.
    'middleware' => ['web', 'auth'],

    // Optional Laravel Gate ability checked in addition to middleware. Example: manage-translations.
    'gate' => null,
];
