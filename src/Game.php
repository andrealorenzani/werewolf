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
    private const CHAT_KEEP = 300;        // messages kept in the chat history
    private const CHAT_KEEP_STORY = 1000; // ... when chat is replayed in the final story

    public string $id;
    public string $type;
    public string $name;
    public string $description;
    public array $roleCounts;
    public array $settings;
    public int $total;
    /** Secret ghost-trait holders to deal (setup choice, <= number of werewolves). null = old save: keep what exists. */
    public ?int $ghosts = null;
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
    /** Public ghost marks of the current day: [[targetId, ghostId], …]; cleared at nightfall. */
    public array $marks = [];
    public int $createdAt;
    /** ms epoch when the game ended (retention: ended games are deleted after a while). Never in a view. */
    public ?int $endedAt = null;
    /** Log lines withheld until the matching hunter has shot (a hunter killed by another hunter). NEVER put in a view. */
    public array $heldLog = [];
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
        if (isset($this->settings)) {
            $this->settings['nightSeconds'] = Config::nightSeconds($this->settings['nightSeconds'] ?? null); // old saves: timer was optional
        }
    }

    /** @param array $config result of Config::normalize */
    public static function create(string $id, string $type, array $config, array $deps = []): self
    {
        $g = new self([
            'id' => $id, 'type' => $type, 'name' => $config['name'], 'description' => $config['description'],
            'roleCounts' => $config['roleCounts'], 'settings' => $config['settings'], 'total' => $config['total'],
            'ghosts' => $config['ghosts'] ?? 0,
            'createdAt' => isset($deps['clock']) ? (int)($deps['clock'])() : (int)(microtime(true) * 1000),
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
        foreach (['id', 'type', 'name', 'description', 'roleCounts', 'settings', 'total', 'ghosts', 'hostId', 'phase', 'round',
            'deadline', 'seq', 'version', 'log', 'chat', 'winner', 'votes', 'night', 'hunter', 'pendingHunters',
            'hunterNext', 'marks', 'createdAt', 'pool', 'endedAt', 'heldLog'] as $k) {
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
        if (in_array($p->id, $this->night['ghosts'] ?? [], true)) {
            return 'haunt';
        }
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
        // 'cs' = id of the last chat message so far: lets the final story interleave chat with the log
        $this->log[] = ['round' => $this->round, 'phase' => $this->phase, 'type' => $type, 'text' => $text,
            'cs' => $this->chat ? end($this->chat)['id'] : 0];
    }

    /** Like publicLog but withheld (heldLog) until flushHeld(). */
    private function holdLog(string $text, string $type): void
    {
        $this->heldLog[] = ['round' => $this->round, 'phase' => $this->phase, 'type' => $type, 'text' => $text,
            'cs' => $this->chat ? end($this->chat)['id'] : 0];
    }

    private function flushHeld(): void
    {
        if ($this->heldLog) {
            array_push($this->log, ...$this->heldLog);
            $this->heldLog = [];
        }
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
        if ($this->phase !== 'lobby') {
            $this->assignGhostSlots();
        }
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

    /**
     * Random card from the hidden pool; evil only if good would still strictly outnumber evil, and no card that
     * would exceed its setup cap (see Config::capFor); else a filler.
     */
    private function drawLateRole(): string
    {
        $evil = 0;
        $counts = [];
        foreach ($this->players as $q) {
            $evil += $this->teamOf($q) === 'evil' ? 1 : 0;
            if ($q->role !== null) {
                $counts[$q->role] = ($counts[$q->role] ?? 0) + 1;
            }
        }
        $good = count($this->players) - $evil;
        $allowed = function (string $id) use ($good, $evil, $counts): bool {
            $r = $this->roleDef($id);
            $isEvil = ($r['team'] ?? null) === 'evil';
            if ($isEvil && $good <= $evil + 1) {
                return false;
            }
            if (isset($r['cap'])) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
                return $counts[$id] <= Config::capFor($r, $counts, $this->def, $good + ($isEvil ? 0 : 1));
            }
            return true;
        };
        $pool = array_values($this->pool);
        if ($pool) {
            $i = (int)floor($this->rand01() * count($pool));
            if ($allowed($pool[$i])) {
                $role = $pool[$i];
                array_splice($pool, $i, 1);
                $this->pool = $pool;
                return $role;
            }
            // Card rejected: it stays in the pool (not consumed) for a later joiner.
            // Take a filler (else any allowed good card) from the pool instead
            foreach ([true, false] as $onlyFiller) {
                foreach ($pool as $j => $id) {
                    $r = $this->roleDef($id);
                    if ($r['team'] === 'good' && (!$onlyFiller || !empty($r['filler'])) && $allowed($id)) {
                        array_splice($pool, $j, 1);
                        $this->pool = $pool;
                        return $id;
                    }
                }
            }
        }
        // Final fallback (empty pool): a free filler card, not taken from the pool, so the
        // total of this role may exceed the configured count.
        return $this->freeFiller();
    }

    /**
     * Ghost trait (games whose definition has a `ghost` entry): `ghosts` (setup choice) random alive non-evil
     * players carry a secret ghost slot, never more than the evil players actually dealt. Only tops up (early
     * start, late join); old saves without the setting (null) keep what they have. Private to the holder.
     */
    private function assignGhostSlots(): void
    {
        if (!isset($this->def['ghost']) || $this->ghosts === null) {
            return;
        }
        $dealt = array_filter($this->players, fn(Player $p) => $p->role !== null);
        $evil = count(array_filter($dealt, fn(Player $p) => $this->teamOf($p) === 'evil'));
        $cands = array_values(array_filter($dealt, fn(Player $p) => $p->alive && $this->teamOf($p) !== 'evil'
            && !isset($this->roleOf($p)['haunt'])));
        $want = min($this->ghosts, $evil, count($cands));
        // holders count whether alive or not, so a dead holder is never replaced by a late top-up
        $have = count(array_filter($dealt, static fn(Player $p) => !empty($p->state['ghostSlot'])));
        $free = $this->shuffle(array_values(array_filter($cands, static fn(Player $p) => empty($p->state['ghostSlot']))));
        foreach (array_slice($free, 0, max(0, $want - $have)) as $p) {
            $p->state['ghostSlot'] = true;
            $this->note($p, 'A restless spirit clings to you. If you die in the night, your ghost will rise.');
        }
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
        if ($this->phase !== 'ended') {
            $this->dirty = true; // an ended game is not rewritten just because someone is still looking at it
        }
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

    /** Only the host may delete the game for everyone. */
    public function canDelete(string $playerId): bool
    {
        return $this->hostId !== null && $playerId === $this->hostId;
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
        $this->assignGhostSlots();
        $this->round = 0;
        $this->publicLog('The game begins. Everyone has received a role card. Day 0: discuss!', 'phase');
        $this->enter('discussion');
    }

    /**
     * Pure: which cards to deal to $n players and which stay in the hidden pool. Evil count is the
     * configured share scaled to $n (at least 1, always fewer than good); roles listed later in the
     * game definition are dropped first, and `filler` roles before other good roles. Capped roles
     * (Config::capFor) are clamped to the caps for the actual $n; the excess goes to the pool.
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
        while ($e > 1 && !$this->evilWithinCaps(array_slice($evil, 0, $e), $n - $e)) {
            $e--;
        }
        $evilCounts = array_count_values(array_slice($evil, 0, $e));
        $good = [];
        $rest = [];
        foreach (array_merge($special, $filler) as $id) { // specials kept first, fillers dropped first
            $r = $this->roleDef($id);
            $taken = array_count_values($good);
            if (count($good) < $n - $e && (!isset($r['cap']) || ($taken[$id] ?? 0) < Config::capFor($r, $evilCounts + $taken, $this->def, $n - $e))) {
                $good[] = $id;
            } else {
                $rest[] = $id;
            }
        }
        while (count($good) < $n - $e) { // every card left is over its cap: a free filler
            $good[] = $this->freeFiller();
        }
        return ['dealt' => array_merge(array_slice($evil, 0, $e), $good), 'pool' => array_merge(array_slice($evil, $e), $rest)];
    }

    /** Do these evil cards respect their caps when $nonEvil other players are dealt? */
    private function evilWithinCaps(array $evilCards, int $nonEvil): bool
    {
        $counts = array_count_values($evilCards);
        foreach ($counts as $id => $c) {
            $r = $this->roleDef((string)$id);
            if (isset($r['cap']) && $c > Config::capFor($r, $counts, $this->def, $nonEvil)) {
                return false;
            }
        }
        return true;
    }

    private function freeFiller(): string
    {
        foreach ($this->def['roles'] as $r) {
            if (!empty($r['filler'])) {
                return $r['id'];
            }
        }
        throw new GameError('No role card available.');
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
        $this->ghosts = $cfg['ghosts'];
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
            $this->marks = [];
            $this->night = ['step' => 1, 'actions' => [], 'suggest' => [], 'victim' => null, 'actors' => [], 'dying' => [], 'ghosts' => []];
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
        if ($this->phase === 'ended' && $this->endedAt === null) {
            $this->endedAt = $this->now(); // old save: fix the retention clock once, later writes must not move it
            $this->dirty = true;
        }
        if ($this->phase === 'night' && $this->deadline === null) {
            $this->arm($this->settings['nightSeconds']); // old save from the time the night timer was optional
            $this->dirty = true;
        }
        $guard = 0;
        while ($this->deadline !== null && $this->now() >= $this->deadline && $guard++ < 3) {
            $this->advance();
        }
        foreach ($this->phase === 'ended' ? [] : $this->players as $p) {
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
            case 'chat': $this->chatMessage($p, $cmd['text'] ?? '', $this->idOrNull($cmd['to'] ?? null)); break;
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

    /** Channel a player's messages go to right now, or null when they must stay quiet. */
    private function chatChannel(Player $p): ?string
    {
        if ($this->phase === 'lobby' || $this->phase === 'ended') {
            return 'all';
        }
        if (!$p->alive) {
            return 'dead';
        }
        if ($this->phase === 'discussion' || $this->phase === 'vote') {
            return 'all';
        }
        return $this->teamOf($p) === 'evil' ? 'evil' : null;
    }

    /** $to = recipient id for a private message (DM), null for the normal channel. */
    private function chatMessage(Player $p, mixed $text, ?string $to = null): void
    {
        $text = Config::clean($text, 300);
        if ($text === '') {
            return;
        }
        $ch = $this->chatChannel($p);
        if ($to === null) {
            $ch ?? throw new GameError('You cannot talk right now.');
        } else {
            $q = $this->player($to);
            $inLobbyOrEnd = $this->phase === 'lobby' || $this->phase === 'ended';
            if (!$q || $q->id === $p->id || $this->phase === 'night' || !in_array($ch, ['all', 'dead'], true)
                || (!$inLobbyOrEnd && $q->alive !== ($ch === 'all'))) {
                throw new GameError('You cannot send private messages right now.');
            }
            $ch = 'dm';
        }
        $last = $this->chat ? end($this->chat)['id'] : 0;
        $m = ['id' => $last + 1, 'ch' => $ch, 'from' => $p->id];
        if ($to !== null) {
            $m['to'] = $to;
        }
        $this->chat[] = $m + ['text' => $text, 'ts' => $this->now(), 'ph' => $this->phase];
        $story = !empty($this->settings['storyPublicChat']) || !empty($this->settings['storyPrivateChat']);
        $keep = $story ? self::CHAT_KEEP_STORY : self::CHAT_KEEP;
        if (count($this->chat) > $keep) {
            $this->chat = array_slice($this->chat, -$keep);
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
    private function revealSuffix(Player $p, bool $force = false): string
    {
        return $force || $this->settings['revealRoles'] ? ' They were a ' . ($this->roleOf($p)['name'] ?? 'mystery') . '.' : '';
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
        $this->flushHeld();
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
        $this->flushHeld(); // a shot at this hunter, withheld so that he shot without knowing why he died
        if ($target) {
            $line = $this->text('shot', $target->name, $hunter->name) . $this->revealSuffix($target);
            if (($this->roleOf($target)['onDeath'] ?? null) === 'shoot') {
                $this->holdLog($line, 'death'); // the target is a hunter too: he shoots first, blind
            } else {
                $this->publicLog($line, 'death');
            }
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
        $this->flushHeld();
        $this->winner = $winner;
        $this->endedAt = $this->now();
        $this->phase = 'ended';
        $this->seq++;
        $this->deadline = null;
        $this->publicLog($this->def['text']['win'][$winner], 'win');
        $this->bump();
        return true;
    }

    // ---------- night ----------
    /**
     * Night steps: 1 = everyone without `night.step`, 2 = roles with `step` 2 (they see the victim),
     * 3 = ghosts created by this night's deaths (haunt). Effects are applied once, between 2 and 3.
     * @return Player[]
     */
    private function actorsForStep(): array
    {
        $step = $this->night['step'];
        if ($step === 3) {
            return array_values(array_filter($this->alive(), fn(Player $p) => in_array($p->id, $this->night['ghosts'] ?? [], true)
                && $this->canAct($p, 'haunt')));
        }
        return array_values(array_filter($this->alive(), function (Player $p) use ($step) {
            $kind = $this->nightKind($p);
            return $kind !== null && ($this->roleOf($p)['night']['step'] ?? 1) === $step && $this->canAct($p, $kind);
        }));
    }

    private function canAct(Player $p, string $kind): bool
    {
        return match ($kind) {
            'witch' => empty($p->state['poisonUsed']) || (empty($p->state['healUsed']) && $this->night['victim']),
            'guard' => $this->usesLeft($p) > 0 && $this->night['victim'] !== null,
            'shoot' => empty($p->state['shotUsed']),
            'haunt' => (bool)$this->validTargets($p, 'haunt'),
            default => true,
        };
    }

    /**
     * Charges left for a limited power: team size (dead included) minus the charges already spent.
     * The charges are shared by every player with the same role (all paladins draw from one stock).
     */
    private function usesLeft(Player $p): int
    {
        $team = $this->roleOf($p)['night']['limit']['countTeam'] ?? null;
        if ($team === null) {
            return PHP_INT_MAX;
        }
        $n = count(array_filter($this->players, fn(Player $q) => $this->teamOf($q) === $team));
        $used = 0;
        foreach ($this->players as $q) {
            if ($q->role === $p->role) {
                $used += (int)($q->state['guardUsed'] ?? 0);
            }
        }
        return max(0, $n - $used);
    }

    /** Spec of the ghost this player would become: the role's own (legacy) or the secret ghost trait. */
    private function hauntSpec(Player $p): ?array
    {
        return $this->roleOf($p)['haunt'] ?? (!empty($p->state['ghostSlot']) ? ($this->def['ghost'] ?? null) : null);
    }

    /** @return string[] ids of the killers (evil team) acting in the current step */
    private function killers(): array
    {
        return array_values(array_filter($this->night['actors'] ?? [], fn(string $id) => ($q = $this->player($id)) && $this->nightKind($q) === 'kill'));
    }

    /** The victim every acting killer suggests, or null while they disagree (or some have not suggested). */
    private function agreedTarget(): ?string
    {
        $t = null;
        foreach ($this->killers() as $id) {
            $s = $this->night['suggest'][$id] ?? null;
            if ($s === null || ($t !== null && $s !== $t)) {
                return null;
            }
            $t = $s;
        }
        return $t;
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
            // a good ghost points at someone evil, an evil ghost at someone good; never at the dying
            'haunt' => array_values(array_filter($alive, fn(Player $q) => $q->id !== $p->id
                && !in_array($q->id, $this->night['dying'] ?? [], true)
                && !empty($p->state['ghost']['evil']) === ($this->teamOf($q) !== 'evil'))),
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
        } elseif ($kind === 'guard') {
            if (!is_bool($cmd['save'] ?? null)) {
                throw new GameError('Invalid choice.');
            }
            $action = ['save' => $cmd['save']];
        } elseif ($kind === 'kill') {
            $this->killAction($p, $cmd);
            $this->stepProgress();
            return;
        } else {
            $target = $this->idOrNull($cmd['target'] ?? null);
            if ($target !== null && !$this->isValidTarget($p, $kind, $target)) {
                throw new GameError('Invalid target.');
            }
            $action = ['target' => $target];
        }
        $this->night['actions'][$p->id] = $action;
        $this->stepProgress();
    }

    /**
     * The killers suggest a victim (`target`; any change clears the confirmations) and, once all suggest the
     * same player, each confirms (`confirm`). The step ends when every acting killer has confirmed. They cannot pass.
     */
    private function killAction(Player $p, array $cmd): void
    {
        if (!empty($cmd['confirm'])) {
            if ($this->agreedTarget() === null) {
                throw new GameError('Your team has not agreed on a victim yet.');
            }
            $this->night['actions'][$p->id] = ['target' => $this->night['suggest'][$p->id]];
            return;
        }
        $target = $this->idOrNull($cmd['target'] ?? null);
        if ($target === null) {
            throw new GameError('Choose a victim: your team cannot pass.');
        }
        if (!$this->isValidTarget($p, 'kill', $target)) {
            throw new GameError('Invalid target.');
        }
        if (($this->night['suggest'][$p->id] ?? null) !== $target) {
            $this->night['suggest'][$p->id] = $target;
            foreach ($this->killers() as $id) {
                unset($this->night['actions'][$id]); // a changed suggestion voids every confirmation
            }
        }
    }

    /** After an action: end the step when every actor is done, else just publish the change. */
    private function stepProgress(): void
    {
        foreach ($this->night['actors'] as $id) {
            if (!array_key_exists($id, $this->night['actions'])) {
                $this->bump();
                return;
            }
        }
        $this->advanceNight();
    }

    /** The current step is over: run the next step that has actors, or resolve the night. */
    private function advanceNight(): void
    {
        while ($this->night['step'] < 3) {
            $done = $this->night['step'];
            if ($done === 1) {
                $this->night['victim'] = $this->teamVictim($this->blockedSet());
            } elseif ($done === 2) {
                $this->applyNightEffects();
            }
            $this->night['step'] = $done + 1;
            $this->night['actors'] = array_map(static fn(Player $p) => $p->id, $this->actorsForStep());
            if ($this->night['actors']) {
                $this->seq++;
                $this->arm($this->settings['nightSeconds']);
                $this->bump();
                return;
            }
        }
        $this->finishNight();
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

    /**
     * The evil team's victim: the player their (unblocked) killers suggest. When everyone confirmed this is a
     * single player; on a timeout or host skip a random one among the distinct suggestions; none = nobody dies.
     */
    private function teamVictim(array $blocked): ?string
    {
        $suggest = $this->night['suggest'] ?? [];
        foreach ($this->night['actions'] as $id => $a) { // a night saved before suggestions existed: the plain choices count
            if (!empty($a['target'])) {
                $suggest += [$id => $a['target']];
            }
        }
        $picks = [];
        foreach ($suggest as $id => $target) {
            $p = $this->player((string)$id);
            if ($p && $this->nightKind($p) === 'kill' && !isset($blocked[$id])) {
                $picks[$target] = true;
            }
        }
        $picks = array_map('strval', array_keys($picks));
        if (!$picks) {
            return null;
        }
        return count($picks) === 1 ? $picks[0] : $picks[(int)floor($this->rand01() * count($picks))];
    }

    /** Apply every night action (once, before the ghosts act): saves, potions, shots, inspections. */
    private function applyNightEffects(): void
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
        $guarded = false;
        // Paladins decide together: every living paladin must choose to save; then ONE shared charge is spent
        $paladins = array_values(array_filter($this->alive(), fn(Player $q) => $this->nightKind($q) === 'guard'));
        $saves = array_filter($of('guard'), static fn($x) => !empty($x['a']['save']));
        if ($victim && $paladins && count($saves) === count($paladins) && $this->usesLeft($paladins[0]) > 0) {
            $paladins[0]->state['guardUsed'] = (int)($paladins[0]->state['guardUsed'] ?? 0) + 1;
            $guarded = true;
            foreach ($paladins as $q) {
                $this->note($q, count($paladins) > 1
                    ? "You and your fellow paladins shielded {$this->player($victim)->name} from tonight's attack."
                    : "You shielded {$this->player($victim)->name} from tonight's attack.");
            }
        }
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
        $wolfKills = $victim && !$healed && !$guarded;
        if ($wolfKills) {
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

        // A dying player whose card (legacy: wolf kill only) or secret trait haunts rises as a ghost
        $ghosts = [];
        foreach ($dying as $id) {
            $v = $this->player($id);
            $haunt = $v ? $this->hauntSpec($v) : null;
            if ($haunt && (($haunt['trigger'] ?? null) !== 'evil' || ($wolfKills && $id === $victim)) && $this->raiseGhost($v, $haunt)) {
                $ghosts[] = $v->id;
            }
        }
        $this->night['dying'] = $dying;
        $this->night['ghosts'] = $ghosts;
    }

    /** Turn a dying player into a ghost (once). Returns false when they already are one. */
    private function raiseGhost(Player $v, array $haunt): bool
    {
        if (!empty($v->state['ghost'])) {
            return false;
        }
        $evil = $this->rand01() < (float)($haunt['evilChance'] ?? 0.5);
        $v->state['ghost'] = ['evil' => $evil];
        unset($this->night['actions'][$v->id]); // the earlier action is spent; the ghost acts again in step 3
        $good = $this->def['teams']['good']['name'];
        $bad = $this->def['teams']['evil']['name'];
        $this->note($v, $evil
            ? "Your ghost lingers, but death has turned you: you now side with the {$bad}. Point a finger at someone good to make the others doubt them."
            : "Your ghost lingers and you stay on the side of the {$good}. Point a finger at someone you believe belongs to the {$bad}.");
        return true;
    }

    /** Dawn: announce the deaths (ghosts pointing a finger), kill, and move on to the day. */
    private function finishNight(): void
    {
        $dying = $this->night['dying'] ?? [];
        $ghosts = $this->night['ghosts'] ?? [];
        $actions = $this->night['actions'];
        $this->night = null;
        $this->phase = 'resolving';
        if (!$dying) {
            $this->publicLog($this->def['text']['nobodyDied'], 'info');
        }
        foreach ($dying as $id) {
            $p = $this->player($id);
            $pick = in_array($id, $ghosts, true) ? ($actions[$id]['target'] ?? null) : null;
            if ($pick && ($t = $this->player($pick))) {
                // the haunting line names the card of a legacy Ghost even when roles are not revealed
                $this->publicLog($this->text('ghostFound', $p->name, $t->name) . $this->revealSuffix($p, isset($this->roleOf($p)['haunt'])), 'death');
                $this->marks[] = [$t->id, $p->id];
            } else {
                $this->publicLog($this->text('foundDead', $p->name) . $this->revealSuffix($p), 'death');
            }
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
        if (!empty($viewer->state['ghost'])) {
            return true; // a ghost sees through everyone
        }
        if (!empty($this->roleOf($viewer)['knowsEachOther']) && $viewer->role === $p->role) {
            return true; // e.g. paladins know each other
        }
        return $this->teamOf($viewer) === 'evil' && $this->teamOf($p) === 'evil';
    }

    private function actionFor(Player $p): ?array
    {
        if ($this->phase === 'hunter' && $p->id === $this->hunter) {
            return ['kind' => 'hunter', 'prompt' => 'You are dying. Choose who to shoot.', 'done' => false,
                'targets' => array_map(static fn($q) => $q->id, $this->validTargets($p, 'hunter'))];
        }
        $kind = $this->phase === 'night' && $p->alive ? $this->nightKind($p) : null;
        if ($kind === null) {
            return null;
        }
        $n = $kind === 'haunt' ? $this->hauntSpec($p) : $this->roleOf($p)['night'];
        $mine = $this->night['actions'][$p->id] ?? null;
        if (!in_array($p->id, $this->night['actors'], true)) {
            return ['kind' => $kind, 'prompt' => $n['prompt'], 'waiting' => true];
        }
        $out = ['kind' => $kind, 'prompt' => $n['prompt'], 'done' => $mine !== null, 'choice' => $mine];
        if ($kind === 'witch') {
            $v = $this->night['victim'];
            $out['victim'] = $v ? $this->player($v)->name : null;
            $out['canHeal'] = empty($p->state['healUsed']) && $v !== null;
            $out['canPoison'] = empty($p->state['poisonUsed']);
            $out['targets'] = array_map(static fn($q) => $q->id, $this->validTargets($p, 'poison'));
        } elseif ($kind === 'guard') {
            $v = $this->night['victim'];
            $left = $this->usesLeft($p);
            $out['victim'] = $v ? $this->player($v)->name : null;
            $out['usesLeft'] = $left;
            $out['canSave'] = $v !== null && $left > 0;
            // fellow paladins see each other's choices (and nobody else does)
            $mates = [];
            foreach ($this->night['actions'] as $id => $a) {
                if ($id !== $p->id && ($q = $this->player((string)$id)) && $q->role === $p->role) {
                    $mates[$id] = !empty($a['save']);
                }
            }
            $out['teamChoices'] = (object)$mates;
        } else {
            $out['targets'] = array_map(static fn($q) => $q->id, $this->validTargets($p, $kind));
            if ($kind === 'haunt') {
                $out['evil'] = !empty($p->state['ghost']['evil']);
            }
            if ($kind === 'kill') {
                // suggestions are private to the living team: playerId => suggested victim
                $team = [];
                $confirmed = [];
                foreach ($this->killers() as $id) {
                    if (isset($this->night['suggest'][$id])) {
                        $team[$id] = $this->night['suggest'][$id];
                    }
                    if (isset($this->night['actions'][$id])) {
                        $confirmed[] = $id;
                    }
                }
                $out['teamChoices'] = (object)$team;
                $out['mine'] = $this->night['suggest'][$p->id] ?? null;
                $out['agreed'] = $this->agreedTarget() !== null;
                $out['agreedTarget'] = $this->agreedTarget();
                $out['confirmed'] = $confirmed;
            }
        }
        return $out;
    }

    /**
     * The story shown once the game is over: the log, with chat messages interleaved at the point
     * they were written (log entries carry `cs`, the last chat id at that moment). Which messages
     * appear is a host setting: public chat and/or every non-public channel. Lobby and post-game
     * chat is never included.
     */
    private function storyLog(): array
    {
        $pub = !empty($this->settings['storyPublicChat']);
        $priv = !empty($this->settings['storyPrivateChat']);
        $item = static fn(array $m, array $ctx) => ['round' => $ctx['round'] ?? 0, 'phase' => $ctx['phase'] ?? '', 'type' => 'chat',
            'ch' => $m['ch'], 'from' => $m['from'], 'to' => $m['to'] ?? null, 'text' => $m['text']];
        $eligible = static fn(array $m) => !in_array($m['ph'] ?? '', ['lobby', 'ended'], true) && ($m['ch'] === 'all' ? $pub : $priv);
        $out = [];
        $i = 0;
        $n = count($this->chat);
        $last = [];
        foreach ($this->log as $entry) {
            while ($i < $n && $this->chat[$i]['id'] <= ($entry['cs'] ?? 0)) {
                if ($eligible($this->chat[$i])) {
                    $out[] = $item($this->chat[$i], $entry);
                }
                $i++;
            }
            unset($entry['cs']);
            $out[] = $last = $entry;
        }
        for (; $i < $n; $i++) {
            if ($eligible($this->chat[$i])) {
                $out[] = $item($this->chat[$i], $last);
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
        $chat = array_slice(array_values(array_filter($this->chat, fn($m) =>
            $m['ch'] === 'all'
            || ($m['ch'] === 'dead' && (!$me->alive || $this->phase === 'ended'))
            || ($m['ch'] === 'evil' && $isEvil)
            || ($m['ch'] === 'dm' && ($m['from'] === $me->id || ($m['to'] ?? null) === $me->id)))), -self::CHAT_KEEP);
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
            if ($p->alive) {
                foreach ($this->marks as [$target, $by]) {
                    if ($target === $p->id && ($g = $this->player($by))) {
                        $row['markedBy'] = $g->id;
                        $row['markedText'] = $this->text('ghostMarked', $g->name);
                    }
                }
            }
            $players[] = $row;
        }
        $view = [
            'id' => $this->id, 'type' => $this->type, 'name' => $this->name, 'description' => $this->description,
            'phase' => $this->phase, 'round' => $this->round, 'seq' => $this->seq, 'version' => $this->version,
            'deadline' => $this->deadline, 'now' => $this->now(), 'joinable' => $this->joinable(), 'total' => $this->total, 'ghosts' => $this->ghosts, 'hostId' => $this->hostId,
            'settings' => $this->settings, 'roleCounts' => $this->roleCounts, 'winner' => $this->winner,
            'players' => $players,
            'me' => [
                'id' => $me->id, 'role' => $me->role, 'team' => $role['team'] ?? null, 'alive' => $me->alive,
                'isHost' => $me->id === $this->hostId, 'notes' => $me->notes, 'action' => $this->actionFor($me),
            ],
            'log' => $this->phase === 'ended' ? $this->storyLog()
                : array_map(static fn(array $e) => array_diff_key($e, ['cs' => 1]), $this->log),
            'chat' => $chat,
        ];
        if (!empty($role['knowsEachOther'])) {
            $view['me']['mates'] = array_values(array_map(static fn(Player $q) => $q->id,
                array_filter($this->players, static fn(Player $q) => $q->role === $me->role && $q->id !== $me->id)));
        }
        if (!empty($me->state['ghost'])) {
            $view['me']['ghost'] = ['evil' => !empty($me->state['ghost']['evil'])]; // only the ghost knows its side
        }
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
