<?php
declare(strict_types=1);

namespace Wm;

/**
 * The Impostor engine: a party game of similar questions. Every turn all players privately answer the same question
 * except one (the Impostor), who gets a similar one. Answers are read out one by one, everyone votes, the Impostor
 * unveils itself and scores if it was not discovered.
 *
 *   lobby -> [writing] -> (answer -> reveal -> vote -> unveil -> result) x turns -> ended
 *
 * `writing` exists only when the players write the questions. The host may not play (God mode: sees everything).
 * Like Game, timers are lazy (tick) and all views are built by viewFor from explicit allow-lists.
 */
final class Impostor implements Room
{
    use RoomBase;

    // Safety timeouts (fixed): nobody can stall a game by going away.
    public const ANSWER_MS = 180000; // answering phase
    public const SPEAK_MS = 120000;  // one speaker idle in the reveal phase
    public const UNVEIL_MS = 60000;  // the Impostor takes too long to unveil
    public const QUESTION_MIN = 5;
    public const QUESTION_MAX = 140;
    public const ANSWER_MAX = 100;
    public const NO_ANSWER = '(no answer)';

    public array $settings = [];
    public int $total = 0;
    public int $round = 0;      // current turn (1-based), 0 before the first
    public int $turnsTotal = 0;
    /** @var array<string,int> playerId => points */
    public array $scores = [];
    /** Finished turns, public once played (names are copied so they survive players leaving). */
    public array $history = [];
    /** Pool mode: pool indices of the turns, in order. NEVER in a view (except God mode). */
    public array $deck = [];
    /** Players mode: authorId => {normal, impostor, confirmed}. NEVER in a view (except the author and God mode). */
    public array $pairs = [];
    /** Players mode: author ids in turn order. */
    public array $order = [];
    /** The running turn: n, normal, impostor, author, impostorId, answers, order, idx, votes. NEVER in a view as a whole. */
    public ?array $turn = null;

    private function __construct(array $s, array $deps)
    {
        $this->clock = $deps['clock'] ?? static fn(): int => (int)(microtime(true) * 1000);
        $this->rng = $deps['rng'] ?? static fn(): float => mt_rand() / (mt_getrandmax() + 1);
        foreach ($s as $k => $v) {
            if ($k === 'players') {
                $this->players = array_map([Player::class, 'fromArray'], $v);
            } elseif (property_exists($this, $k)) {
                $this->$k = $v;
            }
        }
    }

    /** @param array $config result of ImpostorConfig::normalize */
    public static function create(string $id, string $type, array $config, array $deps = []): self
    {
        $g = new self([
            'id' => $id, 'type' => $type, 'name' => $config['name'], 'description' => $config['description'],
            'settings' => $config['settings'], 'total' => $config['total'],
            'createdAt' => isset($deps['clock']) ? (int)($deps['clock'])() : (int)(microtime(true) * 1000),
        ], $deps);
        $g->dirty = true;
        return $g;
    }

    public static function fromArray(array $a, array $deps = []): self
    {
        return new self($a, $deps);
    }

    public function toArray(): array
    {
        $out = [];
        foreach (['id', 'type', 'name', 'description', 'settings', 'total', 'hostId', 'phase', 'round', 'turnsTotal', 'deadline',
            'seq', 'version', 'createdAt', 'endedAt', 'scores', 'history', 'deck', 'pairs', 'order', 'turn'] as $k) {
            $out[$k] = $this->$k;
        }
        $out['players'] = array_map(static fn(Player $p) => $p->toArray(), $this->players);
        return $out;
    }

    // ---------- seats ----------
    private static function isGod(Player $p): bool
    {
        return !empty($p->state['spectator']);
    }

    /** @return Player[] those who play (everyone but a non-playing host) */
    public function playing(): array
    {
        return array_values(array_filter($this->players, static fn(Player $p) => !self::isGod($p)));
    }

    public function joinable(): bool
    {
        return $this->phase === 'lobby' && count($this->playing()) < $this->total;
    }

    public function joinedCount(): int
    {
        return count($this->playing());
    }

    protected function seat(Player $p): void
    {
        if ($p->id === $this->hostId && empty($this->settings['hostPlays'])) {
            $p->state['spectator'] = true;
        }
    }

