<?php
declare(strict_types=1);
session_start();

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo '<h1>Setup required</h1><p>Copy <code>config.php.example</code> to <code>config.php</code> and add Reddit API credentials.</p>';
    exit;
}
$config = require $configFile;

const APP_NAME = 'Reddit Giveaway Picker — Ranked Random Order';

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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






function normalizeSpace(string $s): string {
    return trim((string)preg_replace('/\s+/u', ' ', $s));
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
    return preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? '';
}

function tokens(string $s): array {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';
    return array_values(array_filter(preg_split('/\s+/u', trim($s)) ?: []));
}

function titleSimilarity(string $candidate, string $game): float {
    $a = simplify($candidate);
    $b = simplify($game);
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 1.0;
    $max = max(strlen($a), strlen($b));
    return $max ? max(0.0, 1.0 - (levenshtein($a, $b) / $max)) : 1.0;
}

function fragmentLikelyGame(string $fragment, array $games): bool {
    $fragment = stripPreferenceNoise(trim($fragment));
    if ($fragment === '' || mb_strlen($fragment, 'UTF-8') < 2) return false;

    foreach ($games as $game) {
        $score = titleSimilarity($fragment, $game);
        $score = max($score, acronymMatchScore($fragment, $game));
        $fs = simplify($fragment);
        $gs = simplify($game);
        if ($fs !== '' && strlen($fs) >= 3 && (str_contains($gs, $fs) || str_contains($fs, $gs))) {
            $score = max($score, 0.76);
        }
        if ($score >= 0.48) return true;
    }
    return false;
}

function preferenceSegments(string $body, array $games = []): array {
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $body = str_replace(["•", "·", "▪", "◦", "‣", "–", "—", "−"], ["\n", "\n", "\n", "\n", "\n", " - ", " - ", " - "], $body);

    // Split numbered choices even when several are on one physical line.
    // Inline list numbers must use punctuation (1., 2), 3:, etc.).
    // Bare numbers are NOT inline separators, so titles like Crysis 3
    // and Wizard of Legend 2 stay intact.
    $body = preg_replace('/(?<!^)(?<!\n)\s+(?=(?:#?\d{1,3}\s*[\.\)\:\-]\s+))/u', "\n", $body) ?? $body;

    $rawLines = preg_split('/\n+/u', $body) ?: [];
    $segments = [];

    foreach ($rawLines as $raw) {
        $line = trim($raw);
        if ($line === '') continue;

        // Accept "1 Game", "1. Game", "1) Game", etc.
        $line = preg_replace('/^\s*(?:#?\d{1,3}\s*(?:[\.\)\:\-]\s*|\s+)|[-*]\s*)/u', '', $line) ?? $line;
        $line = trim($line);
        if ($line === '') continue;

        // If punctuation alone differs from a master title (e.g. V - Rising),
        // keep it together rather than treating the dash as a separator.
        $wholeMatches = [];
        foreach ($games as $game) {
            if (simplify($line) === simplify($game)) $wholeMatches[] = $game;
        }
        if (count($wholeMatches) === 1) {
            $segments[] = $line;
            continue;
        }

        // Split explicit same-line list separators.
        $parts = preg_split('/\s*(?:;|\||,\s*|\s\/\s|\s+-\s*|\s*-\s+|\s+\&\s+|\s+\bor\b\s+|\s+\band\b\s+)\s*/iu', $line) ?: [$line];
        $parts = array_values(array_filter(array_map('trim', $parts), fn($v) => $v !== ''));
        if (count($parts) > 1) {
            $plausible = 0;
            foreach ($parts as $part) if (fragmentLikelyGame($part, $games)) $plausible++;
            if ($plausible >= 2) {
                foreach ($parts as $part) $segments[] = $part;
                continue;
            }
        }

        // Locate exact master titles anywhere on the line.
        $hits = [];
        foreach ($games as $game) {
            $pos = mb_stripos($line, $game, 0, 'UTF-8');
            if ($pos !== false) $hits[] = ['pos'=>$pos, 'len'=>mb_strlen($game,'UTF-8'), 'game'=>$game];
        }
        usort($hits, fn($a,$b) => $a['pos'] <=> $b['pos']);

        if (count($hits) > 1) {
            $seen = [];
            foreach ($hits as $hit) {
                $k = mb_strtolower($hit['game'], 'UTF-8');
                if (!isset($seen[$k])) { $seen[$k]=true; $segments[]=$hit['game']; }
            }
            continue;
        }

        // If exactly one title is literal but text before/after it resembles a
        // second title, split the line around the exact title. This catches
        // "AC Valhalla Diablo IV" and similar back-to-back lists.
        if (count($hits) === 1) {
            $hit = $hits[0];
            $before = trim(mb_substr($line, 0, $hit['pos'], 'UTF-8'), " \t,;|&-");
            $afterStart = $hit['pos'] + $hit['len'];
            $after = trim(mb_substr($line, $afterStart, null, 'UTF-8'), " \t,;|&-");
            $beforeGame = fragmentLikelyGame($before, $games);
            $afterGame = fragmentLikelyGame($after, $games);
            if ($beforeGame || $afterGame) {
                if ($beforeGame) $segments[] = $before;
                $segments[] = $hit['game'];
                if ($afterGame) $segments[] = $after;
                continue;
            }
        }

        $segments[] = $line;
    }

    return $segments;
}

