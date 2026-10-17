<?php
declare(strict_types=1);

// Static rules / character guide: the main indexable content page. Roles come from src/games/*.php so they never drift.
require __DIR__ . '/../src/bootstrap.php';

use Wm\Seo;

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$crumbs = [[
    '@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => Seo::url('/')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'How to play', 'item' => Seo::url('/how-to-play')],
    ],
]];
?>
<!doctype html>
<html lang="en">
<head>
<?= Seo::head('/how-to-play', $crumbs) ?>
<link rel="stylesheet" href="css/app.css">
</head>
<body>
  <main class="stack" style="max-width:760px;margin:0 auto;padding:16px">
    <nav><a href="./">Werewolf &amp; Mafia</a> › How to play</nav>
    <h1>How to play Werewolf &amp; Mafia</h1>
<?= Wm\Rules::html() ?>

    <p class="muted">Last updated <?= $h(Seo::PAGES['/how-to-play']['updated']) ?>. <a href="./">Back to the games</a>.</p>
  </main>
</body>
</html>