    protected function dropped(Player $p): void
    {
        unset($this->scores[$p->id]);
        if ($this->phase === 'lobby' || $this->phase === 'ended') {
            return;
        }
        if (count($this->playing()) < Config::MIN_PLAYERS) {
            $this->end();
            return;
        }
        if ($this->turn !== null && $this->turn['impostorId'] !== $p->id) {
            // the leaver vanishes from the turn so the history never shows a '?'
            unset($this->turn['answers'][$p->id], $this->turn['votes'][$p->id]);
            foreach ($this->turn['votes'] as $by => $target) {
                if ($target === $p->id) {
                    unset($this->turn['votes'][$by]); // the voter picks someone else
                }
            }
        }
        $t = $this->turn;
        switch ($this->phase) {
            case 'writing':
                unset($this->pairs[$p->id]);
                $this->startIfWritten();
                break;
            case 'answer':
                $t['impostorId'] === $p->id ? $this->finishTurn(true) : $this->revealIfAnswered();
                break;
            case 'reveal':
                if ($t['impostorId'] === $p->id) {
                    $this->finishTurn(true);
                    break;
                }
                $i = array_search($p->id, $t['order'], true);
                if ($i !== false) {
                    array_splice($this->turn['order'], $i, 1);
                    if ($i < $t['idx']) {
                        $this->turn['idx']--;
                    } elseif ($i === $t['idx']) {
                        $this->arm(self::SPEAK_MS);
                    }
                    if ($this->turn['idx'] >= count($this->turn['order'])) {
                        $this->enter('vote');
                    }
                }
                break;
            case 'vote':
                if ($t['impostorId'] === $p->id) {
                    $this->finishTurn(true);
                    break;
                }
                $this->unveilIfVoted();
                break;
            case 'unveil':
                if ($t['impostorId'] === $p->id) {
                    $this->finishTurn(true);
                }
                break;
        }
    }

    // ---------- phase machine ----------
    private function enter(string $phase): void
    {
        $this->phase = $phase;
        $this->seq++;
        $this->deadline = null;
        $ms = match ($phase) {
            'answer' => self::ANSWER_MS,
            'reveal' => self::SPEAK_MS,
            'vote' => $this->settings['voteSeconds'] * 1000,
            'unveil' => self::UNVEIL_MS,
            default => null,
        };
        if ($ms !== null) {
            $this->arm($ms);
        }
        if ($phase === 'vote' && $this->turn !== null) {
            $this->turn['idx'] = count($this->turn['order']); // everything has been read out
        }
        $this->bump();
    }

    private function arm(int $ms): void
    {
        $this->deadline = $this->now() + $ms;
    }

    public function tick(): void
    {
        if ($this->phase === 'ended' && $this->endedAt === null) {
            $this->endedAt = $this->now();
            $this->dirty = true;
        }
        $guard = 0;
        while ($this->deadline !== null && $this->now() >= $this->deadline && $guard++ < 3) {
            $this->advance();
        }
        $this->updatePresence();
    }

    /** Move on from the current phase (deadline or host skip). */
    private function advance(): void
    {
        match ($this->phase) {
            'answer' => $this->toReveal(),
            'reveal' => $this->nextSpeaker(),
            'vote' => $this->enter('unveil'),
            'unveil' => $this->finishTurn(false),
            default => $this->deadline = null,
        };
    }

    // ---------- commands ----------
    public function command(string $playerId, array $cmd): void
    {
        $p = $this->player($playerId) ?? throw new GameError('Unknown player.');
        match ($cmd['t'] ?? '') {
            'start' => $this->start($p),
            'settings' => $this->configure($p, $cmd),
            'profile' => $this->updateProfile($p, $cmd),
            'kick' => $this->removePlayer((string)($cmd['playerId'] ?? ''), $playerId),
            'leave' => $this->removePlayer($playerId, $playerId),
            'write' => $this->write($p, $cmd),
            'confirm' => $this->confirm($p),
            'forcestart' => $this->forceStart($p),
            'answer' => $this->answer($p, $cmd['text'] ?? ''),
            'done' => $this->done($p),
            'startvote' => $this->startVote($p),
            'vote' => $this->vote($p, is_string($cmd['target'] ?? null) && $cmd['target'] !== '' ? $cmd['target'] : null),
            'unveil' => $this->unveil($p),
            'next' => $this->next($p),
            'skip' => $this->skip($p),
            default => throw new GameError('Unknown command.'),
        };
    }

