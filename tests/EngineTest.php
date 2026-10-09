<?php
declare(strict_types=1);

use Wm\Config;
use Wm\Game;
use Wm\Games;

const WW = ['werewolf', 'villager', 'villager', 'villager', 'seer', 'doctor'];

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
    act($g, $ps[4], ['target' => $ps[0]->id]); // seer inspects wolf
    T::same('night', $g->phase);
    act($g, $ps[5], ['target' => $ps[5]->id]); // doctor protects self
    T::same('discussion', $g->phase);
    T::same(false, $ps[1]->alive);
    T::matches('/IS a werewolf/', $ps[4]->notes[0]['text']);
});

T::test('doctor protection saves the victim', function () {
    [$g, $ps] = startedGame('werewolf', WW);
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
    act($g, $ps[5], ['target' => $ps[5]->id]);
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
    act($g, $ps[5], ['target' => $ps[5]->id]);
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
    act($copy, $copy->player($ps[5]->id), ['target' => $ps[5]->id]);
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
    foreach ([['werewolf', ['villager' => 7, 'werewolf' => 3, 'seer' => 1, 'doctor' => 1, 'hunter' => 1]],
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
    [$g, $ps] = lobby('werewolf', ['villager' => 4, 'werewolf' => 2, 'seer' => 1, 'doctor' => 1], 5);
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
