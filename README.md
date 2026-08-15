# Reddit Giveaway Picker — API Review Source

This repository contains the source code for a small, private, non-commercial PHP utility used to administer occasional Reddit giveaways.

## Purpose

The tool reads comments from one Reddit giveaway thread supplied manually by the operator, identifies which listed game each entrant requested, verifies basic eligibility criteria, and randomly selects one winner per game.

The utility is read-only with respect to Reddit. It does not post, comment, vote, send messages, moderate communities, or modify Reddit content.

## Eligibility Rules

An entrant must have:

- Account age of at least 10 full days
- At least 150 comment karma

If Reddit account information cannot be verified, that entrant is excluded rather than guessed eligible.

## Game Matching

The operator enters the actual game titles, one per line.

The tool:

1. Looks for exact case-insensitive title matches.
2. Normalizes punctuation and spacing.
3. Uses conservative fuzzy matching for minor misspellings.
4. Sends uncertain matches to a manual review screen before the drawing.
5. Does not randomize anything until the review step is completed.

## Random Selection

Final winners are selected using PHP's `random_int()` from the eligible pool for each game.

## Reddit Authentication

The application uses OAuth client credentials.

The local `config.php` file contains:

- Reddit client ID
- Reddit client secret
- Descriptive User-Agent

`config.php` is intentionally excluded from this repository and is listed in `.gitignore`.

`config.php.example` contains placeholders only.

## Reddit Endpoints Used

The application accesses only the following Reddit endpoints:

### OAuth token

`POST https://www.reddit.com/api/v1/access_token`

Used to obtain an OAuth bearer token with the application's client credentials.

### Read giveaway thread and comments

`GET https://oauth.reddit.com/comments/{post_id}`

Used to retrieve the manually supplied giveaway post and its comments.

### Retrieve additional comment children

`GET https://oauth.reddit.com/api/morechildren`

Used only when the giveaway thread contains additional comment objects not included in the initial thread response.

### Read public Reddit account information

`GET https://oauth.reddit.com/user/{username}/about`

Used only for entrants whose comments appear to request one of the listed giveaway games.

The application reads:

- `created_utc`
- `comment_karma`

These values are used solely to apply the giveaway's published eligibility requirements.

## Data Handling

- The tool is run manually for occasional giveaways.
- It does not continuously monitor Reddit.
- It does not build user profiles.
- It does not sell, redistribute, or monetize Reddit data.
- It does not use Reddit data for advertising.
- It does not use Reddit data to train AI or machine-learning models.
- Data is used only to determine giveaway eligibility and select winners.

## Source Files

- `index.php` — application logic, Reddit API access, matching, eligibility checks, manual review, and drawing
- `config.php.example` — credential configuration template with placeholders only
- `.htaccess` — optional Apache protection for configuration files
- `.gitignore` — prevents local credentials from being committed

## Requirements

- PHP 8.1+
- PHP cURL extension
- PHP mbstring extension
- Reddit API credentials
- Network access to Reddit

## Security

The real `config.php` containing credentials must remain private and must never be committed to source control.
