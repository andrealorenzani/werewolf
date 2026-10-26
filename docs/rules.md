# Game rules

Three games live here. **Werewolf** and **Mafia** are social-deduction games that share one engine (everything up to *Differences between the two games*); **Impostor** is a separate quick question game with its own rules (see the *Impostor* section). In Werewolf and Mafia a hidden minority (the **evil** team) tries to
eliminate the informed-but-powerless majority (the **good** team). The server acts as the
moderator, so no human narrator is needed.

Background: the rules follow the classic party games *Mafia* (Dimitry Davidoff, 1986) and
*Werewolf / Ultimate Werewolf*, with the common variants listed below fixed to one choice so the games are unambiguous.

## Shared rules

### Setup
- The host picks a game, a name/description, and **how many of each character** to include. The number of players is the sum of all counts.
- Limits: 4–30 players, at least one evil member, and the good team must strictly outnumber the evil team at the start. Werewolf has extra caps on the counts (see its section); the setup form enforces them and early start clamps the dealt cards to them (the excess goes to the hidden pool).
- Players join with a code / QR / link. The host can start as soon as **at least 4 players** have joined, even if not every seat is filled. Until the game starts the host can change the whole setup (character counts, timers, name); the number of seats can never drop below the players already joined.
- **Starting early:** the cards are scaled to the players present. The evil share is scaled by players/seats (at least 1, always fewer than the good team). Characters listed later in the game's role table are dropped first, and plain fillers (Villager/Citizen) before special good characters. Undealt cards stay in a hidden pool.
- **Late join:** while seats remain, players can still join until day 0 discussion ends. A late joiner gets a random card from the hidden pool, but an evil card only if the good team would still strictly outnumber evil; otherwise a filler/good card. A public log line announces the arrival; evil teammates see a late evil joiner. A late joiner starts "not done", so day 0 waits until they press "done" or the host skips.
- Each player secretly receives one character card (shuffled randomly). Evil members know each other; nobody else knows anything.
- Any player may change their name (unique in the game, case-insensitive) or avatar at any time ("My profile"), at most once every 3 seconds.

### Phases
```
Day 0 (discussion) → Night 1 → Day 1 (discussion → vote) → Night 2 → Day 2 … → game over
```
1. **Day 0 – discussion.** Everyone talks; no one has information yet. No vote, no elimination.
2. **Night.** Special characters act in secret (see *Night order*). Players without a night power just wait.
3. **Dawn.** Deaths are announced (never the cause). If *reveal characters* is on, the dead player's card is shown.
4. **Discussion.** The living talk. Ends when every living player presses "done", when the timer (if any) expires, or when the host skips.
5. **Vote.** Each living player votes for one other player or abstains; votes are public and can be changed until everyone has voted or time runs out. The player with the **most votes** is eliminated. **Ties (or no votes) eliminate nobody.**
6. Back to night.

Eliminated players can no longer vote or use powers. They may chat with other eliminated players only.

**Private messages:** a player can send a private message to one other player, except at night. During the game it works alive to alive and dead to dead; in the lobby and after the game ends anyone can message anyone.

### Winning
Checked after every elimination:
- **Good team wins** when no evil member is alive.
- **Evil team wins** when living evil members ≥ living good members.
- **Neutral players** (the Mafia Corrupt) count for neither side in this check. They win or lose with the side they chose (see *Corrupt*). At the end, each player is told whether they personally won.
- Setup: neutral cards are dealt only while the real good players still outnumber the evil team (also for early start and late join); otherwise they stay in the pool.

### Night order
Night has up to three steps. The night ends as soon as all acting players submit, when the step timer expires (the night timer is always on), or when the host skips the step (missing actions count as "skip"). The host can also skip the Hunter's shot.
1. **Step 1:** block, kill, protect, inspect, shoot – all chosen simultaneously. Night actions, the Hunter's shot and the vote open as popups; players without a night action just see "It is night…" (the player board is not clickable).
2. **Step 2:** the Witch and the Paladin (Werewolf only) are told who the evil team chose to kill, then decide.
3. **Step 3 (Werewolf):** a Ghost raised tonight points a finger (see Ghost). It ends when the ghost acts, by timer, or when the host skips. A ghost with no valid target is skipped.

