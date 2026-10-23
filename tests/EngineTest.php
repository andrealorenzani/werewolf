<?php
declare(strict_types=1);

use Wm\Config;
use Wm\Game;
use Wm\Games;
use Wm\Player;

const WW = ['werewolf', 'villager', 'villager', 'villager', 'seer', 'villager'];

T::test('config rejects unbalanced or tiny games', function () {
    $def = Games::get('werewolf');
    T::throws(fn() => Config::normalize($def, ['name' => 'x', 'roles' => ['villager' => 1, 'werewolf' => 1]]), '/At least 3 Villager/');
    T::throws(fn() => Config::normalize(Games::get('mafia'), ['name' => 'x', 'roles' => ['citizen' => 1, 'mafioso' => 1]]), '/At least 4/');
    T::throws(fn() => Config::normalize($def, ['name' => 'x', 'roles' => ['villager' => 3, 'werewolf' => 3]]), '/At most/');
    T::throws(fn() => Config::normalize($def, ['name' => 'x', 'roles' => ['villager' => 4]]), '/At least 1/');
    T::throws(fn() => Config::normalize($def, ['name' => 'x', 'roles' => ['villager' => 4, 'werewolf' => 1, 'seer' => 2]]), '/At most/');
    T::same(5, Config::normalize($def, ['name' => 'x', 'roles' => ['villager' => 4, 'werewolf' => 1]])['total']);
});

T::test('profile validation', function () {
    T::throws(fn() => Config::profile(['name' => '  ']), '/name/');
    T::throws(fn() => Config::profile(['name' => 'a', 'avatar' => 'javascript:alert(1)']), '/avatar/');
    T::same('Bob', Config::profile(['name' => ' Bob ', 'avatar' => null])['name']);
});

T::test('game starts with discussion then night once host skips', function () {
    [$g, $ps] = startedGame('werewolf', WW);
    T::same('discussion', $g->phase);
    T::same(0, $g->round);
    toNight($g, $ps);
    T::same('night', $g->phase);
    T::same(1, $g->round);
});

T::test('everyone ready ends discussion', function () {
    [$g, $ps] = startedGame('werewolf', WW);
    foreach ($ps as $p) {
        $g->command($p->id, ['t' => 'ready']);
    }
    T::same('night', $g->phase);
});

T::test('lazy timers: tick() advances an expired phase', function () {
    [$g, $ps, $clock] = startedGame('werewolf', WW, ['discussionSeconds' => 20, 'nightSeconds' => 30]);
    $g->tick();
    T::same('discussion', $g->phase);
    $clock->ms += 21_000;
    $g->tick();
    T::same('night', $g->phase);
    $clock->ms += 31_000;
    $g->tick(); // step 1 expires -> step 2 (nobody) -> resolved
    T::same('discussion', $g->phase);
    T::same(1, $g->round);
    T::same(6, count($g->alive()));
});

T::test('werewolf kills; night ends when all actors acted', function () {
    [$g, $ps] = startedGame('werewolf', WW);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    T::same('night', $g->phase);
    act($g, $ps[4], ['target' => $ps[0]->id]); // seer inspects wolf
    T::same('discussion', $g->phase);
    T::same(false, $ps[1]->alive);
    T::matches('/IS a werewolf/', $ps[4]->notes[0]['text']);
});

T::test('mafia: doctor protection saves the victim', function () {
    [$g, $ps] = startedGame('mafia', ['mafioso', 'citizen', 'citizen', 'citizen', 'detective', 'doctor']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    act($g, $ps[4], ['target' => $ps[2]->id]);
    act($g, $ps[5], ['target' => $ps[1]->id]);
    T::true($ps[1]->alive);
    T::matches('/saved/', $ps[5]->notes[0]['text']);
});

T::test('night actions are validated', function () {
    [$g, $ps] = startedGame('werewolf', WW);
    toNight($g, $ps);
    T::throws(fn() => act($g, $ps[1], ['target' => $ps[2]->id]), '/cannot act/');
    T::throws(fn() => killAct($g, $ps[0], $ps[0]), '/Invalid/');
    T::throws(fn() => act($g, $ps[4], ['target' => $ps[4]->id]), '/Invalid/');
});

T::test('vote tie eliminates nobody', function () {
    [$g, $ps] = startedGame('werewolf', WW);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    act($g, $ps[4], ['target' => $ps[0]->id]);
    $g->command($ps[0]->id, ['t' => 'skip']);
    T::same('vote', $g->phase);
    $g->command($ps[2]->id, ['t' => 'vote', 'target' => $ps[0]->id]);
    $g->command($ps[3]->id, ['t' => 'vote', 'target' => $ps[0]->id]);
    $g->command($ps[0]->id, ['t' => 'vote', 'target' => $ps[2]->id]);
    $g->command($ps[4]->id, ['t' => 'vote', 'target' => $ps[2]->id]);
    $g->command($ps[5]->id, ['t' => 'vote', 'target' => null]);
    T::same('night', $g->phase);
    T::same(5, count($g->alive()));
});

T::test('villagers win by voting out the last werewolf', function () {
    [$g, $ps] = startedGame('werewolf', WW);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    act($g, $ps[4], ['target' => $ps[0]->id]);
    $g->command($ps[0]->id, ['t' => 'skip']);
    foreach ([2, 3, 4, 5] as $i) {
        $g->command($ps[$i]->id, ['t' => 'vote', 'target' => $ps[0]->id]);
    }
    $g->command($ps[0]->id, ['t' => 'vote', 'target' => null]);
    T::same('ended', $g->phase);
    T::same('good', $g->winner);
});

T::test('werewolves win when they reach parity', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'villager', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    T::same('discussion', $g->phase); // 1 wolf vs 3
    $g->command($ps[0]->id, ['t' => 'skip']);
    foreach ([2, 3, 4] as $i) {
        $g->command($ps[$i]->id, ['t' => 'vote', 'target' => $i === 2 ? $ps[3]->id : $ps[2]->id]);
    }
    $g->command($ps[0]->id, ['t' => 'vote', 'target' => $ps[2]->id]);
    T::same('night', $g->phase); // ps[2] eliminated: 1 wolf vs 2
    killAct($g, $ps[0], $ps[3]);
    T::same('ended', $g->phase);
    T::same('evil', $g->winner);
});

T::test('hunter shoots on death, then the game continues', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'hunter', 'villager', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    T::same('hunter', $g->phase);
    T::throws(fn() => act($g, $ps[2], ['target' => $ps[0]->id]), '/not your turn/');
    act($g, $ps[1], ['target' => $ps[0]->id]);
    T::same('ended', $g->phase);
    T::same('good', $g->winner);
});

T::test('hunter phase times out without a shot', function () {
    [$g, $ps, $clock] = startedGame('werewolf', ['werewolf', 'hunter', 'villager', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    $clock->ms += 60_000;
    $g->tick();
    T::same('discussion', $g->phase);
    T::true($ps[0]->alive);
});

T::test('witch heals the victim and can poison', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'witch', 'villager', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[2]);
    T::same('night', $g->phase);
    T::same('p2', $g->viewFor($ps[1]->id)['me']['action']['victim']);
    act($g, $ps[1], ['heal' => true, 'poison' => $ps[0]->id]);
    T::true($ps[2]->alive);
    T::same(false, $ps[0]->alive);
    T::same('good', $g->winner);
});

T::test('mafia: godfather appears innocent, escort blocks', function () {
    [$g, $ps] = startedGame('mafia', ['godfather', 'detective', 'escort', 'citizen', 'citizen', 'citizen']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[3]);
    act($g, $ps[1], ['target' => $ps[0]->id]);
    act($g, $ps[2], ['target' => $ps[0]->id]); // godfather blocked: no kill
    T::same('discussion', $g->phase);
    T::true($ps[3]->alive);
    T::matches('/kept you busy/', $ps[0]->notes[0]['text']);
    T::matches('/INNOCENT/', $ps[1]->notes[0]['text']);
});

T::test('mafia: vigilante shoots once', function () {
    [$g, $ps] = startedGame('mafia', ['mafioso', 'vigilante', 'citizen', 'citizen', 'citizen', 'citizen']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[2]);
    act($g, $ps[1], ['target' => $ps[0]->id]);
    T::same('ended', $g->phase); // mafioso dead, last mafia -> town wins
    T::same('good', $g->winner);
});

T::test('views hide other roles but show teammates and the dead', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'werewolf', 'villager', 'villager', 'villager', 'villager', 'villager']);
    $roleIn = fn(array $v, string $id) => array_values(array_filter($v['players'], fn($p) => $p['id'] === $id))[0]['role'] ?? null;
    $v = $g->viewFor($ps[0]->id);
    T::same('werewolf', $roleIn($v, $ps[1]->id));
    T::same(null, $roleIn($v, $ps[2]->id));
    T::same(null, $roleIn($g->viewFor($ps[2]->id), $ps[0]->id));
    // avatars/tokens never appear in views
    T::true(!str_contains(json_encode($v), $ps[1]->token));
});

T::test('night chat is private to the evil team', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'werewolf', 'villager', 'villager', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    $g->command($ps[0]->id, ['t' => 'chat', 'text' => 'kill p2']);
    T::throws(fn() => $g->command($ps[2]->id, ['t' => 'chat', 'text' => 'hi']), '/cannot talk/');
    T::same(1, count($g->viewFor($ps[1]->id)['chat']));
    T::same(0, count($g->viewFor($ps[2]->id)['chat']));
});

T::test('state survives a JSON round trip', function () {
    [$g, $ps] = startedGame('werewolf', WW);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    $copy = Game::fromArray(json_decode(json_encode($g->toArray()), true), ['rng' => fn() => 0.5]);
    T::same($g->viewFor($ps[4]->id)['me']['action'], $copy->viewFor($ps[4]->id)['me']['action']);
    act($copy, $copy->player($ps[4]->id), ['target' => $ps[0]->id]);
    T::same('discussion', $copy->phase);
});

// ---------- optional timers, flexible start, late join, settings, profile ----------
function lobby(string $type, array $roles, int $joined, array $settings = [], int $ghosts = 0): array
{
    $cfg = Config::normalize(Games::get($type), ['name' => 'L', 'roles' => $roles, 'settings' => $settings, 'ghosts' => $ghosts]);
    $clock = new Clock();
    $g = Game::create('TESTBB', $type, $cfg, ['rng' => fn() => 0.5, 'clock' => $clock]);
    $ps = [];
    for ($i = 0; $i < $joined; $i++) {
        $ps[] = $g->addPlayer(['name' => "p$i"]);
    }
    return [$g, $ps, $clock];
}

T::test('day timers default to off; the night timer is always on (60s default)', function () {
    $cfg = Config::normalize(Games::get('werewolf'), ['name' => 'x', 'roles' => ['villager' => 4, 'werewolf' => 1]]);
    T::same(0, $cfg['settings']['discussionSeconds']);
    T::same(0, $cfg['settings']['voteSeconds']);
    T::same(60, $cfg['settings']['nightSeconds']);
    [$g, $ps, $clock] = lobby('werewolf', ['villager' => 4, 'werewolf' => 1], 5);
    $g->start($ps[0]->id);
    T::same(null, $g->deadline);
    toNight($g, $ps);
    T::same($clock->ms + 60_000, $g->deadline);
});

T::test('timer settings: 0 or empty is off, positive values are clamped', function () {
    $def = Games::get('werewolf');
    $n = fn(array $s) => Config::normalize($def, ['name' => 'x', 'roles' => ['villager' => 4, 'werewolf' => 1], 'settings' => $s])['settings'];
    T::same(0, $n(['discussionSeconds' => 0])['discussionSeconds']);
    T::same(0, $n(['voteSeconds' => ''])['voteSeconds']);
    T::same(15, $n(['discussionSeconds' => 1])['discussionSeconds']);
    T::same(300, $n(['voteSeconds' => 9999])['voteSeconds']);
    T::same(60, $n(['nightSeconds' => 60])['nightSeconds']);
});

T::test('night timer: empty, 0 or invalid = 60, clamped to 30-90, no way to switch it off', function () {
    $def = Games::get('werewolf');
    $n = fn(mixed $v) => Config::normalize($def, ['name' => 'x', 'roles' => ['villager' => 4, 'werewolf' => 1], 'settings' => ['nightSeconds' => $v]])['settings']['nightSeconds'];
    foreach ([null, '', 0, '0', false, -5, 'abc'] as $v) {
        T::same(60, $n($v), var_export($v, true));
    }
    T::same(30, $n(1));
    T::same(30, $n(29));
    T::same(30, $n(30));
    T::same(45, $n('45'));
    T::same(90, $n(90));
    T::same(90, $n(500));
    $m = Config::normalize(Games::get('mafia'), ['name' => 'x', 'roles' => ['citizen' => 4, 'mafioso' => 1]]);
    T::same(60, $m['settings']['nightSeconds']);
});

T::test('old save with the night timer off loads with 60s and arms the running night', function () {
    [$g, $ps, $clock] = startedGame('werewolf', WW);
    toNight($g, $ps);
    $a = json_decode(json_encode($g->toArray()), true);
    $a['settings']['nightSeconds'] = 0;
    $a['deadline'] = null;
    unset($a['endedAt'], $a['heldLog']);
    $old = Game::fromArray($a, ['rng' => fn() => 0.5, 'clock' => $clock]);
    T::same(60, $old->settings['nightSeconds']);
    T::same([], $old->heldLog);
    T::same(null, $old->endedAt);
    $old->tick();
    T::same($clock->ms + 60_000, $old->deadline);
});

T::test('role defaults pass validation', function () {
    foreach (['werewolf', 'mafia'] as $type) {
        $def = Games::get($type);
        $roles = [];
        foreach ($def['roles'] as $r) {
            $roles[$r['id']] = $r['def'];
        }
        T::same(6, Config::normalize($def, ['name' => 'x', 'roles' => $roles])['total']);
    }
});

T::test('no day timers: host can skip night steps and the hunter shot', function () {
    [$g, $ps, $clock] = startedGame('werewolf', ['werewolf', 'witch', 'villager', 'villager', 'villager', 'villager'], ['discussionSeconds' => 0, 'voteSeconds' => 0]);
    T::same(null, $g->deadline);
    toNight($g, $ps);
    T::throws(fn() => $g->command($ps[2]->id, ['t' => 'skip']), '/host/');
    killAct($g, $ps[0], $ps[2]);
    T::same(2, $g->night['step']);
    T::same($clock->ms + 45_000, $g->deadline); // every night step has its own timer
    $g->command($ps[0]->id, ['t' => 'skip']); // witch missing action = skip
    T::same('discussion', $g->phase);
    T::same(false, $ps[2]->alive);

    [$g, $ps] = startedGame('werewolf', ['werewolf', 'hunter', 'villager', 'villager', 'villager', 'villager'], ['discussionSeconds' => 0, 'voteSeconds' => 0]);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    T::same('hunter', $g->phase);
    T::same(null, $g->deadline);
    $g->command($ps[0]->id, ['t' => 'skip']);
    T::same('discussion', $g->phase);
    T::true($ps[0]->alive);
});

T::test('skip is refused in the lobby', function () {
    [$g, $ps] = lobby('werewolf', ['villager' => 4, 'werewolf' => 1], 5);
    T::throws(fn() => $g->command($ps[0]->id, ['t' => 'skip']), '/Cannot skip/');
});

T::test('settings: host only, lobby only, total >= joined, bumps version', function () {
    [$g, $ps] = lobby('werewolf', ['villager' => 4, 'werewolf' => 1], 3);
    $v = $g->version;
    $new = ['name' => 'New', 'description' => 'd', 'roles' => ['villager' => 5, 'werewolf' => 1, 'seer' => 1], 'settings' => ['nightSeconds' => 30]];
    T::throws(fn() => $g->command($ps[1]->id, ['t' => 'settings'] + $new), '/host/');
    $g->command($ps[0]->id, ['t' => 'settings'] + $new);
    T::same('New', $g->name);
    T::same(7, $g->total);
    T::same(30, $g->settings['nightSeconds']);
    T::true($g->version > $v);
    T::throws(fn() => $g->command($ps[0]->id, ['t' => 'settings', 'name' => 'x', 'roles' => ['villager' => 1, 'werewolf' => 1, 'seer' => 1]]), '/At least 3 Villager/');
    $g->addPlayer(['name' => 'p3']);
    $g->addPlayer(['name' => 'p4']);
    T::throws(fn() => $g->command($ps[0]->id, ['t' => 'settings', 'name' => 'x', 'roles' => ['villager' => 3, 'werewolf' => 1]]), '/already joined/');
    $g->start($ps[0]->id);
    T::throws(fn() => $g->command($ps[0]->id, ['t' => 'settings'] + $new), '/before the game starts/');
});

