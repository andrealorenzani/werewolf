<?php
declare(strict_types=1);

use Wm\Config;
use Wm\Game;
use Wm\Games;

const WW = ['werewolf', 'villager', 'villager', 'villager', 'seer', 'villager'];

T::test('config rejects unbalanced or tiny games', function () {
    $def = Games::get('werewolf');
    T::throws(fn() => Config::normalize($def, ['name' => 'x', 'roles' => ['villager' => 1, 'werewolf' => 1]]), '/At least 4/');
    T::throws(fn() => Config::normalize($def, ['name' => 'x', 'roles' => ['villager' => 2, 'werewolf' => 2]]), '/outnumber/');
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
    [$g, $ps, $clock] = startedGame('werewolf', WW, ['discussionSeconds' => 20, 'nightSeconds' => 20]);
    $g->tick();
    T::same('discussion', $g->phase);
    $clock->ms += 21_000;
    $g->tick();
    T::same('night', $g->phase);
    $clock->ms += 21_000;
    $g->tick(); // step 1 expires -> step 2 (nobody) -> resolved
    T::same('discussion', $g->phase);
    T::same(1, $g->round);
    T::same(6, count($g->alive()));
});

T::test('werewolf kills; night ends when all actors acted', function () {
    [$g, $ps] = startedGame('werewolf', WW);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[1]->id]);
    T::same('night', $g->phase);
    act($g, $ps[4], ['target' => $ps[0]->id]); // seer inspects wolf
    T::same('discussion', $g->phase);
    T::same(false, $ps[1]->alive);
    T::matches('/IS a werewolf/', $ps[4]->notes[0]['text']);
});

T::test('mafia: doctor protection saves the victim', function () {
    [$g, $ps] = startedGame('mafia', ['mafioso', 'citizen', 'citizen', 'citizen', 'detective', 'doctor']);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[1]->id]);
    act($g, $ps[4], ['target' => $ps[2]->id]);
    act($g, $ps[5], ['target' => $ps[1]->id]);
    T::true($ps[1]->alive);
    T::matches('/saved/', $ps[5]->notes[0]['text']);
});

T::test('night actions are validated', function () {
    [$g, $ps] = startedGame('werewolf', WW);
    toNight($g, $ps);
    T::throws(fn() => act($g, $ps[1], ['target' => $ps[2]->id]), '/cannot act/');
    T::throws(fn() => act($g, $ps[0], ['target' => $ps[0]->id]), '/Invalid/');
    T::throws(fn() => act($g, $ps[4], ['target' => $ps[4]->id]), '/Invalid/');
});

T::test('vote tie eliminates nobody', function () {
    [$g, $ps] = startedGame('werewolf', WW);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[1]->id]);
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
    act($g, $ps[0], ['target' => $ps[1]->id]);
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
    act($g, $ps[0], ['target' => $ps[1]->id]);
    T::same('discussion', $g->phase); // 1 wolf vs 3
    $g->command($ps[0]->id, ['t' => 'skip']);
    foreach ([2, 3, 4] as $i) {
        $g->command($ps[$i]->id, ['t' => 'vote', 'target' => $i === 2 ? $ps[3]->id : $ps[2]->id]);
    }
    $g->command($ps[0]->id, ['t' => 'vote', 'target' => $ps[2]->id]);
    T::same('night', $g->phase); // ps[2] eliminated: 1 wolf vs 2
    act($g, $ps[0], ['target' => $ps[3]->id]);
    T::same('ended', $g->phase);
    T::same('evil', $g->winner);
});

T::test('hunter shoots on death, then the game continues', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'hunter', 'villager', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[1]->id]);
    T::same('hunter', $g->phase);
    T::throws(fn() => act($g, $ps[2], ['target' => $ps[0]->id]), '/not your turn/');
    act($g, $ps[1], ['target' => $ps[0]->id]);
    T::same('ended', $g->phase);
    T::same('good', $g->winner);
});

T::test('hunter phase times out without a shot', function () {
    [$g, $ps, $clock] = startedGame('werewolf', ['werewolf', 'hunter', 'villager', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[1]->id]);
    $clock->ms += 60_000;
    $g->tick();
    T::same('discussion', $g->phase);
    T::true($ps[0]->alive);
});

