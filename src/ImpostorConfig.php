<?php
declare(strict_types=1);

namespace Wm;

/** Validation of Impostor game setup requests. Single source of the limits (also sent to the client and used in the rules text). */
final class ImpostorConfig
{
    public const SEATS_MIN = 4;
    public const SEATS_MAX = 30;
    public const SEATS_DEF = 8;
    public const TURNS_MIN = 1;
    public const TURNS_MAX = 20;
    public const TURNS_DEF = 3;
    public const VOTE_MIN = 30;
    public const VOTE_MAX = 90;
    public const VOTE_DEF = 60;
    public const POINTS_MIN = 1;
    public const POINTS_MAX = 10;
    public const POINTS_DEF = 3;
    public const PLAYER_POINTS_MIN = 0;
    public const PLAYER_POINTS_MAX = 9;
    public const PLAYER_POINTS_DEF = 0;
    public const SOURCES = ['pool', 'players', 'host'];

    /** @return array<string,int> limits for the client's setup form */
    public static function limits(): array
    {
        return ['seatsMin' => self::SEATS_MIN, 'seatsMax' => self::SEATS_MAX, 'seatsDef' => self::SEATS_DEF,
            'turnsMin' => self::TURNS_MIN, 'turnsMax' => self::TURNS_MAX, 'turnsDef' => self::TURNS_DEF,
            'voteMin' => self::VOTE_MIN, 'voteMax' => self::VOTE_MAX, 'voteDef' => self::VOTE_DEF,
            'pointsMin' => self::POINTS_MIN, 'pointsMax' => self::POINTS_MAX, 'pointsDef' => self::POINTS_DEF,
            'playerPointsMin' => self::PLAYER_POINTS_MIN, 'playerPointsMax' => self::PLAYER_POINTS_MAX, 'playerPointsDef' => self::PLAYER_POINTS_DEF,
            'questionMin' => Impostor::QUESTION_MIN, 'questionMax' => Impostor::QUESTION_MAX, 'answerMax' => Impostor::ANSWER_MAX];
    }

    private static function int(mixed $raw, int $lo, int $hi, int $def, string $label): int
    {
        if ($raw === null || $raw === '') {
            return $def;
        }
        if (!is_numeric($raw) || (int)$raw != $raw) {
            throw new GameError("Invalid {$label}.");
        }
        $n = (int)$raw;
        if ($n < $lo || $n > $hi) {
            throw new GameError("{$label} must be between {$lo} and {$hi}.");
        }
        return $n;
    }

    /** @return array{name:string,description:string,settings:array,total:int} */
    public static function normalize(array $def, array $input): array
    {
        $name = Config::clean($input['name'] ?? '', 40);
        if ($name === '') {
            throw new GameError('The game needs a name.');
        }
        $in = is_array($input['settings'] ?? null) ? $input['settings'] : [];
        $source = $in['source'] ?? 'pool';
        if (!in_array($source, self::SOURCES, true)) {
            throw new GameError('Invalid question source.');
        }
        $hostPlays = filter_var($in['hostPlays'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        if ($source === 'host' && $hostPlays) {
            throw new GameError('Only a host who does not play can write the questions.');
        }
        $points = self::int($in['points'] ?? null, self::POINTS_MIN, self::POINTS_MAX, self::POINTS_DEF, 'Points');
        $playerPoints = self::int($in['playerPoints'] ?? null, self::PLAYER_POINTS_MIN, self::PLAYER_POINTS_MAX, self::PLAYER_POINTS_DEF, 'Points for finding the Impostor');
        if ($playerPoints >= $points) {
            throw new GameError('Points for finding the Impostor must be lower than the Impostor\'s points.');
        }
        $vote = $in['voteSeconds'] ?? null;
        $vote = !is_numeric($vote) || (float)$vote <= 0 ? self::VOTE_DEF : (int)min(self::VOTE_MAX, max(self::VOTE_MIN, round((float)$vote)));
        return [
            'name' => $name,
            'description' => Config::clean($input['description'] ?? '', 300),
            'settings' => [
                'hostPlays' => $hostPlays, 'source' => $source,
                'turns' => self::int($in['turns'] ?? null, self::TURNS_MIN, self::TURNS_MAX, self::TURNS_DEF, 'Number of turns'),
                'voteSeconds' => $vote,
                'points' => $points, 'playerPoints' => $playerPoints,
            ],
            'total' => self::int($input['seats'] ?? null, self::SEATS_MIN, self::SEATS_MAX, self::SEATS_DEF, 'Number of seats'),
        ];
    }
}
