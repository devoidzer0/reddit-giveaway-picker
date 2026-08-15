<?php
declare(strict_types=1);

session_start();

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo '<h1>Setup required</h1><p>Copy <code>config.php.example</code> to <code>config.php</code> and add your Reddit API credentials.</p>';
    exit;
}
$config = require $configFile;

const APP_NAME = 'Reddit Giveaway Picker';
const MIN_ACCOUNT_AGE_DAYS = 10;
const MIN_COMMENT_KARMA = 150;

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalizeSpace(string $s): string {
    return trim((string)preg_replace('/\s+/u', ' ', $s));
}

function redditRequest(string $url, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) throw new RuntimeException("Network error: $err");
    if ($status < 200 || $status >= 300) throw new RuntimeException("Reddit API returned HTTP $status.");
    $json = json_decode($body, true);
    if (!is_array($json)) throw new RuntimeException('Reddit returned an unreadable JSON response.');
    return $json;
}

function getAccessToken(array $config): string {
    $clientId = trim((string)($config['reddit_client_id'] ?? ''));
    $clientSecret = trim((string)($config['reddit_client_secret'] ?? ''));
    $userAgent = trim((string)($config['user_agent'] ?? ''));

    if ($clientId === '' || $clientSecret === '' || $userAgent === '') {
        throw new RuntimeException('Reddit API credentials are missing from config.php.');
    }

    $ch = curl_init('https://www.reddit.com/api/v1/access_token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'client_credentials']),
        CURLOPT_USERPWD => $clientId . ':' . $clientSecret,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => [
            'User-Agent: ' . $userAgent,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) throw new RuntimeException("OAuth network error: $err");
    $json = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($json) || empty($json['access_token'])) {
        $detail = is_array($json) && !empty($json['error']) ? ' (' . $json['error'] . ')' : '';
        throw new RuntimeException("Could not obtain Reddit OAuth token. HTTP $status$detail");
    }
    return (string)$json['access_token'];
}

function extractPostPath(string $url): string {
    $parts = parse_url(trim($url));
    if (!$parts || empty($parts['host'])) throw new RuntimeException('Please enter a valid Reddit thread URL.');
    $host = strtolower((string)$parts['host']);
    $path = (string)($parts['path'] ?? '');

    if ($host === 'redd.it') {
        $id = trim($path, '/');
        if (!preg_match('/^[a-z0-9]+$/i', $id)) throw new RuntimeException('Could not determine the Reddit post ID.');
        return '/comments/' . $id;
    }
    if (!preg_match('/(^|\.)reddit\.com$/i', $host)) {
        throw new RuntimeException('Please use a reddit.com or redd.it thread URL.');
    }
    if (!preg_match('~/(?:r/[^/]+/)?comments/([a-z0-9]+)~i', $path, $m)) {
        throw new RuntimeException('Could not determine the Reddit post ID from that URL.');
    }
    return '/comments/' . $m[1];
}

function flattenComments(array $children, int $depth = 0, array &$comments = [], array &$moreIds = []): void {
    foreach ($children as $child) {
        if (!is_array($child)) continue;
        $kind = $child['kind'] ?? '';
        $data = $child['data'] ?? [];
        if ($kind === 't1') {
            $comments[] = [
                'id' => (string)($data['id'] ?? ''),
                'name' => (string)($data['name'] ?? ''),
                'author' => (string)($data['author'] ?? ''),
                'body' => (string)($data['body'] ?? ''),
                'depth' => $depth,
                'permalink' => (string)($data['permalink'] ?? ''),
            ];
            $replies = $data['replies'] ?? null;
            if (is_array($replies)) {
                $replyChildren = $replies['data']['children'] ?? [];
                if (is_array($replyChildren)) flattenComments($replyChildren, $depth + 1, $comments, $moreIds);
            }
        } elseif ($kind === 'more') {
            foreach (($data['children'] ?? []) as $id) {
                if (is_string($id) && $id !== '') $moreIds[] = $id;
            }
        }
    }
}

