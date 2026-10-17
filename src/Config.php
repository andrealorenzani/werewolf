<?php
declare(strict_types=1);

namespace Wm;

/** Validation of game setup requests and player profiles. */
final class Config
{
    public const MIN_PLAYERS = 4;
    public const MAX_PLAYERS = 30;

    public const DEFAULT_SETTINGS = [
        'discussionSeconds' => 0, // 0 = no time limit
        'voteSeconds' => 0,
        'nightSeconds' => self::NIGHT_DEFAULT, // mandatory, never 0
        'revealRoles' => true, // reveal the role of eliminated players
        'storyPublicChat' => false,  // replay the public chat in the final story
        'storyPrivateChat' => false, // replay DMs, wolf night chat and graveyard chat in the final story
    ];

    public const NIGHT_DEFAULT = 60;
    public const NIGHT_MIN = 30;
    public const NIGHT_MAX = 90;

    /** Optional timers: missing / empty / 0 = no limit. */
    private const RANGES = [
        'discussionSeconds' => [15, 900],
        'voteSeconds' => [10, 300],
    ];

    /** Night step length: always on. Empty, 0 or garbage = default, otherwise clamped to 30-90. */
    public static function nightSeconds(mixed $raw): int
    {
        if (!is_numeric($raw) || (float)$raw <= 0) {
            return self::NIGHT_DEFAULT;
        }
        return (int)min(self::NIGHT_MAX, max(self::NIGHT_MIN, round((float)$raw)));
    }

    /**
     * Highest allowed count of a role given the other counts: its `max` and, when it has one, its `cap`
     * (see src/games/werewolf.php). $nonEvil overrides the number of non-evil cards (used for a partial deal).
     * @param array<string,int> $counts role id => count
     */
    public static function capFor(array $role, array $counts, array $def, ?int $nonEvil = null): int
    {
        $max = (int)($role['max'] ?? 1);
        $cap = $role['cap'] ?? null;
        if ($cap === null) {
            return $max;
        }
        if (isset($cap['nonEvil'])) {
            if ($nonEvil === null) {
                $nonEvil = 0;
                foreach ($def['roles'] as $r) {
                    if ($r['team'] !== 'evil' && empty($r['legacy'])) {
                        $nonEvil += (int)($counts[$r['id']] ?? 0);
                    }
                }
            }
            return min($max, (int)ceil($nonEvil / $cap['nonEvil']));
        }
        return min($max, (int)($counts[$cap['count']] ?? 0));
    }

    public static function clean(mixed $s, int $max): string
    {
        $s = trim((string)preg_replace('/\s+/u', ' ', is_scalar($s) ? (string)$s : ''));
        return mb_substr($s, 0, $max);
    }

    /** @return array{name:string,description:string,roleCounts:array,settings:array,total:int} */
    public static function normalize(array $def, array $input): array
    {
        $name = self::clean($input['name'] ?? '', 40);
        if ($name === '') {
            throw new GameError('The game needs a name.');
        }
        $description = self::clean($input['description'] ?? '', 300);

        $counts = [];
        $total = 0;
        foreach ($def['roles'] as $role) {
            if (!empty($role['legacy'])) {
                continue; // old cards still in saved games: not selectable any more
            }
            $raw = $input['roles'][$role['id']] ?? 0;
            if (!is_numeric($raw) || (int)$raw != $raw || (int)$raw < 0) {
                throw new GameError("Invalid count for {$role['name']}.");
            }
            $n = (int)$raw;
            $max = $role['max'] ?? 1;
            $min = $role['min'] ?? 0;
            if ($n > $max) {
                throw new GameError("At most {$max} {$role['name']} allowed.");
            }
            if ($n < $min) {
                throw new GameError("At least {$min} {$role['name']} required.");
            }
            $counts[$role['id']] = $n;
            $total += $n;
        }
        foreach ($def['roles'] as $role) {
            if (!empty($role['legacy']) || !isset($role['cap'])) {
                continue;
            }
            $cap = self::capFor($role, $counts, $def);
            if ($counts[$role['id']] > $cap) {
                throw new GameError("At most {$cap} {$role['name']} allowed with this setup.");
            }
        }
        if ($total < self::MIN_PLAYERS) {
            throw new GameError('At least ' . self::MIN_PLAYERS . ' players are needed.');
        }
        if ($total > self::MAX_PLAYERS) {
            throw new GameError('At most ' . self::MAX_PLAYERS . ' players are allowed.');
        }

        $count = static function (string $team) use ($def, $counts): int {
            $sum = 0;
            foreach ($def['roles'] as $r) {
                if ($r['team'] === $team) {
                    $sum += $counts[$r['id']] ?? 0;
                }
            }
            return $sum;
        };
        $evil = $count('evil');
        if ($evil < 1) {
            throw new GameError("At least one {$def['teams']['evil']['name']} member is needed.");
        }
        if ($count('good') <= $evil) {
            throw new GameError("{$def['teams']['good']['name']} must outnumber {$def['teams']['evil']['name']}.");
        }

        $settings = self::DEFAULT_SETTINGS;
        foreach (self::RANGES as $key => [$lo, $hi]) {
            $raw = $input['settings'][$key] ?? null;
            if ($raw === null || $raw === '' || $raw === false) {
                continue; // missing / empty = no limit
            }
            if (!is_numeric($raw)) {
                throw new GameError("Invalid {$key}.");
            }
            $v = (float)$raw;
            $settings[$key] = $v <= 0 ? 0 : (int)min($hi, max($lo, round($v)));
        }
        $settings['nightSeconds'] = self::nightSeconds($input['settings']['nightSeconds'] ?? null);
        if (isset($input['settings']['revealRoles'])) {
            $settings['revealRoles'] = (bool)$input['settings']['revealRoles'];
        }
        foreach (['storyPublicChat', 'storyPrivateChat'] as $key) {
            $settings[$key] = ($input['settings'][$key] ?? false) === true;
        }

        return ['name' => $name, 'description' => $description, 'roleCounts' => $counts,
            'settings' => $settings, 'total' => $total];
    }

    /** @return array{name:string,avatar:?string} */
    public static function profile(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $name = self::clean($input['name'] ?? '', 20);
        if ($name === '') {
            throw new GameError('Please enter your name.');
        }
        $avatar = $input['avatar'] ?? null;
        if (is_string($avatar) && $avatar !== '') {
            if (strlen($avatar) > 40000 || !preg_match('#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$#', $avatar)) {
                throw new GameError('Invalid avatar image.');
            }
        } else {
            $avatar = null;
        }
        return ['name' => $name, 'avatar' => $avatar];
    }
}
