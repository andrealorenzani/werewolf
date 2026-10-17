<?php
declare(strict_types=1);

namespace Wm;

/**
 * File-based persistence: one JSON file per game in the data directory.
 *  - Writers take an exclusive flock on <id>.lock, load, mutate, then replace <id>.json atomically
 *    (write temp file + rename), so lock-free readers never see a partial file.
 *  - No database is needed; games are short-lived and old files are swept (see expired()).
 */
final class Store
{
    private const ID_PATTERN = '/^[A-Z2-9]{6}$/';
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no look-alike characters
    public const IDLE_MS = 7 * 86400 * 1000;  // an unfinished game nobody touched for this long is deleted
    public const ENDED_MS = 2 * 86400 * 1000; // an ended game is deleted this long after it ended

    /** @param callable|null $clock ms epoch; injectable for tests */
    public function __construct(private string $dir, private $clock = null)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create data directory: $dir");
        }
    }

    public static function validId(string $id): bool
    {
        return (bool)preg_match(self::ID_PATTERN, $id);
    }

    private function path(string $id, string $ext = 'json'): string
    {
        if (!self::validId($id)) {
            throw new GameError('Game not found.');
        }
        return "{$this->dir}/$id.$ext";
    }

    /** Create a new game with a fresh unique id. @param callable(string):Game $factory */
    public function create(callable $factory): Game
    {
        if (random_int(1, 20) === 1) {
            $this->sweep();
        }
        for ($i = 0; $i < 20; $i++) {
            $id = '';
            for ($k = 0; $k < 6; $k++) {
                $id .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $fh = @fopen($this->path($id), 'x'); // exclusive create: fails if the id exists
            if ($fh === false) {
                continue;
            }
            fclose($fh);
            $game = $factory($id);
            $this->write($game);
            return $game;
        }
        throw new \RuntimeException('Could not allocate a game id.');
    }

    private function nowMs(): int
    {
        return $this->clock ? (int)($this->clock)() : (int)(microtime(true) * 1000);
    }

    /**
     * Retention rule for a stored game: ended games go 2 days after `endedAt` (file mtime when unknown),
     * all others after 7 days without any player activity (latest `lastSeen`, else creation / file mtime).
     * @param int|null $mtime file modification time in seconds, used only as a fallback
     */
    public static function expired(array $raw, int $nowMs, ?int $mtime = null): bool
    {
        $fallback = $mtime !== null ? $mtime * 1000 : $nowMs;
        if (($raw['phase'] ?? '') === 'ended') {
            return $nowMs - (int)($raw['endedAt'] ?? $fallback) > self::ENDED_MS;
        }
        $last = (int)($raw['createdAt'] ?? 0);
        foreach ($raw['players'] ?? [] as $p) {
            $last = max($last, (int)($p['lastSeen'] ?? 0));
        }
        return $nowMs - ($last > 0 ? $last : $fallback) > self::IDLE_MS;
    }

    /** Like peek(), but an expired game is deleted and reported as missing. */
    public function peekLive(string $id): ?array
    {
        $raw = $this->peek($id);
        if ($raw !== null && self::expired($raw, $this->nowMs(), (int)@filemtime($this->path($id)))) {
            $this->discard($id);
            return null;
        }
        return $raw;
    }

    /**
     * Take the exclusive lock of a game. The lock file may be unlinked by a deleter while we waited, so after
     * flock() we check that the path still points at the inode we hold, and retry otherwise.
     * @return resource|null
     */
    private function lock(string $id)
    {
        $path = $this->path($id, 'lock');
        for ($i = 0; $i < 5; $i++) {
            $fh = @fopen($path, 'c');
            if ($fh === false || !flock($fh, LOCK_EX)) {
                return null;
            }
            $a = fstat($fh);
            $b = @stat($path);
            if ($b !== false && $a !== false && $a['ino'] === $b['ino']) {
                return $fh;
            }
            fclose($fh);
        }
        return null;
    }

    /** @param resource $fh */
    private function unlock($fh): void
    {
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    /** Delete the game's files; call while holding its lock. */
    private function unlinkGame(string $id): void
    {
        @unlink($this->path($id));
        @unlink($this->path($id, 'lock'));
    }

    /** Delete an expired game under its lock (re-checked, a player may have just come back). */
    private function discard(string $id): void
    {
        $lock = $this->lock($id);
        if ($lock === null) {
            return;
        }
        try {
            $raw = $this->peek($id);
            if ($raw === null || self::expired($raw, $this->nowMs(), (int)@filemtime($this->path($id)))) {
                $this->unlinkGame($id);
            }
        } finally {
            $this->unlock($lock);
        }
    }

    /** Lock-free read of the raw stored array (or null). */
    public function peek(string $id): ?array
    {
        if (!self::validId($id)) {
            return null;
        }
        $raw = @file_get_contents($this->path($id));
        $data = $raw ? json_decode($raw, true) : null;
        return is_array($data) ? $data : null;
    }

    /**
     * Run $fn(Game) under an exclusive lock; the game is tick()ed first and saved afterwards if it
     * became dirty. Returns whatever $fn returns.
     * @template T @param callable(Game):T $fn @return T
     */
    public function mutate(string $id, callable $fn, array $deps = []): mixed
    {
        $lock = $this->lock($id) ?? throw new \RuntimeException('Cannot lock game.');
        try {
            $data = $this->peek($id);
            if ($data === null || self::expired($data, $this->nowMs(), (int)@filemtime($this->path($id)))) {
                if ($data !== null) {
                    $this->unlinkGame($id);
                }
                throw new GameError('Game not found.');
            }
            $game = Game::fromArray($data, $deps);
            $game->tick();
            $result = $fn($game);
            if ($game->dirty) {
                $this->write($game);
            }
            return $result;
        } finally {
            $this->unlock($lock);
        }
    }

    /**
     * Delete a game for good under the same lock writers use. $guard(Game) runs first and may throw
     * (e.g. GameError when the caller is not allowed); nothing is deleted in that case.
     * @param callable(Game):void $guard
     */
    public function remove(string $id, callable $guard, array $deps = []): void
    {
        $lock = $this->lock($id) ?? throw new \RuntimeException('Cannot lock game.');
        try {
            $data = $this->peek($id);
            if ($data === null) {
                $this->unlinkGame($id);
                throw new GameError('Game not found.');
            }
            $guard(Game::fromArray($data, $deps));
            $this->unlinkGame($id);
        } finally {
            $this->unlock($lock);
        }
    }

    private function write(Game $game): void
    {
        $path = $this->path($game->id);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($game->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        rename($tmp, $path);
        $game->dirty = false;
    }

    /** Delete every expired game (decodes each file; see expired()). Each delete is re-checked under the lock. */
    public function sweep(): void
    {
        foreach (glob("{$this->dir}/*.json") ?: [] as $file) {
            $id = basename($file, '.json');
            if (!self::validId($id)) {
                continue;
            }
            $raw = json_decode((string)@file_get_contents($file), true);
            $mtime = (int)@filemtime($file);
            $gone = is_array($raw) ? self::expired($raw, $this->nowMs(), $mtime) : $this->nowMs() - $mtime * 1000 > self::IDLE_MS;
            if ($gone) {
                $this->discard($id);
            }
        }
    }
}
