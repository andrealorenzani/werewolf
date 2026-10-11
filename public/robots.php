<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo Wm\Seo::robots();
