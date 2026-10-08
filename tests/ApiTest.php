<?php
declare(strict_types=1);

use Wm\Api;
use Wm\Store;

function tmpStore(): array
{
    $dir = sys_get_temp_dir() . '/wm-test-' . bin2hex(random_bytes(4));
    return [new Store($dir), $dir];
}

function rmTree(string $dir): void
{
    foreach (glob("$dir/*") ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

T::test('api: create, join, poll, play', function () {
    [$store, $dir] = tmpStore();
    $api = new Api($store);
    $avatar = 'data:image/jpeg;base64,/9j/4AAQ';

    [$s, $created] = $api->handle('create', [
        'type' => 'werewolf', 'name' => 'Test', 'roles' => ['villager' => 3, 'werewolf' => 1, 'seer' => 1],
        'player' => ['name' => 'Host', 'avatar' => $avatar],
    ]);
    T::same(200, $s);
    T::matches('/^[A-Z2-9]{6}$/', $created['id']);
    $id = $created['id'];

    [$s] = $api->handle('create', ['type' => 'werewolf', 'name' => 'x', 'roles' => ['villager' => 1], 'player' => ['name' => 'a']]);
    T::same(400, $s);
    [$s] = $api->handle('create', ['type' => 'nope', 'name' => 'x', 'roles' => [], 'player' => ['name' => 'a']]);
    T::same(400, $s);

    [, $info] = $api->handle('info', ['id' => strtolower($id)]);
    T::same(1, $info['joined']);
    [$s] = $api->handle('join', ['id' => $id, 'player' => ['name' => 'host']]);
    T::same(400, $s); // duplicate name

    $tokens = [$created['token']];
    for ($i = 1; $i < 5; $i++) {
        [$s, $j] = $api->handle('join', ['id' => $id, 'player' => ['name' => "P$i"]]);
        T::same(200, $s);
        $tokens[] = $j['token'];
    }
    [$s] = $api->handle('join', ['id' => $id, 'player' => ['name' => 'late']]);
    T::same(400, $s); // full

    [$s, $r] = $api->handle('state', ['id' => $id, 'token' => $tokens[1], 'v' => -1]);
    T::same(200, $s);
    $state = $r['state'];
    T::same(5, count($state['players']));
    T::true(!str_contains(json_encode($state), 'data:image'), 'avatars are not inlined in state');

    // unchanged poll
    [, $r2] = $api->handle('state', ['id' => $id, 'token' => $tokens[1], 'v' => $state['version']]);
    T::true($r2['unchanged'] ?? false);

    // avatars on demand
    $hostId = $created['playerId'];
    [, $av] = $api->handle('avatars', ['id' => $id, 'token' => $tokens[1], 'ids' => [$hostId]]);
    T::same($avatar, ((array)$av['avatars'])[$hostId]);

    // bad token
    [$s] = $api->handle('state', ['id' => $id, 'token' => 'nope', 'v' => -1]);
    T::same(400, $s);

    // non-host cannot start; host can
    [$s] = $api->handle('cmd', ['id' => $id, 'token' => $tokens[1], 'cmd' => ['t' => 'start']]);
    T::same(400, $s);
    [$s, $r] = $api->handle('cmd', ['id' => $id, 'token' => $tokens[0], 'cmd' => ['t' => 'start']]);
    T::same(200, $s);
    T::same('discussion', $r['state']['phase']);

    // each player sees their own role, only evil sees foreign roles
    foreach ($tokens as $t) {
        [, $r] = $api->handle('state', ['id' => $id, 'token' => $t, 'v' => -1]);
        T::true($r['state']['me']['role']);
        foreach ($r['state']['players'] as $p) {
            if ($p['id'] !== $r['state']['me']['id'] && isset($p['role'])) {
                T::same('evil', $r['state']['me']['team']);
            }
        }
    }
    [, $r] = $api->handle('cmd', ['id' => $id, 'token' => $tokens[0], 'cmd' => ['t' => 'skip']]);
    T::same('night', $r['state']['phase']);

    // path traversal / invalid id
    [$s] = $api->handle('info', ['id' => '../../etc']);
    T::same(400, $s);
    rmTree($dir);
});

T::test('api: lazy timers run on the next poll', function () {
    [$store, $dir] = tmpStore();
    $clock = new Clock();
    $api = new Api($store, ['clock' => $clock, 'rng' => fn() => 0.5]);
    [, $c] = $api->handle('create', [
        'type' => 'mafia', 'name' => 'T', 'roles' => ['citizen' => 3, 'mafioso' => 1, 'detective' => 1],
        'settings' => ['discussionSeconds' => 15], 'player' => ['name' => 'Host'],
    ]);
    $tokens = [$c['token']];
    for ($i = 1; $i < 5; $i++) {
        [, $j] = $api->handle('join', ['id' => $c['id'], 'player' => ['name' => "P$i"]]);
        $tokens[] = $j['token'];
    }
    [, $r] = $api->handle('cmd', ['id' => $c['id'], 'token' => $tokens[0], 'cmd' => ['t' => 'start']]);
    $v = $r['state']['version'];
    T::same('discussion', $r['state']['phase']);
    $clock->ms += 16_000;
    [, $r] = $api->handle('state', ['id' => $c['id'], 'token' => $tokens[1], 'v' => $v]);
    T::same('night', $r['state']['phase']);
    rmTree($dir);
});

T::test('store: ids are validated and sweep removes old games', function () {
    [$store, $dir] = tmpStore();
    T::same(null, $store->peek('../x'));
    $api = new Api($store);
    [, $c] = $api->handle('create', ['type' => 'mafia', 'name' => 'T', 'roles' => ['citizen' => 3, 'mafioso' => 1], 'player' => ['name' => 'H']]);
    touch("$dir/{$c['id']}.json", time() - 90000);
    $store->sweep();
    T::same(null, $store->peek($c['id']));
    rmTree($dir);
});