T::test('witch heals the victim and can poison', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'witch', 'villager', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[2]->id]);
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
    act($g, $ps[0], ['target' => $ps[3]->id]);
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
    act($g, $ps[0], ['target' => $ps[2]->id]);
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
    act($g, $ps[0], ['target' => $ps[1]->id]);
    $copy = Game::fromArray(json_decode(json_encode($g->toArray()), true), ['rng' => fn() => 0.5]);
    T::same($g->viewFor($ps[4]->id)['me']['action'], $copy->viewFor($ps[4]->id)['me']['action']);
    act($copy, $copy->player($ps[4]->id), ['target' => $ps[0]->id]);
    T::same('discussion', $copy->phase);
});

// ---------- optional timers, flexible start, late join, settings, profile ----------
function lobby(string $type, array $roles, int $joined, array $settings = []): array
{
    $cfg = Config::normalize(Games::get($type), ['name' => 'L', 'roles' => $roles, 'settings' => $settings]);
    $clock = new Clock();
    $g = Game::create('TESTBB', $type, $cfg, ['rng' => fn() => 0.5, 'clock' => $clock]);
    $ps = [];
    for ($i = 0; $i < $joined; $i++) {
        $ps[] = $g->addPlayer(['name' => "p$i"]);
    }
    return [$g, $ps, $clock];
}

