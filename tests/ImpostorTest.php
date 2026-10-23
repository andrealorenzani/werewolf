<?php
declare(strict_types=1);

use Wm\Config;
use Wm\Games;
use Wm\GameError;
use Wm\Impostor;
use Wm\ImpostorConfig;
use Wm\Player;
use Wm\Rooms;
use Wm\Store;

// ---------- helpers ----------
function impCmd(Impostor $g, Player $p, string $t, array $more = []): void
{
    $g->command($p->id, ['t' => $t] + $more);
}

/** Players that play (the God-mode host excluded). @return Player[] */
function impPlaying(Impostor $g): array
{
    return $g->playing();
}

function impImpostor(Impostor $g): Player
{
    return $g->player($g->turn['impostorId']);
}

function impAnswerAll(Impostor $g): void
{
    foreach (impPlaying($g) as $p) {
        impCmd($g, $p, 'answer', ['text' => "answer of {$p->name}"]);
    }
}

/** Answer everything and read all answers out: ends in the vote phase. */
function impToVote(Impostor $g): void
{
    impAnswerAll($g);
    while ($g->phase === 'reveal') {
        impCmd($g, $g->player($g->turn['order'][$g->turn['idx']]), 'done');
    }
}

/** Players vote by name: ['p1' => 'p2', 'p2' => null (abstain)] */
function impVotes(Impostor $g, array $map): void
{
    $by = array_column(array_map(fn(Player $p) => ['n' => $p->name, 'p' => $p], $g->players), 'p', 'n');
    foreach ($map as $voter => $target) {
        impCmd($g, $by[$voter], 'vote', ['target' => $target === null ? null : $by[$target]->id]);
    }
}

/** Play the vote to the end (voters not in $map abstain by timeout) and let the Impostor unveil. */
function impFinishTurn(Impostor $g, Clock $clock, array $map): array
{
    impToVote($g);
    impVotes($g, $map);
    if ($g->phase === 'vote') {
        $clock->ms += 100000;
        $g->tick();
    }
    T::same('unveil', $g->phase);
    impCmd($g, impImpostor($g), 'unveil');
    T::same('result', $g->phase);
    return end($g->history);
}

/** Name of the Impostor in a started game, and the names of all the other playing players. */
function impNames(Impostor $g): array
{
    $imp = impImpostor($g)->name;
    return [$imp, array_values(array_filter(array_map(fn(Player $p) => $p->name, impPlaying($g)), fn($n) => $n !== $imp))];
}

// ---------- config ----------
T::test('impostor config: defaults and ranges', function () {
    $def = Games::get('impostor');
    $c = ImpostorConfig::normalize($def, ['name' => '  Fun  ']);
    T::same('Fun', $c['name']);
    T::same(8, $c['total']);
    T::same(['hostPlays' => true, 'source' => 'pool', 'turns' => 3, 'voteSeconds' => 60, 'points' => 3], $c['settings']);
    $c = ImpostorConfig::normalize($def, ['name' => 'x', 'seats' => 30, 'settings' => ['hostPlays' => false, 'source' => 'players', 'turns' => 20, 'voteSeconds' => 5, 'points' => 10]]);
    T::same(30, $c['total']);
    T::same(false, $c['settings']['hostPlays']);
    T::same('players', $c['settings']['source']);
    T::same(30, $c['settings']['voteSeconds']); // clamped to the minimum
    T::same(90, ImpostorConfig::normalize($def, ['name' => 'x', 'settings' => ['voteSeconds' => 500]])['settings']['voteSeconds']);
    T::same(60, ImpostorConfig::normalize($def, ['name' => 'x', 'settings' => ['voteSeconds' => 0]])['settings']['voteSeconds']); // mandatory
    foreach ([['seats' => 3], ['seats' => 31], ['seats' => 'x'], ['settings' => ['turns' => 0]], ['settings' => ['turns' => 21]],
        ['settings' => ['points' => 0]], ['settings' => ['points' => 11]], ['settings' => ['source' => 'dice']], ['name' => '']] as $bad) {
        T::throws(fn() => ImpostorConfig::normalize($def, $bad + ['name' => 'x']), '/./');
    }
});

T::test('impostor pool: enough distinct pairs, each with two different questions', function () {
    $pairs = Games::get('impostor')['pairs'];
    T::true(count($pairs) >= 60 && count($pairs) >= ImpostorConfig::TURNS_MAX);
    T::same(count($pairs), count(array_unique(array_map(fn($p) => $p[0], $pairs))));
    foreach ($pairs as [$a, $b]) {
        T::true($a !== $b && $a !== '' && $b !== '');
    }
});

T::test('impostor: Games registry exposes the engine; classic games stay classic', function () {
    T::same('impostor', Games::engine('impostor'));
    T::same('classic', Games::engine('werewolf'));
    $by = array_column(Games::publicList(), null, 'id');
    T::same('impostor', $by['impostor']['engine']);
    T::true(!isset($by['impostor']['roles']) && !isset($by['impostor']['teams']) && !isset($by['impostor']['pairs']));
    T::same(30, $by['impostor']['limits']['seatsMax']);
    T::same('classic', $by['mafia']['engine']);
});

// ---------- lobby ----------
T::test('impostor lobby: needs 4 players, only the host starts, no join after start', function () {
    [$g, $ps] = startedImpostor([], 3, true);
    T::throws(fn() => impCmd($g, $ps[0], 'start'), '/At least 4/');
    $p3 = $g->addPlayer(['name' => 'p3']);
    T::throws(fn() => impCmd($g, $ps[1], 'start'), '/Only the host/');
    impCmd($g, $ps[0], 'start');
    T::same('answer', $g->phase);
    T::false($g->joinable());
    T::throws(fn() => $g->addPlayer(['name' => 'late']), '/already started/');
    T::throws(fn() => impCmd($g, $ps[0], 'start'), '/already started/');
});

