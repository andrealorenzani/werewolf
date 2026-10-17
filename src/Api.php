<?php
declare(strict_types=1);

namespace Wm;

/**
 * HTTP API as a pure function: handle(action, body) -> [status, payload]. public/api.php adapts it
 * to PHP's request/response, tests call it directly.
 *
 * Actions (all JSON; authenticated calls carry the player's secret `token` in the POST body):
 *   defs                               GET   list of games and roles
 *   create {type,name,description,roles,settings,player}   -> {id, playerId, token}
 *   info   {id}                        game summary for the join page
 *   join   {id, player}                -> {id, playerId, token}
 *   state  {id, token, v}              poll: {unchanged:true} or {state}
 *   avatars{id, token, ids[]}          {avatars:{playerId:dataURL}}
 *   cmd    {id, token, cmd}           run a game command -> {state}
 *   (there is deliberately no way to list games; unfinished games expire after 7 days, ended ones 2 days after the end)
 *   delete {id, token}                 host only: remove the game for everyone -> {deleted:true}
 */
final class Api
{
    public function __construct(private Store $store, private array $deps = [])
    {
    }

    /** @return array{0:int,1:array} */
    public function handle(string $action, array $in): array
    {
        try {
            return [200, $this->dispatch($action, $in)];
        } catch (GameError $e) {
            return [400, ['error' => $e->getMessage()]];
        } catch (\Throwable $e) {
            error_log('[werewolf] ' . $e);
            return [500, ['error' => 'Server error.']];
        }
    }

    private function dispatch(string $action, array $in): array
    {
        return match ($action) {
            'defs' => ['games' => Games::publicList()],
            'create' => $this->create($in),
            'info' => $this->info($this->id($in)),
            'join' => $this->join($this->id($in), $in['player'] ?? null),
            'state' => $this->state($this->id($in), $this->token($in), (int)($in['v'] ?? -1)),
            'avatars' => $this->avatars($this->id($in), $this->token($in), $in['ids'] ?? []),
            'cmd' => $this->command($this->id($in), $this->token($in), $in['cmd'] ?? null),
            'delete' => $this->deleteGame($this->id($in), $this->token($in)),
            default => throw new GameError('Unknown action.'),
        };
    }

    private function id(array $in): string
    {
        return strtoupper(is_string($in['id'] ?? null) ? trim($in['id']) : '');
    }

    private function token(array $in): string
    {
        return is_string($in['token'] ?? null) ? $in['token'] : '';
    }

    private function create(array $in): array
    {
        $type = is_string($in['type'] ?? null) ? $in['type'] : '';
        $def = Games::get($type) ?? throw new GameError('Unknown game type.');
        $config = Config::normalize($def, $in);
        Config::profile($in['player'] ?? null); // validate before allocating anything
        $host = null;
        $game = $this->store->create(function (string $id) use ($type, $config, $in, &$host) {
            $g = Game::create($id, $type, $config, $this->deps);
            $host = $g->addPlayer($in['player'] ?? null);
            return $g;
        });
        return ['id' => $game->id, 'playerId' => $host->id, 'token' => $host->token];
    }

    private function info(string $id): array
    {
        if (random_int(1, 20) === 1) {
            $this->store->sweep(); // housekeeping without a cron job
        }
        $d = $this->store->peekLive($id) ?? throw new GameError('Game not found.');
        return ['id' => $d['id'], 'type' => $d['type'], 'name' => $d['name'], 'description' => $d['description'],
            'phase' => $d['phase'], 'joinable' => Game::fromArray($d, $this->deps)->joinable(), 'joined' => count($d['players']), 'total' => $d['total']];
    }

    private function join(string $id, mixed $profile): array
    {
        return $this->store->mutate($id, function (Game $g) use ($profile) {
            $p = $g->addPlayer($profile);
            return ['id' => $g->id, 'playerId' => $p->id, 'token' => $p->token];
        }, $this->deps);
    }

    private function state(string $id, string $token, int $since): array
    {
        $now = $this->deps['clock'] ?? static fn() => (int)(microtime(true) * 1000);
        // Fast path, no lock: nothing changed, no deadline due, and our presence ping is fresh.
        $raw = $this->store->peekLive($id);
        if ($raw === null) {
            throw new GameError('Game not found.');
        }
        $me = null;
        foreach ($raw['players'] as $p) {
            if (hash_equals((string)$p['token'], $token)) {
                $me = $p;
            }
        }
        if ($me === null) {
            throw new GameError('Session expired. Please join again.');
        }
        $t = $now();
        // Ended games need no presence ping (tick() skips it) and have no deadline. An old night save with no
        // deadline must go through the locked path, where tick() arms the night timer.
        $ended = $raw['phase'] === 'ended';
        $stuckNight = $raw['phase'] === 'night' && $raw['deadline'] === null;
        if ($raw['version'] === $since && !$stuckNight && ($raw['deadline'] === null || $t < $raw['deadline'])
            && ($ended || $t - $me['lastSeen'] < 5000)) {
            return ['unchanged' => true, 'now' => $t];
        }
        return $this->store->mutate($id, function (Game $g) use ($token, $since) {
            $p = $g->playerByToken($token) ?? throw new GameError('You were removed from this game.');
            $g->touch($p);
            return $g->version === $since ? ['unchanged' => true, 'now' => $g->now()] : ['state' => $g->viewFor($p->id)];
        }, $this->deps);
    }

    private function avatars(string $id, string $token, mixed $ids): array
    {
        $d = $this->store->peekLive($id) ?? throw new GameError('Game not found.');
        $g = Game::fromArray($d, $this->deps);
        $g->playerByToken($token) ?? throw new GameError('Session expired. Please join again.');
        return ['avatars' => (object)$g->avatars(is_array($ids) ? array_slice($ids, 0, 40) : [])];
    }

    private function deleteGame(string $id, string $token): array
    {
        $this->store->remove($id, function (Game $g) use ($token) {
            $p = $g->playerByToken($token) ?? throw new GameError('Session expired. Please join again.');
            if (!$g->canDelete($p->id)) {
                throw new GameError('Only the host can delete this game.');
            }
        }, $this->deps);
        return ['deleted' => true];
    }

    private function command(string $id, string $token, mixed $cmd): array
    {
        if (!is_array($cmd)) {
            throw new GameError('Bad command.');
        }
        return $this->store->mutate($id, function (Game $g) use ($token, $cmd) {
            $p = $g->playerByToken($token) ?? throw new GameError('You were removed from this game.');
            $g->touch($p);
            $g->command($p->id, $cmd);
            return $g->player($p->id) ? ['state' => $g->viewFor($p->id)] : ['left' => true];
        }, $this->deps);
    }
}