function stripPreferenceNoise(string $segment): string {
    $s = trim($segment);
    $s = preg_replace('/^\s*(?:i(?:\'|’)d\s+like|i\s+would\s+like|i\s+want|entering\s+for|my\s+(?:pick|choice|choices)\s*(?:is|are)?|please\s+enter\s+me\s+for)\s*[:\-]?\s*/iu', '', $s) ?? $s;
    $s = preg_replace('/(?:[\s\.,!;:\-]+)(?:thanks?|thank\s+you|thx|cheers|good\s+luck|appreciate\s+it|please|pls)\b.*$/iu', '', $s) ?? $s;
    return trim($s, " \t\n\r\0\x0B,.;:!-");
}

function normalizedTitleWords(string $s): array {
    $s = mb_strtolower($s, 'UTF-8');
    $s = str_replace(["’", "`"], "'", $s);
    $s = preg_replace("/'s\\b/u", '', $s) ?? $s;
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';
    $parts = array_values(array_filter(preg_split('/\s+/u', trim($s)) ?: []));
    $roman = ['i'=>'1','ii'=>'2','iii'=>'3','iv'=>'4','v'=>'5','vi'=>'6','vii'=>'7','viii'=>'8','ix'=>'9','x'=>'10'];
    foreach ($parts as &$p) if (isset($roman[$p])) $p = $roman[$p];
    unset($p);
    return $parts;
}

function significantTitleWords(string $s): array {
    $stop = ['the'=>true,'a'=>true,'an'=>true,'of'=>true,'for'=>true,'to'=>true,'and'=>true,'or'=>true,'is'=>true,'are'=>true,'in'=>true,'on'=>true,'my'=>true,'i'=>true,'me'=>true,'please'=>true,'pls'=>true,'thanks'=>true,'thank'=>true,'you'=>true,'thx'=>true,'good'=>true,'luck'=>true,'giveaway'=>true,'chance'=>true,'op'=>true,'everyone'=>true];
    return array_values(array_filter(normalizedTitleWords($s), fn($w) => !isset($stop[$w]) && mb_strlen($w, 'UTF-8') >= 2));
}

function acronymMatchScore(string $segment, string $game): float {
    $sw = significantTitleWords($segment); $gw = significantTitleWords($game);
    if (!$sw || count($gw) < 2) return 0.0;
    for ($n=2; $n<=min(4,count($gw)); $n++) {
        $acronym=''; for($i=0;$i<$n;$i++) $acronym .= mb_substr($gw[$i],0,1,'UTF-8');
        if (($sw[0]??'') !== $acronym) continue;
        $remainingGame=array_slice($gw,$n); $remainingSeg=array_slice($sw,1);
        if (!$remainingGame && !$remainingSeg) return 0.98;
        $hits=0; foreach($remainingGame as $word) if(in_array($word,$remainingSeg,true)) $hits++;
        if ($remainingGame) { $coverage=$hits/count($remainingGame); if($coverage>=1.0) return 0.97; if($coverage>=0.5) return 0.91; }
    }
    return 0.0;
}