    private function requireHost(Player $p, string $what): void
    {
        if ($p->id !== $this->hostId) {
            throw new GameError("Only the host can {$what}.");
        }
    }

    private function requirePhase(string $phase, string $msg): void
    {
        if ($this->phase !== $phase) {
            throw new GameError($msg);
        }
    }

    /** Host edits the setup while still in the lobby. Same payload as `create`. */
    private function configure(Player $p, array $input): void
    {
        $this->requireHost($p, 'change the settings');
        $this->requirePhase('lobby', 'Settings can only be changed before the game starts.');
        $cfg = ImpostorConfig::normalize(Games::get($this->type), $input);
        $others = count(array_filter($this->playing(), fn(Player $q) => $q->id !== $this->hostId));
        $seated = $others + ($cfg['settings']['hostPlays'] ? 1 : 0);
        if ($cfg['total'] < $seated) {
            throw new GameError(sprintf('%d players have already joined: the game needs at least %d seats.', $seated, $seated));
        }
        $this->name = $cfg['name'];
        $this->description = $cfg['description'];
        $this->settings = $cfg['settings'];
        $this->total = $cfg['total'];
        $host = $this->player($this->hostId);
        if ($host) {
            if ($cfg['settings']['hostPlays']) {
                unset($host->state['spectator']);
            } else {
                $host->state['spectator'] = true;
            }
        }
        $this->bump();
    }

    private function start(Player $p): void
    {
        $this->requireHost($p, 'start the game');
        $this->requirePhase('lobby', 'The game has already started.');
        $n = count($this->playing());
        if ($n < Config::MIN_PLAYERS) {
            throw new GameError(sprintf('At least %d players needed (%d/%d).', Config::MIN_PLAYERS, $n, $this->total));
        }
        $this->scores = array_fill_keys(array_map(static fn(Player $q) => $q->id, $this->playing()), 0);
        if ($this->settings['source'] === 'players') {
            $this->enter('writing');
            return;
        }
        $count = count(Games::get($this->type)['pairs']);
        $this->deck = array_slice($this->shuffle(range(0, $count - 1)), 0, $this->settings['turns']);
        $this->turnsTotal = count($this->deck);
        $this->startTurn(1);
    }

    // ---------- writing (players write the questions) ----------
    private function writer(Player $p): void
    {
        $this->requirePhase('writing', 'Questions can only be written before the game starts.');
        if ($this->settings['source'] !== 'players') {
            throw new GameError('This game uses the built-in questions.');
        }
    }

    private function question(mixed $v, string $label): string
    {
        $s = Config::clean($v, 1000);
        $len = mb_strlen($s);
        if ($len < self::QUESTION_MIN || $len > self::QUESTION_MAX) {
            throw new GameError(sprintf('%s must be %d to %d characters.', $label, self::QUESTION_MIN, self::QUESTION_MAX));
        }
        return $s;
    }

    private function write(Player $p, array $cmd): void
    {
        $this->writer($p);
        if (!empty($this->pairs[$p->id]['confirmed'])) {
            throw new GameError('You already confirmed your questions.');
        }
        $normal = $this->question($cmd['normal'] ?? '', 'The question for everybody');
        $imp = $this->question($cmd['impostor'] ?? '', "The Impostor's question");
        if (mb_strtolower($normal) === mb_strtolower($imp)) {
            throw new GameError('The two questions must be different.');
        }
        $this->pairs[$p->id] = ['normal' => $normal, 'impostor' => $imp, 'confirmed' => false];
        $this->bump();
    }

    private function confirm(Player $p): void
    {
        $this->writer($p);
        if (!isset($this->pairs[$p->id])) {
            throw new GameError('Write both questions first.');
        }
        $this->pairs[$p->id]['confirmed'] = true;
        $this->bump();
        $this->startIfWritten();
    }