function fetchThread(array $config, string $token, string $postPath): array {
    $headers = ['Authorization: Bearer ' . $token, 'User-Agent: ' . $config['user_agent']];
    $json = redditRequest('https://oauth.reddit.com' . $postPath . '?limit=500&depth=10&raw_json=1&sort=old', $headers);
    if (!isset($json[0]['data']['children'][0]['data'])) throw new RuntimeException('Could not read the Reddit post.');

    $post = $json[0]['data']['children'][0]['data'];
    $comments = []; $moreIds = [];
    flattenComments($json[1]['data']['children'] ?? [], 0, $comments, $moreIds);

    $linkId = (string)($post['name'] ?? '');
    $seenMore = [];
    $moreIds = array_values(array_unique($moreIds));
    while ($moreIds) {
        $batch = array_splice($moreIds, 0, 100);
        $batch = array_values(array_filter($batch, fn($id) => !isset($seenMore[$id])));
        if (!$batch) continue;
        foreach ($batch as $id) $seenMore[$id] = true;

        $url = 'https://oauth.reddit.com/api/morechildren?api_type=json&link_id='
            . rawurlencode($linkId) . '&children=' . rawurlencode(implode(',', $batch))
            . '&sort=old&raw_json=1';
        $moreJson = redditRequest($url, $headers);
        $things = $moreJson['json']['data']['things'] ?? [];
        $newMore = [];
        if (is_array($things)) flattenComments($things, 0, $comments, $newMore);
        foreach ($newMore as $id) if (!isset($seenMore[$id])) $moreIds[] = $id;
    }

    $uniq = [];
    foreach ($comments as $c) {
        $key = $c['name'] ?: $c['id'];
        if ($key !== '') $uniq[$key] = $c;
    }

    return [
        'post' => [
            'id' => (string)($post['id'] ?? ''),
            'title' => (string)($post['title'] ?? ''),
            'author' => (string)($post['author'] ?? ''),
            'subreddit' => (string)($post['subreddit'] ?? ''),
        ],
        'comments' => array_values($uniq),
    ];
}

function parseGames(string $text): array {
    $games = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $g = normalizeSpace($line);
        if ($g !== '') $games[mb_strtolower($g, 'UTF-8')] = $g;
    }
    return array_values($games);
}

function simplify(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? '';
    return $s;
}

function tokens(string $s): array {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';
    return array_values(array_filter(preg_split('/\s+/u', trim($s)) ?: []));
}

function levenshteinUtf8(string $a, string $b): int {
    // Game titles are overwhelmingly ASCII/Latin. Transliterate when intl is available.
    if (function_exists('transliterator_transliterate')) {
        $a = transliterator_transliterate('Any-Latin; Latin-ASCII', $a) ?: $a;
        $b = transliterator_transliterate('Any-Latin; Latin-ASCII', $b) ?: $b;
    }
    return levenshtein($a, $b);
}

function titleSimilarity(string $candidate, string $game): float {
    $a = simplify($candidate);
    $b = simplify($game);
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 1.0;
    $maxLen = max(strlen($a), strlen($b));
    if ($maxLen === 0) return 1.0;
    return max(0.0, 1.0 - (levenshteinUtf8($a, $b) / $maxLen));
}

function bestGameMatch(string $body, array $games): array {
    $bodyLower = mb_strtolower($body, 'UTF-8');
    $exact = [];
    foreach ($games as $game) {
        if (mb_stripos($body, $game, 0, 'UTF-8') !== false) $exact[] = $game;
    }
    if (count($exact) === 1) return ['status'=>'confident','game'=>$exact[0],'score'=>1.0,'reason'=>'exact title'];
    if (count($exact) > 1) return ['status'=>'uncertain','game'=>null,'score'=>1.0,'reason'=>'mentions multiple game titles','candidates'=>$exact];

    // Generate short word windows from the comment and compare them to each game.
    $bodyTokens = tokens($body);
    $scores = [];
    foreach ($games as $game) {
        $gt = tokens($game);
        $n = count($gt);
        $best = 0.0;

        // Whole-comment containment after punctuation/spacing removal handles "Cyber Punk 2077".
        $simpleBody = simplify($body);
        $simpleGame = simplify($game);
        if ($simpleGame !== '' && str_contains($simpleBody, $simpleGame)) $best = 0.98;

        // Compare windows near the title's word count, allowing +/- one token.
        foreach ([$n-1, $n, $n+1] as $w) {
            if ($w < 1 || $w > count($bodyTokens)) continue;
            for ($i=0; $i <= count($bodyTokens)-$w; $i++) {
                $window = implode(' ', array_slice($bodyTokens, $i, $w));
                $best = max($best, titleSimilarity($window, $game));
            }
        }
        $scores[$game] = $best;
    }

    arsort($scores);
    $ordered = array_keys($scores);
    $bestGame = $ordered[0] ?? null;
    $bestScore = $bestGame !== null ? $scores[$bestGame] : 0.0;
    $secondScore = isset($ordered[1]) ? $scores[$ordered[1]] : 0.0;

    // Conservative thresholds:
    // >= .90 and clearly ahead = confident typo/spacing match.
    // >= .72 = manual review.
    if ($bestGame !== null && $bestScore >= 0.90 && ($bestScore - $secondScore) >= 0.08) {
        return ['status'=>'confident','game'=>$bestGame,'score'=>$bestScore,'reason'=>'strong fuzzy match'];
    }
    if ($bestGame !== null && $bestScore >= 0.72) {
        $candidates = [];
        foreach ($scores as $g=>$s) if ($s >= 0.72) $candidates[$g] = $s;
        return ['status'=>'uncertain','game'=>$bestGame,'score'=>$bestScore,'reason'=>'possible misspelling','candidates'=>$candidates];
    }
    return ['status'=>'none','game'=>null,'score'=>$bestScore,'reason'=>'no sufficiently close game title'];
}

