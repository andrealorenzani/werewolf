<?php
declare(strict_types=1);

// Impostor game definition: pure data, no logic (engine: src/Impostor.php). See docs/rules.md.
// The built-in question pool lives in resources/impostor-questions.md (see Wm\ImpostorQuestions).
return [
    'id' => 'impostor',
    'engine' => 'impostor',
    'title' => 'Impostor',
    'icon' => '🎭',
    'tagline' => 'Everybody answers the same question, except one player. Can you spot the odd one out?',
    'pitch' => 'Everybody privately answers the same question, except the Impostor, who gets a similar but different one and has to blend in. '
        . 'Answers are read out one by one, then everyone votes for who they think the Impostor is.',
];
