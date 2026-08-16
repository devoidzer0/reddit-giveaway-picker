# Reddit Giveaway Picker — Ranked Random Order (API)

This version uses Reddit OAuth/Data API access only to retrieve the giveaway thread and its visible top-level comments.

It does **not** make per-user profile lookups for account age or comment karma. Eligibility is assumed to have already been enforced by the subreddit/moderation system before the drawing.

## Why this tool exists

Traditional Reddit raffle tools are designed primarily to select random commenters as winners. That works well when a giveaway has a single prize or when all prizes are interchangeable, but it does not address the allocation problem created by a giveaway containing many different games.

This application is designed for a single Reddit giveaway post containing multiple Steam games. Entrants can submit a ranked list of the games they would like to receive.

For example, an entrant might comment:

```text
1. Game A
2. Game C
3. Game F
```

The application reads the giveaway's top-level comments and matches each entrant's ranked choices against the giveaway's master game list. The operator reviews the parsed choices before any drawing occurs.

The application then generates one random ordering of the entrants. Starting with the first randomly ordered entrant, each person receives their highest-ranked game that is still available. Once a game has been awarded, it is removed from the available pool. If all of an entrant's requested games have already been awarded, that entrant is skipped and the application continues through the same random ordering.

This allows a multi-game giveaway to be conducted in one Reddit post while preserving both random winner selection and entrants' ranked game preferences.

Reddit API access is used only to retrieve the giveaway post and its public top-level comments. All preference parsing, operator review, randomization, and prize allocation occur within the application.

## Ranked giveaway allocation

Entrants may list multiple games in order of preference.

After review, the program:

1. randomizes all eligible entrants once
2. processes that fixed random order from top to bottom
3. gives each entrant their highest-ranked game still available
4. removes that game from the available pool
5. skips a user if every requested game has already been awarded
6. stops when all games are awarded or all entrants have been processed

The program never rerolls or reshuffles midway through a giveaway.

## Reddit API usage

The application uses Reddit only to:

- obtain an OAuth access token
- retrieve the giveaway post and comments
- retrieve additional comment children when Reddit omits them from the initial response

It does not:

- retrieve account profile/karma data
- post comments
- vote
- send messages
- moderate communities
- edit or delete Reddit content

## Eligibility

The app treats the subreddit’s own moderation/eligibility filtering as authoritative.

This is especially useful for communities that already enforce requirements such as:

- minimum account age
- minimum comment karma
- other participation/history rules

Only visible top-level giveaway entries returned from the thread are considered.

## Preference parsing

Each numbered/bulleted line in a user’s comment is compared against the master game list. Exact matches, spacing/punctuation differences, minor misspellings, and some abbreviations can be suggested.

Every detected preference appears on the review screen so the operator can correct or ignore it before any randomization happens.

## Allocation rule

Example randomized order:

1. User A — Game 1 → Game 2 → Game 3
2. User B — Game 1 → Game 3
3. User C — Game 2 → Game 1

If User A receives Game 1, User B’s first choice is no longer available, so User B receives Game 3. User C then receives Game 2.

If all of a user’s requested games are already taken, that user is skipped and the program continues down the same random order.

## No fixed game limit

There is no fixed maximum number of games.

## Requirements

- PHP 8.1+
- PHP cURL
- PHP mbstring
- approved Reddit API credentials


## Supported preference-list formats

The parser now handles:

```text
1. Game A
2. Game B
3. Game C
```

```text
Game A

Game B

Game C
```

```text
Game A, Game B, Game C
```

```text
Game A - Game B - Game C
```

```text
Game A; Game B; Game C
```

and mixed numbered/bulleted formatting.

Comma splitting is conservative so ordinary prose containing a comma is less likely to be broken into fake game choices. Hyphens are treated as separators only when surrounded by spaces, so normal hyphenated game titles are preserved.

If one line contains several exact game titles from the master list, each title is extracted separately and kept in order.


## Parser v3

Improved multi-game comment parsing for commas, blank lines, conjunctions, spaced dashes, back-to-back exact titles, abbreviations, and minor misspellings.


## Parser v4 safeguards

- Suggestions below 75% confidence default to `Ignore this line`.
- Thank-you/giveaway prose is stripped before matching where possible.
- Generic words no longer inflate token-overlap scores.
- Roman numerals and Arabic digits are normalized.
- Acronyms such as `AC Valhalla` and `SMT V` are recognized more strongly.
- Longer titles that merely begin with a listed title (for example `Life is Strange: Double Exposure`) are not treated as exact matches.


## Parser v5 fix

Inline bare numbers are no longer interpreted as new list items. Titles such as `Crysis 3` and `Wizard of Legend 2` therefore remain intact. Bare numbered entries like `1 Crysis 3` are still accepted when the number begins a physical line. Inline numbered lists remain supported when item numbers use punctuation, such as `1. Game A 2. Game B`.