T::test('impostor lobby: a non-playing host is not counted and cannot start with 3 players', function () {
    [$g, $ps] = startedImpostor(['hostPlays' => false], 3, true);
    T::same(4, count($g->players));
    T::same(3, $g->joinedCount());
    T::throws(fn() => impCmd($g, $ps[0], 'start'), '/At least 4/');
    $g->addPlayer(['name' => 'p4']);
    impCmd($g, $ps[0], 'start');
    T::same(4, count($g->playing()));
    T::false(in_array($ps[0]->id, array_map(fn($p) => $p->id, $g->playing()), true));
    T::true($g->turn['impostorId'] !== $ps[0]->id);
});

T::test('impostor lobby: seats are limited to playing players', function () {
    [$g, $ps] = startedImpostor(['hostPlays' => false], 4, true, null, 4);
    T::false($g->joinable());
    T::throws(fn() => $g->addPlayer(['name' => 'extra']), '/full/');
});

T::test('impostor lobby: host changes settings, others cannot; seats cannot drop below joined; host toggles playing', function () {
    [$g, $ps] = startedImpostor([], 5, true);
    $payload = ['name' => 'New', 'description' => 'd', 'seats' => 6, 'settings' => ['hostPlays' => true, 'source' => 'players', 'turns' => 5, 'voteSeconds' => 40, 'points' => 2]];
    T::throws(fn() => impCmd($g, $ps[1], 'settings', $payload), '/Only the host/');
    impCmd($g, $ps[0], 'settings', $payload);
    T::same('New', $g->name);
    T::same('players', $g->settings['source']);
    T::same(6, $g->total);
    T::throws(fn() => impCmd($g, $ps[0], 'settings', ['seats' => 4] + $payload), '/at least 5 seats/');
    T::throws(fn() => impCmd($g, $ps[0], 'settings', ['seats' => 99] + $payload), '/between/');
    // the host stops playing: 4 players remain, host becomes God
    impCmd($g, $ps[0], 'settings', ['settings' => ['hostPlays' => false] + $payload['settings']] + $payload);
    T::same(4, count($g->playing()));
    T::true($g->viewFor($ps[0]->id)['me']['god']);
    // and back
    impCmd($g, $ps[0], 'settings', $payload);
    T::same(5, count($g->playing()));
    // seats full with a non-playing host: playing again would need one more seat
    impCmd($g, $ps[0], 'settings', ['seats' => 5, 'settings' => ['hostPlays' => false] + $payload['settings']] + $payload);
    $g->addPlayer(['name' => 'extra']);
    T::throws(fn() => impCmd($g, $ps[0], 'settings', ['seats' => 5] + $payload), '/at least 6 seats/');
    // settings are not available after the start
    impCmd($g, $ps[0], 'settings', ['seats' => 6, 'settings' => ['hostPlays' => true] + $payload['settings']] + $payload);
    impCmd($g, $ps[0], 'settings', ['settings' => ['source' => 'pool'] + $payload['settings']] + $payload);
    impCmd($g, $ps[0], 'start');
    T::throws(fn() => impCmd($g, $ps[0], 'settings', $payload), '/before the game starts/');
});

T::test('impostor lobby: leave, kick, profile and unknown commands', function () {
    [$g, $ps] = startedImpostor([], 5, true);
    impCmd($g, $ps[2], 'leave');
    T::same(4, count($g->players));
    T::throws(fn() => impCmd($g, $ps[1], 'kick', ['playerId' => $ps[3]->id]), '/Only the host/');
    impCmd($g, $ps[0], 'kick', ['playerId' => $ps[3]->id]);
    T::same(3, count($g->players));
    T::throws(fn() => impCmd($g, $ps[0], 'leave'), '/host cannot leave/');
    T::throws(fn() => impCmd($g, $ps[0], 'kick', ['playerId' => $ps[0]->id]), '/host cannot leave/');
    T::throws(fn() => impCmd($g, $ps[1], 'kick', ['playerId' => 'nobody']), '/Only the host/');
    T::throws(fn() => impCmd($g, $ps[0], 'kick', ['playerId' => 'nobody']), '/Unknown player/');
    impCmd($g, $ps[1], 'profile', ['name' => 'Renamed']);
    T::same('Renamed', $ps[1]->name);
    T::throws(fn() => impCmd($g, $ps[4], 'profile', ['name' => 'renamed']), '/taken/');
    T::throws(fn() => impCmd($g, $ps[0], 'chat', ['text' => 'hi']), '/Unknown command/');
    T::throws(fn() => $g->command('nobody', ['t' => 'start']), '/Unknown player/');
});

// ---------- pool mode ----------
T::test('impostor pool: one impostor per turn, questions assigned, no repeated pair, turn count, end', function () {
    [$g, $ps, $clock] = startedImpostor(['turns' => 4], 5);
    $pairs = Games::get('impostor')['pairs'];
    T::same(4, $g->turnsTotal);
    T::same(4, count(array_unique($g->deck)));
    $seen = [];
    for ($n = 1; $n <= 4; $n++) {
        T::same('answer', $g->phase);
        T::same($n, $g->round);
        $imp = impImpostor($g);
        $views = array_map(fn(Player $p) => $g->viewFor($p->id)['me'], $g->playing());
        T::same(1, count(array_filter($views, fn($m) => $m['impostor'])));
        $normal = $g->turn['normal'];
        $seen[] = $normal;
        foreach ($views as $m) {
            T::same($m['impostor'] ? $g->turn['impostor'] : $normal, $m['question']);
        }
        T::true(in_array([$g->turn['normal'], $g->turn['impostor']], $pairs, true));
        impFinishTurn($g, $clock, []);
        impCmd($g, $ps[$n % 5], 'next');
    }
    T::same('ended', $g->phase);
    T::same(4, count(array_unique($seen)));
    T::same(4, count($g->history));
    T::throws(fn() => impCmd($g, $ps[0], 'next'), '/not over/');
});

T::test('impostor: the impostor changes between turns with a varying rng', function () {
    $r = 0;
    [$g, $ps, $clock] = startedImpostor(['turns' => 10], 6, false, function () use (&$r) { $r = fmod($r + 0.37, 1.0); return $r; });
    $ids = [];
    for ($n = 1; $n <= 10; $n++) {
        $ids[] = $g->turn['impostorId'];
        impFinishTurn($g, $clock, []);
        impCmd($g, $ps[0], 'next');
    }
    T::true(count(array_unique($ids)) > 1);
});

