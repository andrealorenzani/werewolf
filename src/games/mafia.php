<?php
declare(strict_types=1);

// Mafia game definition: pure data, no logic. See docs/rules.md.
return [
    'id' => 'mafia',
    'title' => 'Mafia',
    'icon' => '🕴️',
    'tagline' => 'Organised crime has infiltrated the town. Can the citizens expose it?',
    'teams' => [
        'good' => ['name' => 'Town', 'goal' => 'Find and eliminate every member of the Mafia.'],
        'evil' => ['name' => 'Mafia', 'goal' => 'Eliminate citizens until the Mafia are at least as many as the rest.'],
    ],
    'text' => [
        'nightFalls' => 'Night %d. The town goes to sleep…',
        'dayBreaks' => 'Day %d dawns.',
        'nobodyDied' => 'Nobody was killed during the night.',
        'foundDead' => '%s was found dead.',
        'lynched' => 'The town lynched %s.',
        'shot' => '%2$s took a last shot and killed %1$s.',
        'inspect' => ['good' => 'appears INNOCENT', 'evil' => 'is a member of the MAFIA'],
        'win' => [
            'good' => 'The town has crushed the Mafia!',
            'evil' => 'The Mafia now controls the town!',
        ],
    ],
    'roles' => [
        ['id' => 'citizen', 'name' => 'Citizen', 'team' => 'good', 'icon' => '🧍', 'filler' => true, 'max' => 30, 'def' => 3,
            'desc' => 'No special power. Use your voice and your vote to expose the Mafia.'],
        ['id' => 'mafioso', 'name' => 'Mafioso', 'team' => 'evil', 'icon' => '🔫', 'min' => 0, 'max' => 10, 'def' => 1,
            'desc' => 'Each night, the Mafia agree on one person to kill.',
            'night' => ['kind' => 'kill', 'prompt' => 'Choose a person to kill.']],
        ['id' => 'godfather', 'name' => 'Godfather', 'team' => 'evil', 'icon' => '🎩', 'max' => 1, 'def' => 0, 'appearsAs' => 'good',
            'desc' => 'You lead the Mafia and take part in the night kill. The Detective sees you as innocent.',
            'night' => ['kind' => 'kill', 'prompt' => 'Choose a person to kill.']],
        ['id' => 'detective', 'name' => 'Detective', 'team' => 'good', 'icon' => '🕵️', 'max' => 1, 'def' => 1,
            'desc' => 'Each night, investigate one player to learn whether they are Mafia.',
            'night' => ['kind' => 'inspect', 'prompt' => 'Choose a player to investigate.']],
        ['id' => 'doctor', 'name' => 'Doctor', 'team' => 'good', 'icon' => '⚕️', 'max' => 1, 'def' => 1,
            'desc' => 'Each night, protect one player (yourself included) from being killed.',
            'night' => ['kind' => 'protect', 'prompt' => 'Choose a player to protect.']],
        ['id' => 'escort', 'name' => 'Escort', 'team' => 'good', 'icon' => '💃', 'max' => 1, 'def' => 0,
            'desc' => 'Each night, distract one player: their night action fails.',
            'night' => ['kind' => 'block', 'prompt' => 'Choose a player to distract.']],
        ['id' => 'vigilante', 'name' => 'Vigilante', 'team' => 'good', 'icon' => '🎯', 'max' => 1, 'def' => 0,
            'desc' => 'Once per game, you may shoot a player at night.',
            'night' => ['kind' => 'shoot', 'prompt' => 'Shoot someone (once per game), or hold your fire.']],
    ],
];
