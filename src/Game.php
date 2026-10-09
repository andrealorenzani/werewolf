<?php
declare(strict_types=1);

namespace Wm;

/**
 * One game room: lobby, then the phase machine
 *   discussion(day 0) -> night -> discussion -> vote -> night -> ... -> ended
 * with an optional `hunter` phase after any death. The server is the moderator: all rules live
 * here; clients send commands and receive per-player views (viewFor).
 *
 * There is no background process on shared hosting, so timers are *lazy*: `deadline` is an
 * absolute time and tick() applies expired deadlines at the start of every request.
 * A Game is loaded from / saved to JSON by Store (see toArray / fromArray).
 */
final class Game
{
    private const PRESENCE_MS = 15000;

    public string $id;
    public string $type;
    public string $name;
    public string $description;
    public array $roleCounts;
    public array $settings;
    public int $total;
    /** @var Player[] */
    public array $players = [];
    public ?string $hostId = null;
    public string $phase = 'lobby';
    public int $round = 0;
    public ?int $deadline = null; // ms epoch
    public int $seq = 0;          // bumped on every phase change
    public int $version = 0;      // bumped on every visible change; clients poll with it
    public array $log = [];
    public array $chat = [];
    /** Role cards not dealt at start (hidden; late joiners draw from here). NEVER put in a view. */
    public array $pool = [];
    public ?string $winner = null;
    public array $votes = [];     // voterId => targetId|null
    public ?array $night = null;  // {step, actions{playerId=>action}, victim, actors[]}
    public ?string $hunter = null;
    public array $pendingHunters = [];
    public ?string $hunterNext = null;
    public int $createdAt;
    public bool $dirty = false;   // needs saving

    private array $def;
    /** @var callable */
    private $clock;
    /** @var callable */
    private $rng;

    /** @param array{clock?:callable,rng?:callable} $deps injectable for deterministic tests */
    private function __construct(array $s, array $deps)
    {
        $this->clock = $deps['clock'] ?? static fn(): int => (int)(microtime(true) * 1000);
        $this->rng = $deps['rng'] ?? static fn(): float => mt_rand() / (mt_getrandmax() + 1);
        $this->def = Games::get($s['type']) ?? throw new \InvalidArgumentException('Unknown game type');
        foreach ($s as $k => $v) {
            if ($k === 'players') {
                $this->players = array_map([Player::class, 'fromArray'], $v);
            } elseif (property_exists($this, $k)) {
                $this->$k = $v;
            }
        }
    }

    /** @param array $config result of Config::normalize */
    public static function create(string $id, string $type, array $config, array $deps = []): self
    {
        $g = new self([
            'id' => $id, 'type' => $type, 'name' => $config['name'], 'description' => $config['description'],
            'roleCounts' => $config['roleCounts'], 'settings' => $config['settings'], 'total' => $config['total'],
            'createdAt' => (int)(microtime(true) * 1000),
        ], $deps);
        $g->dirty = true;
        return $g;
    }

    public static function fromArray(array $a, array $deps = []): self
    {
        return new self($a, $deps);
    }

    public function toArray(): array
    {
        $out = [];
        foreach (['id', 'type', 'name', 'description', 'roleCounts', 'settings', 'total', 'hostId', 'phase', 'round',
            'deadline', 'seq', 'version', 'log', 'chat', 'winner', 'votes', 'night', 'hunter', 'pendingHunters',
            'hunterNext', 'createdAt', 'pool'] as $k) {
            $out[$k] = $this->$k;
        }
        $out['players'] = array_map(static fn(Player $p) => $p->toArray(), $this->players);
        return $out;
    }

    // ---------- helpers ----------
    public function now(): int
    {
        return ($this->clock)();
    }

    private function rand01(): float
    {
        return ($this->rng)();
    }

    private function bump(): void
    {
        $this->version++;
        $this->dirty = true;
    }

    private function shuffle(array $a): array
    {
        for ($i = count($a) - 1; $i > 0; $i--) {
            $j = (int)floor($this->rand01() * ($i + 1));
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }
        return $a;
    }

    public function roleOf(Player $p): ?array
    {
        foreach ($this->def['roles'] as $r) {
            if ($r['id'] === $p->role) {
                return $r;
            }
        }
        return null;
    }

