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
    'roles' => [
        ['id' => 'villager', 'name' => 'Villager', 'team' => 'good', 'icon' => '🧑‍🌾', 'filler' => true, 'max' => 30, 'def' => 5,
            'desc' => 'No special power. Use your voice and your vote to find the werewolves.'],
        ['id' => 'werewolf', 'name' => 'Werewolf', 'team' => 'evil', 'icon' => '🐺', 'min' => 1, 'max' => 10, 'def' => 1,
            'desc' => 'Each night, the werewolves agree on one villager to devour. By day, blend in.',
            'night' => ['kind' => 'kill', 'prompt' => 'Choose a victim to devour.']],
        ['id' => 'seer', 'name' => 'Seer', 'team' => 'good', 'icon' => '🔮', 'max' => 1, 'def' => 0,
            'desc' => 'Each night, learn whether one player is a werewolf.',
            'night' => ['kind' => 'inspect', 'prompt' => 'Choose a player to investigate.']],
        ['id' => 'ghost', 'name' => 'Ghost', 'team' => 'good', 'icon' => '👻', 'max' => 3, 'def' => 0,
            'desc' => 'If the werewolves kill you at night, your ghost stays and points a finger at one player. Death may turn you evil: only you know your side.',
            'haunt' => ['trigger' => 'evil', 'evilChance' => 0.5, 'prompt' => 'Your ghost rises. Point a finger at one player.']],
        ['id' => 'paladin', 'name' => 'Paladin', 'team' => 'good', 'icon' => '🛡️', 'max' => 1, 'def' => 0,
            'desc' => "After the werewolves choose, you may shield their victim. You have as many charges as there are werewolves in the game, dead ones included.",
            'night' => ['kind' => 'guard', 'step' => 2, 'limit' => ['countTeam' => 'evil'], 'prompt' => 'The werewolves have chosen a victim. Shield them?']],
        ['id' => 'witch', 'name' => 'Witch', 'team' => 'good', 'icon' => '🧪', 'max' => 1, 'def' => 0,
            'desc' => "You own one healing potion and one poison for the whole game. You see the werewolves' victim.",
            'night' => ['kind' => 'witch', 'step' => 2, 'prompt' => 'Use a potion, or do nothing.']],
        ['id' => 'hunter', 'name' => 'Hunter', 'team' => 'good', 'icon' => '🏹', 'max' => 1, 'def' => 0,
            'desc' => 'When you die, for any reason, you immediately shoot one player of your choice.',
            'onDeath' => 'shoot'],
    ],
];
