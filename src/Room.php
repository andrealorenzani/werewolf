<?php
declare(strict_types=1);

namespace Wm;

/**
 * What Api and Store need from any game room (the classic Werewolf/Mafia engine `Game`, the `Impostor` engine).
 * Implementations also expose the public properties `id`, `version` and `dirty` (needs saving) and are built by
 * `Rooms::create` / `Rooms::load`. Hidden information leaves a room only through `viewFor`.
 */
interface Room
{
    public static function fromArray(array $a, array $deps = []): self;

    public function toArray(): array;

    /** Apply expired deadlines and presence changes. Called at the start of every locked request. */
    public function tick(): void;

    public function now(): int;

    public function touch(Player $p): void;

    public function player(?string $id): ?Player;

    public function playerByToken(string $token): ?Player;

    public function addPlayer(mixed $profile): Player;

    public function joinable(): bool;

    /** Number of seats taken (shown on the join page). */
    public function joinedCount(): int;

    /** Run a player's command; throws GameError for invalid ones. */
    public function command(string $playerId, array $cmd): void;

    /** The per-player view: the only data that may be sent to a client. */
    public function viewFor(string $viewerId): array;

    /** Avatars (data URLs) of the requested players. */
    public function avatars(array $ids): array;

    public function canDelete(string $playerId): bool;
}