// ---------- players write the questions ----------
function impWritten(array $settings = [], int $n = 5): array
{
    return startedImpostor(['source' => 'players'] + $settings, $n);
}

function impWrite(Impostor $g, Player $p, string $a = 'What is your favourite fruit?', string $b = 'What is your favourite vegetable?'): void
{
    impCmd($g, $p, 'write', ['normal' => $a . " ({$p->name})", 'impostor' => $b . " ({$p->name})"]);
    impCmd($g, $p, 'confirm');
}

T::test('impostor players mode: writing, confirm, validation, auto start when everyone confirmed', function () {
    [$g, $ps] = impWritten();
    T::same('writing', $g->phase);
    T::throws(fn() => impCmd($g, $ps[1], 'answer', ['text' => 'x']), '/not being collected/');
    T::throws(fn() => impCmd($g, $ps[1], 'confirm'), '/Write both/');
    T::throws(fn() => impCmd($g, $ps[1], 'write', ['normal' => 'abc', 'impostor' => 'What is this?']), '/5 to 140/');
    T::throws(fn() => impCmd($g, $ps[1], 'write', ['normal' => 'What is this?', 'impostor' => str_repeat('x', 141)]), '/5 to 140/');
    T::throws(fn() => impCmd($g, $ps[1], 'write', ['normal' => 'What is this?', 'impostor' => ' what is  this? ']), '/different/');
    impCmd($g, $ps[1], 'write', ['normal' => 'What is this?', 'impostor' => 'What is that?']);
    impCmd($g, $ps[1], 'write', ['normal' => 'What is these?', 'impostor' => 'What is that?']); // editable until confirmed
    T::same('What is these?', $g->pairs[$ps[1]->id]['normal']);
    impCmd($g, $ps[1], 'confirm');
    T::throws(fn() => impCmd($g, $ps[1], 'write', ['normal' => 'What is this?', 'impostor' => 'What is that?']), '/already confirmed/');
    foreach ([0, 2, 3] as $i) {
        impWrite($g, $ps[$i]);
    }
    T::same('writing', $g->phase);
    impWrite($g, $ps[4]);
    T::same('answer', $g->phase);
    T::same(5, $g->turnsTotal);
    T::throws(fn() => impCmd($g, $ps[1], 'write', ['normal' => 'What is this?', 'impostor' => 'What is that?']), '/before the game starts/');
});

T::test('impostor players mode: forcestart drops unconfirmed pairs, needs a confirmed pair, turns = confirmed pairs', function () {
    [$g, $ps] = impWritten();
    T::throws(fn() => impCmd($g, $ps[0], 'forcestart'), '/Nobody has confirmed/');
    impWrite($g, $ps[1]);
    impWrite($g, $ps[2]);
    impCmd($g, $ps[3], 'write', ['normal' => 'Draft question one?', 'impostor' => 'Draft question two?']);
    T::throws(fn() => impCmd($g, $ps[1], 'forcestart'), '/Only the host/');
    impCmd($g, $ps[0], 'forcestart');
    T::same('answer', $g->phase);
    T::same(2, $g->turnsTotal);
    T::same(2, count($g->pairs));
    T::throws(fn() => impCmd($g, $ps[0], 'forcestart'), '/before the game starts/');
});

T::test('impostor players mode: the author is never the impostor and always sees the normal question', function () {
    for ($i = 0; $i < 10; $i++) {
        [$g, $ps, $clock] = startedImpostor(['source' => 'players'], 4, false, fn() => $i / 10);
        foreach ($ps as $p) {
            impWrite($g, $p);
        }
        for ($n = 1; $n <= 4; $n++) {
            $author = $g->turn['author'];
            T::true($g->turn['impostorId'] !== $author, 'author is not the impostor');
            T::same($g->turn['normal'], $g->viewFor($author)['me']['question']);
            impFinishTurn($g, $clock, []);
            impCmd($g, $ps[0], 'next');
        }
        T::same('ended', $g->phase);
    }
});

T::test('impostor players mode: pair drafts are private; others only see who has confirmed', function () {
    [$g, $ps] = impWritten();
    impCmd($g, $ps[1], 'write', ['normal' => 'Secret normal question', 'impostor' => 'Secret impostor question']);
    impWrite($g, $ps[2]);
    $mine = $g->viewFor($ps[1]->id);
    T::same('Secret normal question', $mine['me']['pair']['normal']);
    T::false($mine['me']['pair']['confirmed']);
    $other = $g->viewFor($ps[3]->id);
    T::true(!isset($other['me']['pair']));
    T::false(str_contains(json_encode($other), 'Secret'));
    T::false(str_contains(json_encode($other), 'favourite fruit'));
    $conf = array_column($other['players'], 'confirmed', 'id');
    T::same(true, $conf[$ps[2]->id]);
    T::same(false, $conf[$ps[1]->id]);
    T::true(!isset($other['god']));
});

T::test('impostor players mode: a God-mode host may write pairs and sees all pairs, even future turns', function () {
    [$g, $ps] = startedImpostor(['source' => 'players', 'hostPlays' => false], 4);
    T::same('writing', $g->phase);
    impWrite($g, $ps[1]);
    impCmd($g, $ps[0], 'write', ['normal' => 'Host question one?', 'impostor' => 'Host question two?']);
    $god = $g->viewFor($ps[0]->id)['god'];
    T::same(2, count($god['pairs']));
    T::same('writing', $g->phase);
    impCmd($g, $ps[0], 'confirm');
    foreach ([2, 3] as $i) {
        impWrite($g, $ps[$i]);
    }
    impWrite($g, $ps[4]);
    T::same('answer', $g->phase);
    T::same(5, $g->turnsTotal); // four players and the host
    T::same(5, count($g->viewFor($ps[0]->id)['god']['pairs']));
    $author = $g->turn['author'];
    // a host-authored turn can pick any playing player
    T::true($g->turn['impostorId'] !== $ps[0]->id);
});

