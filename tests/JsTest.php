<?php
declare(strict_types=1);

T::test('js: every client script passes node --check', function () {
    $node = trim((string)@shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        return; // node not available: skip
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../public/js', FilesystemIterator::SKIP_DOTS));
    $n = 0;
    foreach ($it as $f) {
        if ($f->getExtension() !== 'js') {
            continue;
        }
        $n++;
        exec('node --check ' . escapeshellarg($f->getPathname()) . ' 2>&1', $out, $code);
        T::same(0, $code, 'syntax error in ' . $f->getPathname() . ': ' . implode("\n", $out));
        $out = [];
    }
    T::true($n > 0, 'js files found');
});
