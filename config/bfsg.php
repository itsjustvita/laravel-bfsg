<?php

return [
    /*
    |--------------------------------------------------------------------------
    | BFSG Compliance Level
    |--------------------------------------------------------------------------
    |
    | Defines the WCAG level: 'A', 'AA', or 'AAA'
    | Default is 'AA' as required by BFSG
    |
    */
    'compliance_level' => env('BFSG_LEVEL', 'AA'),
    
    /*
    |--------------------------------------------------------------------------
    | Automatic Corrections
    |--------------------------------------------------------------------------
    |
    | When enabled, simple issues will be automatically fixed
    |
    */
    'auto_fix' => env('BFSG_AUTO_FIX', false),
    
    /*
    |--------------------------------------------------------------------------
    | Active Checks
    |--------------------------------------------------------------------------
    |
    | Defines which checks should be performed
    |
    */
    'checks' => [
        'images' => true,      // Alt text validation
        'forms' => true,       // Form label checking
        'headings' => true,    // Heading hierarchy
        'contrast' => true,    // Color contrast ratios
        'aria' => true,        // ARIA attributes
        'links' => true,       // Link accessibility
        'keyboard' => true,    // Keyboard navigation
        'language' => true,    // Language attributes (BFSG §3 requirement)
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Reporting
    |--------------------------------------------------------------------------
    */
    'reporting' => [
        'enabled' => env('BFSG_REPORTING', true),
        'email' => env('BFSG_REPORT_EMAIL', null),
        'save_to_database' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware Configuration
    |--------------------------------------------------------------------------
    */
    'middleware' => [
        'enabled' => env('BFSG_MIDDLEWARE_ENABLED', false),
        'log_violations' => true,
        'ignored_paths' => [
            'admin/*',
            'api/*',
            '_debugbar/*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Configuration
    |--------------------------------------------------------------------------
    |
    | Configure default authentication settings for the command line tool
    |
    */
    'authentication' => [
        'default_login_url' => '/login',
        'sanctum_enabled' => false,
        'timeout' => 30,
    ],
];