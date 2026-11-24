<?php
// 紅月闘技会 成功率ログ集計API（匿名・回数ベース、個人識別情報なし）
// 配置: /battle/stats-api として設置。データは同階層 battle-stats/stats.json に自動生成。
// POST {op:"report", defense:{key:{counter,just,guard,none,fail}}, matches:{key:{w,turns}}}
//   defense key: "mode|defChar|atkChar|dBucket|rallyBand"  (dBucket: d0<400 d1<550 d2<700 d3<900 d4>=900 / rallyBand: r0<=6 r1<=14 r2>=15)
//   matches key: "mode|winnerChar|loserChar"
// GET: 集計HTMLページ / GET ?format=json: 生JSON
declare(strict_types=1);
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== 'https://iruca.tech') { http_response_code(403); exit; }
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') { http_response_code(403); exit; }
    if (strtolower(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]) !== 'application/json') { http_response_code(415); exit; }
}


const DATA_DIR = '/var/lib/iruca-battle-stats/data';
const DATA_FILE = DATA_DIR . '/stats.json';

function load_stats(): array {
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
    if (!is_file(DATA_FILE)) return ['defense' => new stdClass, 'matches' => new stdClass, 'updated' => null];
    $raw = @file_get_contents(DATA_FILE);
    if ($raw === false || $raw === '') return ['defense' => new stdClass, 'matches' => new stdClass, 'updated' => null];
    $d = json_decode($raw, true);
    if (!is_array($d)) return ['defense' => new stdClass, 'matches' => new stdClass, 'updated' => null];
    return $d;
}

function valid_key(string $k): bool {
    $c = '(?:vera|hex|morgana|kanna|lucia|sera)';
    return (bool)preg_match('/^(?:(?:cpu|friend)\|' . $c . '\|' . $c . '\|d[0-4]\|r[0-2]|friend\|' . $c . '\|' . $c . ')$/D', $k);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = file_get_contents('php://input');
    if ($body === false || strlen($body) > 65536) { http_response_code(413); echo '{"error":"too large"}'; exit; }
    $in = json_decode($body, true);
    if (!is_array($in) || ($in['op'] ?? '') !== 'report') { http_response_code(400); echo '{"error":"bad request"}'; exit; }

    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
    $fp = @fopen(DATA_FILE . '.lock', 'c');
    if (!$fp || !flock($fp, LOCK_EX)) { http_response_code(500); echo '{"error":"storage"}'; exit; }
    $stats = load_stats();
    $def = (array)($stats['defense'] ?? []);
    $mat = (array)($stats['matches'] ?? []);
    $added = 0;

    $outcomes = ['counter', 'just', 'guard', 'none', 'fail'];
    if (isset($in['defense']) && is_array($in['defense'])) {
        foreach ($in['defense'] as $k => $row) {
            if (!valid_key((string)$k) || !is_array($row)) continue;
            if (!isset($def[$k])) $def[$k] = array_fill_keys($outcomes, 0);
            foreach ($outcomes as $o) {
                $n = $row[$o] ?? 0;
                if (is_numeric($n) && $n > 0 && $n <= 10000) { $def[$k][$o] += (int)$n; $added += (int)$n; }
            }
        }
    }
    if (isset($in['matches']) && is_array($in['matches'])) {
        foreach ($in['matches'] as $k => $row) {
            if (!valid_key((string)$k) || !is_array($row)) continue;
            if (!isset($mat[$k])) $mat[$k] = ['w' => 0, 'turns' => 0];
            $w = $row['w'] ?? 0; $t = $row['turns'] ?? 0;
            if (is_numeric($w) && $w > 0 && $w <= 100) { $mat[$k]['w'] += (int)$w; $added += (int)$w; }
            if (is_numeric($t) && $t > 0 && $t <= 100000) { $mat[$k]['turns'] += (int)$t; }
        }
    }
    $stats['defense'] = $def;
    $stats['matches'] = $mat;
    $stats['updated'] = gmdate('c');
    if (file_put_contents(DATA_FILE . '.tmp', json_encode($stats, JSON_UNESCAPED_UNICODE)) === false || !rename(DATA_FILE . '.tmp', DATA_FILE)) { http_response_code(500); echo '{"error":"storage"}'; exit; }
    if ($fp) { flock($fp, LOCK_UN); fclose($fp); }
    echo json_encode(['ok' => true, 'added' => $added]);
    exit;
}

// GET: viewer
$stats = load_stats();
$def = (array)($stats['defense'] ?? []);
$mat = (array)($stats['matches'] ?? []);

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json');
    echo json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$DB = ['d0' => '〜400ms', 'd1' => '400〜550', 'd2' => '550〜700', 'd3' => '700〜900', 'd4' => '900〜'];
$RB = ['r0' => '序盤(〜6)', 'r1' => '中盤(7〜14)', 'r2' => '終盤(15〜)'];
$NAMES = ['vera' => 'ヴェラ', 'hex' => 'ヘクス', 'morgana' => 'モルガナ', 'kanna' => 'かんな', 'lucia' => 'ルシア', 'sera' => 'セラ'];
$MODES = ['cpu' => 'CPU戦（プレイヤー防御）', 'friend' => 'フレンド対戦'];

function rate($n, $d) { return $d > 0 ? sprintf('%.1f%%', 100 * $n / $d) : '—'; }

