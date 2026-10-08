<?php
declare(strict_types=1);

// Run with: php tests/run.php [filter]
require __DIR__ . '/bootstrap.php';
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}

$filter = $argv[1] ?? '';
$failed = 0;
$ran = 0;
foreach (T::$tests as $name => $fn) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }
    $ran++;
    try {
        $fn();
        echo "  ok   $name\n";
    } catch (\Throwable $e) {
        $failed++;
        echo "  FAIL $name\n       " . get_class($e) . ': ' . $e->getMessage() . "\n       at " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}
echo "\n$ran tests, " . T::$assertions . " assertions, $failed failed\n";
exit($failed ? 1 : 0);