    /** Every playing player has confirmed: go. A non-playing (God) host's pair is optional: used if confirmed, dropped otherwise. */
    private function startIfWritten(): void
    {
        if ($this->phase !== 'writing') {
            return;
        }
        foreach ($this->playing() as $q) {
            if (empty($this->pairs[$q->id]['confirmed'])) {
                return;
            }
        }
        $this->beginTurns();
    }

    private function forceStart(Player $p): void
    {
        $this->requireHost($p, 'start the game');
        $this->writer($p);
        $this->beginTurns();
    }

    private function beginTurns(): void
    {
        $authors = array_keys(array_filter($this->pairs, static fn(array $pr) => $pr['confirmed']));
        if ($authors === []) {
            throw new GameError('Nobody has confirmed a pair of questions yet.');
        }
        $this->pairs = array_intersect_key($this->pairs, array_flip($authors)); // unconfirmed pairs are dropped
        $this->order = $this->shuffle($authors);
        $this->turnsTotal = count($this->order);
        $this->startTurn(1);
    }

    // ---------- a turn ----------
    private function startTurn(int $n): void
    {
        $this->round = $n;
        if ($this->settings['source'] === 'pool') {
            [$normal, $imp] = Games::get($this->type)['pairs'][$this->deck[$n - 1]];
            $author = null;
        } else {
            $author = $this->order[$n - 1];
            ['normal' => $normal, 'impostor' => $imp] = $this->pairs[$author];
        }
        $candidates = array_values(array_filter($this->playing(), static fn(Player $q) => $q->id !== $author));
        $impostor = $candidates[(int)floor($this->rand01() * count($candidates))];
        $this->turn = ['n' => $n, 'normal' => $normal, 'impostor' => $imp, 'author' => $author,
            'authorName' => $author !== null ? ($this->player($author)?->name ?? null) : null,
            'impostorId' => $impostor->id, 'impostorName' => $impostor->name,
            'answers' => [], 'order' => [], 'idx' => 0, 'votes' => []];
        $this->enter('answer');
    }

    private function inTurn(Player $p, string $phase, string $msg): void
    {
        $this->requirePhase($phase, $msg);
        if (self::isGod($p) || $this->turn === null) {
            throw new GameError('You are not playing in this game.');
        }
    }

    private function answer(Player $p, mixed $text): void
    {
        $this->inTurn($p, 'answer', 'Answers are not being collected now.');
        $s = Config::clean($text, 1000);
        if ($s === '' || mb_strlen($s) > self::ANSWER_MAX) {
            throw new GameError(sprintf('Your answer must be 1 to %d characters.', self::ANSWER_MAX));
        }
        $this->turn['answers'][$p->id] = $s;
        $this->bump();
        $this->revealIfAnswered();
    }

    private function revealIfAnswered(): void
    {
        foreach ($this->playing() as $q) {
            if (!isset($this->turn['answers'][$q->id])) {
                return;
            }
        }
        $this->toReveal();
    }

    private function toReveal(): void
    {
        $ids = array_map(static fn(Player $q) => $q->id, $this->playing());
        foreach ($ids as $id) {
            $this->turn['answers'][$id] ??= self::NO_ANSWER;
        }
        $this->turn['order'] = $this->shuffle($ids);
        $this->turn['idx'] = 0;
        $this->enter('reveal');
    }

    private function done(Player $p): void
    {
        $this->inTurn($p, 'reveal', 'Nobody is speaking now.');
        if (($this->turn['order'][$this->turn['idx']] ?? null) !== $p->id) {
            throw new GameError('It is not your turn to speak.');
        }
        $this->nextSpeaker();
    }

    private function nextSpeaker(): void
    {
        $this->turn['idx']++;
        if ($this->turn['idx'] >= count($this->turn['order'])) {
            $this->enter('vote');
            return;
        }
        $this->arm(self::SPEAK_MS);
        $this->bump();
    }

    private function startVote(Player $p): void
    {
        $this->requireHost($p, 'open the vote');
        $this->requirePhase('reveal', 'The vote can only be opened while answers are being read.');
        $this->enter('vote');
    }

    private function vote(Player $p, ?string $target): void
    {
        $this->inTurn($p, 'vote', 'Voting is not open.');
        if ($target !== null) {
            if ($target === $p->id) {
                throw new GameError('You cannot vote for yourself.');
            }
            $t = $this->player($target);
            if ($t === null || self::isGod($t)) {
                throw new GameError('That player is not in the game.');
            }
        }
        $this->turn['votes'][$p->id] = $target;
        $this->bump();
        $this->unveilIfVoted();
    }

