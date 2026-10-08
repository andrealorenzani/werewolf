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
