<?php

/*
 * Merge this into config/ai-translator.php when the host application uses
 * the same database schema as the original True Ventures project.
 */
return [
    'languages' => [
        'default' => 'ar',
        'fallback' => 'en',
        'supported' => [
            'ar' => ['name' => 'Arabic', 'native' => 'العربية', 'direction' => 'rtl', 'flag' => '🇸🇦'],
            'en' => ['name' => 'English', 'native' => 'English', 'direction' => 'ltr', 'flag' => '🇬🇧'],
        ],
    ],
    'live' => [
        'limit' => 40,
        'scan_limit' => 60,
        'override_prefix' => 'translation.',
        'tables' => [
            'services' => ['label' => ['ar' => 'خدمة', 'en' => 'Service'], 'edit' => '/admin/services/{id}/edit', 'name' => ['title']],
            'solutions' => ['label' => ['ar' => 'حل', 'en' => 'Solution'], 'edit' => '/admin/solutions/{id}/edit', 'name' => ['title']],
            'works' => ['label' => ['ar' => 'عمل', 'en' => 'Work'], 'edit' => '/admin/works/{id}/edit', 'name' => ['title']],
            'posts' => ['label' => ['ar' => 'مقال', 'en' => 'Post'], 'edit' => '/admin/posts/{id}/edit', 'name' => ['title']],
            'pages' => ['label' => ['ar' => 'صفحة', 'en' => 'Page'], 'edit' => '/admin/pages/{id}/edit', 'name' => ['title']],
            'branches' => ['label' => ['ar' => 'فرع', 'en' => 'Branch'], 'edit' => '/admin/branches/{id}/edit', 'name' => ['name']],
            'testimonials' => ['label' => ['ar' => 'رأي عميل', 'en' => 'Testimonial'], 'edit' => '/admin/testimonials/{id}/edit', 'name' => ['client_name']],
            'seo_metas' => ['label' => ['ar' => 'SEO', 'en' => 'SEO'], 'edit' => '/admin/seo', 'name' => ['title']],
        ],
        'content_settings' => [
            'enabled' => true,
            'table' => 'settings',
            'key_column' => 'key',
            'value_column' => 'value',
            'group_column' => 'group',
        ],
        'settings_edit' => ['home_' => '/admin/sections', '*' => '/admin/settings'],
        'lang_edit' => '/ai-translator#file-translations',
    ],
];
