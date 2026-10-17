<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Wm\Config;
use Wm\Game;
use Wm\Games;
use Wm\Player;

/** Tiny test harness (no PHPUnit needed on the server). */
final class T
{
    public static array $tests = [];
    public static int $assertions = 0;

    public static function test(string $name, callable $fn): void
    {
        self::$tests[$name] = $fn;
    }

    public static function same(mixed $expected, mixed $actual, string $msg = ''): void
    {
        self::$assertions++;
        if ($expected !== $actual) {
            throw new \AssertionError(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
        }
    }

    public static function true(mixed $v, string $msg = ''): void
    {
        self::same(true, (bool)$v, $msg ?: 'expected truthy');
    }

    public static function matches(string $pattern, string $s): void
    {
        self::$assertions++;
        if (!preg_match($pattern, $s)) {
            throw new \AssertionError("'$s' does not match $pattern");
        }
    }

    public static function throws(callable $fn, string $pattern): void
    {
        try {
            $fn();
        } catch (\Wm\GameError $e) {
            self::matches($pattern, $e->getMessage());
            return;
        }
        throw new \AssertionError("expected GameError matching $pattern");
    }
}

/** Mutable clock for deterministic timers. */
final class Clock
{
    public int $ms = 1_000_000;

    public function __invoke(): int
    {
        return $this->ms;
    }
}

/**
 * A started game with fixed roles: $roles lists one role id per player (p0, p1…).
 * @return array{0:Game,1:Player[],2:Clock}
 */
function startedGame(string $type, array $roles, array $settings = []): array
{
    $def = Games::get($type);
    $settings += ['discussionSeconds' => 120, 'voteSeconds' => 45, 'nightSeconds' => 45]; // timers are off by default
    // legacy cards (the old Ghost role) are not selectable in setup: configure a villager, then force the role below
    $legacy = array_column(array_filter($def['roles'], fn($r) => !empty($r['legacy'])), 'id');
    $filler = array_column(array_filter($def['roles'], fn($r) => !empty($r['filler'])), 'id')[0];
    $counts = array_count_values(array_map(fn($r) => in_array($r, $legacy, true) ? $filler : $r, $roles));
    $cfg = Config::normalize($def, ['name' => 'T', 'roles' => $counts, 'settings' => $settings]);
    $clock = new Clock();
    $g = Game::create('TESTAA', $type, $cfg, ['rng' => fn() => 0.5, 'clock' => $clock]);
    $ps = [];
    foreach ($roles as $i => $_) {
        $ps[] = $g->addPlayer(['name' => "p$i"]);
    }
    $g->start($ps[0]->id);
    $g->players = $ps; // start() shuffles; force the order and roles
    foreach ($ps as $i => $p) {
        $p->role = $roles[$i];
        $p->state = [];  // start() may have dealt secret ghost slots to the wrong people: tests set their own
        $p->notes = [];
    }
    return [$g, $ps, $clock];
}

function toNight(Game $g, array $ps): void
{
    $g->command($ps[0]->id, ['t' => 'skip']);
}

function act(Game $g, Player $p, array $cmd): void
{
    $g->command($p->id, ['t' => 'action'] + $cmd);
}

/** The evil team kills $target: every killer suggests the same player, then every killer confirms. */
function killAct(Game $g, Player|array $killers, Player $target): void
{
    $killers = is_array($killers) ? $killers : [$killers];
    foreach ($killers as $k) {
        act($g, $k, ['target' => $target->id]);
    }
    foreach ($killers as $k) {
        act($g, $k, ['confirm' => true]);
    }
}