**Evil kill (both games):** each living killer *suggests* a victim (changing a suggestion clears everyone's confirmation). Suggestions are visible only to the living evil team. When all killers suggest the same player, each presses confirm; the kill is set once all have confirmed. There is no pass. If time runs out or the host skips, the victim is picked at random among the distinct suggestions; with no suggestion nobody dies.

Resolution:
1. **Blocks** (Escort) cancel the target's action for the night. The blocked player is told.
2. The evil team's victim is the one agreed above (a blocked killer's suggestion does not count).
3. The Paladins' shield or the Witch's heal cancels the evil kill; her poison and the Vigilante's shot add attacks. Known limitation: a heal protects only the wolf victim, so it does not save a poisoned bystander; a victim who is both healed and poisoned dies.
4. A **protected** player (Mafia Doctor) survives every attack. The Doctor is told if their patient was attacked.
5. **Inspections** (Seer / Detective) are answered privately, unless the inspector was blocked.
6. Everyone who still has an attack on them dies.

The evil team cannot target its own members. Protect may target oneself; every other power may not.

### Settings
| Setting | Default | Meaning |
|---|---|---|
| Discussion seconds | off (0) | Optional timer for each discussion (15–900) |
| Vote seconds | off (0) | Optional timer for each vote (10–300); also used for the Hunter's shot |
| Night seconds | 60 | **Mandatory** timer for each night step (30–90); empty or 0 means 60 |
| Reveal characters | on | Show a player's card when they die |
| Story: public chat | off | The end-of-game story replays the public chat between the events |
| Story: private chats | off | The story also replays private messages, night wolf/mafia chat and graveyard chat (everyone sees them at the end) |

In the room these settings are listed under "Your character". The lobby and post-game chat are never part of the story. The chat history keeps the last 300 messages (1000 if a story option is on).

---

## Werewolf

*Setting:* a village where werewolves hide among the villagers.
**Villagers** must find the werewolves; **Werewolves** devour a villager every night.

| Character | Team | Power |
|---|---|---|
| Villager | Villagers | None. Talk and vote. Setup: 3–20. (Fills the remaining seats.) |
| Werewolf | Werewolves | Each night, the werewolves jointly pick a victim (see *Evil kill*). They can chat privately at night. At least 1, at most one third of the non-wolves (rounded up). |
| Seer | Villagers | Each night, learns whether one player **is a werewolf**. Max 1. |
| Paladin | Villagers | After the werewolves choose, may shield their victim (the victim survives). Paladins know each other. They share one stock of charges: werewolves in the game (dead ones included) minus charges spent by any paladin. The shield works only if **all living paladins** choose to save; it then costs one charge that night. A charge is spent even if the Witch also heals; the shield does not stop poison or the Hunter's/Vigilante's shots. Max: one per 10 non-wolves (rounded up). |
| Ghost (hidden trait, not a card) | – | The host sets the number of ghosts (0 up to the number of werewolves; default 0). They are not extra players: that many random living non-wolf players (the Seer too) secretly get the trait. If fewer werewolves or candidates exist than asked (early start), it is reduced; late joiners top it up (holders alive or dead count). Only the holder knows. If a holder dies **at night** (killed by the werewolves or poisoned, unless saved) they become a ghost; votes and Hunter shots never raise one. 50% good (points a finger at a werewolf-side player), 50% evil (points at a good player, not the dying). Only they know their side (green or red banner) and they see everyone's roles. Ghosts act at night step 3, after Paladins and Witches. Dawn announces it, the ghost's role is revealed even if *reveal characters* is off, and the target shows "selected by ghost X" for that day. In setup the Ghost row is shown in black and does not count towards the players. |
| Witch | Villagers | Knows the werewolves' victim. Once per game may **heal** that victim, and once per game may **poison** anyone. Witches act independently (two heals spend both potions). Max: one per 10 non-wolves (rounded up). |
| Cultist | Villagers (wins with the werewolves) | Looks like a villager to everyone, the Seer included, and counts as a villager for the win check, the Ghost and the Paladin charges. Has no power and does not know the werewolves. When the werewolves choose him as their victim he **survives and becomes a werewolf** (red name in setup). If the Witch healed him, a Paladin shielded him, or he was protected, nothing happens. If another source kills him the same night (e.g. a shot), he simply dies. A Witch poison on him the same night is void and her poison is not spent (she is told). The werewolves are told he joined and that he was a Cultist; he reads the werewolf chat only from the turn onward, and loses any Ghost trait. He wins with the werewolves even if he is dead. If the last werewolf dies while he is still unturned, the villagers win and he loses. Max: as many as werewolves. Known trade-off: setup counts him as good for "villagers must outnumber werewolves". |
| Hunter | Villagers | When they die (night, vote, or any cause) they immediately **shoot** one player (or nobody). If a Hunter shoots another Hunter, the news "A shot B" is held back until B has shot or passed (B shoots blind). Max: as many as werewolves. |