function hasSuspiciousSubtitle(string $segment, string $game): bool {
    $segWords=normalizedTitleWords(stripPreferenceNoise($segment)); $gameWords=normalizedTitleWords($game);
    if(!$segWords||!$gameWords||count($segWords)<=count($gameWords)) return false;
    for($i=0;$i<count($gameWords);$i++) if(($segWords[$i]??null)!==$gameWords[$i]) return false;
    $extra=array_slice($segWords,count($gameWords)); $allowed=['remastered','remaster','deluxe','edition','complete','goty'];
    foreach($extra as $word) if(!in_array($word,$allowed,true)) return true;
    return false;
}

function bestGameMatchForSegment(string $segment, array $games): array {
    $segment = stripPreferenceNoise(trim($segment));
    if ($segment === '') return ['status'=>'review','game'=>null,'score'=>0.0,'reason'=>'could not identify a game'];
    $segNorm=implode(' ',normalizedTitleWords($segment)); $scores=[]; $subtitleConflict=[];
    foreach($games as $game){
        $gameNorm=implode(' ',normalizedTitleWords($game));
        if($segNorm!=='' && $segNorm===$gameNorm) return ['status'=>'confident','game'=>$game,'score'=>1.0,'reason'=>'exact title'];
        if(hasSuspiciousSubtitle($segment,$game)) $subtitleConflict[$game]=true;
        $best=acronymMatchScore($segment,$game);
        $segmentSimple=simplify($segment); $gameSimple=simplify($game);
        if($segmentSimple!==''&&$gameSimple!==''){
            if(str_contains($segmentSimple,$gameSimple)){ $coverage=strlen($gameSimple)/max(1,strlen($segmentSimple)); $best=max($best,min(0.91,0.72+0.19*$coverage)); }
            elseif(strlen($segmentSimple)>=4&&str_contains($gameSimple,$segmentSimple)){ $coverage=strlen($segmentSimple)/max(1,strlen($gameSimple)); $best=max($best,min(0.94,0.75+0.19*$coverage)); }
        }
        $segmentTokens=normalizedTitleWords($segment); $gameTokens=normalizedTitleWords($game); $n=count($gameTokens);
        foreach([$n-2,$n-1,$n,$n+1,$n+2] as $w){ if($w<1||$w>count($segmentTokens)) continue; for($i=0;$i<=count($segmentTokens)-$w;$i++){ $window=implode(' ',array_slice($segmentTokens,$i,$w)); $best=max($best,titleSimilarity($window,implode(' ',$gameTokens))); } }
        $best=max($best,titleSimilarity($segNorm,$gameNorm));
        $a=array_unique(significantTitleWords($segment)); $b=array_unique(significantTitleWords($game));
        if($a&&$b){ $overlap=count(array_intersect($a,$b)); if($overlap>0){ $tokenScore=$overlap/max(1,min(count($a),count($b))); $best=max($best,0.52+0.38*$tokenScore); } }
        $scores[$game]=min(1.0,$best);
    }
    arsort($scores); $ordered=array_keys($scores); $bestGame=$ordered[0]??null; $bestScore=$bestGame!==null?(float)$scores[$bestGame]:0.0; $secondScore=isset($ordered[1])?(float)$scores[$ordered[1]]:0.0;
    if($bestGame!==null && isset($subtitleConflict[$bestGame])) return ['status'=>'review','game'=>null,'score'=>$bestScore,'reason'=>'title has additional words; verify that it is the listed game'];
    if($bestGame!==null && $bestScore>=0.92 && ($bestScore-$secondScore)>=0.05) return ['status'=>'confident','game'=>$bestGame,'score'=>$bestScore,'reason'=>'strong fuzzy/partial match'];
    if($bestGame!==null && $bestScore>=0.75) return ['status'=>'review','game'=>$bestGame,'score'=>$bestScore,'reason'=>'possible abbreviation or misspelling'];
    return ['status'=>'review','game'=>null,'score'=>$bestScore,'reason'=>'could not identify a game'];
}

