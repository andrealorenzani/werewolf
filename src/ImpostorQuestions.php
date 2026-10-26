<?php
declare(strict_types=1);

namespace Wm;

/**
 * The built-in Impostor question pool, read from resources/impostor-questions.md (append-only: games in progress
 * refer to pairs by position). Format: `N. question` followed by `N-impostor. similar question`; blank lines and
 * `#` lines are ignored. A malformed file throws \RuntimeException.
 */
final class ImpostorQuestions
{
    private static ?array $cache = null;
    private static ?string $path = null;

    /** Read another file (tests) or, with null, the default one again. */
    public static function useFile(?string $path): void
    {
        self::$path = $path;
        self::$cache = null;
    }

    public static function file(): string
    {
        return self::$path ?? dirname(__DIR__) . '/resources/impostor-questions.md';
    }

    /** @return list<array{0:string,1:string}> [question for everybody, question for the Impostor], in file order */
    public static function all(): array
    {
        return self::$cache ??= self::parse(self::file());
    }

    public static function count(): int
    {
        return count(self::all());
    }

    private static function parse(string $file): array
    {
        $raw = is_file($file) ? file_get_contents($file) : false;
        if ($raw === false) {
            throw new \RuntimeException('Impostor question file is missing.');
        }
        $pairs = [];
        $pending = null; // [n, normal] waiting for its impostor line
        $last = 0;
        foreach (preg_split('/\R/', $raw) as $i => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $at = 'line ' . ($i + 1);
            if (!preg_match('/^(\d+)(-impostor)?\.\s+(.*)$/u', $line, $m)) {
                throw new \RuntimeException("Impostor questions: cannot read {$at}.");
            }
            $n = (int)$m[1];
            $text = trim($m[3]);
            $len = mb_strlen($text);
            if ($len < Impostor::QUESTION_MIN || $len > Impostor::QUESTION_MAX) {
                throw new \RuntimeException("Impostor questions: bad length of question {$n} ({$at}).");
            }
            if ($m[2] === '') {
                if ($pending !== null) {
                    throw new \RuntimeException("Impostor questions: question {$pending[0]} has no impostor line.");
                }
                if ($n <= $last) {
                    throw new \RuntimeException("Impostor questions: duplicate or unordered number {$n} ({$at}).");
                }
                if ($n !== $last + 1) {
                    throw new \RuntimeException("Impostor questions: numbers must be consecutive, found {$n} after {$last} ({$at}).");
                }
                $pending = [$n, $text];
            } else {
                if ($pending === null || $pending[0] !== $n) {
                    throw new \RuntimeException("Impostor questions: impostor line {$n} has no matching question ({$at}).");
                }
                $pairs[] = [$pending[1], $text];
                $last = $n;
                $pending = null;
            }
        }
        if ($pending !== null) {
            throw new \RuntimeException("Impostor questions: question {$pending[0]} has no impostor line.");
        }
        if ($pairs === []) {
            throw new \RuntimeException('Impostor question file has no pairs.');
        }
        return $pairs;
    }
}
