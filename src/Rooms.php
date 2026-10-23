<?php
declare(strict_types=1);

namespace Wm;

/** Builds the right room engine for a game type (`engine` field of src/games/<id>.php, default `classic`). */
final class Rooms
{
    /** Validate a create request for the game's engine. */
    public static function config(array $def, array $input): array
    {
        return ($def['engine'] ?? 'classic') === 'impostor' ? ImpostorConfig::normalize($def, $input) : Config::normalize($def, $input);
    }

    public static function create(string $id, string $type, array $config, array $deps = []): Room
    {
        return Games::engine($type) === 'impostor' ? Impostor::create($id, $type, $config, $deps) : Game::create($id, $type, $config, $deps);
    }

    /** Rebuild a room from its stored array (old saves have no engine marker: the type decides). */
    public static function load(array $data, array $deps = []): Room
    {
        return Games::engine((string)($data['type'] ?? '')) === 'impostor' ? Impostor::fromArray($data, $deps) : Game::fromArray($data, $deps);
    }
}