T::test('impostor players mode: a God-mode host never blocks the auto start; an unconfirmed host draft is dropped', function () {
    [$g, $ps] = startedImpostor(['source' => 'players', 'hostPlays' => false], 4);
    impCmd($g, $ps[0], 'write', ['normal' => 'Host question one?', 'impostor' => 'Host question two?']);
    foreach ([1, 2, 3] as $i) {
        impWrite($g, $ps[$i]);
        T::same('writing', $g->phase);
    }
    impWrite($g, $ps[4]);
    T::same('answer', $g->phase);
    T::same(4, $g->turnsTotal);
    T::true(!isset($g->pairs[$ps[0]->id]));
});

T::test('impostor players mode: nobody confirmed blocks forcestart', function () {
    [$g, $ps] = startedImpostor(['source' => 'players'], 4);
    T::throws(fn() => impCmd($g, $ps[0], 'forcestart'), '/Nobody has confirmed/');
    T::same('writing', $g->phase);
});

// ---------- answer ----------
T::test('impostor answer: validation, editing, host cannot answer when not playing, everyone answered goes to reveal', function () {
    [$g, $ps] = startedImpostor(['hostPlays' => false], 4);
    T::throws(fn() => impCmd($g, $ps[1], 'answer', ['text' => '   ']), '/1 to 100/');
    T::throws(fn() => impCmd($g, $ps[1], 'answer', ['text' => str_repeat('a', 101)]), '/1 to 100/');
    T::throws(fn() => impCmd($g, $ps[0], 'answer', ['text' => 'God']), '/not playing/');
    impCmd($g, $ps[1], 'answer', ['text' => 'first']);
    impCmd($g, $ps[1], 'answer', ['text' => '  second  ']);
    T::same('second', $g->viewFor($ps[1]->id)['me']['answer']);
    T::same(null, $g->viewFor($ps[2]->id)['me']['answer']);
    foreach ([2, 3] as $i) {
        impCmd($g, $ps[$i], 'answer', ['text' => "a$i"]);
    }
    T::same('answer', $g->phase);
    $row = array_column($g->viewFor($ps[2]->id)['players'], null, 'id');
    T::same(true, $row[$ps[1]->id]['answered']);
    T::same(false, $row[$ps[4]->id]['answered']);
    impCmd($g, $ps[4], 'answer', ['text' => 'a4']);
    T::same('reveal', $g->phase);
    T::throws(fn() => impCmd($g, $ps[1], 'answer', ['text' => 'late']), '/not being collected/');
});

T::test('impostor answer: host skip and the safety timeout fill (no answer)', function () {
    [$g, $ps, $clock] = startedImpostor([], 5);
    impCmd($g, $ps[1], 'answer', ['text' => 'mine']);
    T::throws(fn() => impCmd($g, $ps[2], 'skip'), '/Only the host/');
    impCmd($g, $ps[0], 'skip');
    T::same('reveal', $g->phase);
    T::same(Impostor::NO_ANSWER, $g->turn['answers'][$ps[2]->id]);
    T::same('mine', $g->turn['answers'][$ps[1]->id]);
    [$g, $ps, $clock] = startedImpostor([], 5);
    impCmd($g, $ps[1], 'answer', ['text' => 'mine']);
    $clock->ms += Impostor::ANSWER_MS - 1;
    $g->tick();
    T::same('answer', $g->phase);
    $clock->ms += 2;
    $g->tick();
    T::same('reveal', $g->phase);
    T::same(5, count($g->turn['answers']));
});

// ---------- reveal ----------
T::test('impostor reveal: random fixed order, only the speaker presses done, answers appear one by one', function () {
    [$g, $ps] = startedImpostor([], 5);
    impAnswerAll($g);
    T::same('reveal', $g->phase);
    $order = $g->turn['order'];
    T::same(5, count($order));
    $first = $g->player($order[0]);
    $other = $g->player($order[1]);
    $v = $g->viewFor($other->id);
    T::same($order[0], $v['turn']['speaker']);
    T::same([$order[0]], array_column($v['turn']['answers'], 'id'));
    T::throws(fn() => impCmd($g, $other, 'done'), '/not your turn/');
    impCmd($g, $first, 'done');
    $v = $g->viewFor($ps[0]->id);
    T::same([$order[0], $order[1]], array_column($v['turn']['answers'], 'id'));
    T::same($order[1], $v['turn']['speaker']);
    // nobody else's later answer is in the view yet
    $later = $g->player($order[4]);
    T::false(str_contains(json_encode($g->viewFor($ps[1]->id)['turn']), "answer of {$later->name}"));
    for ($i = 1; $i < 5; $i++) {
        impCmd($g, $g->player($order[$i]), 'done');
    }
    T::same('vote', $g->phase);
    T::same(5, count($g->viewFor($ps[1]->id)['turn']['answers']));
});

T::test('impostor reveal: host skips a speaker, the speaker timeout moves on, the host can open the vote early', function () {
    [$g, $ps, $clock] = startedImpostor([], 5);
    impAnswerAll($g);
    impCmd($g, $ps[0], 'skip');
    T::same(1, $g->turn['idx']);
    $clock->ms += Impostor::SPEAK_MS + 1;
    $g->tick();
    T::same(2, $g->turn['idx']);
    T::same('reveal', $g->phase);
    T::throws(fn() => impCmd($g, $ps[1], 'startvote'), '/Only the host/');
    impCmd($g, $ps[0], 'startvote');
    T::same('vote', $g->phase);
    T::same(5, count($g->viewFor($ps[2]->id)['turn']['answers'])); // all answers are visible when voting
    T::throws(fn() => impCmd($g, $ps[0], 'startvote'), '/while answers/');
    // the vote has the host's configured length
    T::same($clock->ms + 60000, $g->deadline);
});

