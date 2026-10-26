---
name: impostor-questions
description: Add new question pairs to the Impostor game's built-in pool (resources/impostor-questions.md). Use when asked to extend, grow or refresh the Impostor questions. The pool is append-only.
---
# Adding Impostor question pairs

The pool is `resources/impostor-questions.md`, read by `Wm\ImpostorQuestions`. Format: a line `N. question for everybody` followed by `N-impostor. similar question for the Impostor`; blank lines and `#` lines are ignored.

## Rules
- **Append only.** Never edit, renumber, reorder or remove an existing pair (the mandatory ones are 1..90, plus any later pair already committed): saved games refer to pairs by position. New pairs get the next consecutive number at the end of the file.
- Each pair: the normal question and the Impostor's variant must be similar enough that plausible answers overlap (so the Impostor can blend in) but not identical. Example: "What is your favourite fruit?" / "What is your favourite vegetable?".
- Normal questions must be unique (compare case-insensitively with every existing normal question; also avoid near-duplicates).
- Length of each question: 5 to 140 characters. Family-friendly, no names of real private people, no politics or religion.
- Plain English text, no markdown inside the questions.
- Never write domain names, server or hosting details anywhere.

## Steps
1. Read the end of `resources/impostor-questions.md` to find the last number N.
2. Write the new pairs as `N+1.`, `N+1-impostor.`, and so on, separated by a blank line, appending to the file.
3. Run `php tests/run.php ImpostorQuestions` and fix any failure (a parser error names the line).
4. If the mandatory set grows, tell the maintainer: `tests/ImpostorQuestionsTest.php` pins the hash of the first pairs.