    public function teamOf(Player $p): ?string
    {
        return $this->roleOf($p)['team'] ?? null;
    }

    private function nightKind(Player $p): ?string
    {
        return $this->roleOf($p)['night']['kind'] ?? null;
    }

    public function player(?string $id): ?Player
    {
        foreach ($this->players as $p) {
            if ($p->id === $id) {
                return $p;
            }
        }
        return null;
    }

    /** @return Player[] */
    public function alive(): array
    {
        return array_values(array_filter($this->players, static fn(Player $p) => $p->alive));
    }

    private function publicLog(string $text, string $type = 'info'): void
    {
        $this->log[] = ['round' => $this->round, 'phase' => $this->phase, 'type' => $type, 'text' => $text];
    }

    private function note(Player $p, string $text): void
    {
        $p->notes[] = ['round' => $this->round, 'text' => $text];
    }

    private function text(string $key, mixed ...$args): string
    {
        return sprintf($this->def['text'][$key], ...$args);
    }

    // ---------- lobby ----------
    public function addPlayer(mixed $profile): Player
    {
        if (!$this->joinable()) {
            if (count($this->players) >= $this->total && ($this->phase === 'lobby' || $this->isDayZero())) {
                throw new GameError('The game is full.');
            }
            throw new GameError('The game has already started.');
        }
        $prof = Config::profile($profile);
        foreach ($this->players as $q) {
            if (mb_strtolower($q->name) === mb_strtolower($prof['name'])) {
                throw new GameError('That name is already taken in this game.');
            }
        }
        $p = new Player();
        // 'p' prefix: purely numeric ids would be turned into integer array keys by PHP
        $p->id = 'p' . bin2hex(random_bytes(4));
        $p->token = bin2hex(random_bytes(16));
        $p->name = $prof['name'];
        $p->avatar = $prof['avatar'];
        $p->lastSeen = $this->now();
        $p->connected = true;
        if ($this->phase !== 'lobby') {
            $p->role = $this->drawLateRole();
            $this->publicLog("{$p->name} joined the game.", 'info');
        }
        $this->players[] = $p;
        $this->hostId ??= $p->id;
        $this->bump();
        return $p;
    }

    private function isDayZero(): bool
    {
        return $this->phase === 'discussion' && $this->round === 0;
    }

    /** Can someone still take a seat? Lobby, or day 0 (before the first night). */
    public function joinable(): bool
    {
        return count($this->players) < $this->total && ($this->phase === 'lobby' || $this->isDayZero());
    }

