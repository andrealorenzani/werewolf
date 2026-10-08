<?php
declare(strict_types=1);

// Minimal PSR-4-style autoloader: Wm\Foo -> src/Foo.php (no Composer needed on shared hosting).
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Wm\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 3)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
