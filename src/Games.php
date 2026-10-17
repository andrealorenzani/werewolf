<?php
declare(strict_types=1);

namespace Wm;

/** Registry of playable games. To add a game: create src/games/<id>.php and list the id here. */
final class Games
{
    private const IDS = ['werewolf', 'mafia'];

    /** @return array<string,array>|null */
    public static function get(string $id): ?array
    {
        return in_array($id, self::IDS, true) ? require __DIR__ . '/games/' . $id . '.php' : null;
    }

    /** JSON-safe descriptions of every game, for the client's setup screen. */
    public static function publicList(): array
    {
        $out = [];
        foreach (self::IDS as $id) {
            $d = self::get($id);
            $out[] = [
                'id' => $d['id'], 'title' => $d['title'], 'icon' => $d['icon'], 'tagline' => $d['tagline'],
                'teams' => $d['teams'],
                'roles' => array_map(static fn(array $r) => [
                    'id' => $r['id'], 'name' => $r['name'], 'team' => $r['team'], 'icon' => $r['icon'], 'desc' => $r['desc'],
                    'min' => $r['min'] ?? 0, 'max' => $r['max'] ?? 1, 'def' => $r['def'] ?? 0,
                    'filler' => !empty($r['filler']),
                    'nightKind' => $r['night']['kind'] ?? null, 'nightStep' => $r['night']['step'] ?? 1,
                    'haunts' => isset($r['haunt']), 'onDeath' => $r['onDeath'] ?? null,
                    'cap' => $r['cap'] ?? null, 'legacy' => !empty($r['legacy']), 'knowsEachOther' => !empty($r['knowsEachOther']),
                ], $d['roles']),
                'ghost' => isset($d['ghost']) ? array_intersect_key($d['ghost'], array_flip(['name', 'icon', 'desc', 'min', 'max', 'def', 'cap'])) : null,
            ];
        }
        return $out;
    }
}
