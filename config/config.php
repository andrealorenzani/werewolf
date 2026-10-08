<?php
declare(strict_types=1);

// Application configuration. Override per server in config/config.local.php (git-ignored), which
// returns an array with the same keys; or set the environment variable WM_DATA_DIR.
$config = [
    // Where game files are stored. Must be writable by PHP and NOT served over HTTP.
    'data_dir' => getenv('WM_DATA_DIR') ?: dirname(__DIR__) . '/data',
];

if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace($config, require __DIR__ . '/config.local.php');
}

return $config;
