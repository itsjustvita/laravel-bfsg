<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Compliance level: 'AA' (BFSG requirement) or 'AAA' (stricter contrast)
    |--------------------------------------------------------------------------
    */
    'compliance_level' => env('BFSG_LEVEL', 'AA'),

    /*
    |--------------------------------------------------------------------------
    | Locale for violation messages and reports (null = app.locale). en and de ship with the package.
    |--------------------------------------------------------------------------
    */
    'locale' => env('BFSG_LOCALE'),

    /*
    |--------------------------------------------------------------------------
    | Active analyzers (registry key => enabled)
    |--------------------------------------------------------------------------
    */
    'checks' => [
        'images' => true,          // Alt text (WCAG 1.1.1)
        'forms' => true,           // Form labels (4.1.2, 1.3.1)
        'headings' => true,        // Heading hierarchy (1.3.1, 2.4.6)
        'contrast' => true,        // Colour contrast (1.4.3)
        'aria' => true,            // ARIA roles and states (4.1.2)
        'links' => true,           // Link purpose (2.4.4)
        'keyboard' => true,        // Keyboard access (2.1.1, 2.4.1, 2.4.3)
        'language' => true,        // Language of page and parts (3.1.1, 3.1.2)
        'tables' => true,          // Table structure (1.3.1)
        'media' => true,           // Video/audio alternatives (1.2.x, 1.4.2)
        'semantic' => true,        // Landmarks and structure (1.3.1, 2.4.1)
        'page_title' => true,      // Page title (2.4.2)
        'input_purpose' => true,   // Input purpose / autocomplete (1.3.5)
        'focus' => true,           // Focus visibility (2.4.7)
        'error_handling' => true,  // Error identification (3.3.1)
        'status_messages' => true, // Status messages (4.1.3)
    ],

    /*
    |--------------------------------------------------------------------------
    | CSS selectors removed from the DOM before analysis (third-party widgets etc.)
    |--------------------------------------------------------------------------
    */
    'ignored_selectors' => [
        '#chatbase-bubble-button',
        '#chatbase-bubble-window',
        '[data-chatbase]',
        'script[src*="chatbase"]',
        'iframe[src*="chatbase"]',
    ],

    /*
    |--------------------------------------------------------------------------
    | Score weights: score = max(0, round(100 - sum(weight per finding)))
    |--------------------------------------------------------------------------
    */
    'scoring' => [
        'weights' => ['error' => 5, 'warning' => 2, 'notice' => 0.5],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fetching pages (bfsg:check, MCP): timeout in seconds, TLS verification, user agent, and inlining of
    | same-origin <link rel="stylesheet"> files so the contrast analyzer sees them
    |--------------------------------------------------------------------------
    */
    'fetch' => [
        'timeout' => env('BFSG_FETCH_TIMEOUT', 30),
        'verify_ssl' => env('BFSG_VERIFY_SSL', true),
        'user_agent' => 'laravel-bfsg/3.0 (+https://github.com/itsjustvita/laravel-bfsg)',
        'inline_stylesheets' => env('BFSG_INLINE_CSS', true),
        'max_stylesheets' => 5,
        'max_stylesheet_bytes' => 524288,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reporting / persistence
    |--------------------------------------------------------------------------
    */
    'reporting' => [
        'save_to_database' => env('BFSG_SAVE_TO_DB', false),
        'database' => [
            'connection' => env('BFSG_DB_CONNECTION'),
        ],
        'output_path' => storage_path('app/bfsg-reports'), // html/pdf reports without --output
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware (register with the `bfsg` alias; disabled by default)
    |--------------------------------------------------------------------------
    */
    'middleware' => [
        'enabled' => env('BFSG_MIDDLEWARE_ENABLED', false),
        'log_violations' => true,
        'ignored_paths' => [
            'admin/*',
            'api/*',
            '_debugbar/*',
            'livewire/*',
            'telescope/*',
            'horizon/*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication defaults for bfsg:check
    |--------------------------------------------------------------------------
    */
    'authentication' => [
        'default_login_url' => '/login',
    ],
];