The Doctor is not in Werewolf (replaced by the Paladin).
Default set: 5 Villagers, 1 Werewolf (other characters 0; add them as you like). The Seer is limited to 1.
Suggestion: one extra werewolf per ~4 players beyond 8.

## Mafia

*Setting:* a town infiltrated by organised crime.
**Citizens** must find the criminals; the **Mafia** assassinate a citizen every night.

| Character | Team | Power |
|---|---|---|
| Citizen | Town | None. Talk and vote. (Fills the remaining seats.) |
| Mafioso | Mafia | Each night, the Mafia jointly choose a victim (see *Evil kill*); private night chat. |
| Godfather | Mafia | Like a Mafioso, but the Detective sees him as **innocent**. Max 1. |
| Detective | Town | Each night, learns whether one player **is Mafia** (Godfather → innocent). Max 1. |
| Doctor | Town | Each night, protects one player (self allowed). Max 1. |
| Escort | Town | Each night, distracts one player: their action fails. Max 1. |
| Vigilante | Town | Once per game, shoots one player at night. Max 1. |
| Corrupt | Neutral | No night power. Knows the Mafioso, Godfather, Detective and Vigilante, and they know him. At any time while alive (discussion, vote, night, hunter shot) he chooses **Town or Mafia** once; the choice is final and private until the game ends. He wins if his side wins; if he never chooses, he loses. Counts for neither side in the win check. Max 1. |

At least one Mafia member (Mafioso or Godfather) is required.
Default set: 5 Citizens, 1 Mafioso (other characters 0).

## Differences between the two games

| | Werewolf | Mafia |
|---|---|---|
| Theme | Monsters in a village | Criminals in a town |
| Investigator | Seer: "is a werewolf" | Detective: "is Mafia"; Godfather evades |
| Unique good roles | Witch, Hunter, Paladin (ghost is a hidden trait) | Escort, Vigilante |
| Unique evil roles | – | Godfather |
| Special | Cultist (good, turns into a werewolf) | Corrupt (neutral, chooses a side) |
| Core loop, win conditions, vote | identical | identical |

---

## Impostor

*Setting:* a party game of similar questions. Every turn everybody privately answers the same question, except one player (the **Impostor**) who gets a similar but different one and has to blend in. There is no chat in this game: talk in person or over a call.

### Setup and lobby
- The host sets a name/description, **seats** (4–30, default 8), **turns** (1–20, default 3; only with the built-in or the host's questions), **points** the Impostor earns (1–10, default 3), the **points for finding the Impostor** (0–9, always lower than the Impostor's, default 0), the **vote time** (30–90 s, default 60), the question source (built-in pool, written by the players, or written by the host, who must then not play) and whether the host plays. Until the game starts the host can edit all of it; seats cannot drop below the players already joined.
- The host starts with **at least 4 playing players** (no late joiners after the start). Players join with a code / QR / link like in the other games and may edit their name or avatar at any time.
- **God mode:** if the host does not play, they never answer or vote but see everything: the whole current turn live (questions, who the Impostor is, answers and votes) and every question pair. They moderate (skip, open the vote). Players can never see this.

