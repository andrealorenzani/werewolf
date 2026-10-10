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
        'nightSeconds' => 0,
        'revealRoles' => true, // reveal the role of eliminated players
        'storyPublicChat' => false,  // replay the public chat in the final story
        'storyPrivateChat' => false, // replay DMs, wolf night chat and graveyard chat in the final story
    ];

    private const RANGES = [
        'discussionSeconds' => [15, 900],
        'voteSeconds' => [10, 300],
        'nightSeconds' => [15, 300],
    ];

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
                    $sum += $counts[$r['id']];
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