T::test('timers default to off and set no deadline', function () {
    $cfg = Config::normalize(Games::get('werewolf'), ['name' => 'x', 'roles' => ['villager' => 4, 'werewolf' => 1]]);
    T::same(0, $cfg['settings']['discussionSeconds']);
    T::same(0, $cfg['settings']['voteSeconds']);
    T::same(0, $cfg['settings']['nightSeconds']);
    [$g, $ps] = lobby('werewolf', ['villager' => 4, 'werewolf' => 1], 5);
    $g->start($ps[0]->id);
    T::same(null, $g->deadline);
    toNight($g, $ps);
    T::same(null, $g->deadline);
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

T::test('no timers: host can skip night steps and the hunter shot', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'witch', 'villager', 'villager', 'villager', 'villager'], ['discussionSeconds' => 0, 'nightSeconds' => 0, 'voteSeconds' => 0]);
    T::same(null, $g->deadline);
    toNight($g, $ps);
    T::throws(fn() => $g->command($ps[2]->id, ['t' => 'skip']), '/host/');
    act($g, $ps[0], ['target' => $ps[2]->id]);
    T::same(2, $g->night['step']);
    T::same(null, $g->deadline); // step 2 does not arm a timer
    $g->command($ps[0]->id, ['t' => 'skip']); // witch missing action = skip
    T::same('discussion', $g->phase);
    T::same(false, $ps[2]->alive);

    [$g, $ps] = startedGame('werewolf', ['werewolf', 'hunter', 'villager', 'villager', 'villager', 'villager'], ['discussionSeconds' => 0, 'voteSeconds' => 0]);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[1]->id]);
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
    T::throws(fn() => $g->command($ps[0]->id, ['t' => 'settings', 'name' => 'x', 'roles' => ['villager' => 1, 'werewolf' => 1, 'seer' => 1]]), '/At least 4/');
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
    foreach ([['werewolf', ['villager' => 7, 'werewolf' => 3, 'seer' => 1, 'paladin' => 1, 'ghost' => 2, 'hunter' => 1]],
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
    // good (3) does not exceed evil+1 (1+1=2)? 3 > 2, so evil is allowed here
    [$g, $ps] = lobby('werewolf', ['villager' => 3, 'werewolf' => 2], 4);
    $g->start($ps[0]->id); // n=4: 1 wolf, 3 villagers; pool = 1 werewolf
    T::same(['werewolf'], $g->pool);
    $a = $g->addPlayer(['name' => 'late']);
    T::same('werewolf', $a->role);
    $wolf = array_values(array_filter($g->players, fn($p) => $p->role === 'werewolf' && $p->id !== $a->id))[0];
    $roleIn = fn(string $viewer, string $id) => array_values(array_filter($g->viewFor($viewer)['players'], fn($p) => $p['id'] === $id))[0]['role'] ?? null;
    T::same('werewolf', $roleIn($wolf->id, $a->id));
    $villager = array_values(array_filter($g->players, fn($p) => $p->role === 'villager'))[0];
    T::same(null, $roleIn($villager->id, $a->id));

    // guard: 2 good vs 1 evil at joining -> an evil draw is replaced by a filler
    [$g, $ps] = lobby('werewolf', ['villager' => 3, 'werewolf' => 2], 4);
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
    [$g, $ps] = lobby('werewolf', ['villager' => 5, 'werewolf' => 1, 'hunter' => 1], 6);
    $g->start($ps[0]->id);
    $g->pool = ['hunter'];
    $late = $g->addPlayer(['name' => 'late']);
    T::same('hunter', $late->role);
    $wolf = array_values(array_filter($g->players, fn($p) => $p->role === 'werewolf'))[0];
    $g->command($ps[0]->id, ['t' => 'skip']);
    act($g, $wolf, ['target' => $late->id]);
    T::same('hunter', $g->phase);
    act($g, $late, ['target' => $wolf->id]);
    T::same('ended', $g->phase);
    T::same('good', $g->winner);
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
    T::same(['villager', 'werewolf', 'seer', 'ghost', 'paladin', 'witch', 'hunter'], array_column($roles, 'id'));
    $by = array_column($roles, null, 'id');
    T::same('guard', $by['paladin']['night']['kind']);
    T::same(2, $by['paladin']['night']['step']);
    T::same(['countTeam' => 'evil'], $by['paladin']['night']['limit']);
    T::same(1, $by['paladin']['max']);
    T::same(3, $by['ghost']['max']);
    T::same(0.5, $by['ghost']['haunt']['evilChance']);
    T::true(!isset($by['ghost']['night']));
    T::same(2, $by['witch']['night']['step']);
    $mafia = array_column(Games::get('mafia')['roles'], null, 'id');
    T::same('protect', $mafia['doctor']['night']['kind']);
    $list = array_column(Games::publicList(), null, 'id')['werewolf']['roles'];
    T::same(true, array_column($list, null, 'id')['ghost']['haunts']);
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
    act($g, $ps[0], ['target' => null]);
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
    act($g, $ps[0], ['target' => $ps[2]->id]);
    T::same(2, $g->night['step']);
    T::same([$ps[1]->id], $g->night['actors']);
    $a = $g->viewFor($ps[1]->id)['me']['action'];
    T::same('guard', $a['kind']);
    T::same('p2', $a['victim']);
    T::same(1, $a['usesLeft']);
    T::same(true, $a['canSave']);
    T::true(!isset($a['targets']));
    T::throws(fn() => act($g, $ps[0], ['target' => $ps[3]->id]), '/cannot act/');
    T::throws(fn() => act($g, $ps[1], []), '/Invalid choice/');
    T::throws(fn() => act($g, $ps[1], ['save' => 'yes']), '/Invalid choice/');
    T::throws(fn() => act($g, $ps[1], ['target' => $ps[2]->id]), '/Invalid choice/');

    [$g, $ps] = startedGame('werewolf', PAL);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => null]); // no victim: the paladin has nothing to do
    T::same('discussion', $g->phase);
    T::same(1, $g->round);
});

T::test('paladin: saving prevents the death and spends the single charge', function () {
    [$g, $ps] = startedGame('werewolf', PAL);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[2]->id]);
    act($g, $ps[1], ['save' => true]);
    T::same('discussion', $g->phase);
    T::true($ps[2]->alive);
    T::same('Nobody was killed during the night.', dawn($g));
    T::matches('/shielded p2/', $ps[1]->notes[0]['text']);
    T::same(1, $ps[1]->state['guardUsed']);
    nextNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[2]->id]); // no charge left: no step 2
    T::same('discussion', $g->phase);
    T::same(false, $ps[2]->alive);
    T::true(!str_contains(json_encode($g->viewFor($ps[3]->id)), 'guardUsed'));
});