T::test('start needs 4 players and may leave seats open', function () {
    [$g, $ps] = lobby('werewolf', ['villager' => 6, 'werewolf' => 2, 'seer' => 1], 3);
    T::throws(fn() => $g->start($ps[0]->id), '/At least 4 players needed \(3\/9\)/');
    $g->addPlayer(['name' => 'p3']);
    $g->start($ps[0]->id);
    T::same('discussion', $g->phase);
    T::same(9 - 4, count($g->pool));
});

T::test('composition: invariants for every n, fillers dropped first, dealt + pool = configured', function () {
    foreach ([['werewolf', ['villager' => 7, 'werewolf' => 3, 'seer' => 1, 'paladin' => 1, 'hunter' => 1]],
        ['mafia', ['citizen' => 6, 'mafioso' => 1, 'godfather' => 1, 'detective' => 1, 'doctor' => 1, 'escort' => 1]]] as [$type, $roles]) {
        [$g] = lobby($type, $roles, 0);
        $def = Games::get($type);
        $team = [];
        $filler = [];
        foreach ($def['roles'] as $r) {
            $team[$r['id']] = $r['team'];
            $filler[$r['id']] = !empty($r['filler']);
        }
        $total = $g->total;
        for ($n = 4; $n <= $total; $n++) {
            $c = $g->composition($n);
            T::same($n, count($c['dealt']));
            $e = count(array_filter($c['dealt'], fn($id) => $team[$id] === 'evil'));
            T::true($e >= 1, "evil >= 1 at n=$n");
            T::true($n - $e > $e, "good > evil at n=$n");
            $all = array_merge($c['dealt'], $c['pool']);
            sort($all);
            $want = [];
            foreach ($roles as $id => $k) {
                $want = array_merge($want, array_fill(0, $k, $id));
            }
            sort($want);
            T::same($want, $all);
            if ($n < $total) {
                // a special good role is only dropped once no filler is left in the dealt cards
                $poolSpecial = array_filter($c['pool'], fn($id) => $team[$id] === 'good' && !$filler[$id]);
                if ($poolSpecial) {
                    T::same(0, count(array_filter($c['dealt'], fn($id) => $filler[$id])));
                }
            } else {
                T::same([], $c['pool']);
            }
        }
    }
});

T::test('start deals composition, keeps the pool hidden from views', function () {
    [$g, $ps] = lobby('werewolf', ['villager' => 4, 'werewolf' => 2, 'seer' => 1, 'paladin' => 1], 5);
    $g->start($ps[0]->id);
    T::same(3, count($g->pool));
    foreach ($g->players as $p) {
        T::true($p->role !== null);
        $json = json_encode($g->viewFor($p->id));
        T::true(!str_contains($json, '"pool"'));
        T::true(!str_contains($json, 'avatar":"data'));
    }
    $copy = Game::fromArray(json_decode(json_encode($g->toArray()), true));
    T::same($g->pool, $copy->pool);
    $old = $g->toArray();
    unset($old['pool']);
    T::same([], Game::fromArray($old)->pool);
});

T::test('late join: allowed on day 0, role from pool, rejected afterwards and when full', function () {
    [$g, $ps] = lobby('werewolf', ['villager' => 4, 'werewolf' => 1, 'seer' => 1], 4);
    T::true($g->joinable());
    $g->start($ps[0]->id);
    T::true($g->joinable());
    $pool = $g->pool;
    $p = $g->addPlayer(['name' => 'late']);
    T::true(in_array($p->role, ['villager', 'seer', 'werewolf'], true));
    T::same(count($pool) - 1, count($g->pool));
    T::same(false, $p->ready);
    T::same('late joined the game.', end($g->log)['text']);
    $g->addPlayer(['name' => 'late2']);
    T::same(false, $g->joinable());
    T::throws(fn() => $g->addPlayer(['name' => 'late3']), '/full/');

    [$g, $ps] = lobby('werewolf', ['villager' => 5, 'werewolf' => 1], 4);
    $g->start($ps[0]->id);
    toNight($g, $ps);
    T::same(false, $g->joinable());
    T::throws(fn() => $g->addPlayer(['name' => 'late']), '/already started/');
    T::same(false, $g->viewFor($ps[0]->id)['joinable']);
});

T::test('late joiner never tips the balance to evil; late evil is seen by evil only', function () {
    // 4 villagers + 2 wolves configured; 5 join: composition keeps 1 wolf (2 wolves need 4+ other players)
    [$g, $ps] = lobby('werewolf', ['villager' => 4, 'werewolf' => 2], 5);
    $g->start($ps[0]->id);
    T::same(['werewolf'], $g->pool);
    T::same(1, count(array_filter($g->players, fn($p) => $p->role === 'werewolf')));
    $a = $g->addPlayer(['name' => 'late']); // 4 good > 1 evil + 1 and 2 wolves <= ceil(4/3)
    T::same('werewolf', $a->role);
    $wolf = array_values(array_filter($g->players, fn($p) => $p->role === 'werewolf' && $p->id !== $a->id))[0];
    $roleIn = fn(string $viewer, string $id) => array_values(array_filter($g->viewFor($viewer)['players'], fn($p) => $p['id'] === $id))[0]['role'] ?? null;
    T::same('werewolf', $roleIn($wolf->id, $a->id));
    $villager = array_values(array_filter($g->players, fn($p) => $p->role === 'villager'))[0];
    T::same(null, $roleIn($villager->id, $a->id));

    // guard: 2 good vs 1 evil at joining -> an evil draw is replaced by a filler
    [$g, $ps] = lobby('werewolf', ['villager' => 4, 'werewolf' => 2], 5);
    $g->start($ps[0]->id);
    $g->players = array_slice($g->players, 0, 3);
    foreach ($g->players as $i => $p) {
        $p->role = $i === 0 ? 'werewolf' : 'villager';
    }
    $g->pool = ['werewolf', 'villager'];
    $g->total = 9;
    $r = $g->addPlayer(['name' => 'z'])->role;
    T::same('villager', $r); // good=2, evil=1: 2 > 2 false -> filler
});

T::test('late joiner never exceeds a role cap: a capped card stays in the pool', function () {
    [$g, $ps] = lobby('werewolf', ['villager' => 5, 'werewolf' => 1, 'witch' => 1], 6);
    $g->start($ps[0]->id);
    T::same(1, count(array_filter($g->players, fn($p) => $p->role === 'witch')));
    $g->pool = ['witch'];
    $g->total = 8;
    $late = $g->addPlayer(['name' => 'late']); // 2 witches among 6 non-wolves: cap is ceil(6/10) = 1
    T::same('villager', $late->role);
    T::same(['witch'], $g->pool);
});

T::test('day 0 ends when all living press ready or host skips, with no timer', function () {
    [$g, $ps] = startedGame('werewolf', WW, ['discussionSeconds' => 0]);
    T::same(null, $g->deadline);
    foreach (array_slice($ps, 0, 5) as $p) {
        $g->command($p->id, ['t' => 'ready']);
    }
    T::same('discussion', $g->phase);
    $g->command($ps[5]->id, ['t' => 'ready']);
    T::same('night', $g->phase);
});

T::test('old save without pool/profileAt: late join on day 0 gets a filler', function () {
    [$g, $ps] = lobby('werewolf', ['villager' => 4, 'werewolf' => 1, 'seer' => 1], 4);
    $g->start($ps[0]->id);
    $a = $g->toArray();
    unset($a['pool']);
    foreach ($a['players'] as &$pl) {
        unset($pl['profileAt']);
    }
    unset($pl);
    $old = Game::fromArray($a, ['rng' => fn() => 0.5, 'clock' => new Clock()]);
    T::same([], $old->pool);
    T::true($old->joinable());
    $p = $old->addPlayer(['name' => 'late']);
    T::same('villager', $p->role);
});

T::test('late joiner delays day 0 (timers off) until ready or host skip', function () {
    [$g, $ps] = lobby('werewolf', ['villager' => 5, 'werewolf' => 1, 'seer' => 1], 5);
    $g->start($ps[0]->id);
    $late = $g->addPlayer(['name' => 'late']);
    foreach ($g->players as $p) {
        if ($p->id !== $late->id) {
            $g->command($p->id, ['t' => 'ready']);
        }
    }
    T::same('discussion', $g->phase);
    $g->command($late->id, ['t' => 'ready']);
    T::same('night', $g->phase);

    [$g, $ps] = lobby('werewolf', ['villager' => 5, 'werewolf' => 1, 'seer' => 1], 5);
    $g->start($ps[0]->id);
    $g->addPlayer(['name' => 'late']);
    $g->command($ps[0]->id, ['t' => 'skip']);
    T::same('night', $g->phase);
});

T::test('late-joined hunter shoots after dying', function () {
    [$g, $ps] = lobby('werewolf', ['villager' => 5, 'werewolf' => 2, 'hunter' => 1], 6);
    $g->start($ps[0]->id);
    $g->pool = ['hunter'];
    $late = $g->addPlayer(['name' => 'late']);
    T::same('hunter', $late->role);
    $wolves = array_values(array_filter($g->players, fn($p) => $p->role === 'werewolf'));
    T::same(2, count($wolves));
    $g->command($ps[0]->id, ['t' => 'skip']);
    killAct($g, $wolves, $late);
    T::same('hunter', $g->phase);
    act($g, $late, ['target' => $wolves[0]->id]);
    T::same('discussion', $g->phase);
    T::same(false, $wolves[0]->alive);
});

T::test('settings: roles are required', function () {
    [$g, $ps] = lobby('werewolf', ['villager' => 4, 'werewolf' => 1], 3);
    T::throws(fn() => $g->command($ps[0]->id, ['t' => 'settings', 'name' => 'X']), '/roles/');
});

T::test('profile: any phase, unique name, avatar replace/remove/keep, cooldown, validation', function () {
    [$g, $ps, $clock] = startedGame('werewolf', WW);
    $img = 'data:image/jpeg;base64,/9j/4AAQ';
    $v = $g->version;
    $g->command($ps[1]->id, ['t' => 'profile', 'name' => 'Zed']);
    T::same('Zed', $ps[1]->name);
    T::same(1, $ps[1]->avatarV);
    T::true($g->version > $v);
    $clock->ms += 4000;
    T::throws(fn() => $g->command($ps[1]->id, ['t' => 'profile', 'name' => 'P0']), '/taken/');
    T::throws(fn() => $g->command($ps[1]->id, ['t' => 'profile', 'name' => 'Zed', 'avatar' => 'javascript:x']), '/avatar/');
    T::throws(fn() => $g->command($ps[1]->id, ['t' => 'profile', 'name' => '']), '/name/');
    $g->command($ps[1]->id, ['t' => 'profile', 'name' => 'Zed', 'avatar' => $img]);
    T::same($img, $ps[1]->avatar);
    T::same(2, $ps[1]->avatarV);
    T::throws(fn() => $g->command($ps[1]->id, ['t' => 'profile', 'name' => 'Zed2']), '/wait/');
    $clock->ms += 4000;
    $g->command($ps[1]->id, ['t' => 'profile', 'name' => 'Zed2']); // keep avatar
    T::same($img, $ps[1]->avatar);
    $clock->ms += 4000;
    $g->command($ps[1]->id, ['t' => 'profile', 'name' => 'Zed2', 'avatar' => null]);
    T::same(null, $ps[1]->avatar);
    $json = json_encode($g->viewFor($ps[0]->id));
    T::true(!str_contains($json, 'data:image'));
    T::true(!str_contains($json, $ps[1]->token));
    T::true(str_contains($json, 'Zed2'));
    toNight($g, $ps);
    $clock->ms += 4000;
    $g->command($ps[2]->id, ['t' => 'profile', 'name' => 'Night owl']); // allowed in any phase
    T::same('Night owl', $ps[2]->name);
});

// ---------- paladin, ghost, settings flags, private messages, story chat ----------
function setRng(Game $g, float $v): void
{
    $prop = new ReflectionProperty(Game::class, 'rng');
    $prop->setValue($g, fn() => $v);
}

/** Day -> vote (nobody votes) -> next night. */
function nextNight(Game $g, array $ps): void
{
    $g->command($ps[0]->id, ['t' => 'skip']); // discussion -> vote
    $g->command($ps[0]->id, ['t' => 'skip']); // vote -> night
}

/** The dawn announcement: the log line before "Day N breaks." */
function dawn(Game $g): string
{
    return $g->log[count($g->log) - 2]['text'];
}

T::test('story chat flags default to false and are strict booleans', function () {
    $def = Games::get('werewolf');
    $base = ['name' => 'x', 'roles' => ['villager' => 4, 'werewolf' => 1]];
    $s = Config::normalize($def, $base)['settings'];
    T::same(false, $s['storyPublicChat']);
    T::same(false, $s['storyPrivateChat']);
    $s = Config::normalize($def, $base + ['settings' => ['storyPublicChat' => 'yes', 'storyPrivateChat' => 1]])['settings'];
    T::same(false, $s['storyPublicChat']);
    T::same(false, $s['storyPrivateChat']);
    $s = Config::normalize($def, $base + ['settings' => ['storyPublicChat' => true, 'storyPrivateChat' => true]])['settings'];
    T::same(true, $s['storyPublicChat']);
    T::same(true, $s['storyPrivateChat']);
});

T::test('settings command updates the story chat flags, visible in the view', function () {
    [$g, $ps] = lobby('werewolf', ['villager' => 4, 'werewolf' => 1], 3);
    $new = ['name' => 'N', 'roles' => ['villager' => 4, 'werewolf' => 1], 'settings' => ['storyPublicChat' => true]];
    $g->command($ps[0]->id, ['t' => 'settings'] + $new);
    $v = $g->viewFor($ps[1]->id);
    T::same(true, $v['settings']['storyPublicChat']);
    T::same(false, $v['settings']['storyPrivateChat']);
});

T::test('werewolf role table: no doctor, ghost and paladin after the seer, witch acts in step 2', function () {
    $roles = Games::get('werewolf')['roles'];
    T::same(['villager', 'werewolf', 'seer', 'ghost', 'paladin', 'witch', 'hunter', 'cultist'], array_column($roles, 'id'));
    T::same(['ghost'], array_column(array_filter($roles, fn($r) => !empty($r['legacy'])), 'id'));
    $by = array_column($roles, null, 'id');
    T::same('guard', $by['paladin']['night']['kind']);
    T::same(2, $by['paladin']['night']['step']);
    T::same(['countTeam' => 'evil'], $by['paladin']['night']['limit']);
    T::same(3, $by['paladin']['max']);
    T::same(3, $by['ghost']['max']);
    T::same(0.5, $by['ghost']['haunt']['evilChance']);
    T::true(!isset($by['ghost']['night']));
    T::same(2, $by['witch']['night']['step']);
    $mafia = array_column(Games::get('mafia')['roles'], null, 'id');
    T::same('protect', $mafia['doctor']['night']['kind']);
    $list = array_column(Games::publicList(), null, 'id')['werewolf']['roles'];
    T::same(true, array_column($list, null, 'id')['ghost']['haunts']);
    T::same(true, array_column($list, null, 'id')['ghost']['legacy']);
    T::same(true, array_column($list, null, 'id')['paladin']['knowsEachOther']);
    T::same(['nonEvil' => 3], array_column($list, null, 'id')['werewolf']['cap']);
    T::same(2, array_column($list, null, 'id')['paladin']['nightStep']);
});

T::test('unknown role in a saved game does not break views', function () {
    [$g, $ps] = startedGame('werewolf', WW);
    $ps[2]->role = 'doctor';
    toNight($g, $ps);
    $v = $g->viewFor($ps[2]->id);
    T::same(null, $v['me']['action']);
    T::same('doctor', $v['me']['role']);
});

// --- private messages
T::test('dm: visible only to sender and recipient', function () {
    [$g, $ps] = startedGame('werewolf', WW);
    $g->command($ps[1]->id, ['t' => 'chat', 'text' => 'psst', 'to' => $ps[2]->id]);
    $g->command($ps[1]->id, ['t' => 'chat', 'text' => 'hello all']);
    T::same(2, count($g->viewFor($ps[1]->id)['chat']));
    T::same(2, count($g->viewFor($ps[2]->id)['chat']));
    T::same(1, count($g->viewFor($ps[3]->id)['chat']));
    T::same(1, count($g->viewFor($ps[0]->id)['chat']));
    $m = $g->viewFor($ps[2]->id)['chat'][0];
    T::same('dm', $m['ch']);
    T::same($ps[2]->id, $m['to']);
    T::same('discussion', $m['ph']);
    T::true(!str_contains(json_encode($g->viewFor($ps[3]->id)), 'psst'));
});