// ---------- vote ----------
T::test('impostor vote: no self vote, only players, changeable, abstain counts, everyone voted goes to unveil', function () {
    [$g, $ps] = startedImpostor(['hostPlays' => false], 4);
    impToVote($g);
    T::same('vote', $g->phase);
    T::throws(fn() => impCmd($g, $ps[1], 'vote', ['target' => $ps[1]->id]), '/yourself/');
    T::throws(fn() => impCmd($g, $ps[1], 'vote', ['target' => $ps[0]->id]), '/not in the game/');
    T::throws(fn() => impCmd($g, $ps[1], 'vote', ['target' => 'nobody']), '/not in the game/');
    T::throws(fn() => impCmd($g, $ps[0], 'vote', ['target' => $ps[1]->id]), '/not playing/');
    impCmd($g, $ps[1], 'vote', ['target' => $ps[2]->id]);
    impCmd($g, $ps[1], 'vote', ['target' => $ps[3]->id]);
    $me = $g->viewFor($ps[1]->id)['me'];
    T::same(true, $me['voted']);
    T::same($ps[3]->id, $me['vote']);
    impCmd($g, $ps[2], 'vote', ['target' => null]);
    impCmd($g, $ps[3], 'vote', ['target' => $ps[1]->id]);
    T::same('vote', $g->phase);
    impCmd($g, $ps[4], 'vote', ['target' => $ps[1]->id]);
    T::same('unveil', $g->phase);
    T::throws(fn() => impCmd($g, $ps[1], 'vote', ['target' => $ps[2]->id]), '/not open/');
});

T::test('impostor vote: deadline ends the vote with abstentions; the host can end it; vote length is mandatory', function () {
    [$g, $ps, $clock] = startedImpostor(['voteSeconds' => 30], 5);
    impToVote($g);
    T::same($clock->ms + 30000, $g->deadline);
    impVotes($g, ['p1' => 'p2']);
    $clock->ms += 30001;
    $g->tick();
    T::same('unveil', $g->phase);
    [$g, $ps, $clock] = startedImpostor([], 5);
    impToVote($g);
    impCmd($g, $ps[0], 'skip');
    T::same('unveil', $g->phase);
});

T::test('impostor vote: votes are hidden from everybody but the voter until the result', function () {
    [$g, $ps] = startedImpostor([], 5);
    impToVote($g);
    impVotes($g, ['p1' => 'p2', 'p2' => 'p3']);
    $v = $g->viewFor($ps[3]->id);
    T::true(!isset($v['votes']) && !isset($v['turn']['votes']));
    $rows = array_column($v['players'], null, 'id');
    T::same(true, $rows[$ps[1]->id]['hasVoted']);
    T::same(false, $rows[$ps[3]->id]['hasVoted']);
    $json = json_encode($v);
    T::false(str_contains($json, '"vote":"' . $ps[2]->id));
    T::same($ps[2]->id, $g->viewFor($ps[1]->id)['me']['vote']);
});

// ---------- unveil ----------
T::test('impostor unveil: only the impostor, the host can skip, a timer unveils automatically', function () {
    foreach (['impostor', 'skip', 'timer'] as $how) {
        [$g, $ps, $clock] = startedImpostor([], 5);
        impToVote($g);
        impVotes($g, ['p1' => 'p2', 'p2' => 'p3', 'p3' => 'p1', 'p4' => 'p1', 'p0' => 'p1']);
        T::same('unveil', $g->phase);
        $imp = impImpostor($g);
        $other = array_values(array_filter($g->playing(), fn($p) => $p->id !== $imp->id))[0];
        T::throws(fn() => impCmd($g, $other, 'unveil'), '/Only the Impostor/');
        T::same(Impostor::UNVEIL_MS / 1000, ($g->deadline - $clock->ms) / 1000);
        if ($how === 'impostor') {
            impCmd($g, $imp, 'unveil');
        } elseif ($how === 'skip') {
            impCmd($g, $ps[0], 'skip');
        } else {
            $clock->ms += Impostor::UNVEIL_MS + 1;
            $g->tick();
        }
        T::same('result', $g->phase);
        T::same(null, $g->deadline);
    }
});

// ---------- scoring ----------
T::test('impostor scoring: strictly most votes = discovered, impostor scores nothing', function () {
    [$g, $ps, $clock] = startedImpostor([], 5);
    [$imp, $others] = impNames($g);
    $votes = [];
    foreach ($others as $o) {
        $votes[$o] = $imp; // everybody else votes for the impostor
    }
    $votes[$imp] = $others[0];
    $h = impFinishTurn($g, $clock, $votes);
    T::same(true, $h['discovered']);
    T::same(0, $h['points']);
    T::same(0, $g->scores[$h['impostorId']] ?? 0);
});

T::test('impostor scoring: one vote against the impostor and none against anybody else is enough', function () {
    [$g, $ps, $clock] = startedImpostor([], 5);
    [$imp, $others] = impNames($g);
    $h = impFinishTurn($g, $clock, [$others[0] => $imp]);
    T::same(true, $h['discovered']);
    T::same(0, $g->scores[$h['impostorId']]);
});

T::test('impostor scoring: a tie at the top that includes the impostor means not discovered', function () {
    [$g, $ps, $clock] = startedImpostor(['points' => 4], 6);
    [$imp, $o] = impNames($g);
    $h = impFinishTurn($g, $clock, [$o[0] => $imp, $o[1] => $o[2], $o[2] => $o[3], $o[3] => $o[2], $o[4] => $imp]);
    // impostor 2 votes, o[2] 2 votes
    T::same(false, $h['discovered']);
    T::same(4, $h['points']);
    T::same(4, $g->scores[$h['impostorId']]);
});

T::test('impostor scoring: when somebody else has more votes the impostor escapes', function () {
    [$g, $ps, $clock] = startedImpostor([], 5);
    [$imp, $o] = impNames($g);
    $h = impFinishTurn($g, $clock, [$o[0] => $o[1], $o[2] => $o[1], $o[3] => $imp]);
    T::same(false, $h['discovered']);
    T::same(3, $g->scores[$h['impostorId']]);
});

T::test('impostor scoring: a tie among other players without the impostor having more means escape', function () {
    [$g, $ps, $clock] = startedImpostor([], 5);
    [$imp, $o] = impNames($g);
    $h = impFinishTurn($g, $clock, [$o[0] => $o[1], $o[1] => $o[0], $o[2] => $imp]);
    // o0: 1, o1: 1, impostor: 1 -> a three way tie
    T::same(false, $h['discovered']);
});