function parseRankedPreferences(string $body, array $games): array {
    $segments = preferenceSegments($body, $games);
    $picks = [];
    $seenGames = [];

    foreach ($segments as $segment) {
        $match = bestGameMatchForSegment($segment, $games);

        if ($match['status'] === 'review' &&
            $match['reason'] === 'segment contains multiple listed titles') {

            $hits = [];
            foreach ($games as $game) {
                $pos = mb_stripos($segment, $game, 0, 'UTF-8');
                if ($pos !== false) $hits[] = ['pos'=>$pos,'game'=>$game];
            }
            usort($hits, fn($a,$b) => $a['pos'] <=> $b['pos']);

            foreach ($hits as $hit) {
                $key = mb_strtolower($hit['game'], 'UTF-8');
                if (isset($seenGames[$key])) continue;
                $seenGames[$key] = true;
                $picks[] = [
                    'position'=>count($picks)+1,
                    'segment'=>$hit['game'],
                    'status'=>'confident',
                    'game'=>$hit['game'],
                    'score'=>1.0,
                    'reason'=>'exact title found in multi-game line',
                ];
            }
            continue;
        }

        if ($match['game']) {
            $key = mb_strtolower((string)$match['game'], 'UTF-8');
            if (isset($seenGames[$key])) continue;
            $seenGames[$key] = true;
        }

        $picks[] = [
            'position'=>count($picks)+1,
            'segment'=>$segment,
            'status'=>$match['status'],
            'game'=>$match['game'],
            'score'=>$match['score'],
            'reason'=>$match['reason'],
        ];
    }

    return $picks;
}

function dedupePreferenceGames(array $games): array {
    $seen = [];
    $out = [];
    foreach ($games as $game) {
        if (!$game) continue;
        $k = mb_strtolower((string)$game, 'UTF-8');
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $game;
    }
    return $out;
}

function secureShuffle(array $items): array {
    $items = array_values($items);
    for ($i = count($items) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
    }
    return $items;
}

function allocateByRandomOrder(array $entrants, array $games): array {
    $randomOrder = secureShuffle($entrants);
    $available = array_fill_keys($games, true);
    $assignments = [];
    $skipped = [];

    foreach ($randomOrder as $drawPosition => $entrant) {
        $assigned = null;
        $assignedRank = null;

        foreach ($entrant['preferences'] as $rank => $game) {
            if (isset($available[$game]) && $available[$game]) {
                $assigned = $game;
                $assignedRank = $rank + 1;
                $available[$game] = false;
                break;
            }
        }

        if ($assigned !== null) {
            $assignments[] = [
                'draw_position' => $drawPosition + 1,
                'author' => $entrant['author'],
                'game' => $assigned,
                'preference_rank' => $assignedRank,
                'preferences' => $entrant['preferences'],
            ];
        } else {
            $skipped[] = [
                'draw_position' => $drawPosition + 1,
                'author' => $entrant['author'],
                'preferences' => $entrant['preferences'],
                'reason' => empty($entrant['preferences'])
                    ? 'No valid game preferences'
                    : 'All requested games had already been assigned',
            ];
        }

        if (!in_array(true, $available, true)) break;
    }

    $unassignedGames = [];
    foreach ($available as $game => $isAvailable) {
        if ($isAvailable) $unassignedGames[] = $game;
    }

    return [
        'random_order' => $randomOrder,
        'assignments' => $assignments,
        'skipped' => $skipped,
        'unassigned_games' => $unassignedGames,
    ];
}


function analyzeRedditComments(array $thread, array $games): array {
    $byUser = [];

    foreach ($thread['comments'] as $c) {
        $author = trim((string)$c['author']);
        if ($author === '' || $author === '[deleted]') continue;
        if (strcasecmp($author, $thread['post']['author']) === 0) continue;
        if ((int)$c['depth'] > 0) continue; // top-level entries only

        $key = mb_strtolower($author, 'UTF-8');

        // One giveaway entry per Reddit account. If the user somehow has
        // multiple top-level comments, keep the first one encountered.
        if (!isset($byUser[$key])) {
            $byUser[$key] = [
                'author' => $author,
                'body' => (string)$c['body'],
                'picks' => parseRankedPreferences((string)$c['body'], $games),
            ];
        }
    }

    return ['users' => array_values($byUser)];
}

