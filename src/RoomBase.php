<?php
declare(strict_types=1);

namespace Wm;

/**
 * Shared room plumbing for engines that do not extend the classic `Game`: seats, presence, profiles, leave/kick,
 * clock and rng. Used by `Impostor`. The using class provides `joinable()`, `seat()` and `dropped()`.
 */
trait RoomBase
{
    private const PRESENCE_MS = 15000;

    public string $id;
    public string $type;
    public string $name;
    public string $description;
    /** @var Player[] */
    public array $players = [];
    public ?string $hostId = null;
    public string $phase = 'lobby';
    public ?int $deadline = null; // ms epoch
    public int $seq = 0;          // bumped on every phase change
    public int $version = 0;      // bumped on every visible change; clients poll with it
    public int $createdAt;
    /** ms epoch when the game ended (retention). Never in a view. */
    public ?int $endedAt = null;
    public bool $dirty = false;   // needs saving

    /** @var callable */
    private $clock;
    /** @var callable */
    private $rng;

    /** Hook: runs for a newly created seat (before the host is set). */
    abstract protected function seat(Player $p): void;

    /** Hook: a player was removed (leave or kick) after the list was updated. */
    abstract protected function dropped(Player $p): void;

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

    public function player(?string $id): ?Player
    {
        foreach ($this->players as $p) {
            if ($p->id === $id) {
                return $p;
            }
        }
        return null;
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

    public function addPlayer(mixed $profile): Player
    {
        if (!$this->joinable()) {
            throw new GameError($this->phase === 'lobby' ? 'The game is full.' : 'The game has already started.');
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
        $this->hostId ??= $p->id;
        $this->seat($p);
        $this->players[] = $p;
        $this->bump();
        return $p;
    }

    /** Record that a player is polling. Only flips visible presence when it changes. */
    public function touch(Player $p): void
    {
        $p->lastSeen = $this->now();
        if ($this->phase !== 'ended') {
            $this->dirty = true;
        }
        if (!$p->connected) {
            $p->connected = true;
            $this->bump();
        }
    }

    /** Presence: players silent for a while are shown as disconnected. */
    private function updatePresence(): void
    {
        foreach ($this->phase === 'ended' ? [] : $this->players as $p) {
            if ($p->connected && $this->now() - $p->lastSeen > self::PRESENCE_MS) {
                $p->connected = false;
                $this->bump();
            }
        }
    }

    /** Leave (id = by) or kick (host only). The host can never go. */
    public function removePlayer(string $id, string $byId): void
    {
        if ($this->phase === 'ended') {
            throw new GameError('The game is over.');
        }
        if ($id === $this->hostId) {
            throw new GameError('The host cannot leave the game.');
        }
        if ($id !== $byId && $byId !== $this->hostId) {
            throw new GameError('Only the host can remove players.');
        }
        $p = $this->player($id) ?? throw new GameError('Unknown player.');
        $this->players = array_values(array_filter($this->players, static fn(Player $q) => $q->id !== $id));
        $this->dropped($p);
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

    /** Only the host may delete the game for everyone. */
    public function canDelete(string $playerId): bool
    {
        return $this->hostId !== null && $playerId === $this->hostId;
    }

    public function joinedCount(): int
    {
        return count($this->players);
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