function fetchUserInfo(array $config, string $token, string $username): array {
    $headers = ['Authorization: Bearer ' . $token, 'User-Agent: ' . $config['user_agent']];
    $url = 'https://oauth.reddit.com/user/' . rawurlencode($username) . '/about?raw_json=1';
    $json = redditRequest($url, $headers);
    $data = $json['data'] ?? null;
    if (!is_array($data)) throw new RuntimeException('Profile data unavailable.');
    return [
        'comment_karma' => (int)($data['comment_karma'] ?? 0),
        'created_utc' => (float)($data['created_utc'] ?? 0),
    ];
}

function eligibility(array $profile, float $now): array {
    $created = (float)$profile['created_utc'];
    $karma = (int)$profile['comment_karma'];
    $ageDays = $created > 0 ? floor(($now - $created) / 86400) : 0;
    $reasons = [];
    if ($ageDays < MIN_ACCOUNT_AGE_DAYS) $reasons[] = "account age {$ageDays} days (minimum " . MIN_ACCOUNT_AGE_DAYS . ')';
    if ($karma < MIN_COMMENT_KARMA) $reasons[] = "comment karma {$karma} (minimum " . MIN_COMMENT_KARMA . ')';
    return ['eligible'=>!$reasons,'age_days'=>(int)$ageDays,'comment_karma'=>$karma,'reasons'=>$reasons];
}

function analyzeThread(array $config, string $token, array $thread, array $games, bool $topLevelOnly): array {
    $byUser = [];
    $ignoredCounts = ['deleted'=>0,'op'=>0,'reply'=>0,'no_match'=>0];

    foreach ($thread['comments'] as $c) {
        $author = trim((string)$c['author']);
        if ($author === '' || $author === '[deleted]') { $ignoredCounts['deleted']++; continue; }
        if (strcasecmp($author, $thread['post']['author']) === 0) { $ignoredCounts['op']++; continue; }
        if ($topLevelOnly && (int)$c['depth'] > 0) { $ignoredCounts['reply']++; continue; }

        $m = bestGameMatch((string)$c['body'], $games);
        if ($m['status'] === 'none') { $ignoredCounts['no_match']++; continue; }

        $key = mb_strtolower($author, 'UTF-8');
        if (!isset($byUser[$key])) $byUser[$key] = ['author'=>$author,'matches'=>[],'comments'=>[]];
        $byUser[$key]['matches'][] = $m;
        $byUser[$key]['comments'][] = $c;
    }

    $now = time();
    $profileCache = [];
    $qualified = [];
    $ineligible = [];
    $review = [];

    foreach ($byUser as $key=>$u) {
        try {
            if (!isset($profileCache[$key])) {
                $profileCache[$key] = fetchUserInfo($config, $token, $u['author']);
                // Gentle pacing for per-user profile requests.
                usleep(80000);
            }
            $elig = eligibility($profileCache[$key], $now);
        } catch (Throwable $e) {
            $ineligible[] = [
                'author'=>$u['author'], 'age_days'=>null, 'comment_karma'=>null,
                'reasons'=>['Reddit profile could not be verified: ' . $e->getMessage()]
            ];
            continue;
        }

        if (!$elig['eligible']) {
            $ineligible[] = array_merge(['author'=>$u['author']], $elig);
            continue;
        }

        // Consolidate all matching comments by this user.
        $gamesFound = [];
        $hasUncertain = false;
        $bestSuggestion = null;
        $bestScore = -1.0;
        foreach ($u['matches'] as $m) {
            if ($m['status'] === 'confident' && $m['game']) $gamesFound[$m['game']] = true;
            if ($m['status'] === 'uncertain') {
                $hasUncertain = true;
                if (($m['score'] ?? 0) > $bestScore) {
                    $bestScore = (float)$m['score'];
                    $bestSuggestion = $m['game'] ?? null;
                }
            }
        }

        if (count($gamesFound) === 1 && !$hasUncertain) {
            $qualified[] = [
                'author'=>$u['author'], 'game'=>array_key_first($gamesFound),
                'age_days'=>$elig['age_days'],'comment_karma'=>$elig['comment_karma'],
                'comments'=>$u['comments']
            ];
        } else {
            $review[] = [
                'author'=>$u['author'],
                'suggested_game'=>count($gamesFound) === 1 ? array_key_first($gamesFound) : $bestSuggestion,
                'confident_games'=>array_keys($gamesFound),
                'age_days'=>$elig['age_days'],
                'comment_karma'=>$elig['comment_karma'],
                'comments'=>$u['comments'],
                'matches'=>$u['matches']
            ];
        }
    }

    return [
        'qualified'=>$qualified,
        'review'=>$review,
        'ineligible'=>$ineligible,
        'ignored'=>$ignoredCounts,
    ];
}

