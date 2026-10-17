# Game rules

Two social-deduction games share one engine. A hidden minority (the **evil** team) tries to
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

### Night order
Night has up to three steps. The night ends as soon as all acting players submit, when the step timer expires (the night timer is always on), or when the host skips the step (missing actions count as "skip"). The host can also skip the Hunter's shot.
1. **Step 1:** block, kill, protect, inspect, shoot – all chosen simultaneously. Night actions, the Hunter's shot and the vote open as popups; players without a night action just see "It is night…" (the player board is not clickable).
2. **Step 2:** the Witch and the Paladin (Werewolf only) are told who the evil team chose to kill, then decide.
3. **Step 3 (Werewolf):** a Ghost created by tonight's wolf kill points a finger (see Ghost). It ends when the ghost acts, by timer, or when the host skips. A ghost with no valid target is skipped.

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
| Ghost (hidden trait, not a card) | – | At the start about one fifth of the non-wolf players (rounded up, chosen at random, never a werewolf) get a secret ghost trait; late joiners top it up. Whatever their character (even the Seer), if the werewolves kill such a player (and nothing prevents it) they become a ghost: 50% they turn evil. Only they know their side (green or red banner) and they see everyone's roles. A good ghost points a finger at a werewolf-side player, an evil ghost at a good player (not the dying). Dawn announces it, the ghost's role is revealed even if *reveal characters* is off, and the target shows "selected by ghost X" for that day. |
| Witch | Villagers | Knows the werewolves' victim. Once per game may **heal** that victim, and once per game may **poison** anyone. Witches act independently (two heals spend both potions). Max: one per 10 non-wolves (rounded up). |
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

At least one Mafia member (Mafioso or Godfather) is required.
Default set: 5 Citizens, 1 Mafioso (other characters 0).

## Differences between the two games

| | Werewolf | Mafia |
|---|---|---|
| Theme | Monsters in a village | Criminals in a town |
| Investigator | Seer: "is a werewolf" | Detective: "is Mafia"; Godfather evades |
| Unique good roles | Witch, Hunter, Paladin (ghost is a hidden trait) | Escort, Vigilante |
| Unique evil roles | – | Godfather |
| Core loop, win conditions, vote | identical | identical |

## Variant choices (fixed on purpose)
- The vote is a plurality with **no run-off**; ties spare everyone.
- The Mafia Doctor may protect the same person on consecutive nights and may protect himself.
- Deaths never reveal their cause; the dead's card is revealed only if *reveal characters* is on.
- The Hunter's shot happens immediately, even when the Hunter was voted out.
- Day 0 has no vote.
- Games are deleted 2 days after they end and 7 days after the last activity; afterwards the code answers "Game not found".