T::test('impostor scoring: nobody voting and only abstentions mean not discovered', function () {
    [$g, $ps, $clock] = startedImpostor([], 5);
    $h = impFinishTurn($g, $clock, []);
    T::same(false, $h['discovered']);
    T::same(3, $h['points']);
    [$g, $ps, $clock] = startedImpostor([], 5);
    $h = impFinishTurn($g, $clock, ['p0' => null, 'p1' => null, 'p2' => null, 'p3' => null, 'p4' => null]);
    T::same(false, $h['discovered']);
});

T::test('impostor scoring: points accumulate over turns, shared wins on a tie, zero is no winner', function () {
    // two turns, impostor escapes both times with the same rng -> the same impostor scores twice
    [$g, $ps, $clock] = startedImpostor(['turns' => 2, 'points' => 5], 5);
    impFinishTurn($g, $clock, []);
    impCmd($g, $ps[0], 'next');
    impFinishTurn($g, $clock, []);
    impCmd($g, $ps[0], 'next');
    T::same('ended', $g->phase);
    T::same(10, max($g->scores));
    T::same(1, count($g->winners()));
    $view = $g->viewFor($ps[1]->id);
    T::same($g->winners(), $view['winners']);
    T::same(2, count($view['history']));
    // everybody discovered every time: nobody wins
    [$g, $ps, $clock] = startedImpostor(['turns' => 1], 5);
    [$imp, $o] = impNames($g);
    impFinishTurn($g, $clock, [$o[0] => $imp]);
    impCmd($g, $ps[0], 'next');
    T::same([], $g->winners());
    T::same([], $g->viewFor($ps[0]->id)['winners']);
    // equal top scores are shared wins
    [$g, $ps, $clock] = startedImpostor(['turns' => 1], 5);
    impFinishTurn($g, $clock, ['p1' => 'p2']);
    $g->scores = [$ps[0]->id => 3, $ps[1]->id => 3, $ps[2]->id => 1, $ps[3]->id => 0, $ps[4]->id => 3];
    impCmd($g, $ps[0], 'next');
    $w = $g->winners();
    sort($w);
    $e = [$ps[0]->id, $ps[1]->id, $ps[4]->id];
    sort($e);
    T::same($e, $w);
});

T::test('impostor next: any playing player or the host moves on; the last turn ends the game with endedAt', function () {
    [$g, $ps, $clock] = startedImpostor(['turns' => 2, 'hostPlays' => false], 4);
    impFinishTurn($g, $clock, []);
    impCmd($g, $ps[2], 'next');
    T::same('answer', $g->phase);
    T::same(2, $g->round);
    impFinishTurn($g, $clock, []);
    impCmd($g, $ps[0], 'next'); // the non-playing host
    T::same('ended', $g->phase);
    T::same($clock->ms, $g->endedAt);
    T::same(null, $g->deadline);
});

// ---------- leaving ----------
T::test('impostor leave: the impostor leaving cancels the turn without points', function () {
    foreach (['answer', 'reveal', 'vote', 'unveil'] as $stage) {
        [$g, $ps] = startedImpostor(['turns' => 2], 6);
        $imp = impImpostor($g);
        T::true($imp->id !== $ps[0]->id);
        if ($stage !== 'answer') {
            impAnswerAll($g);
        }
        if ($stage === 'vote' || $stage === 'unveil') {
            while ($g->phase === 'reveal') {
                impCmd($g, $g->player($g->turn['order'][$g->turn['idx']]), 'done');
            }
        }
        if ($stage === 'unveil') {
            impVotes($g, ['p0' => 'p1', 'p1' => 'p2', 'p2' => 'p1', 'p3' => 'p1', 'p4' => 'p1', 'p5' => 'p1']);
            T::same('unveil', $g->phase);
        }
        impCmd($g, $imp, 'leave');
        T::same('result', $g->phase, $stage);
        $h = end($g->history);
        T::same(true, $h['cancelled'], $stage);
        T::same(0, $h['points']);
        T::same(null, $h['discovered']);
        T::same($imp->name, $h['impostorName']);
        T::same(5, count($g->playing()));
        T::false(isset($g->scores[$imp->id]));
        impCmd($g, $ps[0], 'next');
        T::same('answer', $g->phase);
        T::true($g->turn['impostorId'] !== $imp->id);
    }
});

T::test('impostor kick: removing a normal player keeps the turn going and re-checks completion', function () {
    // answer phase: the last missing answer is the kicked player's
    [$g, $ps] = startedImpostor([], 7);
    $imp = impImpostor($g);
    $victim = current(array_filter($ps, fn($p) => $p->id !== $imp->id && $p->id !== $ps[0]->id));
    foreach ($g->playing() as $p) {
        if ($p->id !== $victim->id) {
            impCmd($g, $p, 'answer', ['text' => 'x']);
        }
    }
    T::same('answer', $g->phase);
    impCmd($g, $ps[0], 'kick', ['playerId' => $victim->id]);
    T::same('reveal', $g->phase);
    T::same(6, count($g->turn['order']));
    // reveal phase: the current speaker is kicked, the next one speaks
    $order = $g->turn['order'];
    impCmd($g, $ps[0], 'kick', ['playerId' => $order[0] === $ps[0]->id ? $order[1] : $order[0]]);
    T::same(5, count($g->turn['order']));
    T::same('reveal', $g->phase);
    // vote phase: votes by and for a kicked player are dropped
    while ($g->phase === 'reveal') {
        impCmd($g, $g->player($g->turn['order'][$g->turn['idx']]), 'done');
    }
    $left = array_values(array_filter($g->playing(), fn($p) => $p->id !== $ps[0]->id));
    impCmd($g, $left[0], 'vote', ['target' => $left[1]->id]);
    impCmd($g, $left[1], 'vote', ['target' => $ps[0]->id]);
    impCmd($g, $left[2], 'vote', ['target' => $left[0]->id]);
    T::same('vote', $g->phase);
    impCmd($g, $ps[0], 'kick', ['playerId' => $left[0]->id]);
    T::same([$left[1]->id], array_keys($g->turn['votes']));
    // everybody else abstains or votes: the phase ends with the last vote
    foreach (array_slice($left, 2) as $p) {
        if ($p->id !== $left[0]->id) {
            T::same('vote', $g->phase);
            impCmd($g, $p, 'vote', ['target' => null]);
        }
    }
    T::same('vote', $g->phase);
    impCmd($g, $ps[0], 'vote', ['target' => $left[1]->id]);
    T::same('unveil', $g->phase);
});

