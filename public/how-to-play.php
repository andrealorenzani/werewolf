<?php
declare(strict_types=1);

// Static rules / character guide: the main indexable content page. Roles come from src/games/*.php so they never drift.
require __DIR__ . '/../src/bootstrap.php';

use Wm\Games;
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
    <p>Werewolf and Mafia are social deduction games. A small hidden team (the werewolves or the Mafia) secretly eliminates players at night, while the informed-but-powerless majority tries to find them by talking and voting during the day. The server deals the cards and moderates, so nobody has to narrate.</p>

    <h2>Game flow</h2>
    <ol>
      <li><strong>Day 0:</strong> everyone talks; nobody has information yet and nobody is eliminated.</li>
      <li><strong>Night:</strong> characters with powers act in secret; the evil team picks a victim.</li>
      <li><strong>Dawn:</strong> deaths are announced.</li>
      <li><strong>Discussion and vote:</strong> the living debate, then vote. The player with the most votes is eliminated; ties eliminate nobody.</li>
      <li>Night falls again, until one team wins.</li>
    </ol>

    <h2>How to win</h2>
    <p>The good team wins when no evil player is left alive. The evil team wins when living evil players are at least as many as living good players.</p>

    <h2>Setup</h2>
    <p>Games need 4 to 30 players. The host chooses how many of each character to include, and can start as soon as four players have joined. Late players can still join while seats remain. Timers are optional.</p>

<?php foreach (Games::publicList() as $g): ?>
    <section id="<?= $h($g['id']) ?>">
      <h2><?= $h($g['icon']) ?> <?= $h($g['title']) ?> characters</h2>
      <p><?= $h($g['tagline']) ?> <strong><?= $h($g['teams']['good']['name']) ?>:</strong> <?= $h($g['teams']['good']['goal']) ?> <strong><?= $h($g['teams']['evil']['name']) ?>:</strong> <?= $h($g['teams']['evil']['goal']) ?></p>
      <ul>
<?php foreach ($g['roles'] as $r): ?>
        <li><strong><?= $h($r['icon']) ?> <?= $h($r['name']) ?></strong> (<?= $r['team'] === 'evil' ? 'evil' : 'good' ?>): <?= $h($r['desc']) ?></li>
<?php endforeach; ?>
      </ul>
      <p><a href="?create=<?= $h($g['id']) ?>">Start a <?= $h($g['title']) ?> game</a></p>
    </section>
<?php endforeach; ?>

    <p class="muted">Last updated <?= $h(Seo::PAGES['/how-to-play']['updated']) ?>. <a href="./">Back to the games</a>.</p>
  </main>
</body>
</html>