T::test('paladin: skipping keeps the charge; host skip of the step too', function () {
    [$g, $ps] = startedGame('werewolf', PAL, ['nightSeconds' => 0]);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[2]->id]);
    act($g, $ps[1], ['save' => false]);
    T::same(false, $ps[2]->alive);
    T::same(null, $ps[1]->state['guardUsed'] ?? null);
    nextNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[3]->id]);
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
        act($g, $ps[0], ['target' => $ps[3]->id]);
        act($g, $ps[1], ['target' => $ps[3]->id]);
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
    act($g, $ps[0], ['target' => $ps[3]->id]);
    T::same([$ps[1]->id, $ps[2]->id], $g->night['actors']);
    act($g, $ps[1], ['save' => true]);
    act($g, $ps[2], ['heal' => false, 'poison' => $ps[3]->id]);
    T::same(false, $ps[3]->alive); // saved from the wolves, killed by the poison
    T::same(1, $ps[1]->state['guardUsed']);

    [$g, $ps] = startedGame('werewolf', ['werewolf', 'paladin', 'witch', 'villager', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[3]->id]);
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
    act($g, $ps[0], ['target' => $ps[1]->id]);
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
    T::throws(fn() => act($g, $ps[0], ['target' => $ps[2]->id]), '/cannot act/');
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
    act($g, $ps[0], ['target' => $ps[1]->id]);
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
        act($g, $ps[0], ['target' => $ps[1]->id]);
        T::same($evil, $g->viewFor($ps[1]->id)['me']['ghost']['evil']);
    }
});

T::test('ghost: a paladin save prevents the haunting', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'ghost', 'paladin', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[1]->id]);
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
    act($g, $ps[0], ['target' => $ps[3]->id]);
    act($g, $ps[2], ['heal' => true, 'poison' => $ps[1]->id]);
    T::same('discussion', $g->phase);
    T::same(false, $ps[1]->alive);
    T::true(empty($ps[1]->state['ghost']));
    T::same('p1 was found dead. They were a Ghost.', dawn($g));
    // vote
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'ghost', 'villager', 'villager', 'villager', 'villager']);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => null]);
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
    act($g, $ps[0], ['target' => $ps[1]->id]);
    T::same('hunter', $g->phase);
    act($g, $ps[1], ['target' => $ps[2]->id]);
    T::same(false, $ps[2]->alive);
    T::true(empty($ps[2]->state['ghost']));
    T::same('discussion', $g->phase);
});

T::test('ghost: sees every role, others do not; no hidden data leaks', function () {
    [$g, $ps] = startedGame('werewolf', ['werewolf', 'ghost', 'seer', 'villager', 'villager', 'villager'], ['revealRoles' => false]);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[1]->id]);
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
    act($g, $ps[0], ['target' => $ps[1]->id]);
    act($g, $ps[1], ['target' => $ps[0]->id]);
    T::matches('/pointing a finger at them\. They were a Ghost\.$/', dawn($g));
});

T::test('ghost: step 3 ends by timeout or host skip', function () {
    [$g, $ps, $clock] = startedGame('werewolf', GHOST);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[1]->id]);
    T::same('night', $g->phase);
    T::true($g->deadline !== null);
    $clock->ms += 46_000;
    $g->tick();
    T::same('discussion', $g->phase);
    T::same(false, $ps[1]->alive);
    T::same('p1 was found dead. They were a Ghost.', dawn($g));

    [$g, $ps] = startedGame('werewolf', GHOST, ['nightSeconds' => 0]);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[1]->id]);
    T::same(null, $g->deadline);
    $g->command($ps[0]->id, ['t' => 'skip']);
    T::same('discussion', $g->phase);
    T::same([], $g->marks);
});

T::test('ghost: marks and ghost state survive a JSON round trip; old saves default to no marks', function () {
    [$g, $ps] = startedGame('werewolf', GHOST);
    toNight($g, $ps);
    act($g, $ps[0], ['target' => $ps[1]->id]);
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
    act($g, $ps[0], ['target' => $ps[1]->id]);
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
    act($old, $old->player($ps[0]->id), ['target' => $ps[1]->id]);
    act($old, $old->player($ps[4]->id), ['target' => $ps[0]->id]);
    T::same('discussion', $old->phase);
    $old->viewFor($ps[2]->id);
});
