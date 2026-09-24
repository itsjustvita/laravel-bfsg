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
        'images' => true,          // Text alternatives (WCAG 1.1.1)
        'forms' => true,           // Form labels and button names (4.1.2, 1.3.1, 3.3.2)
        'headings' => true,        // Heading hierarchy (1.3.1, 2.4.6)
        'contrast' => true,        // Colour contrast (1.4.3, 1.4.6)
        'aria' => true,            // ARIA roles, states and references (4.1.2, 1.3.1, 4.1.1)
        'links' => true,           // Link purpose (2.4.4, 4.1.2, 3.2.5)
        'keyboard' => true,        // Keyboard access (2.1.1, 2.4.1, 2.4.3, 4.1.2)
        'language' => true,        // Language of page and parts (3.1.1, 3.1.2)
        'tables' => true,          // Table structure (1.3.1)
        'media' => true,           // Video/audio alternatives (1.2.1, 1.2.2, 1.2.5, 1.4.2, 2.1.1, 2.2.2, 4.1.2)
        'semantic' => true,        // Landmarks and structure (1.3.1, 2.4.1, 4.1.2)
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
    | same-origin <link rel="stylesheet"> files so the contrast analyzer sees them.
    | Allowed-host lists (e.g. for MCP) name plain hosts without scheme or port, case-insensitive; IPv6 hosts
    | may be written with or without brackets ("::1", "[::1]") and a trailing dot is ignored ("example.com.").
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
    | MCP server (bfsg:mcp-server, needs laravel/mcp): hosts analyze_url / generate_report may fetch, and TLS
    |--------------------------------------------------------------------------
    | allowed_hosts: a list of hostnames governs every URL and every redirect hop (paths of this application are
    | checked against the host of app.url). null or [] = any public host: the host of every hop is resolved (A and
    | AAAA records; numeric forms like 0x7f000001 or 127.1 are parsed, not resolved) and every non-public address
    | is refused: loopback, private, link-local (incl. the cloud metadata address 169.254.169.254), CGNAT
    | 100.64/10, reserved, documentation and benchmarking ranges, unspecified, IPv6 unique/site-local, and IPv6
    | addresses embedding one of these (IPv4-mapped/-compatible, NAT64 64:ff9b::/96, 6to4 2002::/16) and local-use
    | NAT64 64:ff9b:1::/48. Hosts that do not resolve, and names that are not plain ASCII (letters, digits, dots,
    | hyphens; give IDNs in punycode), are refused. URLs of this application (the origin of app.url: scheme, host and port) are
    | exempt while they are rendered in-process; a remote page redirecting there is guarded like any other hop. Each request (redirect hops and stylesheets included) is pinned to the addresses that were checked
    | (CURLOPT_RESOLVE), so a DNS answer that changes after the check (rebinding) is never connected to; this needs
    | the curl extension, and without it such fetches fail. TLS verification comes only from verify_ssl; MCP
    | clients cannot switch it off.
    */
    'mcp' => [
        'allowed_hosts' => null,
        'verify_ssl' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware (register with the `bfsg` alias; disabled by default)
    |--------------------------------------------------------------------------
    */
    'middleware' => [
        'enabled' => env('BFSG_MIDDLEWARE_ENABLED', false),
        'log_violations' => true,
        'log_channel' => null, // null = the default log channel
        'ignored_paths' => [
            'admin/*',
            'api/*',
            '_debugbar/*',
            'livewire/*',
            'telescope/*',
            'horizon/*',
            // Laravel's password reset links carry the token in the path; the query string is never stored
            'reset-password/*',
            'password/reset/*',
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
