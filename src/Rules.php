<?php
declare(strict_types=1);

namespace Wm;

/**
 * The detailed rules and character guide as trusted HTML (all dynamic text escaped). One source for the public
 * /how-to-play page and the in-game rules popup (API action `rules`). Characters come from src/games/*.php.
 */
final class Rules
{
    /** @param bool $links add "Start a game" links (the public page); the popup leaves them out */
    public static function html(bool $links = true): string
    {
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $min = Config::MIN_PLAYERS;
        $max = Config::MAX_PLAYERS;
        $nd = Config::NIGHT_DEFAULT;
        $nmin = Config::NIGHT_MIN;
        $nmax = Config::NIGHT_MAX;
        $ghostGames = [];
        foreach (Games::publicList() as $g) {
            if ($g['ghost']) {
                $ghostGames[] = $g['title'];
            }
        }
        $ghostNote = $ghostGames === [] ? '' : ' In ' . implode(' and ', $ghostGames) . ' the host can also add '
            . 'ghosts: they are not extra players, but a secret trait given to some of the good players.';
        $dr = Config::RANGES['discussionSeconds'];
        $vr = Config::RANGES['voteSeconds'];
        $o = <<<HTML
    <p>Werewolf and Mafia are social deduction games. A small hidden team (the werewolves or the Mafia) secretly eliminates players at night, while the informed-but-powerless majority tries to find them by talking and voting during the day. The server deals the cards and moderates, so nobody has to narrate.</p>

    <h2>Game flow</h2>
    <ol>
      <li><strong>Day 0:</strong> everyone talks; nobody has information yet and nobody is eliminated.</li>
      <li><strong>Night:</strong> characters with powers act in secret, within a {$nmin} to {$nmax} second timer per step; the evil team must agree on one victim: each member suggests a player and all confirm the same one.</li>
      <li><strong>Dawn:</strong> deaths are announced.</li>
      <li><strong>Discussion and vote:</strong> the living debate, then vote. The player with the most votes is eliminated; ties eliminate nobody.</li>
      <li>Night falls again, until one team wins.</li>
    </ol>

    <h2>How to win</h2>
    <p>The good team wins when no evil player is left alive. The evil team wins when living evil players are at least as many as living good players.</p>

    <h2>Setup</h2>
    <p>Games need {$min} to {$max} players. The host chooses how many of each character to include, and can start as soon as {$min} players have joined. Late players can still join while seats remain. The night timer is always on ({$nd} seconds by default, {$nmin} to {$nmax} allowed); discussion ({$dr[0]} to {$dr[1]} seconds) and vote ({$vr[0]} to {$vr[1]} seconds) timers are optional. The number of werewolves, witches, paladins and hunters is limited by the table size, so the game stays balanced.{$h($ghostNote)}</p>

HTML;
        foreach (Games::publicList() as $g) {
            $o .= "\n    <section id=\"{$h($g['id'])}\">\n"
                . "      <h2>{$h($g['icon'])} {$h($g['title'])} characters</h2>\n"
                . "      <p>{$h($g['tagline'])} <strong>{$h($g['teams']['good']['name'])}:</strong> {$h($g['teams']['good']['goal'])} "
                . "<strong>{$h($g['teams']['evil']['name'])}:</strong> {$h($g['teams']['evil']['goal'])}</p>\n      <ul>\n";
            foreach ($g['roles'] as $r) {
                if ($r['legacy']) {
                    continue;
                }
                $o .= "        <li><strong>{$h($r['icon'])} {$h($r['name'])}</strong> (" . ($r['team'] === 'evil' ? 'evil' : 'good')
                    . "): {$h($r['desc'])}</li>\n";
            }
            $o .= "      </ul>\n";
            if ($g['ghost']) {
                $o .= "      <p><strong>{$h($g['ghost']['icon'])} Ghosts:</strong> {$h($g['ghost']['desc'])}</p>\n";
            }
            if ($links) {
                $o .= "      <p><a href=\"?create={$h($g['id'])}\">Start a {$h($g['title'])} game</a></p>\n";
            }
            $o .= "    </section>\n";
        }
        return $o;
    }
}
