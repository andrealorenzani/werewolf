<?php
declare(strict_types=1);

// App shell. The home page ships its content as static HTML (crawlers, no-JS); js/main.js replaces it.
// Game rooms / join / create screens are private: same shell, but noindex (see Wm\Seo::head).
require __DIR__ . '/../src/bootstrap.php';

use Wm\Games;
use Wm\Seo;

$private = isset($_GET['game']) || isset($_GET['join']) || isset($_GET['create']);
if ($private) {
    header('X-Robots-Tag: noindex, nofollow');
}
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$faq = [
    ['Are Werewolf, Mafia & Impostor free to play?', 'Yes. It is free, needs no account and no app: open the page in any browser, create a game and share the code.'],
    ['How many players do I need?', 'From 4 to 30 players. The host can start as soon as four players have joined, and latecomers can still join while seats remain.'],
    ['Do I need a moderator?', 'No. The server is the moderator: it deals the secret cards, runs the night actions, counts the votes and announces the result.'],
    ['Can we play in the same room or online?', 'Both. Everyone uses their own phone, whether you sit around the same table or talk over a video call.'],
    ['What is Impostor?', 'A quick party game of similar questions: everyone secretly answers the same question except one player, the Impostor, who gets a different one and has to blend in. Answers are read out, everybody votes for the odd one out, and the Impostor scores if nobody catches it.'],
    ['What is the difference between Werewolf and Mafia?', 'The rules are the same; only the theme and the characters change. Werewolves hide in a village, the Mafia hides in a town.'],
];
$jsonLd = [[
    '@context' => 'https://schema.org', '@type' => 'FAQPage',
    'mainEntity' => array_map(static fn(array $q) => [
        '@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]],
    ], $faq),
]];
?>
<!doctype html>
<html lang="en">
<head>
<?= Seo::head($private ? null : '/', $private ? [] : $jsonLd) ?>
<link rel="stylesheet" href="css/app.css">
</head>
<body>
  <div id="app">
<?php if (!$private): ?>
    <div class="stack">
      <header>
        <h1>Play Werewolf, Mafia &amp; Impostor online, free</h1>
        <p>Werewolf and Mafia are party games of bluffing and deduction: a hidden minority tries to eliminate the majority, and everyone argues about who to trust. Impostor is a fast question game where one player bluffs with a different question. Here you play them all in the browser, on your phone, with friends. Create a game, share the code or QR link, and the server acts as the moderator.</p>
      </header>
      <section class="cards">
<?php foreach (Games::publicList() as $g): ?>
        <a class="panel game-card" href="?create=<?= $h($g['id']) ?>">
          <div class="big"><?= $h($g['icon']) ?></div><h2>Play <?= $h($g['title']) ?></h2>
          <p class="muted"><?= $h($g['tagline']) ?></p>
<?php if ($g['engine'] === 'classic'): ?>
          <span class="team-good"><?= $h($g['teams']['good']['name']) ?></span> vs <span class="team-evil"><?= $h($g['teams']['evil']['name']) ?></span>
<?php else: ?>
          <span class="team-evil">One Impostor</span> vs <span class="team-good">everyone else</span>
<?php endif; ?>
        </a>
<?php endforeach; ?>
      </section>
      <section class="panel stack">
        <h2>How it works</h2>
        <ol>
          <li>Pick Werewolf, Mafia or Impostor and set the game up: characters for Werewolf and Mafia, turns and questions for Impostor.</li>
          <li>Share the game code, link or QR with 3 or more friends.</li>
          <li>Werewolf and Mafia: everyone gets a secret character card. At night special characters act in secret; by day the group discusses and votes someone out. Impostor: everyone answers a question, one player secretly got a different one, and the group votes for the odd one out.</li>
          <li>Werewolf and Mafia end when the evil team is gone or outnumbers everyone else; Impostor ends after the chosen number of turns and the highest score wins.</li>
        </ol>
        <p>Read the full <a href="how-to-play">rules and character guide</a> before your first game.</p>
      </section>
      <section class="panel stack">
        <h2>Frequently asked questions</h2>
<?php foreach ($faq as $q): ?>
        <h3><?= $h($q[0]) ?></h3><p><?= $h($q[1]) ?></p>
<?php endforeach; ?>
      </section>
      <p class="muted">Last updated <?= $h(Seo::PAGES['/']['updated']) ?>.</p>
    </div>
<?php endif; ?>
  </div>
  <div id="toast" role="status" aria-live="polite"></div>
  <script src="js/vendor/qrcode.js"></script>
  <script type="module" src="js/main.js"></script>
</body>
</html>
