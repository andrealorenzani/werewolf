<?php
declare(strict_types=1);

use Wm\Api;
use Wm\Store;

function tmpStore(?callable $clock = null): array
{
    $dir = sys_get_temp_dir() . '/wm-test-' . bin2hex(random_bytes(4));
    return [new Store($dir, $clock), $dir];
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
    $clock = new Clock();
    [$store, $dir] = tmpStore($clock);
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

T::test('store: ids are validated', function () {
    [$store, $dir] = tmpStore();
    T::same(null, $store->peek('../x'));
    T::same(null, $store->peekLive('../x'));
    rmTree($dir);
});

const DAY_MS = 86_400_000;

/** A store, an Api and a game created at clock time; returns [api, store, dir, clock, id, tokens]. */
function clockedGame(): array
{
    $dir = sys_get_temp_dir() . '/wm-test-' . bin2hex(random_bytes(4));
    $clock = new Clock();
    $store = new Store($dir, $clock);
    $api = new Api($store, ['clock' => $clock, 'rng' => fn() => 0.5]);
    [, $c] = $api->handle('create', ['type' => 'werewolf', 'name' => 'T', 'roles' => ['villager' => 4, 'werewolf' => 1], 'player' => ['name' => 'Host']]);
    $tokens = [$c['token']];
    for ($i = 1; $i < 5; $i++) {
        [, $j] = $api->handle('join', ['id' => $c['id'], 'player' => ['name' => "P$i"]]);
        $tokens[] = $j['token'];
    }
    return [$api, $store, $dir, $clock, $c['id'], $tokens];
}

T::test('retention: Store::expired - unfinished games after 7 idle days, ended games 2 days after the end', function () {
    $now = 100 * DAY_MS;
    $live = ['phase' => 'night', 'createdAt' => $now - 30 * DAY_MS, 'players' => [['lastSeen' => $now - 6 * DAY_MS], ['lastSeen' => $now - 20 * DAY_MS]]];
    T::same(false, Store::expired($live, $now));
    $live['players'][0]['lastSeen'] = $now - 7 * DAY_MS - 1;
    T::same(true, Store::expired($live, $now));
    // the latest activity of any player counts
    $live['players'][1]['lastSeen'] = $now - DAY_MS;
    T::same(false, Store::expired($live, $now));
    // 24 hours of idleness no longer expires anything
    T::same(false, Store::expired(['phase' => 'lobby', 'createdAt' => $now - 2 * DAY_MS, 'players' => []], $now));
    $ended = ['phase' => 'ended', 'endedAt' => $now - 2 * DAY_MS, 'createdAt' => 1, 'players' => [['lastSeen' => $now]]];
    T::same(false, Store::expired($ended, $now));
    $ended['endedAt'] = $now - 2 * DAY_MS - 1;
    T::same(true, Store::expired($ended, $now), 'recent activity does not keep an ended game alive');
    // old ended save without endedAt: the file modification time decides
    unset($ended['endedAt']);
    T::same(false, Store::expired($ended, $now, intdiv($now - DAY_MS, 1000)));
    T::same(true, Store::expired($ended, $now, intdiv($now - 3 * DAY_MS, 1000)));
});

T::test('retention: the engine stamps endedAt when the game ends, and it never reaches a view', function () {
    [$g, $ps, $clock] = startedGame('werewolf', ['werewolf', 'villager', 'villager', 'villager', 'villager']);
    T::same(null, $g->endedAt);
    toNight($g, $ps);
    killAct($g, $ps[0], $ps[1]);
    $g->command($ps[0]->id, ['t' => 'skip']);
    foreach ([2, 3, 4] as $i) {
        $g->command($ps[$i]->id, ['t' => 'vote', 'target' => $ps[0]->id]);
    }
    $g->command($ps[0]->id, ['t' => 'vote', 'target' => null]);
    T::same('ended', $g->phase);
    T::same($clock->ms, $g->endedAt);
    T::same($clock->ms, $g->toArray()['endedAt']);
    T::true(!str_contains(json_encode($g->viewFor($ps[2]->id)), 'endedAt'));
    // looking at an ended game does not rewrite it
    $g->dirty = false;
    $clock->ms += 2000;
    $g->touch($ps[2]);
    T::same(false, $g->dirty);
    $clock->ms += 60_000;
    $g->tick(); // presence is frozen too
    T::same(false, $g->dirty);
});

T::test('retention: an old ended save without endedAt is stamped once on tick', function () {
    [$g, $ps, $clock] = startedGame('werewolf', ['werewolf', 'villager', 'villager', 'villager', 'villager']);
    $g->phase = 'ended';
    $g->endedAt = null;
    $g->dirty = false;
    $g->tick();
    T::same($clock->ms, $g->endedAt);
    T::true($g->dirty);
    $g->dirty = false;
    $clock->ms += 5000;
    $g->tick();
    T::same(false, $g->dirty);
    T::true($g->endedAt < $clock->ms);
});

T::test('retention: sweep deletes idle (7d) and ended (2d) games, keeps the rest', function () {
    [$api, $store, $dir, $clock, $idle] = clockedGame();
    [, $c2] = $api->handle('create', ['type' => 'mafia', 'name' => 'E', 'roles' => ['citizen' => 3, 'mafioso' => 1], 'player' => ['name' => 'H']]);
    $ended = $c2['id'];
    $raw = json_decode(file_get_contents("$dir/$ended.json"), true);
    $raw['phase'] = 'ended';
    $raw['endedAt'] = $clock->ms;
    file_put_contents("$dir/$ended.json", json_encode($raw));
    $clock->ms += 2 * DAY_MS - 1000;
    $store->sweep();
    T::true($store->peek($idle) !== null && $store->peek($ended) !== null, 'both still there after 2 days minus a bit');
    $clock->ms += 2000;
    $store->sweep();
    T::true($store->peek($idle) !== null, 'unfinished game is still there after 2 days');
    T::same(null, $store->peek($ended));
    T::true(!file_exists("$dir/$ended.lock"));
    $clock->ms += 5 * DAY_MS;
    $store->sweep();
    T::same(null, $store->peek($idle));
    rmTree($dir);
});

T::test('retention: sweep falls back to the file time for unreadable or old ended games', function () {
    [$store, $dir] = tmpStore();
    file_put_contents("$dir/ABCDEF.json", 'not json');
    touch("$dir/ABCDEF.json", time() - 8 * 86400);
    file_put_contents("$dir/BCDEFG.json", 'not json');
    $raw = ['phase' => 'ended', 'players' => []];
    file_put_contents("$dir/CDEFGH.json", json_encode($raw));
    touch("$dir/CDEFGH.json", time() - 3 * 86400);
    file_put_contents("$dir/DEFGHJ.json", json_encode($raw));
    $store->sweep();
    T::true(!file_exists("$dir/ABCDEF.json"), 'old garbage');
    T::true(file_exists("$dir/BCDEFG.json"), 'recent garbage stays');
    T::true(!file_exists("$dir/CDEFGH.json"), 'ended long ago by mtime');
    T::true(file_exists("$dir/DEFGHJ.json"), 'ended just now by mtime');
    rmTree($dir);
});

T::test('retention: expired games read as not found through state, avatars, info, cmd and join, and are deleted', function () {
    foreach (['state', 'avatars', 'info', 'cmd', 'join'] as $call) {
        [$api, $store, $dir, $clock, $id, $tokens] = clockedGame();
        $clock->ms += 7 * DAY_MS + 1000;
        $in = ['id' => $id, 'token' => $tokens[1], 'v' => -1, 'ids' => [], 'cmd' => ['t' => 'chat', 'text' => 'hi'], 'player' => ['name' => 'Zed']];
        [$s, $r] = $api->handle($call, $in);
        T::same(400, $s, $call);
        T::matches('/Game not found/', $r['error']);
        T::true(!file_exists("$dir/$id.json"), "$call deletes the file");
        rmTree($dir);
    }
    // a game that has activity within 7 days is alive
    [$api, $store, $dir, $clock, $id, $tokens] = clockedGame();
    $clock->ms += 6 * DAY_MS;
    [$s] = $api->handle('state', ['id' => $id, 'token' => $tokens[1], 'v' => -1]);
    T::same(200, $s);
    $clock->ms += 6 * DAY_MS; // 6 days since the last poll
    [$s] = $api->handle('state', ['id' => $id, 'token' => $tokens[2], 'v' => -1]);
    T::same(200, $s);
    rmTree($dir);
});

T::test('api: there is no way to list games', function () {
    [$store, $dir] = tmpStore();
    $api = new Api($store);
    foreach (['list', 'games', 'all', 'search'] as $a) {
        [$s, $r] = $api->handle($a, []);
        T::same(400, $s, $a);
        T::matches('/Unknown action/', $r['error']);
    }
    [$s, $r] = $api->handle('defs', []);
    T::true(!isset($r['games'][0]['ids']) && !isset($r['ids']));
    rmTree($dir);
});

T::test('api: day-0 join, info joinable, settings and profile via cmd, version bumps', function () {
    $clock = new Clock();
    [$store, $dir] = tmpStore($clock);
    $api = new Api($store, ['clock' => $clock, 'rng' => fn() => 0.5]);
    [, $c] = $api->handle('create', [
        'type' => 'werewolf', 'name' => 'T', 'roles' => ['villager' => 5, 'werewolf' => 1, 'seer' => 1], 'player' => ['name' => 'Host'],
    ]);
    $id = $c['id'];
    $tokens = [$c['token']];
    for ($i = 1; $i < 4; $i++) {
        [, $j] = $api->handle('join', ['id' => $id, 'player' => ['name' => "P$i"]]);
        $tokens[] = $j['token'];
    }
    [, $info] = $api->handle('info', ['id' => $id]);
    T::same(true, $info['joinable']);
    T::same('lobby', $info['phase']);

    // settings: version bump, host only
    [, $r] = $api->handle('state', ['id' => $id, 'token' => $tokens[0], 'v' => -1]);
    $v = $r['state']['version'];
    $cfg = ['t' => 'settings', 'name' => 'Renamed', 'roles' => ['villager' => 4, 'werewolf' => 1], 'settings' => ['voteSeconds' => 30]];
    [$s] = $api->handle('cmd', ['id' => $id, 'token' => $tokens[1], 'cmd' => $cfg]);
    T::same(400, $s);
    [$s, $r] = $api->handle('cmd', ['id' => $id, 'token' => $tokens[0], 'cmd' => $cfg]);
    T::same(200, $s);
    T::same('Renamed', $r['state']['name']);
    T::same(5, $r['state']['total']);
    T::true($r['state']['version'] > $v);
    [, $r2] = $api->handle('state', ['id' => $id, 'token' => $tokens[1], 'v' => $v]);
    T::same('Renamed', $r2['state']['name']); // other clients see the change on their next poll

    [$s] = $api->handle('cmd', ['id' => $id, 'token' => $tokens[0], 'cmd' => ['t' => 'start']]);
    T::same(200, $s);
    [, $info] = $api->handle('info', ['id' => $id]);
    T::same(true, $info['joinable']);
    T::same('discussion', $info['phase']);

    // day-0 join
    [$s, $late] = $api->handle('join', ['id' => $id, 'player' => ['name' => 'Late']]);
    T::same(200, $s);
    [, $r] = $api->handle('state', ['id' => $id, 'token' => $late['token'], 'v' => -1]);
    T::true($r['state']['me']['role']);
    T::true(!str_contains(json_encode($r['state']), '"pool"'));

    // profile with avatar, then fetch it through the avatars action
    $img = 'data:image/jpeg;base64,/9j/4AAQ';
    [$s, $r] = $api->handle('cmd', ['id' => $id, 'token' => $late['token'], 'cmd' => ['t' => 'profile', 'name' => 'Late2', 'avatar' => $img]]);
    T::same(200, $s);
    $me = array_values(array_filter($r['state']['players'], fn($p) => $p['id'] === $late['playerId']))[0];
    T::same('Late2', $me['name']);
    T::same(2, $me['avatarV']);
    [, $av] = $api->handle('avatars', ['id' => $id, 'token' => $tokens[1], 'ids' => [$late['playerId']]]);
    T::same($img, ((array)$av['avatars'])[$late['playerId']]);

    // from night 1 on, joining is refused
    [, $r] = $api->handle('cmd', ['id' => $id, 'token' => $tokens[0], 'cmd' => ['t' => 'skip']]);
    T::same('night', $r['state']['phase']);
    [$s] = $api->handle('join', ['id' => $id, 'player' => ['name' => 'TooLate']]);
    T::same(400, $s);
    [, $info] = $api->handle('info', ['id' => $id]);
    T::same(false, $info['joinable']);
    rmTree($dir);
});

/** Game with a host and 4 more players; returns [api, dir, id, tokens[0]=host]. */
function apiGame(): array
{
    $clock = new Clock();
    [$store, $dir] = tmpStore($clock);
    $api = new Api($store, ['clock' => $clock, 'rng' => fn() => 0.5]);
    [, $c] = $api->handle('create', [
        'type' => 'werewolf', 'name' => 'T', 'roles' => ['villager' => 4, 'werewolf' => 1], 'player' => ['name' => 'Host'],
    ]);
    $tokens = [$c['token']];
    for ($i = 1; $i < 5; $i++) {
        [, $j] = $api->handle('join', ['id' => $c['id'], 'player' => ['name' => "P$i"]]);
        $tokens[] = $j['token'];
    }
    return [$api, $dir, $c['id'], $tokens];
}

T::test('api: the host deletes the game in the lobby, mid-game and when ended', function () {
    foreach (['lobby', 'night', 'ended'] as $stage) {
        [$api, $dir, $id, $tokens] = apiGame();
        if ($stage !== 'lobby') {
            $api->handle('cmd', ['id' => $id, 'token' => $tokens[0], 'cmd' => ['t' => 'start']]);
            $api->handle('cmd', ['id' => $id, 'token' => $tokens[0], 'cmd' => ['t' => 'skip']]);
        }
        if ($stage === 'ended') {
            $raw = json_decode(file_get_contents("$dir/$id.json"), true);
            $raw['phase'] = 'ended';
            file_put_contents("$dir/$id.json", json_encode($raw));
        }
        [$s, $r] = $api->handle('delete', ['id' => strtolower($id), 'token' => $tokens[0]]);
        T::same(200, $s, $stage);
        T::same(['deleted' => true], $r);
        T::true(!file_exists("$dir/$id.json"), $stage);
        T::true(!file_exists("$dir/$id.lock"), $stage);
        [$s, $r] = $api->handle('state', ['id' => $id, 'token' => $tokens[1], 'v' => -1]);
        T::same(400, $s);
        T::matches('/not found/', $r['error']);
        [$s] = $api->handle('info', ['id' => $id]);
        T::same(400, $s);
        [$s, $r] = $api->handle('delete', ['id' => $id, 'token' => $tokens[0]]); // already gone
        T::same(400, $s);
        T::matches('/not found/', $r['error']);
        rmTree($dir);
    }
});

T::test('api: a non-host cannot delete; wrong token and bad ids are rejected', function () {
    [$api, $dir, $id, $tokens] = apiGame();
    [$s, $r] = $api->handle('delete', ['id' => $id, 'token' => $tokens[1]]);
    T::same(400, $s);
    T::matches('/Only the host/', $r['error']);
    [$s, $r] = $api->handle('delete', ['id' => $id, 'token' => 'nope']);
    T::same(400, $s);
    T::matches('/expired/', $r['error']);
    [$s] = $api->handle('delete', ['id' => $id, 'token' => '']);
    T::same(400, $s);
    [$s] = $api->handle('delete', ['id' => '../../etc', 'token' => $tokens[0]]);
    T::same(400, $s);
    [$s] = $api->handle('delete', ['id' => 'ZZZZZZ', 'token' => $tokens[0]]);
    T::same(400, $s);
    T::true(!file_exists("$dir/ZZZZZZ.lock"), 'no stray lock for unknown games');
    T::true(file_exists("$dir/$id.json"), 'game remains');
    [$s, $r] = $api->handle('state', ['id' => $id, 'token' => $tokens[1], 'v' => -1]);
    T::same(200, $s);
    T::same(5, count($r['state']['players']));
    rmTree($dir);
});

T::test('api: story chat settings and DMs travel through create and cmd', function () {
    $clock = new Clock();
    [$store, $dir] = tmpStore($clock);
    $api = new Api($store, ['clock' => $clock, 'rng' => fn() => 0.5]);
    [, $c] = $api->handle('create', [
        'type' => 'werewolf', 'name' => 'T', 'roles' => ['villager' => 4, 'werewolf' => 1],
        'settings' => ['storyPublicChat' => true], 'player' => ['name' => 'Host'],
    ]);
    [, $j] = $api->handle('join', ['id' => $c['id'], 'player' => ['name' => 'Bob']]);
    [, $k] = $api->handle('join', ['id' => $c['id'], 'player' => ['name' => 'Cy']]);
    [, $r] = $api->handle('state', ['id' => $c['id'], 'token' => $c['token'], 'v' => -1]);
    T::same(true, $r['state']['settings']['storyPublicChat']);
    T::same(false, $r['state']['settings']['storyPrivateChat']);
    [$s] = $api->handle('cmd', ['id' => $c['id'], 'token' => $c['token'], 'cmd' => ['t' => 'chat', 'text' => 'hi Bob', 'to' => $j['playerId']]]);
    T::same(200, $s);
    [, $r] = $api->handle('state', ['id' => $c['id'], 'token' => $j['token'], 'v' => -1]);
    T::same('dm', $r['state']['chat'][0]['ch']);
    [, $r] = $api->handle('state', ['id' => $c['id'], 'token' => $k['token'], 'v' => -1]);
    T::same([], $r['state']['chat']);
    rmTree($dir);
});

T::test('api: rules returns trusted, escaped HTML without a token', function () {
    [$store, $dir] = tmpStore();
    $api = new Api($store);
    [$s, $r] = $api->handle('rules', []);
    T::same(200, $s);
    T::true(str_contains($r['html'], '<strong>👻 Ghosts:</strong>') && str_contains($r['html'], 'Paladin'));
    T::true(!str_contains($r['html'], '<script') && !str_contains($r['html'], '?create='), 'no scripts, no create links in the popup');
    T::true(!str_contains($r['html'], '<strong>👻 Ghost</strong>'), 'no legacy card');
    rmTree($dir);
});

T::test('api: create accepts, stores and shows the ghost count; rejects too many', function () {
    $clock = new Clock();
    [$store, $dir] = tmpStore($clock);
    $api = new Api($store, ['clock' => $clock, 'rng' => fn() => 0.5]);
    $in = ['type' => 'werewolf', 'name' => 'G', 'roles' => ['villager' => 5, 'werewolf' => 2], 'player' => ['name' => 'Host']];
    [$s] = $api->handle('create', $in + ['ghosts' => 3]);
    T::same(400, $s);
    [$s, $c] = $api->handle('create', $in + ['ghosts' => 2]);
    T::same(200, $s);
    [, $st] = $api->handle('state', ['id' => $c['id'], 'token' => $c['token'], 'v' => -1]);
    T::same(2, $st['state']['ghosts']);
    T::same(7, $st['state']['total']);
    [$s, $c2] = $api->handle('create', $in);
    [, $st] = $api->handle('state', ['id' => $c2['id'], 'token' => $c2['token'], 'v' => -1]);
    T::same(0, $st['state']['ghosts']);
    // host settings re-validate
    [$s] = $api->handle('cmd', ['id' => $c['id'], 'token' => $c['token'],
        'cmd' => ['t' => 'settings', 'name' => 'G', 'roles' => ['villager' => 5, 'werewolf' => 1], 'ghosts' => 2]]);
    T::same(400, $s);
    [$s] = $api->handle('cmd', ['id' => $c['id'], 'token' => $c['token'],
        'cmd' => ['t' => 'settings', 'name' => 'G', 'roles' => ['villager' => 5, 'werewolf' => 1], 'ghosts' => 1]]);
    T::same(200, $s);
    rmTree($dir);
});

T::test('api: the corrupt chooses a side via cmd; second time, wrong role and invalid side are 400', function () {
    $clock = new Clock();
    [$store, $dir] = tmpStore($clock);
    $api = new Api($store, ['clock' => $clock, 'rng' => fn() => 0.5]);
    [, $c] = $api->handle('create', [
        'type' => 'mafia', 'name' => 'M', 'roles' => ['citizen' => 4, 'mafioso' => 1, 'corrupt' => 1], 'player' => ['name' => 'Host'],
    ]);
    $tokens = [$c['token']];
    for ($i = 1; $i < 6; $i++) {
        [, $j] = $api->handle('join', ['id' => $c['id'], 'player' => ['name' => "P$i"]]);
        $tokens[] = $j['token'];
    }
    $cmd = fn(string $tok, array $body) => $api->handle('cmd', ['id' => $c['id'], 'token' => $tok, 'cmd' => $body]);
    [$s] = $cmd($tokens[0], ['t' => 'side', 'side' => 'good']);
    T::same(400, $s); // lobby
    [$s] = $cmd($tokens[0], ['t' => 'start']);
    T::same(200, $s);
    $roles = [];
    foreach ($tokens as $t) {
        [, $r] = $api->handle('state', ['id' => $c['id'], 'token' => $t, 'v' => -1]);
        $roles[$t] = $r['state']['me']['role'];
    }
    $corrupt = array_search('corrupt', $roles, true);
    $other = array_search('citizen', $roles, true);
    [$s] = $cmd($other, ['t' => 'side', 'side' => 'good']);
    T::same(400, $s);
    [$s] = $cmd($corrupt, ['t' => 'side', 'side' => 'bogus']);
    T::same(400, $s);
    [$s, $r] = $cmd($corrupt, ['t' => 'side', 'side' => 'evil']);
    T::same(200, $s);
    T::same('evil', $r['state']['me']['side']);
    [$s, $r] = $cmd($corrupt, ['t' => 'side', 'side' => 'good']);
    T::same(400, $s);
    T::matches('/already chose/', $r['error']);
    [, $r] = $api->handle('state', ['id' => $c['id'], 'token' => $other, 'v' => -1]);
    T::true(!array_key_exists('side', $r['state']['me']));
    rmTree($dir);
});

// ---------- Impostor through the API ----------
function apiImpostor(Api $api, array $settings = [], int $players = 4): array
{
    [$s, $c] = $api->handle('create', [
        'type' => 'impostor', 'name' => 'Odd one', 'seats' => 8, 'settings' => $settings, 'player' => ['name' => 'Host'],
    ]);
    T::same(200, $s);
    $tokens = [$c['token']];
    for ($i = 1; $i < $players; $i++) {
        [$s, $j] = $api->handle('join', ['id' => $c['id'], 'player' => ['name' => "P$i"]]);
        T::same(200, $s);
        $tokens[] = $j['token'];
    }
    return [$c['id'], $tokens];
}

T::test('api impostor: defs list the game, create validates, info reports playing seats', function () {
    [$store, $dir] = tmpStore();
    $api = new Api($store);
    [, $defs] = $api->handle('defs', []);
    $d = array_column($defs['games'], null, 'id');
    T::same('impostor', $d['impostor']['engine']);
    T::same('classic', $d['werewolf']['engine']);
    [$s, $r] = $api->handle('create', ['type' => 'impostor', 'name' => 'X', 'seats' => 2, 'player' => ['name' => 'H']]);
    T::same(400, $s);
    [$s] = $api->handle('create', ['type' => 'impostor', 'name' => 'X', 'player' => ['name' => '']]);
    T::same(400, $s);
    [$id, $tokens] = apiImpostor($api, ['hostPlays' => false], 3);
    [$s, $info] = $api->handle('info', ['id' => $id]);
    T::same(200, $s);
    T::same('impostor', $info['type']);
    T::same(2, $info['joined']); // the non-playing host is not a player
    T::same(8, $info['total']);
    T::true($info['joinable']);
    rmTree($dir);
});

T::test('api impostor: a full game over the API, polls are unchanged until something happens', function () {
    $clock = new Clock();
    [$store, $dir] = tmpStore($clock);
    $api = new Api($store, ['clock' => $clock, 'rng' => fn() => 0.5]);
    [$id, $tokens] = apiImpostor($api, ['turns' => 1]);
    $cmd = fn(string $tok, array $body) => $api->handle('cmd', ['id' => $id, 'token' => $tok, 'cmd' => $body]);
    $state = fn(string $tok, int $v = -1) => $api->handle('state', ['id' => $id, 'token' => $tok, 'v' => $v]);
    [$s, $r] = $cmd($tokens[1], ['t' => 'start']);
    T::same(400, $s);
    [$s, $r] = $cmd($tokens[0], ['t' => 'start']);
    T::same(200, $s);
    T::same('answer', $r['state']['phase']);
    $v = $r['state']['version'];
    [, $u] = $state($tokens[1], $v);
    T::same(true, $u['unchanged']);
    $clock->ms += 3000;
    [, $u] = $state($tokens[1], $v + 100);
    T::true(isset($u['state']) || isset($u['unchanged']));
    foreach ($tokens as $i => $t) {
        [$s, $r] = $cmd($t, ['t' => 'answer', 'text' => "a$i"]);
        T::same(200, $s);
    }
    [, $r] = $state($tokens[0]);
    T::same('reveal', $r['state']['phase']);
    T::same($r['state']['turn']['speaker'] !== null, true);
    // timers are lazy on the poll path: after the speaker timeout the reveal moves on
    $v = $r['state']['version'];
    $clock->ms += Wm\Impostor::SPEAK_MS + 1;
    [, $r] = $state($tokens[0], $v);
    T::same(1, $r['state']['turn']['index']);
    [$s, $r] = $cmd($tokens[0], ['t' => 'bogus']);
    T::same(400, $s);
    [$s, $r] = $cmd('badtoken', ['t' => 'start']);
    T::same(400, $s);
    rmTree($dir);
});

T::test('api impostor: avatars, leave and delete work for the new engine', function () {
    [$store, $dir] = tmpStore();
    $api = new Api($store);
    $avatar = 'data:image/jpeg;base64,/9j/4AAQ';
    [, $c] = $api->handle('create', ['type' => 'impostor', 'name' => 'A', 'player' => ['name' => 'Host', 'avatar' => $avatar]]);
    [, $j] = $api->handle('join', ['id' => $c['id'], 'player' => ['name' => 'Guest']]);
    [, $r] = $api->handle('state', ['id' => $c['id'], 'token' => $j['token'], 'v' => -1]);
    $json = json_encode($r['state']);
    T::false(str_contains($json, $c['token']) || str_contains($json, $j['token']) || str_contains($json, $avatar));
    [, $a] = $api->handle('avatars', ['id' => $c['id'], 'token' => $j['token'], 'ids' => [$c['playerId']]]);
    T::same($avatar, ((array)$a['avatars'])[$c['playerId']]);
    [, $l] = $api->handle('cmd', ['id' => $c['id'], 'token' => $j['token'], 'cmd' => ['t' => 'leave']]);
    T::same(true, $l['left']);
    [$s] = $api->handle('delete', ['id' => $c['id'], 'token' => $j['token']]);
    T::same(400, $s);
    [$s, $d] = $api->handle('delete', ['id' => $c['id'], 'token' => $c['token']]);
    T::same(200, $s);
    [$s] = $api->handle('info', ['id' => $c['id']]);
    T::same(400, $s);
    rmTree($dir);
});

T::test('api impostor: classic saves still load through Rooms::load and the classic API is unchanged', function () {
    [$store, $dir] = tmpStore();
    $api = new Api($store);
    [, $c] = $api->handle('create', ['type' => 'mafia', 'name' => 'M', 'roles' => ['citizen' => 3, 'mafioso' => 1], 'player' => ['name' => 'Host']]);
    $raw = $store->peek($c['id']);
    T::true(Wm\Rooms::load($raw) instanceof Wm\Game);
    T::same('mafia', $raw['type']);
    [$s, $info] = $api->handle('info', ['id' => $c['id']]);
    T::same(1, $info['joined']);
    $impostor = Wm\Rooms::create('ZZZZZZ', 'impostor', Wm\Rooms::config(Wm\Games::get('impostor'), ['name' => 'I']));
    T::true($impostor instanceof Wm\Impostor);
    rmTree($dir);
});