function buildPools(array $games, array $qualified, array $review, array $decisions): array {
    $pools = array_fill_keys($games, []);
    $seen = [];

    foreach ($qualified as $u) {
        $key = mb_strtolower($u['author'], 'UTF-8');
        if (!isset($seen[$key]) && isset($pools[$u['game']])) {
            $pools[$u['game']][] = $u;
            $seen[$key] = true;
        }
    }

    foreach ($review as $idx=>$u) {
        $choice = (string)($decisions[$idx] ?? 'exclude');
        if ($choice === 'exclude' || !isset($pools[$choice])) continue;
        $key = mb_strtolower($u['author'], 'UTF-8');
        if (!isset($seen[$key])) {
            $u['game'] = $choice;
            $pools[$choice][] = $u;
            $seen[$key] = true;
        }
    }
    return $pools;
}

function chooseWinners(array $pools): array {
    $winners = [];
    foreach ($pools as $game=>$pool) {
        $winners[$game] = $pool ? $pool[random_int(0, count($pool)-1)] : null;
    }
    return $winners;
}

$error = null;
$stage = 'form';
$data = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? 'analyze');

        if ($action === 'analyze') {
            $url = trim((string)($_POST['reddit_url'] ?? ''));
            $gamesText = (string)($_POST['games'] ?? '');
            $games = parseGames($gamesText);
            $topLevelOnly = isset($_POST['top_level_only']);

            if (count($games) < 2) throw new RuntimeException('Enter at least two game titles, one per line.');
            if (count($games) > 25) throw new RuntimeException('This tool supports up to 25 game titles per drawing.');

            $token = getAccessToken($config);
            $thread = fetchThread($config, $token, extractPostPath($url));
            $analysis = analyzeThread($config, $token, $thread, $games, $topLevelOnly);

            $_SESSION['draw_data'] = [
                'url'=>$url, 'gamesText'=>$gamesText, 'games'=>$games,
                'topLevelOnly'=>$topLevelOnly, 'thread'=>$thread, 'analysis'=>$analysis
            ];
            $data = $_SESSION['draw_data'];
            $stage = 'review';
        } elseif ($action === 'draw') {
            if (empty($_SESSION['draw_data']) || !is_array($_SESSION['draw_data'])) {
                throw new RuntimeException('The review session expired. Fetch the Reddit comments again.');
            }
            $data = $_SESSION['draw_data'];
            $decisions = is_array($_POST['review'] ?? null) ? $_POST['review'] : [];
            $pools = buildPools($data['games'], $data['analysis']['qualified'], $data['analysis']['review'], $decisions);
            $winners = chooseWinners($pools);
            $data['pools'] = $pools;
            $data['winners'] = $winners;
            $data['decisions'] = $decisions;
            $data['drawn_at'] = date(DATE_RFC3339);
            $_SESSION['last_result'] = $data;
            unset($_SESSION['draw_data']);
            $stage = 'result';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $stage = 'form';
    }
}

