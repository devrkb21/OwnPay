<?php
declare(strict_types=1);

/**
 * System configuration settings.
 *
 * Defines directory paths, environmental settings, session lifecycles, rate limiting
 * parameters, security algorithms, and other baseline constants. Sensitive configurations
 * (e.g. database credentials) are derived from the local environment (.env) parameters.
 *
 * @return array<string, mixed>
 */

/**
 * @ I just realized open source isn't just a word, it's a responsibility.
 * @ It's not just about opening up the source code.
 * @ Fattain Naime
 */
return [
    // Identity parameters
    'name' => ($_ENV['APP_NAME'] ?? getenv('APP_NAME')) ?: 'OwnPay',
    'version' => \OwnPay\Support\Version::CURRENT,
    'codename'=> 'Genesis',

    // Environment settings
    'env'   => ($_ENV['APP_ENV'] ?? getenv('APP_ENV')) ?: 'production',
    'debug' => filter_var(($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG')) ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'url'   => ($_ENV['APP_URL'] ?? getenv('APP_URL')) ?: '',

    // Documentation can be served remotely and falls back to the bundled manifest.
    'docs_manifest_url' => ($_ENV['DOCS_MANIFEST_URL'] ?? getenv('DOCS_MANIFEST_URL')) ?: 'https://ownpay.org/docs/docs-manifest.json',
    'docs_manifest_timeout' => is_numeric($_ENV['DOCS_MANIFEST_TIMEOUT'] ?? getenv('DOCS_MANIFEST_TIMEOUT'))
        ? max(1, (int) ($_ENV['DOCS_MANIFEST_TIMEOUT'] ?? getenv('DOCS_MANIFEST_TIMEOUT')))
        : 3,
    'docs_manifest_cache_ttl' => is_numeric($_ENV['DOCS_MANIFEST_CACHE_TTL'] ?? getenv('DOCS_MANIFEST_CACHE_TTL'))
        ? max(60, (int) ($_ENV['DOCS_MANIFEST_CACHE_TTL'] ?? getenv('DOCS_MANIFEST_CACHE_TTL')))
        : 86400,

    // System-wide timezone configuration
    'timezone' => ($_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE')) ?: 'Asia/Dhaka',

    // Relative system directory structures
    'paths' => [
        'root'      => dirname(__DIR__),
        'public'    => dirname(__DIR__) . '/public',
        'config'    => __DIR__,
        'src'       => dirname(__DIR__) . '/src',
        'templates' => dirname(__DIR__) . '/templates',
        'storage'   => dirname(__DIR__) . '/storage',
        'modules'   => dirname(__DIR__) . '/modules',
        'database'  => dirname(__DIR__) . '/database',
        'logs'      => dirname(__DIR__) . '/storage/logs',
        'cache'     => dirname(__DIR__) . '/storage/cache',
        'queue'     => dirname(__DIR__) . '/storage/queue',
        'sessions'  => dirname(__DIR__) . '/storage/sessions',
        'backups'   => dirname(__DIR__) . '/storage/backups',
        'temp'      => dirname(__DIR__) . '/storage/temp',
        'plugins'   => dirname(__DIR__) . '/storage/plugins',
        'docs_manifest' => dirname(__DIR__) . '/.github/scripts/docs-manifest.json',
    ],

    // Default route paths (fallback configurations)
    'default_login_path' => '/login',
    'default_admin_path' => '/admin',

    // Cookie session management lifecycle
    'session' => [
        'name'     => 'op_session',
        'lifetime' => 7200,     // 2 hours in seconds
        'secure'   => true,     // HTTPS-only cookie
        'httponly'  => true,
        'samesite'  => 'Lax',
    ],

    // Rate limiting parameter thresholds. also configurable is admin panel.
    'rate_limit' => [
        'api'   => ['max' => 60,  'window' => 60],   // 60 req/min per key
        'login' => ['max' => 10,   'window' => 300],   // 10 attempts per 5 min
        'global'=> ['max' => 120, 'window' => 60],    // 120 req/min per IP
    ],

    // Payment checkout settings
    'checkout' => [
        'timer_seconds' => 600,  // 10 minutes default
    ],

    // System updater configuration parameters
    'update' => [
        'check_url'    => 'https://update.ownpay.org/manifest.json',
        'night_window' => ['start' => '02:00', 'end' => '04:00'],
        'idle_minutes' => 15,
        'max_retries'  => 3,
    ],

    // Security policies
    'security' => [
        'password_algo' => PASSWORD_ARGON2ID,
        'encryption'    => 'aes-256-gcm',
        'csrf_rotation' => true,    // Rotate token on every request
    ],

    // Engine drivers
    'cache_driver' => ($_ENV['CACHE_DRIVER'] ?? getenv('CACHE_DRIVER')) ?: 'file',
    'queue_driver' => ($_ENV['QUEUE_DRIVER'] ?? getenv('QUEUE_DRIVER')) ?: 'file',
];
