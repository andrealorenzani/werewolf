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
            if ($g['engine'] === 'classic' && $g['ghost']) {
                $ghostGames[] = $g['title'];
            }
        }
        $ghostNote = $ghostGames === [] ? '' : ' In ' . implode(' and ', $ghostGames) . ' the host can also add '
            . 'ghosts: they are not extra players, but a secret trait given to some of the good players.';
        $dr = Config::RANGES['discussionSeconds'];
        $vr = Config::RANGES['voteSeconds'];
        $o = <<<HTML
    <p>Werewolf and Mafia are social deduction games; Impostor is a quick question game (see below). A small hidden team (the werewolves or the Mafia) secretly eliminates players at night, while the informed-but-powerless majority tries to find them by talking and voting during the day. The server deals the cards and moderates, so nobody has to narrate.</p>

    <h2>Game flow</h2>
    <ol>
      <li><strong>Day 0:</strong> everyone talks; nobody has information yet and nobody is eliminated.</li>
      <li><strong>Night:</strong> characters with powers act in secret, within a {$nmin} to {$nmax} second timer per step; the evil team must agree on one victim: each member suggests a player and all confirm the same one.</li>
      <li><strong>Dawn:</strong> deaths are announced.</li>
      <li><strong>Discussion and vote:</strong> the living debate, then vote. The player with the most votes is eliminated; ties eliminate nobody.</li>
      <li>Night falls again, until one team wins.</li>
    </ol>

    <h2>How to win</h2>
    <p>The good team wins when no evil player is left alive. The evil team wins when living evil players are at least as many as living good players. Neutral players (the Corrupt in Mafia) are counted for neither team: they pick a side during the game and win or lose with it. A Cultist in Werewolf counts as a villager until the werewolves attack him, then he turns into a werewolf instead of dying; he wins with the werewolves, even if he is eliminated before turning.</p>

    <h2>Setup</h2>
    <p>Games need {$min} to {$max} players. The host chooses how many of each character to include, and can start as soon as {$min} players have joined. Late players can still join while seats remain. The night timer is always on ({$nd} seconds by default, {$nmin} to {$nmax} allowed); discussion ({$dr[0]} to {$dr[1]} seconds) and vote ({$vr[0]} to {$vr[1]} seconds) timers are optional. The number of werewolves, witches, paladins, hunters and cultists is limited by the table size, so the game stays balanced.{$h($ghostNote)}</p>

HTML;
        foreach (Games::publicList() as $g) {
            if ($g['engine'] === 'impostor') {
                $o .= self::impostor($g, $links);
                continue;
            }
            $o .= "\n    <section id=\"{$h($g['id'])}\">\n"
                . "      <h2>{$h($g['icon'])} {$h($g['title'])} characters</h2>\n"
                . "      <p>{$h($g['tagline'])} <strong>{$h($g['teams']['good']['name'])}:</strong> {$h($g['teams']['good']['goal'])} "
                . "<strong>{$h($g['teams']['evil']['name'])}:</strong> {$h($g['teams']['evil']['goal'])}"
                . (isset($g['teams']['neutral']) ? " <strong>{$h($g['teams']['neutral']['name'])}:</strong> {$h($g['teams']['neutral']['goal'])}" : '')
                . "</p>\n      <ul>\n";
            foreach ($g['roles'] as $r) {
                if ($r['legacy']) {
                    continue;
                }
                $label = $r['team'];
                if ($r['allegiance'] === 'choose') {
                    $label = 'neutral, wins with the side it chooses';
                } elseif ($r['allegiance'] !== null && $r['allegiance'] !== $r['team']) {
                    $label .= ', wins with the ' . $g['teams'][$r['allegiance']]['name'] . ' team';
                }
                $o .= "        <li><strong>{$h($r['icon'])} {$h($r['name'])}</strong> ({$h($label)}): {$h($r['desc'])}</li>\n";
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

    /** Rules section of the Impostor game; every number comes from ImpostorConfig / Impostor. */
    private static function impostor(array $g, bool $links): string
    {
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $vmin = ImpostorConfig::VOTE_MIN;
        $vmax = ImpostorConfig::VOTE_MAX;
        $pmin = ImpostorConfig::POINTS_MIN;
        $pmax = ImpostorConfig::POINTS_MAX;
        $pdef = ImpostorConfig::POINTS_DEF;
        $tmin = ImpostorConfig::TURNS_MIN;
        $tmax = ImpostorConfig::TURNS_MAX;
        $smin = ImpostorConfig::SEATS_MIN;
        $smax = ImpostorConfig::SEATS_MAX;
        $amax = Impostor::ANSWER_MAX;
        $sm = Impostor::SPEAK_MS / 1000;
        $am = Impostor::ANSWER_MS / 1000;
        $um = Impostor::UNVEIL_MS / 1000;
        $o = "\n    <section id=\"impostor\">\n      <h2>{$h($g['icon'])} {$h($g['title'])} rules</h2>\n"
            . "      <p>{$h($g['pitch'])}</p>\n      <ol>\n"
            . "        <li><strong>Questions:</strong> the host picks the built-in question pool or lets the players write their own pairs of similar questions (the author of a pair always answers the question for everybody and is never the Impostor of that turn).</li>\n"
            . "        <li><strong>Answer:</strong> everyone answers privately, up to {$amax} characters. The Impostor knows it is the Impostor and sees its own question. The phase ends when everybody has answered, or after {$am} seconds.</li>\n"
            . "        <li><strong>Read out:</strong> in a random order each player reveals their answer and presses done ({$sm} seconds at most per speaker; the host can skip a speaker or open the vote early).</li>\n"
            . "        <li><strong>Vote:</strong> everybody, the Impostor included, votes for who they think the Impostor is (not for themselves; votes can be changed until the phase ends). The vote lasts {$vmin} to {$vmax} seconds, chosen by the host, or ends as soon as everybody has voted.</li>\n"
            . "        <li><strong>Unveil:</strong> the Impostor reveals itself (automatically after {$um} seconds), then the questions, answers and votes are shown to everyone.</li>\n"
            . "      </ol>\n"
            . "      <p><strong>Scoring:</strong> only the Impostor scores. It earns the host's points ({$pmin} to {$pmax}, {$pdef} by default) if it was not discovered. It is discovered only when it received strictly more votes than every other player: a tie at the top that includes the Impostor, or nobody voting, means it escapes. After the last turn ({$tmin} to {$tmax} turns with the built-in questions, one per written pair otherwise) the highest score wins; players tied on the top score share the win and a top score of zero means no winner.</p>\n"
            . "      <p><strong>Setup:</strong> {$smin} to {$smax} players. The host may choose not to play: then the host sees everything (the current turn live and all question pairs) and moderates. If the Impostor leaves or is removed the turn is cancelled without points; if fewer than {$smin} players remain the game ends.</p>\n";
        if ($links) {
            $o .= "      <p><a href=\"?create={$h($g['id'])}\">Start an {$h($g['title'])} game</a></p>\n";
        }
        return $o . "    </section>\n";
    }
}