    private function roleDef(string $id): ?array
    {
        foreach ($this->def['roles'] as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        return null;
    }

    /** Random card from the hidden pool; evil only if good would still strictly outnumber evil, else a filler. */
    private function drawLateRole(): string
    {
        $evil = 0;
        foreach ($this->players as $q) {
            $evil += $this->teamOf($q) === 'evil' ? 1 : 0;
        }
        $good = count($this->players) - $evil;
        $pool = array_values($this->pool);
        if ($pool) {
            $i = (int)floor($this->rand01() * count($pool));
            if (($this->roleDef($pool[$i])['team'] ?? null) !== 'evil' || $good > $evil + 1) {
                $role = $pool[$i];
                array_splice($pool, $i, 1);
                $this->pool = $pool;
                return $role;
            }
            // Evil card rejected: it stays in the pool (not consumed) for a later joiner.
            // Take a filler (else any good card) from the pool instead
            foreach ([true, false] as $onlyFiller) {
                foreach ($pool as $j => $id) {
                    $r = $this->roleDef($id);
                    if ($r['team'] === 'good' && (!$onlyFiller || !empty($r['filler']))) {
                        array_splice($pool, $j, 1);
                        $this->pool = $pool;
                        return $id;
                    }
                }
            }
        }
        // Final fallback (empty pool): a free filler card, not taken from the pool, so the
        // total of this role may exceed the configured count.
        foreach ($this->def['roles'] as $r) {
            if (!empty($r['filler'])) {
                return $r['id'];
            }
        }
        throw new GameError('No role card available.');
    }

    public function playerByToken(string $token): ?Player
    {
        foreach ($this->players as $p) {
            if (hash_equals($p->token, $token)) {
                return $p;
            }
        }
        return null;
    }

    /** Record that a player is polling. Only flips visible presence when it changes. */
    public function touch(Player $p): void
    {
        $p->lastSeen = $this->now();
        $this->dirty = true;
        if (!$p->connected) {
            $p->connected = true;
            $this->bump();
        }
    }

    public function removePlayer(string $id, string $byId): void
    {
        if ($this->phase !== 'lobby') {
            throw new GameError('Players can only leave before the game starts.');
        }
        if ($id === $this->hostId) {
            throw new GameError('The host cannot leave the game.');
        }
        if ($id !== $byId && $byId !== $this->hostId) {
            throw new GameError('Only the host can remove players.');
        }
        $this->players = array_values(array_filter($this->players, static fn(Player $p) => $p->id !== $id));
        $this->bump();
    }

    public function start(string $byId): void
    {
        if ($byId !== $this->hostId) {
            throw new GameError('Only the host can start the game.');
        }
        if ($this->phase !== 'lobby') {
            throw new GameError('The game has already started.');
        }
        $n = count($this->players);
        if ($n < Config::MIN_PLAYERS) {
            throw new GameError(sprintf('At least %d players needed (%d/%d).', Config::MIN_PLAYERS, $n, $this->total));
        }
        $comp = $this->composition($n);
        foreach ($this->shuffle($comp['dealt']) as $i => $roleId) {
            $this->players[$i]->role = $roleId;
        }
        $this->pool = $comp['pool'];
        $this->players = $this->shuffle($this->players); // seating order must not reveal roles
        $this->round = 0;
        $this->publicLog('The game begins. Everyone has received a role card. Day 0: discuss!', 'phase');
        $this->enter('discussion');
    }

    /**
     * Pure: which cards to deal to $n players and which stay in the hidden pool. Evil count is the
     * configured share scaled to $n (at least 1, always fewer than good); roles listed later in the
     * game definition are dropped first, and `filler` roles before other good roles.
     * @return array{dealt:string[],pool:string[]}
     */
    public function composition(int $n): array
    {
        $evil = [];
        $special = [];
        $filler = [];
        foreach ($this->def['roles'] as $r) {
            $cards = array_fill(0, (int)($this->roleCounts[$r['id']] ?? 0), $r['id']);
            if ($r['team'] === 'evil') {
                $evil = array_merge($evil, $cards);
            } elseif (!empty($r['filler'])) {
                $filler = array_merge($filler, $cards);
            } else {
                $special = array_merge($special, $cards);
            }
        }
        $e = (int)max(1, min(floor(($n - 1) / 2), round(count($evil) * $n / max(1, $this->total))));
        $e = min($e, count($evil));
        $good = array_merge($special, $filler); // specials kept first, fillers dropped first
        $dealt = array_merge(array_slice($evil, 0, $e), array_slice($good, 0, $n - $e));
        $pool = array_merge(array_slice($evil, $e), array_slice($good, $n - $e));
        return ['dealt' => $dealt, 'pool' => $pool];
    }

    /** Host edits the setup while still in the lobby. Same payload as `create`. */
    public function configure(string $byId, array $input): void
    {
        if ($byId !== $this->hostId) {
            throw new GameError('Only the host can change the settings.');
        }
        if ($this->phase !== 'lobby') {
            throw new GameError('Settings can only be changed before the game starts.');
        }
        if (!isset($input['roles']) || !is_array($input['roles'])) {
            throw new GameError('The roles setup (roles) is missing.');
        }
        $cfg = Config::normalize($this->def, $input);
        if ($cfg['total'] < count($this->players)) {
            throw new GameError(sprintf('%d players have already joined: the game needs at least %d seats.',
                count($this->players), count($this->players)));
        }
        $this->name = $cfg['name'];
        $this->description = $cfg['description'];
        $this->roleCounts = $cfg['roleCounts'];
        $this->settings = $cfg['settings'];
        $this->total = $cfg['total'];
        $this->bump();
    }

    /** Change own name/avatar at any time. Avatar key absent = keep, null/"" = remove, data URL = replace. */
    private function updateProfile(Player $p, array $cmd): void
    {
        $hasAvatar = array_key_exists('avatar', $cmd);
        $prof = Config::profile(['name' => $cmd['name'] ?? '', 'avatar' => $hasAvatar ? $cmd['avatar'] : null]);
        if ($p->profileAt > 0 && $this->now() - $p->profileAt < 3000) {
            throw new GameError('Please wait a few seconds before changing your profile again.');
        }
        foreach ($this->players as $q) {
            if ($q->id !== $p->id && mb_strtolower($q->name) === mb_strtolower($prof['name'])) {
                throw new GameError('That name is already taken in this game.');
            }
        }
        $p->name = $prof['name'];
        if ($hasAvatar) {
            $p->avatar = $prof['avatar'];
            $p->avatarV++;
        }
        $p->profileAt = $this->now();
        $this->bump();
    }

    // ---------- phase machine ----------
    private function secondsFor(string $phase): ?int
    {
        return [
            'discussion' => $this->settings['discussionSeconds'], 'vote' => $this->settings['voteSeconds'],
            'night' => $this->settings['nightSeconds'], 'hunter' => $this->settings['voteSeconds'],
        ][$phase] ?? null;
    }

    private function enter(string $phase): void
    {
        $this->phase = $phase;
        $this->seq++;
        $this->deadline = null;
        foreach ($this->players as $p) {
            $p->ready = false;
        }
        if ($phase === 'vote') {
            $this->votes = [];
            $this->publicLog('Time to vote. Who should be eliminated?', 'phase');
        } elseif ($phase === 'night') {
            $this->round++;
            $this->publicLog($this->text('nightFalls', $this->round), 'phase');
            $this->night = ['step' => 1, 'actions' => [], 'victim' => null, 'actors' => []];
            $this->night['actors'] = array_map(static fn(Player $p) => $p->id, $this->actorsForStep());
        } elseif ($phase === 'discussion' && $this->round > 0) {
            $this->publicLog($this->text('dayBreaks', $this->round), 'phase');
        }
        $secs = $this->secondsFor($phase);
        if ($secs) {
            $this->arm($secs);
        }
        $this->bump();
    }

    private function arm(int $seconds): void
    {
        $this->deadline = $this->now() + $seconds * 1000;
    }

    /** Apply expired deadlines and presence changes. Call at the start of every request. */
    public function tick(): void
    {
        $guard = 0;
        while ($this->deadline !== null && $this->now() >= $this->deadline && $guard++ < 3) {
            $this->advance();
        }
        foreach ($this->players as $p) {
            if ($p->connected && $this->now() - $p->lastSeen > self::PRESENCE_MS) {
                $p->connected = false;
                $this->bump();
            }
        }
    }

    /** Move on from the current phase (timer expiry, everyone ready, or host skip). */
    public function advance(): void
    {
        switch ($this->phase) {
            case 'discussion':
                $this->enter($this->round === 0 ? 'night' : 'vote');
                break;
            case 'vote':
                $this->resolveVote();
                break;
            case 'night':
                $this->advanceNight();
                break;
            case 'hunter':
                $this->finishHunter(null);
                break;
        }
    }

    // ---------- commands ----------
    /** Entry point for every player action. Throws GameError for invalid ones. */
    public function command(string $playerId, array $cmd): void
    {
        $p = $this->player($playerId) ?? throw new GameError('Unknown player.');
        switch ($cmd['t'] ?? '') {
            case 'start': $this->start($playerId); break;
            case 'kick': $this->removePlayer((string)($cmd['playerId'] ?? ''), $playerId); break;
            case 'leave': $this->removePlayer($playerId, $playerId); break;
            case 'chat': $this->chatMessage($p, $cmd['text'] ?? ''); break;
            case 'ready': $this->ready($p); break;
            case 'skip': $this->skip($p); break;
            case 'settings': $this->configure($playerId, $cmd); break;
            case 'profile': $this->updateProfile($p, $cmd); break;
            case 'vote': $this->vote($p, $this->idOrNull($cmd['target'] ?? null)); break;
            case 'action': $this->action($p, $cmd); break;
            default: throw new GameError('Unknown command.');
        }
    }

    private function idOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v)) {
            throw new GameError('Invalid target.');
        }
        return $v;
    }

    private function chatMessage(Player $p, mixed $text): void
    {
        $text = Config::clean($text, 300);
        if ($text === '') {
            return;
        }
        if ($this->phase === 'lobby' || $this->phase === 'ended') {
            $ch = 'all';
        } elseif (!$p->alive) {
            $ch = 'dead';
        } elseif ($this->phase === 'discussion' || $this->phase === 'vote') {
            $ch = 'all';
        } elseif ($this->teamOf($p) === 'evil') {
            $ch = 'evil';
        } else {
            throw new GameError('You cannot talk right now.');
        }
        $last = $this->chat ? end($this->chat)['id'] : 0;
        $this->chat[] = ['id' => $last + 1, 'ch' => $ch, 'from' => $p->id, 'text' => $text, 'ts' => $this->now()];
        if (count($this->chat) > 300) {
            $this->chat = array_slice($this->chat, -300);
        }
        $this->bump();
    }

    private function ready(Player $p): void
    {
        if ($this->phase !== 'discussion') {
            throw new GameError('Nothing to confirm right now.');
        }
        if (!$p->alive) {
            throw new GameError('You are out of the game.');
        }
        $p->ready = !$p->ready;
        foreach ($this->alive() as $q) {
            if (!$q->ready) {
                $this->bump();
                return;
            }
        }
        $this->advance();
    }

    private function skip(Player $p): void
    {
        if ($p->id !== $this->hostId) {
            throw new GameError('Only the host can skip.');
        }
        if (!in_array($this->phase, ['discussion', 'vote', 'night', 'hunter'], true)) {
            throw new GameError('Cannot skip this phase.');
        }
        $this->advance();
    }

    private function vote(Player $p, ?string $target): void
    {
        if ($this->phase !== 'vote') {
            throw new GameError('It is not time to vote.');
        }
        if (!$p->alive) {
            throw new GameError('Dead players cannot vote.');
        }
        if ($target !== null) {
            $t = $this->player($target);
            if (!$t || !$t->alive) {
                throw new GameError('Invalid target.');
            }
            if ($t->id === $p->id) {
                throw new GameError('You cannot vote for yourself.');
            }
        }
        $this->votes[$p->id] = $target;
        foreach ($this->alive() as $q) {
            if (!array_key_exists($q->id, $this->votes)) {
                $this->bump();
                return;
            }
        }
        $this->resolveVote();
    }

    // ---------- voting ----------
    private function resolveVote(): void
    {
        $tally = [];
        foreach ($this->votes as $t) {
            if ($t) {
                $tally[$t] = ($tally[$t] ?? 0) + 1;
            }
        }
        $top = $tally ? max($tally) : 0;
        $leaders = array_keys(array_filter($tally, static fn($n) => $n === $top));
        if ($top === 0 || count($leaders) !== 1) {
            $this->publicLog($top === 0 ? 'Nobody received a vote. No one is eliminated.'
                : 'The vote is tied. No one is eliminated.', 'vote');
            $this->afterDeaths('night');
            return;
        }
        $victim = $this->player((string)$leaders[0]);
        $this->publicLog($this->text('lynched', $victim->name) . $this->revealSuffix($victim), 'death');
        $this->kill($victim);
        $this->afterDeaths('night');
    }

    // ---------- deaths & victory ----------
    private function revealSuffix(Player $p): string
    {
        return $this->settings['revealRoles'] ? " They were a {$this->roleOf($p)['name']}." : '';
    }

    private function kill(Player $p): void
    {
        if (!$p->alive) {
            return;
        }
        $p->alive = false;
        if (($this->roleOf($p)['onDeath'] ?? null) === 'shoot') {
            $this->pendingHunters[] = $p->id;
        }
    }

    private function afterDeaths(string $next): void
    {
        $hunterId = array_shift($this->pendingHunters);
        if ($hunterId !== null && count($this->alive()) > 0) {
            $h = $this->player($hunterId);
            $this->hunter = $hunterId;
            $this->hunterNext = $next;
            $this->publicLog("{$h->name} is a {$this->roleOf($h)['name']}! They take a last shot…", 'phase');
            $this->enter('hunter');
            return;
        }
        if ($this->checkWin()) {
            return;
        }
        $this->enter($next);
    }

    private function finishHunter(?string $targetId): void
    {
        $hunter = $this->player($this->hunter);
        $target = $targetId ? $this->player($targetId) : null;
        $this->hunter = null;
        if ($target) {
            $this->publicLog($this->text('shot', $target->name, $hunter->name) . $this->revealSuffix($target), 'death');
            $this->kill($target);
        } else {
            $this->publicLog("{$hunter->name} did not shoot anyone.", 'info');
        }
        $this->afterDeaths($this->hunterNext);
    }

    private function checkWin(): bool
    {
        $alive = $this->alive();
        $evil = count(array_filter($alive, fn(Player $p) => $this->teamOf($p) === 'evil'));
        $good = count($alive) - $evil;
        $winner = null;
        if ($evil === 0) {
            $winner = 'good';
        } elseif ($evil >= $good) {
            $winner = 'evil';
        }
        if ($winner === null) {
            return false;
        }
        $this->winner = $winner;
        $this->phase = 'ended';
        $this->seq++;
        $this->deadline = null;
        $this->publicLog($this->def['text']['win'][$winner], 'win');
        $this->bump();
        return true;
    }

    // ---------- night ----------
    /** @return Player[] */
    private function actorsForStep(): array
    {
        $step = $this->night['step'];
        return array_values(array_filter($this->alive(), function (Player $p) use ($step) {
            $kind = $this->nightKind($p);
            if ($kind === null) {
                return false;
            }
            if ($step === 1) {
                return $kind !== 'witch' && !($kind === 'shoot' && !empty($p->state['shotUsed']));
            }
            return $kind === 'witch'
                && (empty($p->state['poisonUsed']) || (empty($p->state['healUsed']) && $this->night['victim']));
        }));
    }

    /** @return Player[] */
    private function validTargets(Player $p, string $kind): array
    {
        $alive = $this->alive();
        return match ($kind) {
            'kill' => array_values(array_filter($alive, fn(Player $q) => $this->teamOf($q) !== 'evil')),
            'protect' => $alive,
            'hunter', 'inspect', 'block', 'shoot', 'poison' =>
                array_values(array_filter($alive, static fn(Player $q) => $q->id !== $p->id)),
            default => [],
        };
    }

    private function isValidTarget(Player $p, string $kind, string $target): bool
    {
        foreach ($this->validTargets($p, $kind) as $q) {
            if ($q->id === $target) {
                return true;
            }
        }
        return false;
    }

    private function action(Player $p, array $cmd): void
    {
        if ($this->phase === 'hunter') {
            if ($p->id !== $this->hunter) {
                throw new GameError('It is not your turn.');
            }
            $target = $this->idOrNull($cmd['target'] ?? null);
            if ($target !== null && !$this->isValidTarget($p, 'hunter', $target)) {
                throw new GameError('Invalid target.');
            }
            $this->finishHunter($target);
            return;
        }
        if ($this->phase !== 'night') {
            throw new GameError('It is not night.');
        }
        if (!in_array($p->id, $this->night['actors'], true)) {
            throw new GameError('You cannot act right now.');
        }
        $kind = $this->nightKind($p);
        if ($kind === 'witch') {
            $heal = !empty($cmd['heal']);
            $poison = $this->idOrNull($cmd['poison'] ?? null);
            if ($heal && (!empty($p->state['healUsed']) || !$this->night['victim'])) {
                throw new GameError('You cannot heal now.');
            }
            if ($poison !== null && (!empty($p->state['poisonUsed']) || !$this->isValidTarget($p, 'poison', $poison))) {
                throw new GameError('You cannot poison that player.');
            }
            $action = ['heal' => $heal, 'poison' => $poison];
        } else {
            $target = $this->idOrNull($cmd['target'] ?? null);
            if ($target !== null && !$this->isValidTarget($p, $kind, $target)) {
                throw new GameError('Invalid target.');
            }
            $action = ['target' => $target];
        }
        $this->night['actions'][$p->id] = $action;
        foreach ($this->night['actors'] as $id) {
            if (!array_key_exists($id, $this->night['actions'])) {
                $this->bump();
                return;
            }
        }
        $this->advanceNight();
    }

    private function advanceNight(): void
    {
        if ($this->night['step'] === 1) {
            $this->night['victim'] = $this->teamVictim($this->blockedSet());
            $this->night['step'] = 2;
            $this->night['actors'] = array_map(static fn(Player $p) => $p->id, $this->actorsForStep());
            if ($this->night['actors']) {
                $this->seq++;
                if ($this->settings['nightSeconds'] > 0) {
                    $this->arm($this->settings['nightSeconds']);
                }
                $this->bump();
                return;
            }
        }
        $this->resolveNight();
    }

    /** @return array<string,true> */
    private function blockedSet(): array
    {
        $blocked = [];
        foreach ($this->night['actions'] as $id => $a) {
            $p = $this->player((string)$id);
            if ($p && $this->nightKind($p) === 'block' && !empty($a['target'])) {
                $blocked[$a['target']] = true;
            }
        }
        return $blocked;
    }

    private function teamVictim(array $blocked): ?string
    {
        $votes = [];
        foreach ($this->night['actions'] as $id => $a) {
            $p = $this->player((string)$id);
            if (!$p || $this->nightKind($p) !== 'kill' || empty($a['target']) || isset($blocked[$id])) {
                continue;
            }
            $votes[$a['target']] = ($votes[$a['target']] ?? 0) + 1;
        }
        if (!$votes) {
            return null;
        }
        $top = max($votes);
        $leaders = array_keys(array_filter($votes, static fn($n) => $n === $top));
        return (string)$leaders[(int)floor($this->rand01() * count($leaders))];
    }

    private function resolveNight(): void
    {
        $blocked = $this->blockedSet();
        $acts = [];
        foreach ($this->night['actions'] as $id => $a) {
            if ($p = $this->player((string)$id)) {
                $acts[] = ['p' => $p, 'a' => $a, 'kind' => $this->nightKind($p)];
            }
        }
        $effective = [];
        foreach ($acts as $x) {
            if (isset($blocked[$x['p']->id])) {
                $this->note($x['p'], 'Someone kept you busy tonight: your action failed.');
            } else {
                $effective[] = $x;
            }
        }
        $of = static fn(string $kind) => array_values(array_filter($effective, static fn($x) => $x['kind'] === $kind));
        $victim = $this->night['victim'];

        $protected = [];
        $doctors = $of('protect');
        foreach ($doctors as $x) {
            if ($x['a']['target']) {
                $protected[$x['a']['target']] = true;
            }
        }

        $attacks = [];
        $healed = false;
        foreach ($of('witch') as $x) {
            $p = $x['p'];
            if ($x['a']['heal'] && $victim && empty($p->state['healUsed'])) {
                $p->state['healUsed'] = true;
                $healed = true;
                $this->note($p, "You healed {$this->player($victim)->name}.");
            }
            if ($x['a']['poison'] && empty($p->state['poisonUsed'])) {
                $p->state['poisonUsed'] = true;
                $attacks[] = $x['a']['poison'];
                $this->note($p, "You poisoned {$this->player($x['a']['poison'])->name}.");
            }
        }
        if ($victim && !$healed) {
            $attacks[] = $victim;
        }
        foreach ($of('shoot') as $x) {
            if ($x['a']['target']) {
                $x['p']->state['shotUsed'] = true;
                $attacks[] = $x['a']['target'];
            }
        }

        $dying = [];
        foreach ($attacks as $id) {
            if (!isset($protected[$id]) && !in_array($id, $dying, true)) {
                $dying[] = $id;
            }
        }
        foreach ($doctors as $x) {
            if ($x['a']['target'] && in_array($x['a']['target'], $attacks, true)) {
                $this->note($x['p'], "{$this->player($x['a']['target'])->name} was attacked tonight and you saved them!");
            }
        }
        foreach ($of('inspect') as $x) {
            if ($x['a']['target']) {
                $t = $this->player($x['a']['target']);
                $appears = $this->roleOf($t)['appearsAs'] ?? $this->teamOf($t);
                $this->note($x['p'], "{$t->name} {$this->def['text']['inspect'][$appears]}.");
            }
        }

        $this->night = null;
        $this->phase = 'resolving';
        if (!$dying) {
            $this->publicLog($this->def['text']['nobodyDied'], 'info');
        }
        foreach ($dying as $id) {
            $p = $this->player($id);
            $this->publicLog($this->text('foundDead', $p->name) . $this->revealSuffix($p), 'death');
            $this->kill($p);
        }
        $this->afterDeaths('discussion');
    }

    // ---------- views ----------
    private function canSeeRole(Player $viewer, Player $p): bool
    {
        if ($p->id === $viewer->id || $this->phase === 'ended') {
            return true;
        }
        if (!$p->alive && $this->settings['revealRoles']) {
            return true;
        }
        return $this->teamOf($viewer) === 'evil' && $this->teamOf($p) === 'evil';
    }

    private function actionFor(Player $p): ?array
    {
        if ($this->phase === 'hunter' && $p->id === $this->hunter) {
            return ['kind' => 'hunter', 'prompt' => 'You are dying. Choose who to shoot.', 'done' => false,
                'targets' => array_map(static fn($q) => $q->id, $this->validTargets($p, 'hunter'))];
        }
        if ($this->phase !== 'night' || !$p->alive || !$this->nightKind($p)) {
            return null;
        }
        $n = $this->roleOf($p)['night'];
        $mine = $this->night['actions'][$p->id] ?? null;
        if (!in_array($p->id, $this->night['actors'], true)) {
            return ['kind' => $n['kind'], 'prompt' => $n['prompt'], 'waiting' => true];
        }
        $out = ['kind' => $n['kind'], 'prompt' => $n['prompt'], 'done' => $mine !== null, 'choice' => $mine];
        if ($n['kind'] === 'witch') {
            $v = $this->night['victim'];
            $out['victim'] = $v ? $this->player($v)->name : null;
            $out['canHeal'] = empty($p->state['healUsed']) && $v !== null;
            $out['canPoison'] = empty($p->state['poisonUsed']);
            $out['targets'] = array_map(static fn($q) => $q->id, $this->validTargets($p, 'poison'));
        } else {
            $out['targets'] = array_map(static fn($q) => $q->id, $this->validTargets($p, $n['kind']));
            if ($n['kind'] === 'kill') {
                $team = [];
                foreach ($this->night['actions'] as $id => $a) {
                    if ($this->teamOf($this->player((string)$id)) === 'evil') {
                        $team[$id] = $a['target'];
                    }
                }
                $out['teamChoices'] = (object)$team;
            }
        }
        return $out;
    }

    /** What `$viewerId` is allowed to know. Never leak other players' hidden roles here. */
    public function viewFor(string $viewerId): array
    {
        $me = $this->player($viewerId);
        $role = $this->roleOf($me);
        $isEvil = ($role['team'] ?? null) === 'evil';
        $chat = array_values(array_filter($this->chat, fn($m) =>
            $m['ch'] === 'all'
            || ($m['ch'] === 'dead' && (!$me->alive || $this->phase === 'ended'))
            || ($m['ch'] === 'evil' && $isEvil)));
        $players = [];
        foreach ($this->players as $p) {
            $row = ['id' => $p->id, 'name' => $p->name, 'avatarV' => $p->avatarV, 'hasAvatar' => $p->avatar !== null,
                'alive' => $p->alive, 'connected' => $p->connected, 'ready' => $p->ready];
            if ($this->phase === 'vote') {
                $row['hasVoted'] = array_key_exists($p->id, $this->votes);
            }
            if ($p->role !== null && $this->canSeeRole($me, $p)) {
                $row['role'] = $p->role;
            }
            $players[] = $row;
        }
        $view = [
            'id' => $this->id, 'type' => $this->type, 'name' => $this->name, 'description' => $this->description,
            'phase' => $this->phase, 'round' => $this->round, 'seq' => $this->seq, 'version' => $this->version,
            'deadline' => $this->deadline, 'now' => $this->now(), 'joinable' => $this->joinable(), 'total' => $this->total, 'hostId' => $this->hostId,
            'settings' => $this->settings, 'roleCounts' => $this->roleCounts, 'winner' => $this->winner,
            'players' => $players,
            'me' => [
                'id' => $me->id, 'role' => $me->role, 'team' => $role['team'] ?? null, 'alive' => $me->alive,
                'isHost' => $me->id === $this->hostId, 'notes' => $me->notes, 'action' => $this->actionFor($me),
            ],
            'log' => $this->log, 'chat' => $chat,
        ];
        if ($this->phase === 'vote') {
            $view['votes'] = (object)$this->votes;
        }
        return $view;
    }

    /** Avatars (data URLs) of the requested players, for the polling client. */
    public function avatars(array $ids): array
    {
        $out = [];
        foreach ($this->players as $p) {
            if ($p->avatar !== null && in_array($p->id, $ids, true)) {
                $out[$p->id] = $p->avatar;
            }
        }
        return $out;
    }
}