T::test('dm: rejected for self, unknown player, at night and between alive and dead', function () {
    [$g, $ps] = startedGame('werewolf', WW);
    $dm = fn($from, $to) => fn() => $g->command($from->id, ['t' => 'chat', 'text' => 'x', 'to' => $to]);
    T::throws($dm($ps[1], $ps[1]->id), '/private messages/');
    T::throws($dm($ps[1], 'pnope'), '/private messages/');
    T::throws(fn() => $g->command($ps[1]->id, ['t' => 'chat', 'text' => 'x', 'to' => ['a']]), '/Invalid target/');
    $ps[2]->alive = false;
    $ps[3]->alive = false;
    T::throws($dm($ps[1], $ps[2]->id), '/private messages/'); // alive -> dead
    T::throws($dm($ps[2], $ps[1]->id), '/private messages/'); // dead -> alive
    $g->command($ps[2]->id, ['t' => 'chat', 'text' => 'boo', 'to' => $ps[3]->id]); // dead <-> dead
    T::same('dm', $g->viewFor($ps[3]->id)['chat'][0]['ch']);
    T::same(0, count($g->viewFor($ps[1]->id)['chat']));
    toNight($g, $ps);
    T::throws($dm($ps[0], $ps[4]->id), '/private messages/'); // night
    T::throws($dm($ps[2], $ps[3]->id), '/private messages/');
    T::throws($dm($ps[4], $ps[1]->id), '/private messages/');
});

T::test('dm: allowed in the lobby and after the game, empty text ignored', function () {
    [$g, $ps] = lobby('werewolf', ['villager' => 4, 'werewolf' => 1], 3);
    $g->command($ps[0]->id, ['t' => 'chat', 'text' => 'hi', 'to' => $ps[1]->id]);
    T::same(1, count($g->viewFor($ps[1]->id)['chat']));
    T::same(0, count($g->viewFor($ps[2]->id)['chat']));
    $g->command($ps[0]->id, ['t' => 'chat', 'text' => '   ', 'to' => $ps[1]->id]);
    T::same(1, count($g->viewFor($ps[1]->id)['chat']));
    [$g, $ps] = startedGame('werewolf', WW);
    $g->phase = 'ended';
    $g->command($ps[1]->id, ['t' => 'chat', 'text' => 'gg', 'to' => $ps[0]->id]);
    T::same(1, count($g->viewFor($ps[0]->id)['chat']));
});

T::test('chat history: last 300 in views, 300 kept by default and 1000 with a story flag', function () {
    [$g, $ps] = startedGame('werewolf', WW);
    for ($i = 0; $i < 320; $i++) {
        $g->command($ps[1]->id, ['t' => 'chat', 'text' => "m$i"]);
    }
    T::same(300, count($g->chat));
    T::same(300, count($g->viewFor($ps[2]->id)['chat']));
    [$g, $ps] = startedGame('werewolf', WW, ['storyPublicChat' => true]);
    for ($i = 0; $i < 320; $i++) {
        $g->command($ps[1]->id, ['t' => 'chat', 'text' => "m$i"]);
    }
    T::same(320, count($g->chat));
    T::same(300, count($g->viewFor($ps[2]->id)['chat']));
    T::same('m319', end($g->viewFor($ps[2]->id)['chat'])['text']);
});

// --- story
/** 5 players, wolf p0; returns the game ended by a day-1 vote, with chat in every phase. */
function storyGame(array $settings): array
{
    [$g, $ps, $clock] = lobby('werewolf', ['villager' => 4, 'werewolf' => 1], 5, $settings);
    $g->command($ps[0]->id, ['t' => 'chat', 'text' => 'lobby talk']);
    $g->start($ps[0]->id);
    $g->players = $ps;
    foreach ($ps as $i => $p) {
        $p->role = $i === 0 ? 'werewolf' : 'villager';
    }
    $g->command($ps[1]->id, ['t' => 'chat', 'text' => 'day0 talk']);
    toNight($g, $ps);
    $g->command($ps[0]->id, ['t' => 'chat', 'text' => 'wolf plan']);
    $g->command($ps[0]->id, ['t' => 'skip']); // the wolves choose nobody
    $g->command($ps[2]->id, ['t' => 'chat', 'text' => 'secret dm', 'to' => $ps[3]->id]);
    $g->command($ps[2]->id, ['t' => 'chat', 'text' => 'day1 talk']);
    $g->command($ps[0]->id, ['t' => 'skip']);
    foreach ([1, 2, 3, 4] as $i) {
        $g->command($ps[$i]->id, ['t' => 'vote', 'target' => $ps[0]->id]);
    }
    $g->command($ps[0]->id, ['t' => 'vote', 'target' => null]);
    return [$g, $ps];
}

$storyShape = fn(Game $g, array $ps) => array_map(
    fn($e) => $e['type'] === 'chat' ? 'chat:' . $e['ch'] . ':' . $e['text'] : $e['type'],
    $g->viewFor($ps[1]->id)['log']
);

T::test('story: chat is interleaved at the right place when both flags are on', function () use ($storyShape) {
    [$g, $ps] = storyGame(['storyPublicChat' => true, 'storyPrivateChat' => true]);
    T::same('ended', $g->phase);
    $g->command($ps[1]->id, ['t' => 'chat', 'text' => 'gg']); // after the end: never in the story
    $shape = $storyShape($g, $ps);
    $chatOnly = array_values(array_filter($shape, fn($x) => str_starts_with($x, 'chat:')));
    T::same(['chat:all:day0 talk', 'chat:evil:wolf plan', 'chat:dm:secret dm', 'chat:all:day1 talk'], $chatOnly);
    // each message sits right before the log entry written after it
    $texts = array_map(fn($e) => $e['text'], $g->viewFor($ps[1]->id)['log']);
    $at = fn(string $t) => array_search($t, $texts, true);
    T::true($at('day0 talk') < $at('Night 1 falls on the village. Everyone closes their eyes…'));
    T::true($at('wolf plan') > $at('Night 1 falls on the village. Everyone closes their eyes…'));
    T::true($at('wolf plan') < $at('Nobody was killed during the night.'));
    T::true($at('secret dm') > $at('Day 1 breaks.'));
    T::true($at('day1 talk') > $at('secret dm'));
    T::true($at('day1 talk') < $at('Time to vote. Who should be eliminated?'));
    T::same(false, in_array('lobby talk', $texts, true));
    T::same(false, in_array('gg', $texts, true));
    $item = array_values(array_filter($g->viewFor($ps[1]->id)['log'], fn($e) => $e['text'] === 'secret dm'))[0];
    T::same($ps[2]->id, $item['from']);
    T::same($ps[3]->id, $item['to']);
    T::same('chat', $item['type']);
    T::same(1, $item['round']);
    T::true(!isset($item['cs']));
});

T::test('story: flag combinations', function () use ($storyShape) {
    foreach ([[false, false, []], [true, false, ['chat:all:day0 talk', 'chat:all:day1 talk']],
        [false, true, ['chat:evil:wolf plan', 'chat:dm:secret dm']]] as [$pub, $priv, $want]) {
        [$g, $ps] = storyGame(['storyPublicChat' => $pub, 'storyPrivateChat' => $priv]);
        T::same($want, array_values(array_filter($storyShape($g, $ps), fn($x) => str_starts_with($x, 'chat:'))));
    }
});

T::test('story: no chat in the log (or cs) before the game ends, even with flags on', function () {
    [$g, $ps] = startedGame('werewolf', WW, ['storyPublicChat' => true, 'storyPrivateChat' => true]);
    $g->command($ps[1]->id, ['t' => 'chat', 'text' => 'hello']);
    toNight($g, $ps);
    foreach ($g->viewFor($ps[1]->id)['log'] as $e) {
        T::true($e['type'] !== 'chat');
        T::true(!array_key_exists('cs', $e));
    }
    T::same(true, array_key_exists('cs', $g->log[0]));
    T::same(1, $g->log[1]['cs']);
});

T::test('story: old logs without cs/ph still load and render', function () {
    [$g, $ps] = storyGame(['storyPublicChat' => true, 'storyPrivateChat' => true]);
    $a = json_decode(json_encode($g->toArray()), true);
    foreach ($a['log'] as &$e) {
        unset($e['cs']);
    }
    unset($e);
    foreach ($a['chat'] as &$m) {
        unset($m['ph']);
    }
    unset($m);
    $old = Game::fromArray($a);
    $log = $old->viewFor($ps[1]->id)['log'];
    T::same(count($g->log), count(array_filter($log, fn($e) => $e['type'] !== 'chat')));
    T::true(count($log) > count($g->log));
    // and a game without marks/ghost/story keys at all
    unset($a['marks'], $a['settings']['storyPublicChat'], $a['settings']['storyPrivateChat']);
    $older = Game::fromArray($a);
    T::same(count($g->log), count($older->viewFor($ps[1]->id)['log']));
});

// --- paladin
const PAL = ['werewolf', 'paladin', 'villager', 'villager', 'villager', 'villager'];

T::test('paladin: acts in step 2, only when there is a victim and a charge', function () {
    [$g, $ps] = startedGame('werewolf', PAL);
    toNight($g, $ps);
    T::same([$ps[0]->id], $g->night['actors']);
    T::same(null, $g->viewFor($ps[1]->id)['me']['action']['done'] ?? null);
    killAct($g, $ps[0], $ps[2]);
    T::same(2, $g->night['step']);
    T::same([$ps[1]->id], $g->night['actors']);
    $a = $g->viewFor($ps[1]->id)['me']['action'];
    T::same('guard', $a['kind']);
    T::same('p2', $a['victim']);
    T::same(1, $a['usesLeft']);
    T::same(true, $a['canSave']);
    T::true(!isset($a['targets']));
    T::throws(fn() => killAct($g, $ps[0], $ps[3]), '/cannot act/');
    T::throws(fn() => act($g, $ps[1], []), '/Invalid choice/');
    T::throws(fn() => act($g, $ps[1], ['save' => 'yes']), '/Invalid choice/');
    T::throws(fn() => act($g, $ps[1], ['target' => $ps[2]->id]), '/Invalid choice/');

    [$g, $ps] = startedGame('werewolf', PAL);
    toNight($g, $ps);
    $g->command($ps[0]->id, ['t' => 'skip']); // no victim: the paladin has nothing to do
    T::same('discussion', $g->phase);
    T::same(1, $g->round);
});

T::test('paladin: saving prevents the death and spends the single charge', function () {
    [$g, $ps] = startedGame('werewolf', PAL);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[2]);
    act($g, $ps[1], ['save' => true]);
    T::same('discussion', $g->phase);
    T::true($ps[2]->alive);
    T::same('Nobody was killed during the night.', dawn($g));
    T::matches('/shielded p2/', $ps[1]->notes[0]['text']);
    T::same(1, $ps[1]->state['guardUsed']);
    nextNight($g, $ps);
    killAct($g, $ps[0], $ps[2]); // no charge left: no step 2
    T::same('discussion', $g->phase);
    T::same(false, $ps[2]->alive);
    T::true(!str_contains(json_encode($g->viewFor($ps[3]->id)), 'guardUsed'));
});

T::test('paladin: skipping keeps the charge; host skip of the step too', function () {
    [$g, $ps] = startedGame('werewolf', PAL, ['nightSeconds' => 0]);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[2]);
    act($g, $ps[1], ['save' => false]);
    T::same(false, $ps[2]->alive);
    T::same(null, $ps[1]->state['guardUsed'] ?? null);
    nextNight($g, $ps);
    killAct($g, $ps[0], $ps[3]);
    T::same([$ps[1]->id], $g->night['actors']);
    $g->command($ps[0]->id, ['t' => 'skip']); // host skips the paladin
    T::same(false, $ps[3]->alive);
    T::same(null, $ps[1]->state['guardUsed'] ?? null);
});

T::test('paladin: as many charges as werewolves in the game', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'werewolf', 'paladin', 'villager', 'villager', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    for ($n = 1; $n <= 3; $n++) {
        if ($n > 1) {
            nextNight($g, $ps);
        }
        killAct($g, [$ps[0], $ps[1]], $ps[3]);
        if ($n <= 2) {
            T::same([$ps[2]->id], $g->night['actors'], "charge available in night $n");
            act($g, $ps[2], ['save' => true]);
            T::true($ps[3]->alive);
        } else {
            T::same('discussion', $g->phase, 'no charge left');
            T::same(false, $ps[3]->alive);
        }
    }
    T::same(2, $ps[2]->state['guardUsed']);
});

T::test('paladin: does not stop poison; spends the charge even if the witch also heals', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'paladin', 'witch', 'villager', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[3]);
    T::same([$ps[1]->id, $ps[2]->id], $g->night['actors']);
    act($g, $ps[1], ['save' => true]);
    act($g, $ps[2], ['heal' => false, 'poison' => $ps[3]->id]);
    T::same(false, $ps[3]->alive); // saved from the wolves, killed by the poison
    T::same(1, $ps[1]->state['guardUsed']);

    [$g, $ps] = startedGame('werewolf', ['werewolf', 'paladin', 'witch', 'villager', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[3]);
    act($g, $ps[1], ['save' => true]);
    act($g, $ps[2], ['heal' => true]);
    T::true($ps[3]->alive);
    T::same(1, $ps[1]->state['guardUsed']);
    T::true(!empty($ps[2]->state['healUsed']));
});

// --- ghost
const GHOST = ['werewolf', 'ghost', 'villager', 'villager', 'villager', 'villager'];

T::test('ghost: killed by the wolves, becomes the step-3 actor and points at an evil player (good ghost)', function () {
    [$g, $ps] = startedGame('werewolf', GHOST); // rng 0.5 -> not < 0.5 -> good
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    T::same('night', $g->phase);
    T::same(3, $g->night['step']);
    T::same([$ps[1]->id], $g->night['actors']);
    T::true($ps[1]->alive); // still alive until dawn
    $a = $g->viewFor($ps[1]->id)['me']['action'];
    T::same('haunt', $a['kind']);
    T::same([$ps[0]->id], $a['targets']);
    T::same(false, $a['evil']);
    T::same(['evil' => false], $g->viewFor($ps[1]->id)['me']['ghost']);
    T::throws(fn() => act($g, $ps[1], ['target' => $ps[2]->id]), '/Invalid target/'); // good ghost: evil only
    T::throws(fn() => act($g, $ps[1], ['target' => $ps[1]->id]), '/Invalid target/');
    T::throws(fn() => killAct($g, $ps[0], $ps[2]), '/cannot act/');
    T::throws(fn() => act($g, $ps[2], ['target' => $ps[0]->id]), '/cannot act/');
    act($g, $ps[1], ['target' => $ps[0]->id]);
    T::same('discussion', $g->phase);
    T::same(false, $ps[1]->alive);
    T::same("p1 was found dead. Their ghost is now floating around p0, pointing a finger at them. They were a Ghost.", dawn($g));
    T::same([[$ps[0]->id, $ps[1]->id]], $g->marks);
    $row = array_values(array_filter($g->viewFor($ps[2]->id)['players'], fn($p) => $p['id'] === $ps[0]->id))[0];
    T::same($ps[1]->id, $row['markedBy']);
    T::same('selected by ghost p1', $row['markedText']);
    $other = array_values(array_filter($g->viewFor($ps[2]->id)['players'], fn($p) => $p['id'] === $ps[3]->id))[0];
    T::true(!isset($other['markedBy']));
    nextNight($g, $ps);
    T::same([], $g->marks);
    foreach ($g->viewFor($ps[2]->id)['players'] as $p) {
        T::true(!isset($p['markedBy']));
    }
});

T::test('ghost: evil ghost (fixed rng) points at a good player only', function () {
    [$g, $ps] = startedGame('werewolf', GHOST);
    setRng($g, 0.1);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    $a = $g->viewFor($ps[1]->id)['me']['action'];
    T::same(true, $a['evil']);
    T::same([$ps[2]->id, $ps[3]->id, $ps[4]->id, $ps[5]->id], $a['targets']);
    T::throws(fn() => act($g, $ps[1], ['target' => $ps[0]->id]), '/Invalid target/');
    T::matches('/turned you|death has turned/', $ps[1]->notes[0]['text']);
    act($g, $ps[1], ['target' => $ps[4]->id]);
    T::matches('/floating around p4/', dawn($g));
    T::same([[$ps[4]->id, $ps[1]->id]], $g->marks);
});