### Questions
- **Built-in pool:** the server picks the configured number of pairs at random. The built-in questions have an adult tone (crude-but-light, confessional humour; no slurs, minors, politics or graphic sexual detail): use the players or host source for a family audience.
- **Written by the host (writing phase):** only if the host does not play. They add pairs (up to 50; 5–140 characters each, the two must differ, no repeated normal question) and can delete them; nobody else sees them. The host starts the game once there are at least as many pairs as turns; the turns use that many pairs picked at random.
- **Written by the players (writing phase):** each playing player writes a pair (the question for everybody, and a similar one for the Impostor; 5–140 characters each, the two must differ) and **confirms** it. The game starts by itself when every playing player has confirmed; the host can also force the start with the pairs confirmed so far (at least one). A non-playing host may write a pair too: it is used if confirmed, dropped otherwise. Unconfirmed pairs are dropped. There is one turn per pair, in random order. The author of a pair answers the normal question and is **never the Impostor** of that turn. Authors see only their own pair while writing.

### A turn
1. **Answer** (at most 180 s): everyone answers privately (1–100 characters). Only the Impostor knows it is the Impostor and sees its own question. It ends when everybody has answered; players who did not answer show "(no answer)".
2. **Reveal:** in a random order each player's answer is shown and that player presses *done* (at most 120 s per speaker; the host can skip a speaker or open the vote early).
3. **Vote** (host-set time, 30–90 s): everyone votes for who they think the Impostor is (not themselves; blank allowed; votes can be changed until the phase ends). Voting opens after the last answer has been read out, or when the host opens it earlier. When all playing players have voted it moves on early to the unveil.
4. **Unveil** (at most 60 s): the Impostor reveals itself (automatically on timeout).
5. **Result:** both questions, all answers, who voted for whom and the tally are shown. Any player (or the host) presses *next* for the following turn; after the last turn the game ends.

### Scoring and winning
- The Impostor scores only when it escapes. It is **discovered** only if it received **strictly more votes** than every other player. A tie at the top that includes the Impostor, or nobody voting, means it is **not discovered** and it earns the host's points.
- If it is discovered, every player still in the game who voted for it earns the host's *points for finding the Impostor* (0 by default, so by default nobody scores). When it escapes, only the Impostor scores.
- A **cancelled** turn (the Impostor left or was removed) scores 0.
- After the last turn the highest score wins; players tied on top share the win; a top score of 0 means no winner.

### Leaving and timeouts
- A player may leave and the host may remove players. The host can never leave. A leaver's answers and votes are removed, and votes for them are cleared (those voters can vote again). If the **Impostor** leaves, the turn is cancelled (no points). If fewer than 4 playing players remain, the game ends. The turn then continues if the leaver was a speaker or the last missing answer/vote.
- The answer, reveal and unveil timeouts are fixed, so a silent player cannot stall a running turn. **Known limitation:** nothing advances in the lobby or the writing phase without the host (a gone host stalls it); such games are deleted after 7 days idle.
- Ended games are deleted 2 days after the end, others after 7 days idle (as in the other games).

## Variant choices (fixed on purpose)
- The vote is a plurality with **no run-off**; ties spare everyone.
- The Mafia Doctor may protect the same person on consecutive nights and may protect himself.
- Deaths never reveal their cause; the dead's card is revealed only if *reveal characters* is on.
- The Hunter's shot happens immediately, even when the Hunter was voted out.
- Day 0 has no vote.
- Impostor: the Impostor escapes on a tie, there is no run-off or second guess.
- Games are deleted 2 days after they end and 7 days after the last activity; afterwards the code answers "Game not found".