T::test('impostor kick: fewer than 4 players left ends the game; a leaver in the lobby does not', function () {
    [$g, $ps] = startedImpostor(['turns' => 3], 4);
    impCmd($g, $ps[3], 'leave');
    T::same('ended', $g->phase);
    T::true($g->endedAt !== null);
    T::throws(fn() => impCmd($g, $ps[2], 'leave'), '/over/');
    [$g, $ps] = startedImpostor(['source' => 'players'], 4);
    impCmd($g, $ps[3], 'leave');
    T::same('ended', $g->phase);
});

T::test('impostor writing: a leaver loses their pair and the rest can start', function () {
    [$g, $ps] = startedImpostor(['source' => 'players'], 5);
    foreach ([0, 1, 2, 3] as $i) {
        impWrite($g, $ps[$i]);
    }
    T::same('writing', $g->phase);
    impCmd($g, $ps[4], 'leave');
    T::same('answer', $g->phase);
    T::same(4, $g->turnsTotal);
});

T::test('impostor leave: answers and votes by and for the leaver are purged so history has no unknown names', function () {
    foreach (['answer' => 0, 'reveal' => 1, 'vote' => 2, 'unveil' => 3] as $phase => $_) {
        [$g, $ps] = startedImpostor([], 6);
        $imp = impImpostor($g);
        $left = array_values(array_filter($g->playing(), fn($p) => $p->id !== $imp->id && $p->id !== $ps[0]->id));
        $x = $left[0];
        $y = $left[1];
        if ($phase === 'answer') {
            impCmd($g, $x, 'answer', ['text' => 'mine']);
        } else {
            impToVote($g);
        }
        if ($phase === 'vote' || $phase === 'unveil') {
            impCmd($g, $x, 'vote', ['target' => $y->id]);
            impCmd($g, $y, 'vote', ['target' => $x->id]);
        }
        if ($phase === 'unveil') {
            foreach ($g->playing() as $p) {
                if (!array_key_exists($p->id, $g->turn['votes'])) {
                    impCmd($g, $p, 'vote', ['target' => null]);
                }
            }
            T::same('unveil', $g->phase);
        }
        impCmd($g, $ps[0], 'kick', ['playerId' => $x->id]);
        T::true(!isset($g->turn['answers'][$x->id]), "$phase answer");
        T::true(!isset($g->turn['votes'][$x->id]), "$phase vote by");
        T::true(!in_array($x->id, $g->turn['votes'], true), "$phase vote for");
        if ($phase !== 'answer') {
            T::true(!isset($g->turn['votes'][$y->id]) || $g->turn['votes'][$y->id] !== $x->id, "$phase y vote");
        }
    }
});

// ---------- visibility ----------
T::test('impostor views: no tokens, avatars, deck or other secrets in any phase', function () {
    $avatar = 'data:image/jpeg;base64,/9j/4AAQ';
    foreach (['pool', 'players'] as $source) {
        [$g, $ps, $clock] = startedImpostor(['source' => $source], 5, true);
        $g->players[1]->avatar = $avatar;
        impCmd($g, $ps[0], 'start');
        if ($source === 'players') {
            foreach ($ps as $p) {
                impWrite($g, $p);
            }
        }
        $check = function () use ($g, $ps, $avatar) {
            foreach ($ps as $p) {
                $json = json_encode($g->viewFor($p->id));
                foreach ($ps as $q) {
                    T::false(str_contains($json, $q->token), 'token');
                }
                T::false(str_contains($json, $avatar), 'avatar');
                T::false(str_contains($json, '"deck"') || str_contains($json, '"rng"'), 'deck');
            }
        };
        $check();
        impToVote($g);
        $check();
        impVotes($g, ['p1' => 'p2']);
        $clock->ms += 100000;
        $g->tick();
        $check();
        impCmd($g, impImpostor($g), 'unveil');
        $check();
    }
});

T::test('impostor views: the impostor sees only its own question and knows it; normal players do not learn who it is', function () {
    [$g, $ps] = startedImpostor([], 5);
    $imp = impImpostor($g);
    $normalQ = $g->turn['normal'];
    $impQ = $g->turn['impostor'];
    foreach ($ps as $p) {
        $v = $g->viewFor($p->id);
        $json = json_encode($v);
        if ($p->id === $imp->id) {
            T::same(true, $v['me']['impostor']);
            T::same($impQ, $v['me']['question']);
            T::false(str_contains($json, $normalQ));
        } else {
            T::same(false, $v['me']['impostor']);
            T::same($normalQ, $v['me']['question']);
            T::false(str_contains($json, $impQ));
            T::false(str_contains($json, 'impostorId'), 'impostor id');
        }
        T::true(!isset($v['god']) && !isset($v['turn']['impostorId']) && $v['history'] === []);
    }
    // other players' answers stay hidden during the answer phase
    impCmd($g, $ps[1], 'answer', ['text' => 'secret answer one']);
    T::false(str_contains(json_encode($g->viewFor($ps[2]->id)), 'secret answer one'));
    T::true(str_contains(json_encode($g->viewFor($ps[1]->id)), 'secret answer one'));
});