    private function unveilIfVoted(): void
    {
        foreach ($this->playing() as $q) {
            if (!array_key_exists($q->id, $this->turn['votes'])) {
                return;
            }
        }
        $this->enter('unveil');
    }

    private function unveil(Player $p): void
    {
        $this->inTurn($p, 'unveil', 'There is nothing to unveil now.');
        if ($this->turn['impostorId'] !== $p->id) {
            throw new GameError('Only the Impostor can unveil.');
        }
        $this->finishTurn(false);
    }

    /** Score the turn (unless cancelled), archive it publicly and show the result. */
    private function finishTurn(bool $cancelled): void
    {
        $t = $this->turn;
        $tally = [];
        foreach ($t['votes'] as $target) {
            if ($target !== null) {
                $tally[$target] = ($tally[$target] ?? 0) + 1;
            }
        }
        $imp = $t['impostorId'];
        $others = 0;
        foreach ($tally as $id => $n) {
            if ($id !== $imp) {
                $others = max($others, $n);
            }
        }
        $discovered = !$cancelled && ($tally[$imp] ?? 0) > $others;
        $points = $cancelled || $discovered ? 0 : $this->settings['points'];
        if ($points > 0 && isset($this->scores[$imp])) {
            $this->scores[$imp] += $points;
        }
        $name = fn(?string $id): ?string => $id === null ? null : ($this->player($id)?->name ?? '?');
        $ids = array_values(array_filter($t['order'] ?: array_keys($t['answers']), static fn($id) => isset($t['answers'][$id])));
        arsort($tally);
        $this->history[] = [
            'n' => $t['n'], 'normal' => $t['normal'], 'impostor' => $t['impostor'], 'impostorId' => $imp,
            'impostorName' => $t['impostorName'], 'authorName' => $t['authorName'],
            'cancelled' => $cancelled, 'discovered' => $cancelled ? null : $discovered, 'points' => $points,
            'answers' => array_map(fn($id) => ['id' => $id, 'name' => $name($id), 'text' => $t['answers'][$id]], $ids),
            'votes' => array_map(fn($by, $to) => ['id' => $by, 'name' => $name($by), 'target' => $to, 'targetName' => $name($to)],
                array_keys($t['votes']), array_values($t['votes'])),
            'tally' => array_map(fn($id, $n) => ['id' => $id, 'name' => $name($id), 'count' => $n], array_keys($tally), array_values($tally)),
        ];
        $this->turn = null;
        $this->enter('result');
    }

    private function next(Player $p): void
    {
        $this->requirePhase('result', 'The turn is not over yet.');
        if ($this->round >= $this->turnsTotal) {
            $this->end();
            return;
        }
        $this->startTurn($this->round + 1);
    }

    private function end(): void
    {
        $this->turn = null;
        $this->enter('ended');
        $this->endedAt = $this->now();
    }

    private function skip(Player $p): void
    {
        $this->requireHost($p, 'skip');
        match ($this->phase) {
            'answer', 'reveal', 'unveil' => $this->advance(),
            'vote' => $this->enter('unveil'),
            default => throw new GameError('Nothing to skip now.'),
        };
    }

    /** @return string[] ids with the best score (empty when nobody scored) */
    public function winners(): array
    {
        $best = 0;
        foreach ($this->playing() as $p) {
            $best = max($best, $this->scores[$p->id] ?? 0);
        }
        return $best === 0 ? [] : array_values(array_map(static fn(Player $p) => $p->id,
            array_filter($this->playing(), fn(Player $p) => ($this->scores[$p->id] ?? 0) === $best)));
    }