T::test('ghost: the alignment roll uses the evilChance from the role data', function () {
    foreach ([[0.49, true], [0.5, false], [0.99, false]] as [$r, $evil]) {
        [$g, $ps] = startedGame('werewolf', GHOST);
        setRng($g, $r);
        toNight($g, $ps);
        killAct($g, $ps[0], $ps[1]);
        T::same($evil, $g->viewFor($ps[1]->id)['me']['ghost']['evil']);
    }
});

T::test('ghost: a paladin save prevents the haunting', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'ghost', 'paladin', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    act($g, $ps[2], ['save' => true]);
    T::same('discussion', $g->phase);
    T::true($ps[1]->alive);
    T::true(empty($ps[1]->state['ghost']));
    T::same([], $g->marks);
    T::true(!isset($g->viewFor($ps[1]->id)['me']['ghost']));
});

T::test('ghost: poison, vote and the hunter shot do not create a ghost', function () {
    // poison
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'ghost', 'witch', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[3]);
    act($g, $ps[2], ['heal' => true, 'poison' => $ps[1]->id]);
    T::same('discussion', $g->phase);
    T::same(false, $ps[1]->alive);
    T::true(empty($ps[1]->state['ghost']));
    T::same('p1 was found dead. They were a Ghost.', dawn($g));
    // vote
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'ghost', 'villager', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    $g->command($ps[0]->id, ['t' => 'skip']);
    $g->command($ps[0]->id, ['t' => 'skip']);
    foreach ([0, 2, 3, 4, 5] as $i) {
        $g->command($ps[$i]->id, ['t' => 'vote', 'target' => $ps[1]->id]);
    }
    $g->command($ps[1]->id, ['t' => 'vote', 'target' => null]);
    T::same(false, $ps[1]->alive);
    T::true(empty($ps[1]->state['ghost']));
    T::same('night', $g->phase);
    // hunter shot
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'hunter', 'ghost', 'villager', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    T::same('hunter', $g->phase);
    act($g, $ps[1], ['target' => $ps[2]->id]);
    T::same(false, $ps[2]->alive);
    T::true(empty($ps[2]->state['ghost']));
    T::same('discussion', $g->phase);
});

T::test('ghost: sees every role, others do not; no hidden data leaks', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'ghost', 'seer', 'villager', 'villager', 'villager'], ['revealRoles' => false]);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    act($g, $ps[2], ['target' => $ps[3]->id]);
    T::same(3, $g->night['step']);
    $roles = fn(string $viewer) => array_column(array_filter($g->viewFor($viewer)['players'], fn($p) => isset($p['role'])), 'role', 'id');
    $seen = $roles($ps[1]->id);
    T::same(6, count($seen));
    T::same(['werewolf', 'seer', 'villager'], [$seen[$ps[0]->id], $seen[$ps[2]->id], $seen[$ps[3]->id]]);
    T::same(1, count($roles($ps[3]->id)));
    foreach ($ps as $p) {
        $json = json_encode($g->viewFor($p->id));
        T::true(!str_contains($json, 'evilChance'));
        T::true(!str_contains($json, 'guardUsed'));
        if ($p->id !== $ps[1]->id) {
            T::true(!str_contains($json, '"ghost":{'), 'ghost side is private');
            T::true(!array_key_exists('ghost', $g->viewFor($p->id)['me']));
        }
    }
    act($g, $ps[1], ['target' => null]); // pass
    T::same('discussion', $g->phase);
    T::same('p1 was found dead.', dawn($g)); // role stays hidden when nobody was pointed at
    T::same([], $g->marks);
    T::same(false, $ps[1]->alive);
    // a dead ghost keeps seeing the roles
    T::same(6, count($roles($ps[1]->id)));
    T::true(!array_key_exists('ghost', $g->viewFor($ps[3]->id)['me']));
});

T::test('ghost: pointing reveals the Ghost role even when roles are not revealed', function () {
    [$g, $ps] = startedGame('werewolf', GHOST, ['revealRoles' => false]);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    act($g, $ps[1], ['target' => $ps[0]->id]);
    T::matches('/pointing a finger at them\. They were a Ghost\.$/', dawn($g));
});

T::test('ghost: step 3 ends by timeout or host skip', function () {
    [$g, $ps, $clock] = startedGame('werewolf', GHOST);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    T::same('night', $g->phase);
    T::true($g->deadline !== null);
    $clock->ms += 46_000;
    $g->tick();
    T::same('discussion', $g->phase);
    T::same(false, $ps[1]->alive);
    T::same('p1 was found dead. They were a Ghost.', dawn($g));

    [$g, $ps] = startedGame('werewolf', GHOST);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    T::true($g->deadline !== null);
    $g->command($ps[0]->id, ['t' => 'skip']);
    T::same('discussion', $g->phase);
    T::same([], $g->marks);
});

T::test('ghost: marks and ghost state survive a JSON round trip; old saves default to no marks', function () {
    [$g, $ps] = startedGame('werewolf', GHOST);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    $mid = Game::fromArray(json_decode(json_encode($g->toArray()), true), ['rng' => fn() => 0.5, 'clock' => new Clock()]);
    T::same(['evil' => false], $mid->viewFor($ps[1]->id)['me']['ghost']);
    act($mid, $mid->player($ps[1]->id), ['target' => $ps[0]->id]);
    $copy = Game::fromArray(json_decode(json_encode($mid->toArray()), true));
    T::same($mid->marks, $copy->marks);
    T::same([[$ps[0]->id, $ps[1]->id]], $copy->marks);
    T::same($mid->viewFor($ps[2]->id)['players'], $copy->viewFor($ps[2]->id)['players']);
    $old = $mid->toArray();
    unset($old['marks']);
    T::same([], Game::fromArray($old)->marks);
});

T::test('ghost: with no valid target the ghost is not a step-3 actor', function () {
    [$g, $ps] = startedGame('werewolf', GHOST);
    setRng($g, 0.1); // evil ghost: needs a good target
    toNight($g, $ps);
    foreach ([2, 3, 4, 5] as $i) {
        $ps[$i]->alive = false;
    }
    killAct($g, $ps[0], $ps[1]);
    T::same('ended', $g->phase);
    T::same(false, $ps[1]->alive);
    T::same([], $g->marks);
});

T::test('old werewolf save with a doctor loads and renders views', function () {
    [$g, $ps] = startedGame('werewolf', WW);
    toNight($g, $ps);
    $a = json_decode(json_encode($g->toArray()), true);
    $a['players'][2]['role'] = 'doctor';
    $old = Game::fromArray($a, ['rng' => fn() => 0.5, 'clock' => new Clock()]);
    foreach ($old->players as $p) {
        $v = $old->viewFor($p->id);
        T::same('night', $v['phase']);
    }
    T::same(null, $old->viewFor($ps[2]->id)['me']['action']);
    killAct($old, $old->player($ps[0]->id), $old->player($ps[1]->id));
    act($old, $old->player($ps[4]->id), ['target' => $ps[0]->id]);
    T::same('discussion', $old->phase);
    $old->viewFor($ps[2]->id);
});

// ---------- setup caps (Werewolf), composition clamp ----------
function wwCfg(array $roles): array
{
    return Config::normalize(Games::get('werewolf'), ['name' => 'x', 'roles' => $roles]);
}

T::test('caps: villagers 3-20', function () {
    T::throws(fn() => wwCfg(['villager' => 2, 'werewolf' => 1, 'seer' => 1]), '/At least 3 Villager/');
    T::same(4, wwCfg(['villager' => 3, 'werewolf' => 1])['total']);
    T::same(21, wwCfg(['villager' => 20, 'werewolf' => 1])['total']);
    T::throws(fn() => wwCfg(['villager' => 21, 'werewolf' => 1]), '/At most 20 Villager/');
});

T::test('caps: werewolves are at most a third of the non-wolves (rounded up)', function () {
    T::same(6, wwCfg(['villager' => 3, 'werewolf' => 1, 'seer' => 1, 'witch' => 1])['roleCounts']['villager'] + 3);
    // 3 non-wolves -> 1 wolf, 4 -> 2, 6 -> 2, 7 -> 3, 9 -> 3, 10 -> 4
    foreach ([[3, 1], [4, 2], [6, 2], [7, 3], [9, 3], [10, 4]] as [$nonWolves, $max]) {
        T::same($max, wwCfg(['villager' => $nonWolves, 'werewolf' => $max])['roleCounts']['werewolf'], "ok at $nonWolves");
        T::throws(fn() => wwCfg(['villager' => $nonWolves, 'werewolf' => $max + 1]), '/At most ' . $max . ' Werewolf/');
    }
    // non-wolves means every non-evil card, not only villagers
    T::same(2, wwCfg(['villager' => 3, 'seer' => 1, 'werewolf' => 2])['roleCounts']['werewolf']);
    T::throws(fn() => wwCfg(['villager' => 3, 'werewolf' => 0]), '/At least 1/');
});

T::test('caps: seer 1; paladin and witch one per 10 non-wolves; hunter at most the wolves', function () {
    T::throws(fn() => wwCfg(['villager' => 5, 'werewolf' => 1, 'seer' => 2]), '/At most 1 Seer/');
    // 10 non-wolves -> 1 paladin / witch; 11 -> 2
    T::same(1, wwCfg(['villager' => 9, 'werewolf' => 1, 'paladin' => 1])['roleCounts']['paladin']);
    T::throws(fn() => wwCfg(['villager' => 8, 'werewolf' => 1, 'paladin' => 2]), '/At most 1 Paladin/');
    T::same(2, wwCfg(['villager' => 9, 'werewolf' => 1, 'paladin' => 2])['roleCounts']['paladin']);
    T::throws(fn() => wwCfg(['villager' => 8, 'werewolf' => 1, 'witch' => 2]), '/At most 1 Witch/');
    T::same(2, wwCfg(['villager' => 9, 'werewolf' => 1, 'witch' => 2])['roleCounts']['witch']);
    T::same(1, wwCfg(['villager' => 5, 'werewolf' => 1, 'hunter' => 1])['roleCounts']['hunter']);
    T::throws(fn() => wwCfg(['villager' => 5, 'werewolf' => 1, 'hunter' => 2]), '/At most 1 Hunter/');
    T::same(2, wwCfg(['villager' => 5, 'werewolf' => 2, 'hunter' => 2])['roleCounts']['hunter']);
    // lowering the wolves invalidates hunters: nothing is rewritten silently
    T::throws(fn() => wwCfg(['villager' => 5, 'werewolf' => 1, 'hunter' => 2]), '/Hunter/');
});

T::test('caps: Config::capFor is data driven; Mafia has no caps', function () {
    $def = Games::get('werewolf');
    $by = array_column($def['roles'], null, 'id');
    $counts = ['villager' => 7, 'werewolf' => 1, 'seer' => 1, 'witch' => 1];
    T::same(3, Config::capFor($by['werewolf'], $counts, $def)); // 9 non-wolves
    T::same(1, Config::capFor($by['witch'], $counts, $def));
    T::same(1, Config::capFor($by['hunter'], $counts, $def)); // as many as wolves
    T::same(5, Config::capFor($by['werewolf'], [], $def, 13)); // explicit non-evil count
    T::same(20, Config::capFor($by['villager'], $counts, $def));
    T::same(1, Config::capFor($by['seer'], $counts, $def));
    $m = Games::get('mafia');
    foreach ($m['roles'] as $r) {
        T::true(!isset($r['cap']));
    }
    T::same(30, Config::capFor($m['roles'][0], [], $m));
    T::same(7, Config::normalize($m, ['name' => 'x', 'roles' => ['citizen' => 4, 'mafioso' => 3]])['total']);
});

T::test('caps: the legacy Ghost card is ignored by the setup and hidden in the role list', function () {
    $cfg = wwCfg(['villager' => 4, 'werewolf' => 1, 'ghost' => 3]);
    T::same(5, $cfg['total']);
    T::true(!array_key_exists('ghost', $cfg['roleCounts']));
    $list = array_column(array_column(Games::publicList(), null, 'id')['werewolf']['roles'], null, 'id');
    T::same(true, $list['ghost']['legacy']);
    T::same(false, $list['seer']['legacy']);
    $spec = array_column(Games::publicList(), null, 'id')['werewolf']['ghost'];
    T::same(['name' => 'Ghost', 'icon' => '👻', 'min' => 0, 'max' => 10, 'def' => 0, 'cap' => ['count' => 'werewolf']],
        array_diff_key($spec, ['desc' => 1]));
    T::true($spec['desc'] !== '');
    T::same(null, array_column(Games::publicList(), null, 'id')['mafia']['ghost']);
});

T::test('composition clamps wolves and capped roles to the caps of the actual player count', function () {
    [$g] = lobby('werewolf', ['villager' => 9, 'werewolf' => 3, 'seer' => 1, 'hunter' => 1, 'witch' => 1], 0);
    $count = fn(array $cards, string $id) => count(array_filter($cards, fn($c) => $c === $id));
    foreach (range(4, 15) as $n) {
        $c = $g->composition($n);
        $w = $count($c['dealt'], 'werewolf');
        $nonWolves = $n - $w;
        T::same($n, count($c['dealt']), "n=$n");
        T::true($w >= 1 && $w <= (int)ceil($nonWolves / 3), "wolves $w for $n players");
        T::true($count($c['dealt'], 'witch') <= (int)ceil($nonWolves / 10));
        T::true($count($c['dealt'], 'hunter') <= $w);
        T::same(15, count($c['dealt']) + count($c['pool']), 'nothing is lost');
    }
    // a setup outside the caps (e.g. from an old save) is clamped for the table that actually plays:
    // 6 wolves configured; 8 players would get 3 by share but only 2 are allowed (5 non-wolves)
    $g->roleCounts = ['villager' => 8, 'werewolf' => 6, 'hunter' => 3, 'witch' => 2, 'seer' => 1];
    $g->total = 20;
    $c = $g->composition(8);
    $w = $count($c['dealt'], 'werewolf');
    T::same(8, count($c['dealt']));
    T::same(2, $w);
    T::same(6, $w + $count($c['pool'], 'werewolf'));
    T::true($count($c['dealt'], 'hunter') <= $w);
    T::true($count($c['dealt'], 'witch') <= 1);
    T::same(3, $count($c['dealt'], 'hunter') + $count($c['pool'], 'hunter'));
    // 12 wolves configured at a 4-seat table: still a legal table with one wolf
    $g->roleCounts = ['villager' => 3, 'werewolf' => 12, 'hunter' => 3];
    $g->total = 18;
    $c = $g->composition(4);
    T::same(4, count($c['dealt']));
    T::same(1, $count($c['dealt'], 'werewolf'));
    T::true($count($c['dealt'], 'hunter') <= 1);
});

T::test('composition: 2 paladins configured, only one dealt to a small table; the second waits in the pool', function () {
    [$g] = lobby('werewolf', ['villager' => 9, 'werewolf' => 1, 'paladin' => 2], 0);
    $c = $g->composition(8);
    T::same(1, count(array_filter($c['dealt'], fn($x) => $x === 'paladin')));
    T::same(1, count(array_filter($c['pool'], fn($x) => $x === 'paladin')));
    T::same(8, count($c['dealt']));
    $c = $g->composition(12);
    T::same(2, count(array_filter($c['dealt'], fn($x) => $x === 'paladin'))); // 11 non-wolves -> 2
});

// ---------- wolves: suggest + confirm ----------
const WW2 = ['werewolf', 'werewolf', 'villager', 'villager', 'villager', 'villager', 'seer'];