T::test('impostor views: unveil phase keeps the impostor hidden, the result reveals everything', function () {
    [$g, $ps, $clock] = startedImpostor([], 5);
    $imp = impImpostor($g);
    $other = current(array_filter($g->playing(), fn($p) => $p->id !== $imp->id));
    impToVote($g);
    impVotes($g, ['p1' => 'p2', 'p2' => 'p3']);
    $clock->ms += 100000;
    $g->tick();
    T::same('unveil', $g->phase);
    $json = json_encode($g->viewFor($other->id));
    T::false(str_contains($json, 'impostorId'));
    T::false(str_contains($json, $g->turn['impostor']));
    impCmd($g, $imp, 'unveil');
    $v = $g->viewFor($other->id);
    $h = end($v['history']);
    T::same($imp->id, $h['impostorId']);
    T::same($g->history[0]['impostor'], $h['impostor']);
    T::same(5, count($h['answers']));
    T::true(count($h['votes']) >= 2);
    T::true(!isset($v['turn']));
});

T::test('impostor God mode: a non-playing host sees the whole turn live and every pair; a playing host sees nothing extra', function () {
    [$g, $ps, $clock] = startedImpostor(['hostPlays' => false, 'turns' => 3], 4);
    $god = $g->viewFor($ps[0]->id);
    T::true($god['me']['god']);
    $d = $god['god'];
    T::same($g->turn['impostorId'], $d['turn']['impostorId']);
    T::same($g->turn['normal'], $d['turn']['normal']);
    T::same($g->turn['impostor'], $d['turn']['impostor']);
    T::same(3, count($d['pairs'])); // future turns too
    impCmd($g, $ps[1], 'answer', ['text' => 'live answer']);
    T::same('live answer', ((array)$g->viewFor($ps[0]->id)['god']['turn']['answers'])[$ps[1]->id]);
    T::true(!isset($god['me']['question']) && !isset($god['me']['impostor']));
    T::same(true, array_column($god['players'], 'spectator', 'id')[$ps[0]->id]);
    impToVote($g);
    impCmd($g, $ps[1], 'vote', ['target' => $ps[2]->id]);
    T::same($ps[2]->id, ((array)$g->viewFor($ps[0]->id)['god']['turn']['votes'])[$ps[1]->id]);
    // nobody else gets it
    foreach ([1, 2, 3, 4] as $i) {
        T::true(!isset($g->viewFor($ps[$i]->id)['god']));
    }
    [$g2, $ps2] = startedImpostor([], 5);
    T::true(!isset($g2->viewFor($ps2[0]->id)['god']));
    T::false($g2->viewFor($ps2[0]->id)['me']['god']);
});

T::test('impostor God mode: the host controls the flow with skip and next but cannot answer, vote or unveil', function () {
    [$g, $ps, $clock] = startedImpostor(['hostPlays' => false], 4);
    T::throws(fn() => impCmd($g, $ps[0], 'answer', ['text' => 'x']), '/not playing/');
    impCmd($g, $ps[0], 'skip');
    T::same('reveal', $g->phase);
    T::throws(fn() => impCmd($g, $ps[0], 'done'), '/not playing/');
    impCmd($g, $ps[0], 'startvote');
    T::throws(fn() => impCmd($g, $ps[0], 'vote', ['target' => $ps[1]->id]), '/not playing/');
    impCmd($g, $ps[0], 'skip');
    T::same('unveil', $g->phase);
    T::throws(fn() => impCmd($g, $ps[0], 'unveil'), '/not playing/');
    impCmd($g, $ps[0], 'skip');
    T::same('result', $g->phase);
    T::throws(fn() => impCmd($g, $ps[0], 'skip'), '/Nothing to skip/');
    T::same(0, array_sum($g->scores) - 3); // the impostor escaped: 3 points
});

// ---------- persistence ----------
T::test('impostor persistence: toArray/fromArray roundtrip mid-turn keeps everything and resumes', function () {
    [$g, $ps, $clock] = startedImpostor(['source' => 'players'], 5);
    foreach ($ps as $p) {
        impWrite($g, $p);
    }
    impCmd($g, $ps[1], 'answer', ['text' => 'saved answer']);
    $copy = Impostor::fromArray(json_decode(json_encode($g->toArray()), true), ['clock' => $clock, 'rng' => fn() => 0.5]);
    T::same($g->toArray(), $copy->toArray());
    T::same('saved answer', $copy->viewFor($ps[1]->id)['me']['answer']);
    foreach (impPlaying($copy) as $p) {
        $copy->command($p->id, ['t' => 'answer', 'text' => 'again']);
    }
    T::same('reveal', $copy->phase);
    $again = Rooms::load(json_decode(json_encode($copy->toArray()), true), ['clock' => $clock]);
    T::true($again instanceof Impostor);
    T::same($copy->toArray(), $again->toArray());
});

T::test('impostor persistence: ended games expire 2 days after the end, others after 7 idle days', function () {
    [$g, $ps, $clock] = startedImpostor(['turns' => 1], 5);
    $raw = fn() => json_decode(json_encode($g->toArray()), true);
    T::false(Store::expired($raw(), $clock->ms + 6 * 86400000));
    T::true(Store::expired($raw(), $clock->ms + 8 * 86400000));
    impFinishTurn($g, $clock, []);
    impCmd($g, $ps[0], 'next');
    T::same('ended', $g->phase);
    T::false(Store::expired($raw(), $clock->ms + 86400000));
    T::true(Store::expired($raw(), $clock->ms + 3 * 86400000));
});

T::test('impostor presence: tick marks silent players disconnected, touch brings them back', function () {
    [$g, $ps, $clock] = startedImpostor([], 5);
    $clock->ms += 16000;
    $g->touch($ps[0]);
    $g->tick();
    $rows = array_column($g->viewFor($ps[0]->id)['players'], 'connected', 'id');
    T::same(true, $rows[$ps[0]->id]);
    T::same(false, $rows[$ps[1]->id]);
    $g->touch($ps[1]);
    T::same(true, array_column($g->viewFor($ps[0]->id)['players'], 'connected', 'id')[$ps[1]->id]);
});

T::test('impostor rules text mentions the engine constants', function () {
    $html = Wm\Rules::html(false);
    T::true(str_contains($html, 'id="impostor"'));
    T::true(str_contains($html, '30 to 90 seconds'));
    T::true(str_contains($html, 'strictly more votes'));
    T::true(str_contains(Wm\Rules::html(true), '?create=impostor'));
});