    // ---------- views ----------
    private function pairList(): array
    {
        $out = [];
        if ($this->settings['source'] === 'pool') {
            $pairs = Games::get($this->type)['pairs'];
            foreach ($this->deck as $i => $idx) {
                $out[] = ['turn' => $i + 1, 'author' => null, 'normal' => $pairs[$idx][0], 'impostor' => $pairs[$idx][1], 'confirmed' => true];
            }
            return $out;
        }
        foreach ($this->pairs as $author => $pr) {
            $turn = array_search($author, $this->order, true);
            $out[] = ['turn' => $turn === false ? null : $turn + 1, 'author' => $this->player($author)?->name ?? '?',
                'normal' => $pr['normal'], 'impostor' => $pr['impostor'], 'confirmed' => $pr['confirmed']];
        }
        usort($out, static fn(array $a, array $b) => ($a['turn'] ?? 999) <=> ($b['turn'] ?? 999));
        return $out;
    }

    public function viewFor(string $viewerId): array
    {
        $me = $this->player($viewerId) ?? throw new GameError('Unknown player.');
        $god = self::isGod($me);
        $t = $this->turn;
        $live = $t !== null && in_array($this->phase, ['answer', 'reveal', 'vote', 'unveil'], true);
        $players = [];
        foreach ($this->players as $p) {
            $row = ['id' => $p->id, 'name' => $p->name, 'avatarV' => $p->avatarV, 'hasAvatar' => $p->avatar !== null,
                'connected' => $p->connected, 'spectator' => self::isGod($p), 'score' => $this->scores[$p->id] ?? 0];
            if ($this->phase === 'writing') {
                $row['confirmed'] = !empty($this->pairs[$p->id]['confirmed']);
            }
            if ($live && !self::isGod($p)) {
                if ($this->phase === 'answer') {
                    $row['answered'] = isset($t['answers'][$p->id]);
                } elseif ($this->phase === 'vote') {
                    $row['hasVoted'] = array_key_exists($p->id, $t['votes']);
                }
            }
            $players[] = $row;
        }
        $m = ['id' => $me->id, 'isHost' => $me->id === $this->hostId, 'god' => $god];
        if ($this->phase === 'writing' && isset($this->pairs[$me->id])) {
            $m['pair'] = array_intersect_key($this->pairs[$me->id], array_flip(['normal', 'impostor', 'confirmed']));
        }
        if ($live && !$god) {
            $isImp = $t['impostorId'] === $me->id;
            $m['impostor'] = $isImp;
            $m['question'] = $isImp ? $t['impostor'] : $t['normal'];
            $m['answer'] = $t['answers'][$me->id] ?? null;
            if ($this->phase === 'vote' || $this->phase === 'unveil') {
                $m['voted'] = array_key_exists($me->id, $t['votes']);
                $m['vote'] = $t['votes'][$me->id] ?? null;
            }
        }
        $view = [
            'id' => $this->id, 'type' => $this->type, 'engine' => 'impostor', 'name' => $this->name,
            'description' => $this->description, 'phase' => $this->phase, 'round' => $this->round,
            'turns' => $this->turnsTotal, 'seq' => $this->seq, 'version' => $this->version, 'deadline' => $this->deadline,
            'now' => $this->now(), 'joinable' => $this->joinable(), 'total' => $this->total, 'hostId' => $this->hostId,
            'settings' => $this->settings, 'players' => $players, 'me' => $m, 'history' => $this->history,
        ];
        if ($live) {
            $answers = static fn(array $ids) => array_map(static fn($id) => ['id' => $id, 'text' => $t['answers'][$id]], $ids);
            $view['turn'] = ['n' => $t['n']];
            if ($this->phase === 'reveal') {
                $view['turn'] += ['speaker' => $t['order'][$t['idx']] ?? null, 'index' => $t['idx'], 'count' => count($t['order']),
                    'answers' => $answers(array_slice($t['order'], 0, $t['idx'] + 1))];
            } elseif ($this->phase === 'vote' || $this->phase === 'unveil') {
                $view['turn']['answers'] = $answers($t['order']);
            }
        }
        if ($this->phase === 'ended') {
            $view['winners'] = $this->winners();
        }
        if ($god) { // God mode: the non-playing host sees the whole turn live and every question pair
            $view['god'] = ['pairs' => $this->pairList(), 'turn' => $t === null ? null : [
                'normal' => $t['normal'], 'impostor' => $t['impostor'], 'impostorId' => $t['impostorId'],
                'answers' => (object)$t['answers'], 'votes' => (object)$t['votes'],
            ]];
        }
        return $view;
    }
}