T::test('wolves: suggestions are visible to the team only; confirm needs agreement', function () {
    [$g, $ps] = startedGame('werewolf', WW2);
    toNight($g, $ps);
    T::same([$ps[0]->id, $ps[1]->id, $ps[6]->id], $g->night['actors']);
    act($g, $ps[0], ['target' => $ps[2]->id]);
    $a0 = $g->viewFor($ps[0]->id)['me']['action'];
    $a1 = $g->viewFor($ps[1]->id)['me']['action'];
    T::same([$ps[0]->id => $ps[2]->id], (array)$a1['teamChoices']);
    T::same(false, $a1['agreed']);
    T::same(null, $a1['agreedTarget']);
    T::same($ps[2]->id, $a0['mine']);
    T::same(null, $a1['mine']);
    T::throws(fn() => act($g, $ps[1], ['confirm' => true]), '/not agreed/');
    T::throws(fn() => act($g, $ps[0], ['confirm' => true]), '/not agreed/');
    act($g, $ps[1], ['target' => $ps[3]->id]); // disagreement
    T::same(false, $g->viewFor($ps[0]->id)['me']['action']['agreed']);
    T::throws(fn() => act($g, $ps[0], ['confirm' => true]), '/not agreed/');
    act($g, $ps[1], ['target' => $ps[2]->id]);
    $a0 = $g->viewFor($ps[0]->id)['me']['action'];
    T::same(true, $a0['agreed']);
    T::same($ps[2]->id, $a0['agreedTarget']);
    T::same(false, $a0['done']);
    act($g, $ps[0], ['confirm' => true]);
    T::same([$ps[0]->id], $g->viewFor($ps[1]->id)['me']['action']['confirmed']);
    T::same(true, $g->viewFor($ps[0]->id)['me']['action']['done']);
    T::same('night', $g->phase);
    T::same(1, $g->night['step']);
    T::true($ps[2]->alive);
    act($g, $ps[1], ['confirm' => true]);
    T::same(1, $g->night['step']); // the seer has not acted yet
    act($g, $ps[6], ['target' => $ps[0]->id]);
    T::same('discussion', $g->phase);
    T::same(false, $ps[2]->alive);
    T::true($ps[3]->alive);
});

T::test('wolves: a changed suggestion clears every confirmation', function () {
    [$g, $ps] = startedGame('werewolf', WW2);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[2]->id]);
    act($g, $ps[1], ['target' => $ps[2]->id]);
    act($g, $ps[0], ['confirm' => true]);
    T::same([$ps[0]->id], $g->viewFor($ps[0]->id)['me']['action']['confirmed']);
    act($g, $ps[1], ['target' => $ps[2]->id]); // same target again: nothing changes
    T::same([$ps[0]->id], $g->viewFor($ps[0]->id)['me']['action']['confirmed']);
    act($g, $ps[1], ['target' => $ps[4]->id]);
    $a = $g->viewFor($ps[0]->id)['me']['action'];
    T::same([], $a['confirmed']);
    T::same(false, $a['done']);
    T::same(false, $a['agreed']);
    T::true(!array_key_exists($ps[0]->id, $g->night['actions']));
    act($g, $ps[0], ['target' => $ps[4]->id]); // the first wolf follows the second
    act($g, $ps[0], ['confirm' => true]);
    act($g, $ps[1], ['confirm' => true]);
    act($g, $ps[6], ['target' => $ps[0]->id]);
    T::same(false, $ps[4]->alive);
    T::true($ps[2]->alive);
});

T::test('wolves: cannot pass, invalid targets and outsiders are refused', function () {
    [$g, $ps] = startedGame('werewolf', WW2);
    toNight($g, $ps);
    T::throws(fn() => act($g, $ps[0], ['target' => null]), '/cannot pass/');
    T::throws(fn() => act($g, $ps[0], []), '/cannot pass/');
    T::throws(fn() => act($g, $ps[0], ['target' => $ps[1]->id]), '/Invalid target/'); // a fellow wolf
    T::throws(fn() => act($g, $ps[0], ['target' => 'pnobody']), '/Invalid target/');
    T::throws(fn() => act($g, $ps[2], ['target' => $ps[3]->id]), '/cannot act/');
    T::throws(fn() => act($g, $ps[2], ['confirm' => true]), '/cannot act/');
    $v = $g->viewFor($ps[2]->id);
    T::same(null, $v['me']['action']);
    $seer = $g->viewFor($ps[6]->id)['me']['action'];
    T::true(!isset($seer['teamChoices']) && !isset($seer['agreed']));
});

T::test('wolves: suggestions never leak to non-wolves, dead wolves or the story', function () {
    [$g, $ps] = startedGame('werewolf', WW2);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[2]->id]);
    foreach ([2, 3, 4, 5, 6] as $i) {
        $json = json_encode($g->viewFor($ps[$i]->id));
        T::true(!str_contains($json, 'teamChoices'), "p$i");
        T::true(!str_contains($json, 'agreed'));
        T::true(!str_contains($json, '"suggest"'));
    }
    T::true(str_contains(json_encode($g->viewFor($ps[1]->id)), 'teamChoices'));
    killAct($g, [$ps[0], $ps[1]], $ps[2]);
    act($g, $ps[6], ['target' => $ps[0]->id]);
    $ps[1]->alive = false; // a dead wolf has no action at all
    T::same(null, $g->viewFor($ps[1]->id)['me']['action']);
    T::true(!str_contains(json_encode($g->viewFor($ps[3]->id)['log']), 'suggest'));
});

T::test('wolves: on timeout one of the distinct suggestions is picked at random; none = nobody dies', function () {
    foreach ([[0.0, 2], [0.99, 3]] as [$r, $dead]) {
        [$g, $ps, $clock] = startedGame('werewolf', WW2);
        setRng($g, $r);
        toNight($g, $ps);
        act($g, $ps[0], ['target' => $ps[2]->id]);
        act($g, $ps[1], ['target' => $ps[3]->id]);
        act($g, $ps[0], ['target' => $ps[2]->id]);
        $clock->ms += 61_000;
        $g->tick();
        T::same('discussion', $g->phase);
        T::same(false, $ps[$dead]->alive, "rng $r");
        T::same(6, count($g->alive()));
    }
    // agreement without confirmations, then the timer: the agreed player dies
    [$g, $ps, $clock] = startedGame('werewolf', WW2);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[4]->id]);
    act($g, $ps[1], ['target' => $ps[4]->id]);
    $clock->ms += 61_000;
    $g->tick();
    T::same(false, $ps[4]->alive);
    // nobody suggested anything
    [$g, $ps, $clock] = startedGame('werewolf', WW2);
    toNight($g, $ps);
    $clock->ms += 61_000;
    $g->tick();
    T::same('discussion', $g->phase);
    T::same(7, count($g->alive()));
    T::same('Nobody was killed during the night.', dawn($g));
    // a single suggestion by one wolf counts
    [$g, $ps] = startedGame('werewolf', WW2);
    toNight($g, $ps);
    act($g, $ps[1], ['target' => $ps[5]->id]);
    $g->command($ps[0]->id, ['t' => 'skip']);
    T::same(false, $ps[5]->alive);
});

T::test('wolves: mafia uses the same suggest + confirm, godfather included; blocked killers are ignored', function () {
    [$g, $ps] = startedGame('mafia', ['mafioso', 'godfather', 'citizen', 'citizen', 'citizen', 'citizen', 'citizen', 'escort']);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[2]->id]);
    T::same([$ps[0]->id => $ps[2]->id], (array)$g->viewFor($ps[1]->id)['me']['action']['teamChoices']);
    T::throws(fn() => act($g, $ps[1], ['target' => null]), '/cannot pass/');
    T::throws(fn() => act($g, $ps[1], ['confirm' => true]), '/not agreed/');
    act($g, $ps[1], ['target' => $ps[2]->id]);
    act($g, $ps[0], ['confirm' => true]);
    act($g, $ps[1], ['confirm' => true]);
    T::same('night', $g->phase); // the escort still has to act
    act($g, $ps[7], ['target' => $ps[1]->id]);
    T::same(false, $ps[2]->alive); // the mafioso's choice still counts (only the godfather was blocked)

    [$g, $ps] = startedGame('mafia', ['godfather', 'citizen', 'citizen', 'citizen', 'citizen', 'escort']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    act($g, $ps[5], ['target' => $ps[0]->id]);
    T::true($ps[1]->alive); // the only killer was blocked
    T::matches('/kept you busy/', $ps[0]->notes[0]['text']);
});

T::test('wolves: an old save in the middle of a night (plain targets, no suggestions) still resolves', function () {
    [$g, $ps, $clock] = startedGame('werewolf', WW);
    toNight($g, $ps);
    $a = json_decode(json_encode($g->toArray()), true);
    unset($a['night']['suggest']);
    $a['night']['actions'][$ps[0]->id] = ['target' => $ps[2]->id]; // the old format
    $old = Game::fromArray($a, ['rng' => fn() => 0.5, 'clock' => $clock]);
    $old->viewFor($ps[0]->id);
    act($old, $old->player($ps[4]->id), ['target' => $ps[0]->id]);
    $old->command($ps[0]->id, ['t' => 'skip']);
    T::same(false, $old->player($ps[2]->id)->alive);
});

// ---------- ghost trait ----------
function ghostHolders(Game $g): array
{
    return array_values(array_filter($g->players, fn($p) => !empty($p->state['ghostSlot'])));
}

T::test('ghost trait: the chosen number of random non-wolf players get a secret slot at start, never a wolf', function () {
    foreach ([[4, 1, 5, 1, 1], [5, 2, 7, 2, 2], [9, 3, 12, 2, 2], [9, 2, 11, 0, 0]] as [$villagers, $wolves, $n, $ghosts, $slots]) {
        foreach ([0.0, 0.3, 0.7, 0.99] as $r) {
            [$g, $ps] = lobby('werewolf', ['villager' => $villagers, 'werewolf' => $wolves], $n, [], $ghosts);
            setRng($g, $r);
            $total = $g->total;
            T::same($ghosts, $g->ghosts);
            $g->start($ps[0]->id);
            $h = ghostHolders($g);
            T::same($slots, count($h), "$n players rng $r");
            foreach ($h as $p) {
                T::same('villager', $p->role);
                T::matches('/restless spirit/', $p->notes[0]['text']);
            }
            T::same($total, $g->total, 'slots do not count as cards');
            T::same($n, count($g->players));
            foreach ($g->players as $p) {
                T::true(!str_contains(json_encode($g->viewFor($p->id)), 'ghostSlot'));
                if (empty($p->state['ghostSlot'])) {
                    T::same([], $p->notes);
                }
            }
        }
    }
});

T::test('ghost trait: the seer may carry it, wolves never', function () {
    $seen = false;
    foreach (range(0, 40) as $i) {
        [$g, $ps] = lobby('werewolf', ['villager' => 3, 'werewolf' => 1, 'seer' => 1], 5, [], 1);
        setRng($g, ($i % 10) / 10);
        $g->start($ps[0]->id);
        $h = ghostHolders($g);
        T::same(1, count($h));
        T::true($h[0]->role !== 'werewolf');
        $seen = $seen || $h[0]->role === 'seer';
    }
    T::true($seen, 'a seer can be chosen');
});

T::test('ghost trait: early start clamps the slots to the wolves actually dealt and to the candidates', function () {
    // 3 wolves, 3 ghosts configured, only 4 players: one wolf dealt -> one ghost
    [$g, $ps] = lobby('werewolf', ['villager' => 8, 'werewolf' => 3], 4, [], 3);
    $g->start($ps[0]->id);
    $wolves = count(array_filter($g->players, fn($p) => $p->role === 'werewolf'));
    T::same(1, $wolves);
    T::same(1, count(ghostHolders($g)));
    T::same(3, $g->ghosts, 'the setting itself is kept');
});

T::test('ghost trait: only the holder is told; mafia has no ghosts', function () {
    [$g, $ps] = lobby('werewolf', ['villager' => 6, 'werewolf' => 1], 7, [], 1);
    $g->start($ps[0]->id);
    $holders = ghostHolders($g);
    $other = array_values(array_filter($g->players, fn($p) => empty($p->state['ghostSlot'])))[0];
    T::true(str_contains(json_encode($g->viewFor($holders[0]->id)['me']['notes']), 'restless spirit'));
    T::true(!str_contains(json_encode($g->viewFor($other->id)), 'restless'));
    T::true(!str_contains(json_encode($g->viewFor($holders[0]->id)['players']), 'restless'));
    T::same(1, $g->viewFor($other->id)['ghosts'], 'the count is public, the holders are not');
    [$m, $mps] = lobby('mafia', ['citizen' => 5, 'mafioso' => 1], 6, [], 1);
    $m->start($mps[0]->id);
    T::same([], ghostHolders($m));
    T::same(0, $m->ghosts);
});

T::test('ghost trait: late joiners top the slots up to the setting, never beyond', function () {
    // 2 wolves, 2 ghosts; starts with 4 players (1 wolf dealt) -> 1 slot; a later wolf raises the allowance
    [$g, $ps] = lobby('werewolf', ['villager' => 7, 'werewolf' => 2, 'seer' => 1], 4, [], 2);
    $g->start($ps[0]->id);
    $wolves = fn() => count(array_filter($g->players, fn($p) => $p->role === 'werewolf'));
    T::same(min(2, $wolves()), count(ghostHolders($g)));
    $before = count(ghostHolders($g));
    foreach (['l1', 'l2', 'l3', 'l4', 'l5', 'l6'] as $name) {
        $g->addPlayer(['name' => $name]);
        T::true(count(ghostHolders($g)) <= 2);
        T::true(count(ghostHolders($g)) >= $before);
        T::same(min(2, $wolves()), count(ghostHolders($g)));
        $before = count(ghostHolders($g));
    }
    foreach (ghostHolders($g) as $p) {
        T::true($p->role !== 'werewolf');
        T::true($g->viewFor($p->id)['me']['notes'] !== []);
    }
});

T::test('ghost trait: an old save without the setting keeps its slots and assigns none', function () {
    [$g, $ps] = lobby('werewolf', ['villager' => 8, 'werewolf' => 1], 7, [], 1);
    $g->start($ps[0]->id);
    $a = json_decode(json_encode($g->toArray()), true);
    T::same(1, $a['ghosts']);
    unset($a['ghosts']);
    $old = Game::fromArray($a, ['rng' => fn() => 0.5, 'clock' => new Clock()]);
    T::same(null, $old->ghosts);
    T::same(1, count(ghostHolders($old)));
    $old->addPlayer(['name' => 'late']);
    T::same(1, count(ghostHolders($old)));
    // and a legacy save with no slots at all gets none
    foreach ($a['players'] as &$pl) {
        unset($pl['state']['ghostSlot']);
    }
    unset($pl);
    $none = Game::fromArray($a, ['rng' => fn() => 0.5, 'clock' => new Clock()]);
    $none->addPlayer(['name' => 'late']);
    T::same(0, count(ghostHolders($none)));
    // the round trip keeps the setting
    $again = Game::fromArray(json_decode(json_encode($g->toArray()), true), ['clock' => new Clock()]);
    T::same(1, $again->ghosts);
});

T::test('ghost trait: configure re-validates the count against the wolves', function () {
    [$g, $ps] = lobby('werewolf', ['villager' => 6, 'werewolf' => 2], 2, [], 2);
    $in = ['name' => 'x', 'roles' => ['villager' => 6, 'werewolf' => 1], 'ghosts' => 2];
    T::throws(fn() => $g->configure($ps[0]->id, $in), '/At most 1 Ghost/');
    T::same(2, $g->ghosts);
    $g->configure($ps[0]->id, ['ghosts' => 1] + $in);
    T::same(1, $g->ghosts);
    $g->configure($ps[0]->id, ['ghosts' => 0] + $in);
    T::same(0, $g->ghosts);
});

T::test('ghost trait: setup validation (negative, non-integer, above the wolves, default, mafia)', function () {
    $roles = ['villager' => 5, 'werewolf' => 2];
    $n = fn(array $extra) => Config::normalize(Games::get('werewolf'), ['name' => 'x', 'roles' => $roles] + $extra);
    T::same(0, $n([])['ghosts']);
    T::same(2, $n(['ghosts' => 2])['ghosts']);
    T::same(1, $n(['ghosts' => '1'])['ghosts']);
    T::same(7, $n(['ghosts' => 2])['total'], 'ghosts are not extra players');
    T::throws(fn() => $n(['ghosts' => -1]), '/Invalid count for Ghost/');
    T::throws(fn() => $n(['ghosts' => 'x']), '/Invalid count for Ghost/');
    T::throws(fn() => $n(['ghosts' => 1.5]), '/Invalid count for Ghost/');
    T::throws(fn() => $n(['ghosts' => 3]), '/At most 2 Ghost/');
    T::same(0, Config::normalize(Games::get('mafia'), ['name' => 'x', 'roles' => ['citizen' => 5, 'mafioso' => 1], 'ghosts' => 1])['ghosts']);
});