echo '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<title>紅月闘技会 成功率ログ</title><style>body{background:#0b0b0d;color:#efe9da;font-family:sans-serif;margin:20px}h1{font-size:20px}h2{font-size:16px;margin-top:28px;color:#e8b64c}table{border-collapse:collapse;margin:8px 0}th,td{border:1px solid #333;padding:4px 10px;text-align:right;font-size:14px}th{background:#17171b;text-align:center}.l{text-align:left}small{color:#999}</style></head><body>';
echo '<h1>紅月闘技会 成功率ログ</h1><small>最終更新: ' . htmlspecialchars((string)($stats['updated'] ?? '—')) . ' UTC / <a style="color:#e8b64c" href="?format=json">JSON</a></small>';

foreach ($MODES as $mode => $label) {
    echo '<h2>' . $label . '：リング速度帯 × 防御結果</h2>';
    $rows = [];
    foreach ($def as $k => $row) {
        $p = explode('|', $k);
        if (count($p) === 5 && $p[0] === $mode) {
            $db = $p[3];
            foreach (['counter', 'just', 'guard', 'none', 'fail'] as $o) $rows[$db][$o] = ($rows[$db][$o] ?? 0) + (int)($row[$o] ?? 0);
        }
    }
    echo '<table><tr><th>速度帯</th><th>n</th><th>カウンター</th><th>ジャスト</th><th>ガード</th><th>不能</th><th>失敗</th><th>被弾率</th></tr>';
    foreach ($DB as $db => $dl) {
        $r = $rows[$db] ?? ['counter' => 0, 'just' => 0, 'guard' => 0, 'none' => 0, 'fail' => 0];
        $n = array_sum($r);
        echo '<tr><td class="l">' . $dl . '</td><td>' . $n . '</td><td>' . rate($r['counter'], $n) . '</td><td>' . rate($r['just'], $n) . '</td><td>' . rate($r['guard'], $n) . '</td><td>' . rate($r['none'], $n) . '</td><td>' . rate($r['fail'], $n) . '</td><td>' . rate($r['none'] + $r['fail'], $n) . '</td></tr>';
    }
    echo '</table>';

    echo '<h2>' . $label . '：ラリー帯 × 防御成功率</h2>';
    $rows = [];
    foreach ($def as $k => $row) {
        $p = explode('|', $k);
        if (count($p) === 5 && $p[0] === $mode) {
            $rb = $p[4];
            foreach (['counter', 'just', 'guard', 'none', 'fail'] as $o) $rows[$rb][$o] = ($rows[$rb][$o] ?? 0) + (int)($row[$o] ?? 0);
        }
    }
    echo '<table><tr><th>ラリー帯</th><th>n</th><th>成功率（被弾以外）</th><th>カウンター率</th></tr>';
    foreach ($RB as $rb => $rl) {
        $r = $rows[$rb] ?? ['counter' => 0, 'just' => 0, 'guard' => 0, 'none' => 0, 'fail' => 0];
        $n = array_sum($r);
        echo '<tr><td class="l">' . $rl . '</td><td>' . $n . '</td><td>' . rate($r['counter'] + $r['just'] + $r['guard'], $n) . '</td><td>' . rate($r['counter'], $n) . '</td></tr>';
    }
    echo '</table>';
}

echo '<h2>キャラ別 被カウンター率など（防御側キャラ別・全モード合算）</h2>';
$byChar = [];
foreach ($def as $k => $row) {
    $p = explode('|', $k);
    if (count($p) !== 5) continue;
    foreach (['counter', 'just', 'guard', 'none', 'fail'] as $o) $byChar[$p[1]][$o] = ($byChar[$p[1]][$o] ?? 0) + (int)($row[$o] ?? 0);
}
echo '<table><tr><th>防御キャラ</th><th>n</th><th>成功率</th><th>カウンター率</th></tr>';
foreach ($NAMES as $id => $nm) {
    $r = $byChar[$id] ?? ['counter' => 0, 'just' => 0, 'guard' => 0, 'none' => 0, 'fail' => 0];
    $n = array_sum($r);
    echo '<tr><td class="l">' . $nm . '</td><td>' . $n . '</td><td>' . rate($r['counter'] + $r['just'] + $r['guard'], $n) . '</td><td>' . rate($r['counter'], $n) . '</td></tr>';
}
echo '</table>';

echo '<h2>フレンド対戦：キャラ別勝敗</h2>';
$mw = [];
foreach ($mat as $k => $row) {
    $p = explode('|', $k);
    if (count($p) === 3 && $p[0] === 'friend') { $mw[$p[1]][$p[2]] = $row; }
}
echo '<table><tr><th>勝者＼敗者</th>';
foreach ($NAMES as $nm) echo '<th>' . $nm . '</th>';
echo '<th>平均ターン</th></tr>';
foreach ($NAMES as $wid => $wnm) {
    echo '<tr><td class="l">' . $wnm . '</td>';
    $tt = 0; $tw = 0;
    foreach ($NAMES as $lid => $lnm) {
        $cell = $mw[$wid][$lid] ?? null;
        echo '<td>' . ($cell ? (int)$cell['w'] : '—') . '</td>';
        if ($cell) { $tt += (int)$cell['turns']; $tw += (int)$cell['w']; }
    }
    echo '<td>' . ($tw > 0 ? sprintf('%.1f', $tt / $tw) : '—') . '</td></tr>';
}
echo '</table>';
echo '</body></html>';