$error = null;
$stage = 'form';
$data = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? 'analyze');

        if ($action === 'analyze') {
            $url = trim((string)($_POST['reddit_url'] ?? ''));
            $games = parseGames((string)($_POST['games'] ?? ''));
            if (!$games) throw new RuntimeException('Enter at least one game title.');

            $token = getAccessToken($config);
            $thread = fetchThread($config, $token, extractPostPath($url));
            $analysis = analyzeRedditComments($thread, $games);

            $_SESSION['ranked_api'] = [
                'url'=>$url,'games'=>$games,'thread'=>$thread,'analysis'=>$analysis
            ];
            $data = $_SESSION['ranked_api'];
            $stage = 'review';
        } elseif ($action === 'draw') {
            if (empty($_SESSION['ranked_api'])) throw new RuntimeException('Review session expired.');
            $data = $_SESSION['ranked_api'];
            $decisions = is_array($_POST['pick'] ?? null) ? $_POST['pick'] : [];

            $entrants = [];
            foreach ($data['analysis']['users'] as $ui=>$u) {
                $prefs = [];
                foreach ($u['picks'] as $pi=>$pick) {
                    $choice = (string)($decisions[$ui][$pi] ?? '');
                    if ($choice !== '' && in_array($choice, $data['games'], true)) $prefs[] = $choice;
                }
                $prefs = dedupePreferenceGames($prefs);
                if ($prefs) $entrants[] = ['author'=>$u['author'],'preferences'=>$prefs];
            }

            $data['entrants'] = $entrants;
            $data['result'] = allocateByRandomOrder($entrants, $data['games']);
            $data['drawn_at'] = date(DATE_RFC3339);
            unset($_SESSION['ranked_api']);
            $stage = 'result';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $stage = 'form';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h(APP_NAME) ?></title>
<style>
:root{color-scheme:light dark;--bg:#f4f5f7;--panel:#fff;--text:#202124;--muted:#667085;--border:#d0d5dd;--accent:#ff4500;--soft:#fff4ef;--bad:#b42318;--good:#16794b}
@media(prefers-color-scheme:dark){:root{--bg:#111418;--panel:#1b1f24;--text:#eef2f6;--muted:#a7b0bb;--border:#3a414a;--soft:#2a1b16;--bad:#ff8f87;--good:#65d6a0}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
main{max-width:1150px;margin:0 auto;padding:32px 18px 60px}h1{margin:0 0 6px;font-size:30px}h2{margin-top:0}.muted,.lead{color:var(--muted)}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:20px;margin:18px 0}.card{border:1px solid var(--border);border-radius:11px;padding:15px;margin:10px 0}
label{display:block;font-weight:650;margin:12px 0 6px}input[type=url],textarea,select{width:100%;padding:10px;border:1px solid var(--border);border-radius:9px;background:transparent;color:inherit;font:inherit}
textarea{min-height:150px}button,.button{border:0;border-radius:9px;background:var(--accent);color:#fff;padding:11px 17px;font-weight:700;font-size:15px;cursor:pointer;margin-top:14px;text-decoration:none;display:inline-block}
.error{border-left:5px solid var(--bad)}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:9px 8px;border-bottom:1px solid var(--border);vertical-align:top}th{font-size:13px;color:var(--muted)}
.comment{white-space:pre-wrap;background:rgba(127,127,127,.08);padding:10px;border-radius:8px;margin:7px 0}.small{font-size:13px}.pickrow{display:grid;grid-template-columns:55px minmax(180px,1fr) minmax(260px,1.2fr);gap:10px;align-items:center;margin:8px 0}.rank{font-weight:700}
@media(max-width:760px){.pickrow{grid-template-columns:1fr}}
</style>
</head>
<body><main>
<h1><?= h(APP_NAME) ?></h1>
<p class="lead">Fetch top-level Reddit entries, parse ranked preferences, review matches, randomize users once, then assign each entrant their highest-ranked available game.</p>

<?php if ($error): ?><div class="panel error"><strong>Could not continue:</strong> <?= h($error) ?></div><?php endif; ?>

<?php if ($stage === 'form'): ?>
<form method="post" class="panel">
<input type="hidden" name="action" value="analyze">
<label>Reddit thread URL</label>
<input type="url" name="reddit_url" required>
<label>Master game list — one game per line</label>
<textarea name="games" required></textarea>
<p class="small muted">This version fetches the giveaway thread/comments only. It relies on the subreddit to enforce account-age, karma, and other entry requirements.</p>
<button type="submit">Fetch thread &amp; parse ranked picks</button>
</form>

<?php elseif ($stage === 'review'): ?>
<form method="post">
<input type="hidden" name="action" value="draw">
<div class="panel">
<h2>Review ranked preferences</h2>
<p><?= count($data['analysis']['users']) ?> unique top-level entrants found.</p><p class="small muted">This version relies on the subreddit's own moderation/eligibility filtering and does not make per-user profile API requests.</p>
</div>

<?php foreach ($data['analysis']['users'] as $ui=>$u): ?>
<div class="panel">
<h2>u/<?= h($u['author']) ?></h2>
<div class="comment"><?= h($u['body']) ?></div>
<?php foreach ($u['picks'] as $pi=>$pick): ?>
<div class="pickrow">
<div class="rank">#<?= (int)$pick['position'] ?></div>
<div><strong><?= h($pick['segment']) ?></strong><br><span class="small muted"><?= h($pick['reason']) ?></span></div>
<div>
<select name="pick[<?= $ui ?>][<?= $pi ?>]">
<option value="">Ignore this line</option>
<?php foreach ($data['games'] as $game): ?>
<option value="<?= h($game) ?>" <?= $pick['game']===$game?'selected':'' ?>><?= h($game) ?><?= $pick['game']===$game?' — suggested':'' ?></option>
<?php endforeach; ?>
</select>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

<div class="panel">
<p><strong>No randomization has happened yet.</strong></p>
<button type="submit">Randomize users &amp; assign games</button>
</div>
</form>

<?php elseif ($stage === 'result'): $r=$data['result']; ?>
<div class="panel">
<h2>Assignments</h2>
<table><thead><tr><th>Random position</th><th>User</th><th>Game</th><th>Preference</th></tr></thead><tbody>
<?php foreach ($r['assignments'] as $a): ?>
<tr><td>#<?= (int)$a['draw_position'] ?></td><td>u/<?= h($a['author']) ?></td><td><?= h($a['game']) ?></td><td>#<?= (int)$a['preference_rank'] ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<p class="small muted">Drawn <?= h($data['drawn_at']) ?> using a single secure random entrant order.</p>
</div>

<div class="panel">
<h2>Skipped users</h2>
<?php if (!$r['skipped']): ?><p class="muted">None.</p><?php else: ?>
<table><thead><tr><th>Position</th><th>User</th><th>Reason</th><th>Preferences</th></tr></thead><tbody>
<?php foreach ($r['skipped'] as $s): ?><tr><td>#<?= (int)$s['draw_position'] ?></td><td>u/<?= h($s['author']) ?></td><td><?= h($s['reason']) ?></td><td><?= h(implode(' → ', $s['preferences'])) ?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?>
</div>

<div class="panel">
<h2>Unassigned games</h2>
<p><?= $r['unassigned_games'] ? h(implode(', ', $r['unassigned_games'])) : 'All games were assigned.' ?></p>
</div>

<div class="panel">
<h2>Full randomized order</h2>
<ol><?php foreach ($r['random_order'] as $u): ?><li>u/<?= h($u['author']) ?> — <?= h(implode(' → ', $u['preferences'])) ?></li><?php endforeach; ?></ol>
</div>
<a class="button" href="<?= h($_SERVER['PHP_SELF']) ?>">Start another giveaway</a>
<?php endif; ?>
</main></body></html>
