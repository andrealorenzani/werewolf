<?php
declare(strict_types=1);

namespace Wm;

/**
 * File-based persistence: one JSON file per game in the data directory.
 *  - Writers take an exclusive flock on <id>.lock, load, mutate, then replace <id>.json atomically
 *    (write temp file + rename), so lock-free readers never see a partial file.
 *  - No database is needed; games are short-lived and old files are swept.
 */
final class Store
{
    private const ID_PATTERN = '/^[A-Z2-9]{6}$/';
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no look-alike characters
    private const MAX_AGE = 86400;                              // seconds before an idle game is deleted

    public function __construct(private string $dir)
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
        $lock = @fopen($this->path($id, 'lock'), 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock game.');
        }
        try {
            $data = $this->peek($id);
            if ($data === null) {
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
            flock($lock, LOCK_UN);
            fclose($lock);
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

    /** Delete games untouched for MAX_AGE seconds. */
    public function sweep(): void
    {
        foreach (glob("{$this->dir}/*.json") ?: [] as $file) {
            if (time() - (int)filemtime($file) > self::MAX_AGE) {
                @unlink($file);
                @unlink(substr($file, 0, -5) . '.lock');
            }
        }
    }
}