/** A started game where $slot players carry the ghost trait. */
function ghostGame(array $roles, array $slot, array $settings = []): array
{
    [$g, $ps, $clock] = startedGame('werewolf', $roles, $settings);
    foreach ($slot as $i) {
        $ps[$i]->state['ghostSlot'] = true;
    }
    return [$g, $ps, $clock];
}

T::test('ghost trait: a witch holder killed by the wolves, after acting, haunts in step 3 and the night ends', function () {
    [$g, $ps] = ghostGame(['werewolf', 'witch', 'villager', 'villager', 'villager', 'villager'], [1]);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]); // the witch is the victim
    act($g, $ps[1], ['poison' => $ps[2]->id]); // her step-2 action is spent when the ghost rises
    T::same('night', $g->phase);
    T::same(3, $g->night['step']);
    T::same([$ps[1]->id], $g->night['actors']);
    T::same('haunt', $g->viewFor($ps[1]->id)['me']['action']['kind']);
    act($g, $ps[1], ['target' => $ps[0]->id]);
    T::same('discussion', $g->phase);
    T::same(false, $ps[1]->alive);
    T::same(false, $ps[2]->alive); // the poison was applied before she died
    T::same([[$ps[0]->id, $ps[1]->id]], $g->marks);
});

T::test('ghost trait: a holder killed by the wolves rises (any role, seer included) and points a finger', function () {
    [$g, $ps] = ghostGame(WW, [4]); // the seer carries the trait
    toNight($g, $ps);
    act($g, $ps[4], ['target' => $ps[0]->id]); // the seer inspects the wolf first
    killAct($g, $ps[0], $ps[4]);
    T::same('night', $g->phase);
    T::same(3, $g->night['step']);
    T::same([$ps[4]->id], $g->night['actors']);
    T::true($ps[4]->alive);
    $a = $g->viewFor($ps[4]->id)['me']['action'];
    T::same('haunt', $a['kind']);
    T::same(false, $a['done']);
    T::same(false, $a['evil']);
    T::same([$ps[0]->id], $a['targets']);
    T::same('Your ghost rises. Point a finger at one player.', $a['prompt']);
    T::same(['evil' => false], $g->viewFor($ps[4]->id)['me']['ghost']);
    T::matches('/IS a werewolf/', $ps[4]->notes[0]['text']); // the Seer's night result is kept
    act($g, $ps[4], ['target' => $ps[0]->id]);
    T::same('discussion', $g->phase);
    T::same(false, $ps[4]->alive);
    T::matches('/p4 was found dead\. Their ghost is now floating around p0/', dawn($g));
    T::matches('/They were a Seer\.$/', dawn($g));
    T::same([[$ps[0]->id, $ps[4]->id]], $g->marks);
});

T::test('ghost trait: the holder is not revealed when roles are hidden and nobody is pointed at', function () {
    [$g, $ps] = ghostGame(WW, [4], ['revealRoles' => false]);
    toNight($g, $ps);
    act($g, $ps[4], ['target' => $ps[1]->id]);
    killAct($g, $ps[0], $ps[4]);
    act($g, $ps[4], ['target' => $ps[0]->id]);
    T::same("p4 was found dead. Their ghost is now floating around p0, pointing a finger at them.", dawn($g));
    [$g, $ps] = ghostGame(WW, [2], ['revealRoles' => false]);
    toNight($g, $ps);
    act($g, $ps[4], ['target' => $ps[1]->id]);
    killAct($g, $ps[0], $ps[2]);
    $g->command($ps[0]->id, ['t' => 'skip']); // the ghost points at nobody
    T::same('p2 was found dead.', dawn($g));
});

T::test('ghost trait: the evil roll uses the ghost data (chance 0.5) and an evil ghost points at a good player', function () {
    foreach ([[0.49, true], [0.5, false]] as [$r, $evil]) {
        [$g, $ps] = ghostGame(WW, [1]);
        setRng($g, $r);
        toNight($g, $ps);
        act($g, $ps[4], ['target' => $ps[2]->id]);
        killAct($g, $ps[0], $ps[1]);
        T::same($evil, $g->viewFor($ps[1]->id)['me']['ghost']['evil']);
        if ($evil) {
            T::throws(fn() => act($g, $ps[1], ['target' => $ps[0]->id]), '/Invalid target/');
            T::true(in_array($ps[2]->id, $g->viewFor($ps[1]->id)['me']['action']['targets'], true));
        }
    }
});

T::test('ghost trait: night deaths raise a ghost, but not when a paladin or the witch prevents the death', function () {
    // saved by a paladin
    [$g, $ps] = ghostGame(['werewolf', 'paladin', 'villager', 'villager', 'villager', 'villager'], [2]);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[2]);
    act($g, $ps[1], ['save' => true]);
    T::true($ps[2]->alive);
    T::same('discussion', $g->phase);
    T::true(!isset($g->viewFor($ps[2]->id)['me']['ghost']));
    // healed by the witch
    [$g, $ps] = ghostGame(['werewolf', 'witch', 'villager', 'villager', 'villager', 'villager'], [2]);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[2]);
    act($g, $ps[1], ['heal' => true]);
    T::true($ps[2]->alive);
    T::true(empty($ps[2]->state['ghost']));
});

T::test('ghost trait: a poisoned holder rises too and points at a werewolf (good ghost)', function () {
    [$g, $ps] = ghostGame(['werewolf', 'witch', 'villager', 'villager', 'villager', 'villager'], [3]);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[2]);
    act($g, $ps[1], ['heal' => false, 'poison' => $ps[3]->id]);
    T::same(3, $g->night['step']);
    T::same(['evil' => false], $g->viewFor($ps[3]->id)['me']['ghost']);
    act($g, $ps[3], ['target' => $ps[0]->id]);
    T::same('discussion', $g->phase);
    T::false($ps[3]->alive);
    T::false($ps[2]->alive);
    T::matches('/Their ghost is now floating around p0/', $g->log[count($g->log) - 3]['text'] . ' ' . $g->log[count($g->log) - 2]['text']);
    T::same([[$ps[0]->id, $ps[3]->id]], $g->marks);
});

T::test('ghost trait: the targets follow the side (good ghost: wolves, evil ghost: good non-wolves) and one pick only', function () {
    foreach ([[0.9, false], [0.1, true]] as [$r, $evil]) {
        [$g, $ps] = ghostGame(WW, [4]);
        setRng($g, (float)$r);
        toNight($g, $ps);
        act($g, $ps[4], ['target' => $ps[2]->id]);
        killAct($g, $ps[0], $ps[4]);
        T::same($evil, $g->viewFor($ps[4]->id)['me']['ghost']['evil']);
        $targets = $g->viewFor($ps[4]->id)['me']['action']['targets'];
        $wolves = [$ps[0]->id];
        foreach ($targets as $t) {
            T::same(!$evil, in_array($t, $wolves, true));
        }
        T::true($targets !== []);
        act($g, $ps[4], ['target' => $targets[0]]);
        T::same(1, count($g->marks));
    }
});

T::test('ghost trait: a holder who dies by vote still counts, a later top-up never exceeds the total', function () {
    [$g, $ps] = lobby('werewolf', ['villager' => 7, 'werewolf' => 2, 'seer' => 1], 8, [], 1);
    $g->start($ps[0]->id);
    $h = ghostHolders($g);
    T::same(1, count($h));
    $h[0]->alive = false; // eliminated (vote)
    $g->addPlayer(['name' => 'late']);
    T::same(1, count(ghostHolders($g)), 'no replacement slot for a dead holder');
});

T::test('ghost trait: view exposes the ghosts total as int and never the slot', function () {
    [$g, $ps] = ghostGame(['werewolf', 'villager', 'villager', 'villager', 'villager', 'villager'], [1]);
    foreach ($ps as $p) {
        $v = $g->viewFor($p->id);
        T::true(is_int($v['ghosts']));
        T::true(!str_contains(json_encode($v), 'ghostSlot'));
    }
});

T::test('ghost trait: a vote or a hunter shot never raises a ghost', function () {
    // voted out
    [$g, $ps] = ghostGame(['werewolf', 'villager', 'villager', 'villager', 'villager', 'villager'], [1]);
    toNight($g, $ps);
    $g->command($ps[0]->id, ['t' => 'skip']);
    $g->command($ps[0]->id, ['t' => 'skip']);
    foreach ([0, 2, 3, 4, 5] as $i) {
        $g->command($ps[$i]->id, ['t' => 'vote', 'target' => $ps[1]->id]);
    }
    $g->command($ps[1]->id, ['t' => 'vote', 'target' => null]);
    T::false($ps[1]->alive);
    T::true(empty($ps[1]->state['ghost']));
    T::same('night', $g->phase);
    T::same([], $g->marks);
    // shot by a hunter
    [$g, $ps] = ghostGame(['werewolf', 'hunter', 'villager', 'villager', 'villager', 'villager'], [2]);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    act($g, $ps[1], ['target' => $ps[2]->id]);
    T::false($ps[2]->alive);
    T::true(empty($ps[2]->state['ghost']));
    T::same('discussion', $g->phase);
});

T::test('ghost trait: a ghost is raised only once', function () {
    [$g, $ps] = ghostGame(WW, [4]);
    $m = new ReflectionMethod(Game::class, 'raiseGhost');
    T::true($m->invoke($g, $ps[4], ['evilChance' => 0.5]));
    T::false($m->invoke($g, $ps[4], ['evilChance' => 0.5]));
});

T::test('ghost trait: a legacy Ghost role in an old save still works, and the trait adds nothing for it', function () {
    [$g, $ps] = startedGame('werewolf', GHOST);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    T::same(3, $g->night['step']);
    T::same('Your ghost rises. Point a finger at one player.', $g->viewFor($ps[1]->id)['me']['action']['prompt']);
    // a save written by the previous version: ghost role cards, no slots, timer off
    $a = json_decode(json_encode($g->toArray()), true);
    $a['settings']['nightSeconds'] = 0;
    $old = Game::fromArray($a, ['rng' => fn() => 0.5, 'clock' => new Clock()]);
    act($old, $old->player($ps[1]->id), ['target' => $ps[0]->id]);
    T::matches('/They were a Ghost\.$/', dawn($old));
});

// ---------- paladins ----------
const PAL2 = ['werewolf', 'werewolf', 'paladin', 'paladin', 'villager', 'villager', 'villager', 'villager', 'villager', 'villager', 'villager', 'villager', 'villager'];

T::test('paladins: know each other, see each other\'s choices, nobody else does', function () {
    [$g, $ps] = startedGame('werewolf', PAL2);
    $v = $g->viewFor($ps[2]->id);
    T::same([$ps[3]->id], $v['me']['mates']);
    T::same([$ps[2]->id], $g->viewFor($ps[3]->id)['me']['mates']);
    $roleIn = fn(array $v, string $id) => array_values(array_filter($v['players'], fn($p) => $p['id'] === $id))[0]['role'] ?? null;
    T::same('paladin', $roleIn($v, $ps[3]->id));
    T::same(null, $roleIn($v, $ps[4]->id));
    T::same(null, $roleIn($g->viewFor($ps[4]->id), $ps[2]->id));
    T::same(null, $roleIn($g->viewFor($ps[0]->id), $ps[2]->id)); // the wolves do not know them
    foreach ([0, 1, 4] as $i) {
        T::true(!array_key_exists('mates', $g->viewFor($ps[$i]->id)['me']), "p$i");
    }
    toNight($g, $ps);
    killAct($g, [$ps[0], $ps[1]], $ps[5]);
    T::same([$ps[2]->id, $ps[3]->id], $g->night['actors']);
    T::same([], (array)$g->viewFor($ps[3]->id)['me']['action']['teamChoices']);
    act($g, $ps[2], ['save' => true]);
    T::same([$ps[2]->id => true], (array)$g->viewFor($ps[3]->id)['me']['action']['teamChoices']);
    T::same([], (array)$g->viewFor($ps[2]->id)['me']['action']['teamChoices']);
    foreach ([0, 4, 5] as $i) {
        $json = json_encode($g->viewFor($ps[$i]->id));
        T::true(!str_contains($json, 'teamChoices'), "p$i");
    }
});

T::test('paladins: the shield applies only if every living paladin saves; one shared charge is spent', function () {
    [$g, $ps] = startedGame('werewolf', PAL2);
    toNight($g, $ps);
    // night 1: both save -> shielded, one charge (2 wolves = 2 charges for everybody)
    killAct($g, [$ps[0], $ps[1]], $ps[5]);
    T::same(2, $g->viewFor($ps[2]->id)['me']['action']['usesLeft']);
    act($g, $ps[2], ['save' => true]);
    T::same('night', $g->phase);
    act($g, $ps[3], ['save' => true]);
    T::same('discussion', $g->phase);
    T::true($ps[5]->alive);
    T::same(1, ($ps[2]->state['guardUsed'] ?? 0) + ($ps[3]->state['guardUsed'] ?? 0));
    T::matches('/You and your fellow paladins shielded p5/', $ps[2]->notes[0]['text']);
    T::matches('/You and your fellow paladins shielded p5/', $ps[3]->notes[0]['text']);
    // night 2: only one saves -> no shield, no charge spent
    nextNight($g, $ps);
    killAct($g, [$ps[0], $ps[1]], $ps[5]);
    T::same(1, $g->viewFor($ps[3]->id)['me']['action']['usesLeft']); // the stock is shared
    T::same(1, $g->viewFor($ps[2]->id)['me']['action']['usesLeft']);
    act($g, $ps[2], ['save' => true]);
    act($g, $ps[3], ['save' => false]);
    T::same(false, $ps[5]->alive);
    T::same(1, ($ps[2]->state['guardUsed'] ?? 0) + ($ps[3]->state['guardUsed'] ?? 0));
    // night 3: both save again -> the last charge
    nextNight($g, $ps);
    killAct($g, [$ps[0], $ps[1]], $ps[6]);
    act($g, $ps[2], ['save' => true]);
    act($g, $ps[3], ['save' => true]);
    T::true($ps[6]->alive);
    T::same(2, ($ps[2]->state['guardUsed'] ?? 0) + ($ps[3]->state['guardUsed'] ?? 0));
    // night 4: no charge left, no paladin step
    nextNight($g, $ps);
    killAct($g, [$ps[0], $ps[1]], $ps[6]);
    T::same('discussion', $g->phase);
    T::same(false, $ps[6]->alive);
});

T::test('paladins: a timeout with one paladin missing means no shield; a dead paladin does not count', function () {
    [$g, $ps, $clock] = startedGame('werewolf', PAL2);
    toNight($g, $ps);
    killAct($g, [$ps[0], $ps[1]], $ps[5]);
    act($g, $ps[2], ['save' => true]);
    $clock->ms += 61_000;
    $g->tick();
    T::same('discussion', $g->phase);
    T::same(false, $ps[5]->alive);
    T::same(0, ($ps[2]->state['guardUsed'] ?? 0) + ($ps[3]->state['guardUsed'] ?? 0));

    [$g, $ps] = startedGame('werewolf', PAL2);
    $ps[3]->alive = false;
    toNight($g, $ps);
    killAct($g, [$ps[0], $ps[1]], $ps[5]);
    T::same([$ps[2]->id], $g->night['actors']);
    act($g, $ps[2], ['save' => true]);
    T::true($ps[5]->alive);
    T::same(1, $g->viewFor($ps[2]->id)['me']['action']['usesLeft'] ?? 1);
    T::matches('/^You shielded p5/', $ps[2]->notes[0]['text']);
});

// ---------- witches ----------
const WIT2 = ['werewolf', 'witch', 'witch', 'villager', 'villager', 'villager', 'villager', 'villager', 'villager', 'villager', 'villager', 'villager'];

T::test('witches: each has her own potions and acts independently', function () {
    [$g, $ps] = startedGame('werewolf', WIT2);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[3]);
    T::same([$ps[1]->id, $ps[2]->id], $g->night['actors']);
    foreach ([1, 2] as $i) {
        $a = $g->viewFor($ps[$i]->id)['me']['action'];
        T::same('p3', $a['victim']);
        T::true($a['canHeal'] && $a['canPoison']);
    }
    act($g, $ps[1], ['heal' => true]);
    T::same('night', $g->phase);
    act($g, $ps[2], ['heal' => false, 'poison' => $ps[5]->id]);
    T::true($ps[3]->alive);          // healed
    T::same(false, $ps[5]->alive);   // poisoned by the other witch
    T::true(!empty($ps[1]->state['healUsed']) && empty($ps[1]->state['poisonUsed']));
    T::true(empty($ps[2]->state['healUsed']) && !empty($ps[2]->state['poisonUsed']));
    // next night: the first witch still has her poison, the second her heal
    nextNight($g, $ps);
    killAct($g, $ps[0], $ps[4]);
    $a1 = $g->viewFor($ps[1]->id)['me']['action'];
    $a2 = $g->viewFor($ps[2]->id)['me']['action'];
    T::same([false, true], [$a1['canHeal'], $a1['canPoison']]);
    T::same([true, false], [$a2['canHeal'], $a2['canPoison']]);
});