$defaultUrl = (string)($_POST['reddit_url'] ?? '');
$defaultGames = (string)($_POST['games'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h(APP_NAME) ?></title>
<style>
:root{color-scheme:light dark;--bg:#f4f5f7;--panel:#fff;--text:#202124;--muted:#667085;--border:#d0d5dd;--accent:#ff4500;--soft:#fff4ef;--good:#16794b;--bad:#b42318;--warn:#9a6700}
@media(prefers-color-scheme:dark){:root{--bg:#111418;--panel:#1b1f24;--text:#eef2f6;--muted:#a7b0bb;--border:#3a414a;--soft:#2a1b16;--good:#65d6a0;--bad:#ff8f87;--warn:#f0c36a}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
main{max-width:1100px;margin:0 auto;padding:32px 18px 60px}h1{margin:0 0 6px;font-size:30px}h2{margin-top:0}h3{margin-bottom:6px}.lead,.muted{color:var(--muted)}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin:18px 0;box-shadow:0 1px 2px rgba(0,0,0,.05)}
label{display:block;font-weight:650;margin:12px 0 6px}input[type=url],textarea,select{width:100%;padding:11px 12px;border:1px solid var(--border);border-radius:9px;background:transparent;color:inherit;font:inherit}
textarea{min-height:135px}.check{display:flex;gap:9px;align-items:flex-start;font-weight:500}
button,.button{appearance:none;border:0;border-radius:9px;background:var(--accent);color:#fff;padding:11px 17px;font-weight:700;font-size:15px;cursor:pointer;margin-top:14px;text-decoration:none;display:inline-block}
.error{border-left:5px solid var(--bad)}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:14px}.card{border:1px solid var(--border);border-radius:11px;padding:15px}.winner{background:var(--soft);border-color:var(--accent)}.winner strong{font-size:20px}
table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px 8px;border-bottom:1px solid var(--border);vertical-align:top}th{font-size:13px;color:var(--muted)}
.badge{display:inline-block;border:1px solid var(--border);border-radius:999px;padding:2px 8px;margin:2px;font-size:12px}.good{color:var(--good)}.bad{color:var(--bad)}.warn{color:var(--warn)}
.comment{white-space:pre-wrap;background:rgba(127,127,127,.08);padding:10px;border-radius:8px;margin:7px 0}.small{font-size:13px}code{background:rgba(127,127,127,.14);padding:2px 5px;border-radius:4px}
</style>
</head>
<body><main>
<h1><?= h(APP_NAME) ?></h1>
<p class="lead">One Reddit post, multiple games, automatic eligibility checks, typo-tolerant matching, manual review, then one random winner per game.</p>

<?php if ($error): ?><div class="panel error"><strong>Could not continue:</strong> <?= h($error) ?></div><?php endif; ?>

<?php if ($stage === 'form'): ?>
<form method="post" class="panel">
<input type="hidden" name="action" value="analyze">
<label for="reddit_url">Reddit thread URL</label>
<input id="reddit_url" name="reddit_url" type="url" required placeholder="https://www.reddit.com/r/.../comments/..." value="<?= h($defaultUrl) ?>">
<label for="games">Games — one title per line</label>
<textarea id="games" name="games" required placeholder="Game Title One&#10;Game Title Two"><?= h($defaultGames) ?></textarea>
<label class="check"><input type="checkbox" name="top_level_only" value="1" checked><span>Count top-level comments only <span class="muted">(recommended)</span></span></label>
<div class="card" style="margin-top:15px">
<strong>Eligibility requirements</strong><br>
Account age: at least <?= MIN_ACCOUNT_AGE_DAYS ?> days<br>
Comment karma: at least <?= MIN_COMMENT_KARMA ?>
</div>
<button type="submit">Fetch &amp; check entries</button>
</form>

<?php elseif ($stage === 'review'):
$analysis = $data['analysis']; $post = $data['thread']['post'];
?>
<div class="panel">
<h2>Review before drawing</h2>
<p><strong><?= h($post['title']) ?></strong><br><span class="muted">r/<?= h($post['subreddit']) ?> · u/<?= h($post['author']) ?></span></p>
<div class="grid">
<div class="card"><strong><?= count($analysis['qualified']) ?></strong><br>automatically qualified</div>
<div class="card"><strong><?= count($analysis['review']) ?></strong><br>need manual review</div>
<div class="card"><strong><?= count($analysis['ineligible']) ?></strong><br>failed Reddit eligibility</div>
</div>
</div>

<form method="post">
<input type="hidden" name="action" value="draw">

<?php if ($analysis['review']): ?>
<div class="panel">
<h2>Questionable game matches</h2>
<p class="muted">These accounts passed the 10-day/150-comment-karma requirements, but their game choice was misspelled, unclear, or matched more than one title. Choose the intended game or exclude the entry.</p>
<?php foreach ($analysis['review'] as $i=>$u): ?>
<div class="card" style="margin:12px 0">
<h3>u/<?= h($u['author']) ?></h3>
<div class="small muted">Account age: <?= (int)$u['age_days'] ?> days · Comment karma: <?= (int)$u['comment_karma'] ?></div>
<?php foreach ($u['comments'] as $c): ?><div class="comment"><?= h($c['body']) ?></div><?php endforeach; ?>
<label for="review_<?= $i ?>">Decision</label>
<select id="review_<?= $i ?>" name="review[<?= $i ?>]">
<option value="exclude">Exclude / cannot determine</option>
<?php foreach ($data['games'] as $game): ?>
<option value="<?= h($game) ?>" <?= $u['suggested_game'] === $game ? 'selected' : '' ?>><?= h($game) ?><?= $u['suggested_game'] === $game ? ' — suggested' : '' ?></option>
<?php endforeach; ?>
</select>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="panel">
<h2>Automatically qualified entries</h2>
<?php if (!$analysis['qualified']): ?><p class="muted">None.</p><?php endif; ?>
<table><thead><tr><th>User</th><th>Game</th><th>Account age</th><th>Comment karma</th></tr></thead><tbody>
<?php foreach ($analysis['qualified'] as $u): ?>
<tr><td>u/<?= h($u['author']) ?></td><td><?= h($u['game']) ?></td><td><?= (int)$u['age_days'] ?> days</td><td><?= (int)$u['comment_karma'] ?></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>

<?php if ($analysis['ineligible']): ?>
<div class="panel">
<h2>Ineligible accounts</h2>
<table><thead><tr><th>User</th><th>Age</th><th>Comment karma</th><th>Reason</th></tr></thead><tbody>
<?php foreach ($analysis['ineligible'] as $u): ?>
<tr>
<td>u/<?= h($u['author']) ?></td>
<td><?= $u['age_days'] === null ? 'Unknown' : (int)$u['age_days'].' days' ?></td>
<td><?= $u['comment_karma'] === null ? 'Unknown' : (int)$u['comment_karma'] ?></td>
<td class="bad"><?= h(implode('; ', $u['reasons'])) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php endif; ?>

<div class="panel">
<p><strong>Nothing has been randomized yet.</strong> The draw happens only when you click below, after you finish reviewing questionable entries.</p>
<button type="submit">Draw winners</button>
</div>
</form>

<?php elseif ($stage === 'result'): ?>
<div class="panel">
<h2>Official drawing result</h2>
<div class="grid">
<?php foreach ($data['games'] as $game): $pool=$data['pools'][$game] ?? []; $winner=$data['winners'][$game] ?? null; ?>
<div class="card winner">
<div class="muted"><?= h($game) ?></div>
<?php if ($winner): ?><strong>u/<?= h($winner['author']) ?></strong><div><?= count($pool) ?> eligible entrant<?= count($pool)===1?'':'s' ?></div>
<?php else: ?><strong>No winner</strong><div>0 eligible entrants</div><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<p class="small muted">Drawn at <?= h($data['drawn_at']) ?> using PHP <code>random_int()</code>. Account rules: ≥ <?= MIN_ACCOUNT_AGE_DAYS ?> days old and ≥ <?= MIN_COMMENT_KARMA ?> comment karma.</p>
</div>

<div class="panel">
<h2>Final eligible pools</h2>
<?php foreach ($data['games'] as $game): ?>
<h3><?= h($game) ?> (<?= count($data['pools'][$game] ?? []) ?>)</h3>
<?php foreach ($data['pools'][$game] ?? [] as $u): ?><span class="badge">u/<?= h($u['author']) ?></span><?php endforeach; ?>
<?php if (!($data['pools'][$game] ?? [])): ?><span class="muted">None</span><?php endif; ?>
<?php endforeach; ?>
</div>

<a class="button" href="<?= h($_SERVER['PHP_SELF']) ?>">Start another giveaway</a>
<?php endif; ?>
</main></body></html>
