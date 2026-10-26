---
name: impostor-questions
description: Generate or add question pairs for the Impostor game's built-in pool (resources/impostor-questions.md), in the funny, adult, confessional style of the maintainer's 21 samples. Use when asked to extend, grow, refresh or regenerate the Impostor questions.
---
# Writing Impostor question pairs

The pool is `resources/impostor-questions.md`, read by `Wm\ImpostorQuestions`. Format: a line `N. question for everybody` followed by `N-impostor. similar question for the Impostor`; blank lines and `#` lines are ignored.

**Before writing anything read, in this order:** `samples.md` (the 21 reference pairs and the analysis of why they are funny: the model for every new pair) and `research.md` (earlier internet research and the catalogue of pair mechanisms). Do not redo the research; extend `research.md` if you learn something new.

## Rules
- **The 21 samples are pairs 1-21, verbatim, always.** On a full regeneration write them first, unchanged and in order, then the new pairs. On an append check they are still there (the test pins them) and never touch them.
- **Append only** once a pool is committed. Never edit, renumber, reorder or remove an existing pair (mandatory ones are 1..21 plus any later pair already committed): saved games refer to pairs by position. New pairs get the next consecutive number at the end of the file. A full regeneration is an exception that only the maintainer may ask for; then update the pinned hash in `tests/ImpostorQuestionsTest.php` and mention it in the changelog (via the documenter).
- **Style = the samples**: adult, crude-but-light, confessional, absurd. Both questions must have answers of the *same shape* (number, duration, name, place, song, phrase) so the Impostor can bluff, but different enough that the honest answer sounds wrong when read aloud. Vary the mechanism (flip, swap subject, swap context, number vs number, duration, player vs player, confession vs trivia, dark vs light, phrase vs phrase) and do not put two pairs of the same mechanism next to each other.
- Run the self-test at the end of `samples.md` on every pair; drop the ones that fail.
- Normal questions must be unique (compare case-insensitively with every existing normal question; also avoid near-duplicates).
- Length of each question: 5 to 140 characters; the players answer in at most 100 characters, so questions must invite short answers.
- Content limits: no slurs or hatred towards groups, no minors in a sexual or violent context, no real private people (celebrities and "the players" are fine), no politics or religion, no graphic sexual detail.
- Plain English text, no markdown inside the questions (sample 16 is the one legacy exception).
- Never write domain names, server or hosting details anywhere.

## Steps
1. Read `samples.md` and `research.md`, then the end of `resources/impostor-questions.md` to find the last number N.
2. Append the new pairs as `N+1.`, `N+1-impostor.`, and so on, separated by a blank line.
3. Run `php tests/run.php ImpostorQuestions` and fix any failure (a parser error names the line).
4. If the mandatory set changes, update the pinned hash in `tests/ImpostorQuestionsTest.php` and tell the maintainer.
