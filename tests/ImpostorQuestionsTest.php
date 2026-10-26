<?php
declare(strict_types=1);

use Wm\Impostor;
use Wm\ImpostorQuestions;

/** Run $fn with the pool read from a temporary file with $content, then restore the real one. */
function withQuestionFile(string $content, callable $fn): void
{
    $f = tempnam(sys_get_temp_dir(), 'imq');
    file_put_contents($f, $content);
    ImpostorQuestions::useFile($f);
    try {
        $fn();
    } finally {
        ImpostorQuestions::useFile(null);
        unlink($f);
    }
}

/** The pool must throw \RuntimeException. */
function poolThrows(string $why, string $pattern = '/./'): void
{
    try {
        ImpostorQuestions::all();
    } catch (\RuntimeException $e) {
        T::true((bool)preg_match($pattern, $e->getMessage()), $why);
        return;
    }
    throw new \AssertionError("expected RuntimeException: $why");
}

T::test('impostor questions: the shipped pool keeps the mandatory pairs (append-only) and is well formed', function () {
    $all = ImpostorQuestions::all();
    T::same(count($all), ImpostorQuestions::count());
    T::true(count($all) >= 90);
    // the first 90 pairs are mandatory: never edited, renumbered or removed (raise this when the mandatory set grows)
    T::same('82cdc6b2206482f5b848d31e4f0783f8a3cbd5ea', sha1(json_encode(array_slice($all, 0, 90), JSON_UNESCAPED_UNICODE)));
    $normals = array_map(fn($p) => mb_strtolower($p[0]), $all);
    T::same(count($normals), count(array_unique($normals)));
    foreach ($all as [$a, $b]) {
        foreach ([$a, $b] as $q) {
            T::true(mb_strlen($q) >= Impostor::QUESTION_MIN && mb_strlen($q) <= Impostor::QUESTION_MAX);
        }
        T::true($a !== $b);
    }
    T::same($all, ImpostorQuestions::all()); // cached
});

T::test('impostor questions: parser reads pairs, ignores comments and blank lines, caches', function () {
    withQuestionFile("# header\n\n1. First question?\n1-impostor. First twin?\r\n\n  # note\n2. Second question?\n2-impostor. Second twin?\n", function () {
        T::same([['First question?', 'First twin?'], ['Second question?', 'Second twin?']], ImpostorQuestions::all());
        T::same(2, ImpostorQuestions::count());
    });
    withQuestionFile("1. Question one?\n1-impostor. Twin one?\n", function () {
        T::same(1, ImpostorQuestions::count());
        file_put_contents(ImpostorQuestions::file(), "1. Changed?\n1-impostor. Changed twin?\n");
        T::same('Question one?', ImpostorQuestions::all()[0][0]); // cached until reset
    });
});

T::test('impostor questions: a malformed file throws', function () {
    $ok = "1. Question one?\n1-impostor. Twin one?\n";
    $bad = [
        'missing impostor line' => "1. Question one?\n2. Question two?\n2-impostor. Twin two?\n",
        'missing last impostor line' => $ok . "2. Question two?\n",
        'orphan impostor line' => "1-impostor. Twin one?\n",
        'duplicate number' => $ok . "1. Question again?\n1-impostor. Twin again?\n",
        'gap' => $ok . "3. Question three?\n3-impostor. Twin three?\n",
        'mismatched number' => "1. Question one?\n2-impostor. Twin?\n",
        'empty text' => "1. \n1-impostor. Twin one?\n",
        'too short' => "1. Hi\n1-impostor. Twin one?\n",
        'too long' => "1. " . str_repeat('x', 141) . "\n1-impostor. Twin one?\n",
        'garbage' => $ok . "hello\n",
        'empty file' => "# nothing\n",
    ];
    foreach ($bad as $why => $content) {
        withQuestionFile($content, function () use ($why) {
            poolThrows($why);
        });
    }
    ImpostorQuestions::useFile('/nonexistent/impostor.md');
    poolThrows('missing file', '/missing/');
    ImpostorQuestions::useFile(null);
});

T::test('impostor questions: the data folder is never served and is deployed', function () {
    $root = dirname(__DIR__);
    T::true(is_file($root . '/resources/.htaccess'));
    T::true(str_contains(file_get_contents($root . '/resources/.htaccess'), 'Deny from all'));
    T::true((bool)preg_match('/RedirectMatch 404 \^\/\([^)]*\bresources\b/', file_get_contents($root . '/.htaccess')));
    T::true((bool)preg_match("/RUNTIME='[^']*resources\//", file_get_contents($root . '/scripts/deploy.sh')));
});