T::test('witches: a cure and a poison on the wolf victim - the victim dies', function () {
    [$g, $ps] = startedGame('werewolf', WIT2);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[3]);
    act($g, $ps[1], ['heal' => true]);
    act($g, $ps[2], ['poison' => $ps[3]->id]);
    T::same(false, $ps[3]->alive);
    T::same('discussion', $g->phase);
    T::matches('/p3 was found dead/', dawn($g));
});

T::test('witches: a cure only protects the wolf victim - a poisoned bystander dies even if another witch heals', function () {
    // The heal potion has no target: it always heals the werewolves' victim. A witch therefore cannot
    // "cure" a player that the wolves did not attack, so a poison on a non-victim always kills.
    [$g, $ps] = startedGame('werewolf', WIT2);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[3]);
    act($g, $ps[1], ['heal' => true]);
    act($g, $ps[2], ['poison' => $ps[4]->id]);
    T::true($ps[3]->alive);
    T::same(false, $ps[4]->alive);
});

T::test('witches: both may heal (both potions are spent), or do nothing and keep everything', function () {
    [$g, $ps] = startedGame('werewolf', WIT2);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[3]);
    act($g, $ps[1], ['heal' => true]);
    act($g, $ps[2], ['heal' => true]);
    T::true($ps[3]->alive);
    T::true(!empty($ps[1]->state['healUsed']) && !empty($ps[2]->state['healUsed']));

    [$g, $ps] = startedGame('werewolf', WIT2);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[3]);
    act($g, $ps[1], ['heal' => false, 'poison' => null]); // explicitly nothing
    $g->command($ps[0]->id, ['t' => 'skip']);            // the other witch never answers
    T::same(false, $ps[3]->alive);
    foreach ([1, 2] as $i) {
        T::true(empty($ps[$i]->state['healUsed']) && empty($ps[$i]->state['poisonUsed']), "witch $i keeps both potions");
    }
    nextNight($g, $ps);
    killAct($g, $ps[0], $ps[4]);
    T::same([$ps[1]->id, $ps[2]->id], $g->night['actors']);
});

T::test('witches: invalid potion use is refused per witch', function () {
    [$g, $ps] = startedGame('werewolf', WIT2);
    toNight($g, $ps);
    T::throws(fn() => act($g, $ps[1], ['heal' => true]), '/cannot act/'); // step 1: not her turn
    killAct($g, $ps[0], $ps[3]);
    T::throws(fn() => act($g, $ps[1], ['poison' => $ps[1]->id]), '/cannot poison/');
    T::throws(fn() => act($g, $ps[1], ['poison' => 'pnobody']), '/cannot poison/');
    act($g, $ps[1], ['heal' => true]);
    act($g, $ps[2], ['heal' => false]);
    nextNight($g, $ps);
    killAct($g, $ps[0], $ps[4]);
    T::throws(fn() => act($g, $ps[1], ['heal' => true]), '/cannot heal/'); // her potion is gone, the other witch's is not
    act($g, $ps[2], ['heal' => true]);
    T::true($ps[4]->alive);
});

// ---------- hunters shooting hunters ----------
const HUN2 = ['werewolf', 'werewolf', 'hunter', 'hunter', 'villager', 'villager', 'villager', 'villager'];

function logTexts(Game $g, string $viewer): array
{
    return array_map(fn($e) => $e['text'], $g->viewFor($viewer)['log']);
}

/** Night 1 of HUN2: the wolves kill hunter A (p2); A shoots hunter B (p3). */
function hunterChain(): array
{
    [$g, $ps, $clock] = startedGame('werewolf', HUN2);
    toNight($g, $ps);
    killAct($g, [$ps[0], $ps[1]], $ps[2]);
    act($g, $ps[2], ['target' => $ps[3]->id]);
    return [$g, $ps, $clock];
}

const SHOT_AB = 'p2 fired a last shot and killed p3. They were a Hunter.';

T::test('hunters: hunter A shoots hunter B - the line is withheld until B has shot', function () {
    [$g, $ps] = hunterChain();
    T::same('hunter', $g->phase);
    T::same($ps[3]->id, $g->hunter); // B shoots now, without knowing who killed him
    T::same(false, $ps[3]->alive);
    T::same(1, count($g->heldLog));
    foreach ($ps as $p) {
        $json = json_encode($g->viewFor($p->id));
        T::true(!str_contains($json, 'fired a last shot'), $p->name);
        T::true(!str_contains($json, 'heldLog'));
        T::true(!str_contains($json, 'p2 fired'));
    }
    $texts = logTexts($g, $ps[4]->id);
    T::true(in_array('p3 is a Hunter! They take a last shot…', $texts, true));
    T::true(!in_array(SHOT_AB, $texts, true));
    act($g, $ps[3], ['target' => $ps[4]->id]);
    T::same([], $g->heldLog);
    T::true(in_array(SHOT_AB, logTexts($g, $ps[5]->id), true));
});

T::test('hunters: the chain keeps the order of the shots in the log', function () {
    [$g, $ps] = hunterChain();
    act($g, $ps[3], ['target' => $ps[4]->id]);
    $texts = logTexts($g, $ps[5]->id);
    $shots = array_values(array_filter($texts, fn($t) => str_contains($t, 'fired a last shot')));
    T::same([SHOT_AB, 'p3 fired a last shot and killed p4. They were a Villager.'], $shots);
    T::true(array_search($shots[0], $texts, true) < array_search($shots[1], $texts, true));
    $announce = array_search('p3 is a Hunter! They take a last shot…', $texts, true);
    T::true($announce < array_search($shots[0], $texts, true), 'the withheld line comes after B was announced');
    T::same('discussion', $g->phase);
});

T::test('hunters: a hunter who does not shoot (skip) still flushes the withheld line; it survives a save', function () {
    [$g, $ps, $clock] = hunterChain();
    $copy = Game::fromArray(json_decode(json_encode($g->toArray()), true), ['rng' => fn() => 0.5, 'clock' => $clock]);
    T::same($g->heldLog, $copy->heldLog);
    T::true(!str_contains(json_encode($copy->viewFor($ps[4]->id)), 'fired a last shot'));
    $copy->command($ps[0]->id, ['t' => 'skip']);
    $texts = logTexts($copy, $ps[4]->id);
    $i = array_search(SHOT_AB, $texts, true);
    $j = array_search('p3 did not shoot anyone.', $texts, true);
    T::true($i !== false && $j !== false && $i < $j);
    T::same([], $copy->heldLog);
});

T::test('hunters: a hunter killing a non-hunter logs the shot at once; a held line is flushed before the game ends', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'hunter', 'villager', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    act($g, $ps[1], ['target' => $ps[2]->id]);
    T::same([], $g->heldLog);
    T::true(in_array('p1 fired a last shot and killed p2. They were a Villager.', logTexts($g, $ps[3]->id), true));
    // A kills B, B kills the last wolf: the game ends with both shots in the story, in order
    [$g, $ps] = startedGame('werewolf', HUN2);
    $ps[1]->alive = false;
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[2]);
    act($g, $ps[2], ['target' => $ps[3]->id]);
    act($g, $ps[3], ['target' => $ps[0]->id]);
    T::same('ended', $g->phase);
    T::same('good', $g->winner);
    T::same([], $g->heldLog);
    $texts = logTexts($g, $ps[4]->id);
    $shots = array_values(array_filter($texts, fn($t) => str_contains($t, 'fired a last shot')));
    T::same(2, count($shots));
    T::same(SHOT_AB, $shots[0]);
    T::matches('/^p3 fired a last shot and killed p0\./', $shots[1]);
});

// ---------- Cultist (Werewolf) and Corrupt (Mafia) ----------
const CULT6 = ['werewolf', 'cultist', 'villager', 'villager', 'villager', 'villager'];

/** Skip night steps until it is day (nobody acts further). */
function skipNight(Game $g, Player $host): void
{
    for ($i = 0; $i < 6 && $g->phase === 'night'; $i++) {
        $g->command($host->id, ['t' => 'skip']);
    }
}

/** Day -> vote in which everybody else votes for $target; he votes for the first other player. */
function voteOut(Game $g, array $ps, Player $target): void
{
    for ($i = 0; $i < 8 && $g->phase !== 'vote'; $i++) {
        $g->command($ps[0]->id, ['t' => 'skip']); // day 0 / night steps / discussion -> vote
    }
    $other = null;
    foreach ($ps as $p) {
        if ($p->alive && $p->id !== $target->id) {
            $other ??= $p;
        }
    }
    foreach ($ps as $p) {
        if ($p->alive && $g->phase === 'vote') {
            $g->command($p->id, ['t' => 'vote', 'target' => $p->id === $target->id ? $other->id : $target->id]);
        }
    }
}

function noteTexts(Player $p): array
{
    return array_column($p->notes, 'text');
}

T::test('cultist: the werewolves attack him and he turns into a werewolf instead of dying', function () {
    [$g, $ps] = startedGame('werewolf', CULT6);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    T::same('discussion', $g->phase);
    T::true($ps[1]->alive);
    T::same('werewolf', $ps[1]->role);
    T::same('cultist', $ps[1]->state['was']);
    T::same('Nobody was killed during the night.', dawn($g));
    T::same(1, count($ps[1]->notes));
    T::matches('/you are now a Werewolf/', noteTexts($ps[1])[0]);
    T::matches('/p1 was a Cultist/', noteTexts($ps[0])[0]);
    T::same([], $ps[2]->notes); // villagers learn nothing
    T::same([], array_filter($g->log, fn($e) => $e['type'] === 'death'));
    T::same('werewolf', $g->viewFor($ps[1]->id)['me']['role']);
    T::same('evil', $g->viewFor($ps[1]->id)['me']['team']);
});

T::test('cultist: a normal victim still dies; an unattacked cultist stays a cultist', function () {
    [$g, $ps] = startedGame('werewolf', CULT6);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[2]);
    T::false($ps[2]->alive);
    T::same('cultist', $ps[1]->role);
    T::same([], $ps[1]->notes);
});

T::test('cultist: same-night poison and wolf kill cancel each other: he turns and nobody dies', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'cultist', 'witch', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    act($g, $ps[2], ['poison' => $ps[1]->id]);
    T::same('discussion', $g->phase);
    T::true($ps[1]->alive);
    T::same('werewolf', $ps[1]->role);
    T::same('Nobody was killed during the night.', dawn($g));
    T::true(empty($ps[2]->state['poisonUsed']), 'the voided poison is not spent');
    T::matches('/not poisoned/', noteTexts($ps[2])[0]);
    T::true(!str_contains(implode(' ', noteTexts($ps[2])), 'You poisoned'));
    // and she can still use it the next night
    nextNight($g, $ps);
    killAct($g, [$ps[0], $ps[1]], $ps[3]);
    act($g, $ps[2], ['poison' => $ps[4]->id]);
    T::false($ps[4]->alive);
    T::true(!empty($ps[2]->state['poisonUsed']));
});

T::test('cultist: if something else kills him the same night (vigilante-style shot) he dies and does not turn', function () {
    [$g, $ps] = startedGame('mafia', ['mafioso', 'vigilante', 'citizen', 'citizen', 'citizen']);
    // Werewolf has no shooter: use a custom convertible role through the data of the game under test
    $def = new ReflectionProperty(Game::class, 'def');
    $d = $def->getValue($g);
    foreach ($d['roles'] as &$r) {
        if ($r['id'] === 'citizen') {
            $r['convert'] = ['by' => 'kill', 'into' => 'mafioso'];
        }
    }
    unset($r);
    $def->setValue($g, $d);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[2]);
    act($g, $ps[1], ['target' => $ps[2]->id]);
    T::false($ps[2]->alive);
    T::same('citizen', $ps[2]->role);
    T::same([], $ps[0]->notes);
    // a poison on the victim is spent when he is not turned
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'cultist', 'witch', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[3]);
    act($g, $ps[2], ['poison' => $ps[1]->id]);
    T::true(!empty($ps[2]->state['poisonUsed']));
});

T::test('cultist: a converted player does not read the pack chat written before he turned', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'werewolf', 'cultist', 'villager', 'villager', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    $g->command($ps[0]->id, ['t' => 'chat', 'text' => 'secret plan']);
    T::same(1, count(array_filter($g->viewFor($ps[1]->id)['chat'], fn($m) => $m['ch'] === 'evil')));
    killAct($g, [$ps[0], $ps[1]], $ps[2]);
    T::same('werewolf', $ps[2]->role);
    nextNight($g, $ps);
    T::same([], array_filter($g->viewFor($ps[2]->id)['chat'], fn($m) => $m['ch'] === 'evil'));
    $g->command($ps[0]->id, ['t' => 'chat', 'text' => 'welcome']);
    $seen = array_values(array_filter($g->viewFor($ps[2]->id)['chat'], fn($m) => $m['ch'] === 'evil'));
    T::same(['welcome'], array_column($seen, 'text'));
    T::same(2, count(array_filter($g->viewFor($ps[0]->id)['chat'], fn($m) => $m['ch'] === 'evil')));
});

T::test('composition: neutral cards never break the real good > evil guarantee (early start and late join)', function () {
    $roles = ['citizen' => 1, 'mafioso' => 2, 'corrupt' => 1, 'detective' => 1, 'doctor' => 1];
    [$g] = lobby('mafia', $roles, 6);
    for ($n = 4; $n <= 6; $n++) {
        $comp = $g->composition($n);
        T::same($n, count($comp['dealt']));
        $team = fn($id) => Games::get('mafia')['roles'][array_search($id, array_column(Games::get('mafia')['roles'], 'id'), true)]['team'];
        $evil = count(array_filter($comp['dealt'], fn($id) => $team($id) === 'evil'));
        $good = count(array_filter($comp['dealt'], fn($id) => $team($id) === 'good'));
        T::true($good > $evil, "n=$n good $good evil $evil");
    }
    // late joiner: no neutral card when good would not outnumber evil
    [$g, $ps] = startedGame('mafia', ['mafioso', 'citizen', 'citizen', 'citizen']);
    $ps[1]->role = 'mafioso'; // 2 evil vs 2 good
    $g->total++;
    $g->pool = ['corrupt'];
    $late = $g->addPlayer(['name' => 'late']);
    T::same('citizen', $late->role);
    // with real good ahead the corrupt may be drawn
    [$g, $ps] = startedGame('mafia', ['mafioso', 'citizen', 'citizen', 'citizen']);
    $g->total++;
    $g->pool = ['corrupt'];
    T::same('corrupt', $g->addPlayer(['name' => 'late'])->role);
});

T::test('cultist: a poison alone kills him, and he does not turn', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'cultist', 'witch', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[3]);
    act($g, $ps[2], ['poison' => $ps[1]->id]);
    T::false($ps[1]->alive);
    T::same('cultist', $ps[1]->role);
});

T::test('cultist: healed or shielded, the attack is void and he does not turn', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'cultist', 'witch', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    act($g, $ps[2], ['heal' => true, 'poison' => null]);
    T::true($ps[1]->alive);
    T::same('cultist', $ps[1]->role);
    T::same([], $ps[1]->notes);
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'cultist', 'paladin', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    act($g, $ps[2], ['save' => true]);
    T::true($ps[1]->alive);
    T::same('cultist', $ps[1]->role);
    T::same([], $ps[1]->notes);
});

T::test('cultist: the Seer sees a villager before and on the conversion night, a werewolf afterwards', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'cultist', 'seer', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    act($g, $ps[2], ['target' => $ps[1]->id]);
    killAct($g, $ps[0], $ps[1]);
    T::same('werewolf', $ps[1]->role);
    T::same('p1 is NOT a werewolf.', noteTexts($ps[2])[0]);
    nextNight($g, $ps);
    act($g, $ps[2], ['target' => $ps[1]->id]);
    killAct($g, [$ps[0], $ps[1]], $ps[3]);
    T::same('p1 IS a werewolf.', noteTexts($ps[2])[1]);
    $g2 = startedGame('werewolf', ['werewolf', 'cultist', 'seer', 'villager', 'villager', 'villager'])[0];
    T::same('good', $g2->teamOf($g2->players[1]));
});

