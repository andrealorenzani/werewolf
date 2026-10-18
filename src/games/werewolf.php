<?php
declare(strict_types=1);

// Werewolf game definition: pure data, no logic. See docs/rules.md.
// Order matters: when a game starts with fewer players than configured cards, roles listed LATER are
// dropped first (evil roles from the end, then `filler` roles, then other good roles from the end).
return [
    'id' => 'werewolf',
    'title' => 'Werewolf',
    'icon' => '🐺',
    'tagline' => 'A village is haunted by werewolves hiding among its people.',
    'teams' => [
        'good' => ['name' => 'Villagers', 'goal' => 'Find and eliminate every werewolf.'],
        'evil' => ['name' => 'Werewolves', 'goal' => 'Devour the villagers until werewolves are at least as many as the rest.'],
    ],
    'text' => [
        'nightFalls' => 'Night %d falls on the village. Everyone closes their eyes…',
        'dayBreaks' => 'Day %d breaks.',
        'nobodyDied' => 'Nobody was killed during the night.',
        'foundDead' => '%s was found dead.',
        'ghostFound' => '%1$s was found dead. Their ghost is now floating around %2$s, pointing a finger at them.',
        'ghostMarked' => 'selected by ghost %s',
        'lynched' => 'The village condemned %s.',
        'shot' => '%2$s fired a last shot and killed %1$s.',
        'inspect' => ['good' => 'is NOT a werewolf', 'evil' => 'IS a werewolf'],
        'win' => [
            'good' => 'The villagers have rid the village of werewolves!',
            'evil' => 'The werewolves have taken over the village!',
        ],
    ],
    // Ghost is not a card and not an extra player: the host sets how many (0..number of werewolves) and that many
    // random non-werewolf players secretly carry a ghost trait. If such a player dies at NIGHT (werewolves or poison,
    // and nothing prevents it) the ghost rises. Votes and hunter shots never raise one. See docs/rules.md.
    'ghost' => [
        'name' => 'Ghost', 'icon' => '👻', 'min' => 0, 'max' => 10, 'def' => 0, 'cap' => ['count' => 'werewolf'],
        'trigger' => 'death', 'evilChance' => 0.5,
        'prompt' => 'Your ghost rises. Point a finger at one player.',
        'desc' => 'Some non-werewolf players carry a restless spirit, picked at random at the start; the host chooses how many (at most one per werewolf). If such a player dies during the night, their ghost stays and points a finger at one player. Death may turn the ghost evil: only the ghost knows its side.',
    ],
    // Role counts in setup: `max` is a hard limit, `cap` a limit that depends on the other counts:
    //   ['nonEvil' => d]    at most ceil(non-evil cards / d)
    //   ['count' => roleId] at most as many as that role
    'roles' => [
        ['id' => 'villager', 'name' => 'Villager', 'team' => 'good', 'icon' => '🧑‍🌾', 'filler' => true, 'min' => 3, 'max' => 20, 'def' => 5,
            'desc' => 'No special power. Use your voice and your vote to find the werewolves.'],
        ['id' => 'werewolf', 'name' => 'Werewolf', 'team' => 'evil', 'icon' => '🐺', 'min' => 1, 'max' => 10, 'def' => 1, 'cap' => ['nonEvil' => 3],
            'desc' => 'Each night, the werewolves agree on one villager to devour: everyone suggests a victim and all confirm the same one. By day, blend in.',
            'night' => ['kind' => 'kill', 'prompt' => 'Agree on a victim to devour.']],
        ['id' => 'seer', 'name' => 'Seer', 'team' => 'good', 'icon' => '🔮', 'max' => 1, 'def' => 0,
            'desc' => 'Each night, learn whether one player is a werewolf.',
            'night' => ['kind' => 'inspect', 'prompt' => 'Choose a player to investigate.']],
        // Legacy: old saved games may still contain Ghost cards. Hidden from setup, ignored by Config.
        ['id' => 'ghost', 'name' => 'Ghost', 'team' => 'good', 'icon' => '👻', 'max' => 3, 'def' => 0, 'legacy' => true,
            'desc' => 'If the werewolves kill you at night, your ghost stays and points a finger at one player. Death may turn you evil: only you know your side.',
            'haunt' => ['trigger' => 'evil', 'evilChance' => 0.5, 'prompt' => 'Your ghost rises. Point a finger at one player.']],
        ['id' => 'paladin', 'name' => 'Paladin', 'team' => 'good', 'icon' => '🛡️', 'max' => 3, 'def' => 0, 'cap' => ['nonEvil' => 10], 'knowsEachOther' => true,
            'desc' => "After the werewolves choose, you may shield their victim. All paladins share as many charges as there are werewolves in the game, dead ones included; they know each other and must all agree to shield.",
            'night' => ['kind' => 'guard', 'step' => 2, 'limit' => ['countTeam' => 'evil'], 'prompt' => 'The werewolves have chosen a victim. Shield them?']],
        ['id' => 'witch', 'name' => 'Witch', 'team' => 'good', 'icon' => '🧪', 'max' => 3, 'def' => 0, 'cap' => ['nonEvil' => 10],
            'desc' => "You own one healing potion and one poison for the whole game. You see the werewolves' victim. Several witches act independently.",
            'night' => ['kind' => 'witch', 'step' => 2, 'prompt' => 'Use a potion, or do nothing.']],
        ['id' => 'hunter', 'name' => 'Hunter', 'team' => 'good', 'icon' => '🏹', 'max' => 10, 'def' => 0, 'cap' => ['count' => 'werewolf'],
            'desc' => 'When you die, for any reason, you immediately shoot one player of your choice. Hunters do not know each other.',
            'onDeath' => 'shoot'],
        // Plays for the good team until the werewolves attack him: then he is turned into a werewolf instead of dying.
        // `allegiance` = the side whose victory is his own (the werewolves), even while he still counts as good.
        ['id' => 'cultist', 'name' => 'Cultist', 'team' => 'good', 'icon' => '🕯️', 'max' => 10, 'def' => 0, 'cap' => ['count' => 'werewolf'],
            'allegiance' => 'evil', 'convert' => ['by' => 'kill', 'into' => 'werewolf'],
            'goal' => 'Help the werewolves win. You look like a villager, even to the Seer, until they attack you: then you join them instead of dying.',
            'desc' => 'You secretly serve the werewolves but have no power and do not know them. You count as a villager until the werewolves choose you as their victim: you then survive and become a werewolf. If you are voted out first, you still win with the werewolves.'],
    ],
];