T::test('cultist: ghosts treat him as good before and as evil after he turns', function () {
    foreach ([true, false] as $turned) {
        [$g, $ps] = ghostGame(['werewolf', 'cultist', 'witch', 'villager', 'villager', 'villager'], [3]);
        setRng($g, 0.9); // a ghost that stays good: it points at evil players
        toNight($g, $ps);
        if ($turned) {
            killAct($g, $ps[0], $ps[1]);
            act($g, $ps[2], ['poison' => $ps[3]->id]); // the ghost holder dies of poison, the cultist turns
        } else {
            killAct($g, $ps[0], $ps[3]);
            act($g, $ps[2], ['poison' => null]);
        }
        T::same('night', $g->phase);
        $targets = $g->viewFor($ps[3]->id)['me']['action']['targets'];
        T::same($turned, in_array($ps[1]->id, $targets, true));
        T::true(in_array($ps[0]->id, $targets, true));
    }
});

T::test('cultist: ghostSlot is cleared when he turns', function () {
    [$g, $ps] = ghostGame(CULT6, [1]);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    T::true(!isset($ps[1]->state['ghostSlot']));
    T::same('cultist', $ps[1]->state['was']);
});

T::test('cultist: paladin charges are counted by dealt role and do not change when he turns', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'cultist', 'paladin', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    T::same(1, $g->viewFor($ps[2]->id)['me']['action']['usesLeft']);
    act($g, $ps[2], ['save' => false]);
    T::same('werewolf', $ps[1]->role);
    nextNight($g, $ps);
    killAct($g, [$ps[0], $ps[1]], $ps[3]);
    T::same(1, $g->viewFor($ps[2]->id)['me']['action']['usesLeft']);
});

T::test('cultist: once turned he kills with the pack, chats at night and sees the wolves', function () {
    [$g, $ps] = startedGame('werewolf', CULT6);
    T::true(!isset($g->viewFor($ps[0]->id)['players'][1]['role']));
    T::true(!isset($g->viewFor($ps[1]->id)['players'][0]['role']));
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    nextNight($g, $ps);
    T::same([$ps[0]->id, $ps[1]->id], $g->night['actors']);
    T::same('kill', $g->viewFor($ps[1]->id)['me']['action']['kind']);
    $g->command($ps[1]->id, ['t' => 'chat', 'text' => 'hi pack']);
    T::same('evil', end($g->chat)['ch']);
    T::same(1, count(array_filter($g->viewFor($ps[0]->id)['chat'], fn($m) => $m['ch'] === 'evil')));
    T::same(0, count(array_filter($g->viewFor($ps[2]->id)['chat'], fn($m) => $m['ch'] === 'evil')));
    T::same('werewolf', $g->viewFor($ps[0]->id)['players'][1]['role']);
    T::same('werewolf', $g->viewFor($ps[1]->id)['players'][0]['role']);
    // suggest + confirm: both must agree
    act($g, $ps[0], ['target' => $ps[2]->id]);
    act($g, $ps[1], ['target' => $ps[3]->id]);
    T::throws(fn() => act($g, $ps[0], ['confirm' => true]), '/not agreed/');
    act($g, $ps[1], ['target' => $ps[2]->id]);
    killAct($g, [$ps[0], $ps[1]], $ps[2]);
    T::false($ps[2]->alive);
});

T::test('cultist: he cannot be targeted by the wolves as a teammate once turned', function () {
    [$g, $ps] = startedGame('werewolf', CULT6);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    nextNight($g, $ps);
    T::throws(fn() => act($g, $ps[0], ['target' => $ps[1]->id]), '/Invalid target/');
});

T::test('cultist: the last wolf dying with an unturned cultist is a good win, and he loses', function () {
    [$g, $ps] = startedGame('werewolf', CULT6);
    T::same('discussion', $g->phase);
    voteOut($g, $ps, $ps[0]);
    T::same('ended', $g->phase);
    T::same('good', $g->winner);
    $v = $g->viewFor($ps[2]->id);
    T::same(true, $v['me']['won']);
    T::same(false, $g->viewFor($ps[1]->id)['me']['won']);
    $won = array_column($v['players'], 'won', 'id');
    T::same(false, $won[$ps[0]->id]);
    T::same(false, $won[$ps[1]->id]);
    T::same(true, $won[$ps[2]->id]);
});

T::test('cultist: won/me.won exist only when the game has ended', function () {
    [$g, $ps] = startedGame('werewolf', CULT6);
    $v = $g->viewFor($ps[1]->id);
    T::true(!array_key_exists('won', $v['me']));
    T::true(!array_key_exists('won', $v['players'][0]));
});

T::test('cultist: evil wins with an unturned cultist and with a turned one: both won', function () {
    [$g, $ps] = startedGame('werewolf', CULT6);
    $ps[4]->alive = false;
    $ps[5]->alive = false;
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[2]);
    voteOut($g, $ps, $ps[3]);
    T::same('evil', $g->winner);
    T::same(true, $g->viewFor($ps[1]->id)['me']['won']);
    T::same(true, $g->viewFor($ps[0]->id)['me']['won']);
    T::same(false, $g->viewFor($ps[3]->id)['me']['won']);
    // turned
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'cultist', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    voteOut($g, $ps, $ps[2]);
    T::same('evil', $g->winner);
    T::same(true, $g->viewFor($ps[1]->id)['me']['won']);
    T::same(false, $g->viewFor($ps[3]->id)['me']['won']);
});

T::test('cultist: a dead cultist keeps his side: he wins with the werewolves', function () {
    [$g, $ps] = startedGame('werewolf', CULT6);
    voteOut($g, $ps, $ps[1]); // the unturned cultist is voted out
    T::false($ps[1]->alive);
    T::same('night', $g->phase);
    $ps[5]->alive = false;
    $ps[4]->alive = false;
    killAct($g, $ps[0], $ps[2]); // wolf + 1 villager left: evil wins
    T::same('ended', $g->phase);
    T::same('evil', $g->winner);
    T::same(true, $g->viewFor($ps[1]->id)['me']['won']);
    T::same(false, $g->viewFor($ps[3]->id)['me']['won']);
});

T::test('cultist: cap follows the number of werewolves in setup, composition and late draw', function () {
    T::throws(fn() => wwCfg(['villager' => 5, 'werewolf' => 1, 'cultist' => 2]), '/At most 1 Cultist/');
    T::same(2, wwCfg(['villager' => 5, 'werewolf' => 2, 'cultist' => 2])['roleCounts']['cultist']);
    T::same(0, wwCfg(['villager' => 5, 'werewolf' => 1])['roleCounts']['cultist']);
    [$g, $ps] = lobby('werewolf', ['villager' => 5, 'werewolf' => 2, 'cultist' => 2], 5);
    $comp = $g->composition(5); // one werewolf dealt: at most one cultist
    T::true(count(array_keys($comp['dealt'], 'cultist', true)) <= 1);
    T::same(2, count(array_keys(array_merge($comp['dealt'], $comp['pool']), 'cultist', true)));
    // a late joiner cannot draw a cultist beyond the cap
    [$g, $ps] = startedGame('werewolf', CULT6);
    $g->total++;
    $g->pool = ['cultist'];
    $late = $g->addPlayer(['name' => 'late']);
    T::same('villager', $late->role);
    T::same(['cultist'], $g->pool);
});

T::test('cultist: new state keys survive a save/load round trip', function () {
    [$g, $ps] = startedGame('werewolf', CULT6);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    $copy = Game::fromArray(json_decode(json_encode($g->toArray()), true), ['rng' => fn() => 0.5]);
    $c = $copy->player($ps[1]->id);
    T::same('werewolf', $c->role);
    T::same('cultist', $c->state['was']);
    T::same('evil', $copy->sideOf($c));
    T::same('evil', $copy->viewFor($c->id)['me']['team']);
});

T::test('cultist: Games::publicList exposes allegiance, goal and convert', function () {
    $by = [];
    foreach (Games::publicList() as $d) {
        if ($d['engine'] !== 'classic') {
            continue;
        }
        $by[$d['id']] = array_column($d['roles'], null, 'id');
    }
    T::same('evil', $by['werewolf']['cultist']['allegiance']);
    T::same(true, $by['werewolf']['cultist']['convert']);
    T::same(false, $by['werewolf']['seer']['convert']);
    T::same('choose', $by['mafia']['corrupt']['allegiance']);
    T::same('neutral', $by['mafia']['corrupt']['team']);
    T::true(is_string($by['mafia']['corrupt']['goal']));
    T::true(isset(Games::get('mafia')['teams']['neutral']['name']));
});

// ---- Corrupt ----
const COR5 = ['mafioso', 'corrupt', 'citizen', 'citizen', 'citizen'];

T::test('corrupt: choosing a side works in discussion, vote, night and hunter phases', function () {
    foreach (['discussion', 'vote', 'night', 'hunter'] as $phase) {
        [$g, $ps] = startedGame('mafia', COR5);
        if ($phase === 'vote') {
            $g->command($ps[0]->id, ['t' => 'skip']);
            $g->command($ps[0]->id, ['t' => 'skip']);
            skipNight($g, $ps[0]);
            $g->command($ps[0]->id, ['t' => 'skip']);
        } elseif ($phase === 'night') {
            toNight($g, $ps);
        } elseif ($phase === 'hunter') {
            $g->phase = 'hunter';
        }
        T::same($phase, $g->phase);
        $g->command($ps[1]->id, ['t' => 'side', 'side' => 'evil']);
        T::same('evil', $ps[1]->state['side']);
        T::same('evil', $g->viewFor($ps[1]->id)['me']['side']);
        T::same(1, count($ps[1]->notes));
    }
});

T::test('corrupt: invalid side requests are rejected', function () {
    [$g, $ps] = startedGame('mafia', COR5);
    foreach (['neutral', 'town', '', null, ['good']] as $bad) {
        T::throws(fn() => $g->command($ps[1]->id, ['t' => 'side', 'side' => $bad]), '/Invalid side/');
    }
    T::throws(fn() => $g->command($ps[1]->id, ['t' => 'side']), '/Invalid side/');
    T::throws(fn() => $g->command($ps[2]->id, ['t' => 'side', 'side' => 'good']), '/no side to choose/');
    T::throws(fn() => $g->command($ps[0]->id, ['t' => 'side', 'side' => 'good']), '/no side to choose/');
    $g->command($ps[1]->id, ['t' => 'side', 'side' => 'good']);
    T::throws(fn() => $g->command($ps[1]->id, ['t' => 'side', 'side' => 'evil']), '/already chose a side/');
    T::same('good', $ps[1]->state['side']);
    // dead
    [$g, $ps] = startedGame('mafia', COR5);
    $ps[1]->alive = false;
    T::throws(fn() => $g->command($ps[1]->id, ['t' => 'side', 'side' => 'good']), '/out of the game/');
    // lobby and ended
    [$g, $ps] = lobby('mafia', ['citizen' => 3, 'mafioso' => 1, 'corrupt' => 1], 5);
    T::throws(fn() => $g->command($ps[1]->id, ['t' => 'side', 'side' => 'good']), '/cannot choose a side/');
    [$g, $ps] = startedGame('mafia', COR5);
    $g->phase = 'ended';
    T::throws(fn() => $g->command($ps[1]->id, ['t' => 'side', 'side' => 'good']), '/cannot choose a side/');
});

T::test('corrupt: wins with the chosen side and loses when he never chose', function () {
    foreach ([['good', true], ['evil', false], [null, false]] as [$side, $wins]) { // town wins: mafioso voted out
        [$g, $ps] = startedGame('mafia', COR5);
        if ($side) {
            $g->command($ps[1]->id, ['t' => 'side', 'side' => $side]);
        }
        voteOut($g, $ps, $ps[0]);
        T::same('good', $g->winner);
        T::same($wins, $g->viewFor($ps[1]->id)['me']['won'], (string)$side);
        T::same(true, $g->viewFor($ps[2]->id)['me']['won']);
        T::same(false, $g->viewFor($ps[0]->id)['me']['won']);
    }
    foreach ([['evil', true], ['good', false], [null, false]] as [$side, $wins]) { // mafia wins
        [$g, $ps] = startedGame('mafia', ['mafioso', 'corrupt', 'citizen', 'citizen']);
        if ($side) {
            $g->command($ps[1]->id, ['t' => 'side', 'side' => $side]);
        }
        toNight($g, $ps);
        killAct($g, $ps[0], $ps[2]);
        T::same('evil', $g->winner);
        T::same($wins, $g->viewFor($ps[1]->id)['me']['won'], (string)$side);
        T::same(true, $g->viewFor($ps[0]->id)['me']['won']);
        T::same(false, $g->viewFor($ps[3]->id)['me']['won']);
    }
});

T::test('corrupt: neutral is outside the parity count (evil1 good1 corrupt1 -> evil wins; evil0 -> good wins)', function () {
    [$g, $ps] = startedGame('mafia', ['mafioso', 'corrupt', 'citizen', 'citizen']);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[2]);
    T::same('ended', $g->phase);
    T::same('evil', $g->winner);
    [$g, $ps] = startedGame('mafia', COR5);
    $ps[2]->alive = false; // evil 1, good 2, corrupt 1: the game goes on
    voteOut($g, $ps, $ps[3]); // evil 1, good 1: evil wins, the corrupt is not counted
    T::same('evil', $g->winner);
    [$g, $ps] = startedGame('mafia', COR5);
    voteOut($g, $ps, $ps[0]); // evil 0 with the corrupt alive: the town wins
    T::same('good', $g->winner);
});

T::test('corrupt: the side is private to him; the roles he and the others know are mutual', function () {
    $roles = ['mafioso', 'godfather', 'corrupt', 'citizen', 'citizen', 'citizen', 'detective', 'vigilante', 'doctor', 'escort'];
    [$g, $ps] = startedGame('mafia', $roles);
    $g->command($ps[2]->id, ['t' => 'side', 'side' => 'good']);
    $seen = fn(int $viewer, int $of) => $g->viewFor($ps[$viewer]->id)['players'][$of]['role'] ?? null;
    foreach ([0, 1, 6, 7] as $i) { // mafioso, godfather, detective, vigilante
        T::same('corrupt', $seen($i, 2), "role $i sees the corrupt");
        T::same($roles[$i], $seen(2, $i), "the corrupt sees role $i");
    }
    foreach ([3, 8, 9] as $i) { // citizen, doctor, escort
        T::same(null, $seen($i, 2));
        T::same(null, $seen(2, $i));
    }
    // the chosen side is not in anybody else's view
    foreach ([0, 3, 6, 8] as $i) {
        T::true(!array_key_exists('side', $g->viewFor($ps[$i]->id)['me']));
        T::true(!str_contains(json_encode($g->viewFor($ps[$i]->id)), '"side"'));
    }
    T::same('good', $g->viewFor($ps[2]->id)['me']['side']);
    // undecided: side is null for him
    [$g, $ps] = startedGame('mafia', COR5);
    T::same(null, $g->viewFor($ps[1]->id)['me']['side']);
});

T::test('corrupt: setup counts leave neutral out of both teams', function () {
    $def = Games::get('mafia');
    T::throws(fn() => Config::normalize($def, ['name' => 'x', 'roles' => ['citizen' => 2, 'mafioso' => 2, 'corrupt' => 1]]), '/must outnumber/');
    T::throws(fn() => Config::normalize($def, ['name' => 'x', 'roles' => ['citizen' => 3, 'corrupt' => 1]]), '/At least one Mafia/');
    $cfg = Config::normalize($def, ['name' => 'x', 'roles' => ['citizen' => 3, 'mafioso' => 1, 'corrupt' => 1]]);
    T::same(1, $cfg['roleCounts']['corrupt']);
    T::same(5, $cfg['total']);
    T::throws(fn() => Config::normalize($def, ['name' => 'x', 'roles' => ['citizen' => 3, 'mafioso' => 1, 'corrupt' => 2]]), '/At most 1 Corrupt/');
});

T::test('rules html mentions the neutral side and the turned cultist', function () {
    $h = Wm\Rules::html(false);
    T::true(str_contains($h, 'Cultist') && str_contains($h, 'Corrupt'));
    T::true(str_contains($h, 'neutral, wins with the side it chooses'));
    T::true(str_contains($h, 'good, wins with the Werewolves team'));
    T::true(str_contains($h, 'hunters and cultists is limited'));
});
