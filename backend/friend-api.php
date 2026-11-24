<?php
// 紅月闘技会 フレンド対戦API（duel の friend-api と同方式：サーバー保持＋ポーリング）
// 設置: /battle/friend-api としてアクセスできる場所に置く（duel側と同じルーティング）。
// 部屋データは同階層の battle-friend-rooms/ に JSON で保存する（書き込み権限が必要）。
// 席の識別はセッションCookie。ダメージ計算はサーバー側で一元化し、
// 防御の判定区分（guard/just/counter/none/fail）だけを防御側クライアントが申告する
// （反射ゲーはネット遅延の都合上、見えたリングに対してローカル判定するのが唯一遊べる形のため）。

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) fail(405, 'Method not allowed');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== 'https://iruca.tech') fail(403, 'Origin rejected');
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') fail(403, 'Origin rejected');
    if (strtolower(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]) !== 'application/json') fail(415, 'JSON required');
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) fail(413, 'Request too large');
}
session_name('battle_session');
session_set_cookie_params(['path'=>'/battle/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Strict']);
ini_set('session.use_strict_mode', '1');
session_start();
$me = hash('sha256', session_id());

const ROOM_TTL = 3600;      // 1時間で部屋を破棄
const ROSTER = ['vera', 'hex', 'morgana', 'kanna', 'lucia', 'sera'];

function dirPath(): string { return '/var/lib/iruca-battle-friend/rooms'; }
function roomPath(string $code): string { if (!preg_match('/^\d{8}$/D', $code)) fail(404, 'Invalid room'); return dirPath() . '/' . $code . '.json'; }

function fail(int $status, string $msg): never {
    http_response_code($status);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function maxHp(string $fighter): int { return $fighter === 'vera' ? 130 : 100; }

function loadRoom(string $code, bool $mustExist = true): ?array {
    $p = roomPath($code);
    if (!is_file($p)) {
        if ($mustExist) fail(404, '部屋が見つかりません。');
        return null;
    }
    $fp = fopen($p, 'c+');
    if (!$fp) fail(500, 'サーバーエラー。');
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $room = $raw ? json_decode($raw, true) : null;
    if (!is_array($room)) { flock($fp, LOCK_UN); fclose($fp); fail(500, 'サーバーエラー。'); }
    $room['__fp'] = $fp;
    return $room;
}

function saveRoom(array $room): void {
    $fp = $room['__fp'];
    unset($room['__fp']);
    $room['updated'] = time();
    $room['revision']++;
    fseek($fp, 0);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($room, JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function dropRoom(array $room): void {
    $fp = $room['__fp'] ?? null;
    if ($fp) { flock($fp, LOCK_UN); fclose($fp); }
}

function mySeat(array $room, string $me): ?int {
    foreach ($room['seats'] as $i => $s) if ($s === $me) return $i;
    return null;
}

function publicState(array $room, ?int $seat): array {
    return [
        'code'      => $room['code'],
        'revision'  => $room['revision'],
        'serverNow' => (int)(microtime(true) * 1000),
        'seat'      => $seat,
        'occupied'  => array_map(fn($s) => $s !== null, $room['seats']),
        'fighters'  => $room['fighters'],
        'phase'     => $room['phase'],           // lobby | opening | battle | finished
        'ack'       => $room['ack'] ?? [false, false], // openingで両者が「戦闘へ進む」を押したか
        'awaiting'  => $room['awaiting'] ?? null, // attack | defense （phase=battle時）
        'turn'      => $room['turn'],
        'rally'     => $room['rally'],
        'attacker'  => $room['attacker'],
        'hp'        => $room['hp'],
        'maxHp'     => $room['maxHp'],
        'attack'    => $room['attack'],           // {x,y,p,by} 防御側がリング表示に使う
        'lastResult'=> $room['lastResult'],
        'winner'    => $room['winner'],
        'rematchAck'=> $room['rematchAck'],
    ];
}

function cleanup(): void {
    if (!is_dir(dirPath())) { mkdir(dirPath(), 0775, true); return; }
    foreach (glob(dirPath() . '/*.json') ?: [] as $f) {
        if (time() - filemtime($f) > ROOM_TTL) @unlink($f);
    }
}

// ---- ダメージ計算（クライアントのCPU戦と同一式） ----
function resolveExchange(array $room, string $judgment): array {
    $atk = $room['attacker'];
    $def = 1 - $atk;
    $a = $room['attack'];
    $p = (float)$a['p'];
    $dmg  = 8 + 16 * $p;
    $chip = 2 + 6 * $p;
    // ヘクス特性：残HPで攻撃倍率
    if ($room['fighters'][$atk] === 'hex') {
        $frac = $room['hp'][$atk] / max(1, $room['maxHp'][$atk]);
        if ($frac <= 0.25) { $dmg *= 1.5; $chip *= 1.5; }
        elseif ($frac <= 0.5) { $dmg *= 1.25; $chip *= 1.25; }
    }
    $dmg = (int)round($dmg);
    $chip = (int)round($chip);
    $toDef = 0; $toAtk = 0;
    switch ($judgment) {
        case 'guard':   $toDef = $chip; break;
        case 'just':    $toDef = 0; break;
        case 'counter': $toAtk = 10; break;
        case 'none':    $toDef = $dmg; break;
        case 'fail':    $toDef = $dmg; break; // ルシアの早押し失敗
        default: fail(400, '判定が不正です。');
    }
    $room['hp'][$def] = max(0, $room['hp'][$def] - $toDef);
    $room['hp'][$atk] = max(0, $room['hp'][$atk] - $toAtk);
    $result = [
        'turn' => $room['turn'], 'attacker' => $atk, 'judgment' => $judgment,
        'dmgToDef' => $toDef, 'dmgToAtk' => $toAtk, 'heal' => 0,
        'x' => $a['x'], 'y' => $a['y'], 'p' => $p,
    ];
    if ($room['hp'][$def] <= 0 || $room['hp'][$atk] <= 0) {
        $room['phase'] = 'finished';
        $room['winner'] = $room['hp'][$def] <= 0 && $room['hp'][$atk] > 0 ? $atk
                        : ($room['hp'][$atk] <= 0 && $room['hp'][$def] > 0 ? $def : $atk);
    } else {
        $room['attacker'] = $def;
        $room['turn']++;
        $room['rally']++;
        $room['awaiting'] = 'attack';
    }
    $room['attack'] = null;
    $room['lastResult'] = $result;
    return $room;
}

// ---- リクエスト処理 ----
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $code = $_GET['code'] ?? '';
    if (!preg_match('/^\d{8}$/', $code)) fail(404, '部屋が見つかりません。');
    $room = loadRoom($code);
    $seat = mySeat($room, $me);
    $state = publicState($room, $seat);
    dropRoom($room);
    echo json_encode($state, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') fail(405, 'メソッドが不正です。');
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) fail(400, 'Invalid JSON');
$op = $body['op'] ?? '';
cleanup();

switch ($op) {
    case 'create': {
        do { $code = (string)random_int(10000000, 99999999); } while (is_file(roomPath($code)));
        $fp = fopen(roomPath($code), 'c+');
        flock($fp, LOCK_EX);
        $room = [
            'code' => $code, 'revision' => 0, 'created' => time(), 'updated' => time(),
            'seats' => [$me, null], 'fighters' => [null, null],
            'phase' => 'lobby', 'awaiting' => null, 'ack' => [false, false],
            'turn' => 0, 'rally' => 0, 'attacker' => 0,
            'hp' => [100, 100], 'maxHp' => [100, 100],
            'attack' => null, 'lastResult' => null, 'winner' => null,
            'rematchAck' => [false, false], '__fp' => $fp,
        ];
        $state = publicState($room, 0);
        saveRoom($room);
        echo json_encode($state, JSON_UNESCAPED_UNICODE);
        break;
    }
    case 'join': {
        $code = (string)($body['code'] ?? '');
        if (!preg_match('/^\d{8}$/', $code)) fail(404, '部屋が見つかりません。');
        $room = loadRoom($code);
        $seat = mySeat($room, $me);
        if ($seat === null) {
            if ($room['seats'][1] !== null) { dropRoom($room); fail(403, '部屋が満員です。'); }
            $room['seats'][1] = $me;
            $seat = 1;
        }
        $state = publicState($room, $seat);
        saveRoom($room);
        echo json_encode($state, JSON_UNESCAPED_UNICODE);
        break;
    }
    case 'pick': {
        $room = loadRoom((string)($body['code'] ?? ''));
        $seat = mySeat($room, $me);
        if ($seat === null) { dropRoom($room); fail(403, '入室していません。'); }
        if ($room['phase'] !== 'lobby') { dropRoom($room); fail(400, '対戦が始まっています。'); }
        $f = $body['fighter'] ?? '';
        if (!in_array($f, ROSTER, true)) { dropRoom($room); fail(400, 'キャラが不正です。'); }
        $room['fighters'][$seat] = $f;
        if ($room['fighters'][0] !== null && $room['fighters'][1] !== null) {
            // duelと同様、先攻・後攻はコイントス（opening）で見せてから開始する
            $room['maxHp'] = [maxHp($room['fighters'][0]), maxHp($room['fighters'][1])];
            $room['hp'] = $room['maxHp'];
            $room['phase'] = 'opening';
            $room['awaiting'] = null;
            $room['ack'] = [false, false];
            $room['attacker'] = random_int(0, 1);
            $room['turn'] = 0; $room['rally'] = 0;
        }
        $state = publicState($room, $seat);
        saveRoom($room);
        echo json_encode($state, JSON_UNESCAPED_UNICODE);
        break;
    }
    case 'ready': {
        // opening（コイントス）で「戦闘へ進む」を押す。両者そろったら対戦開始
        $room = loadRoom((string)($body['code'] ?? ''));
        $seat = mySeat($room, $me);
        if ($seat === null || $room['phase'] !== 'opening') { dropRoom($room); fail(400, '今は進めません。'); }
        $room['ack'][$seat] = true;
        if ($room['ack'][0] && $room['ack'][1]) {
            $room['phase'] = 'battle';
            $room['awaiting'] = 'attack';
        }
        $state = publicState($room, $seat);
        saveRoom($room);
        echo json_encode($state, JSON_UNESCAPED_UNICODE);
        break;
    }
    case 'attack': {
        $room = loadRoom((string)($body['code'] ?? ''));
        $seat = mySeat($room, $me);
        if ($seat === null || $room['phase'] !== 'battle' || $room['awaiting'] !== 'attack'
            || $room['attacker'] !== $seat || $room['attack'] !== null) {
            dropRoom($room); fail(400, '今は攻撃できません。');
        }
        if (!empty($body['fizzle'])) {
            // 不発：3秒以内に触れなかった。ダメージなしでターン交替
            $room['lastResult'] = ['turn' => $room['turn'], 'attacker' => $seat, 'judgment' => 'fizzle',
                'dmgToDef' => 0, 'dmgToAtk' => 0, 'heal' => 0, 'x' => null, 'y' => null, 'p' => 0];
            $room['attacker'] = 1 - $seat;
            $room['turn']++; $room['rally']++;
            $room['awaiting'] = 'attack';
        } elseif (!empty($body['heal'])) {
            if ($room['fighters'][$seat] !== 'sera') { dropRoom($room); fail(400, '回復できるのはセラだけです。'); }
            $amount = (int)round($room['maxHp'][$seat] * 0.1);
            $room['hp'][$seat] = min($room['maxHp'][$seat], $room['hp'][$seat] + $amount);
            $room['lastResult'] = ['turn' => $room['turn'], 'attacker' => $seat, 'judgment' => 'heal',
                'dmgToDef' => 0, 'dmgToAtk' => 0, 'heal' => $amount, 'x' => null, 'y' => null, 'p' => 0];
            $room['attacker'] = 1 - $seat;
            $room['turn']++; $room['rally']++;
            $room['awaiting'] = 'attack';
        } else {
            $x = max(0.0, min(1.0, (float)($body['x'] ?? -1)));
            $y = max(0.0, min(1.0, (float)($body['y'] ?? -1)));
            $p = max(0.0, min(1.0, (float)($body['p'] ?? 0)));
            if ($x < 0 || $y < 0) { dropRoom($room); fail(400, '攻撃位置が不正です。'); }
            $room['attack'] = ['x' => $x, 'y' => $y, 'p' => $p, 'by' => $seat];
            $room['awaiting'] = 'defense';
        }
        $state = publicState($room, $seat);
        saveRoom($room);
        echo json_encode($state, JSON_UNESCAPED_UNICODE);
        break;
    }
    case 'defend': {
        $room = loadRoom((string)($body['code'] ?? ''));
        $seat = mySeat($room, $me);
        if ($seat === null || $room['phase'] !== 'battle' || $room['awaiting'] !== 'defense'
            || $room['attacker'] === $seat || $room['attack'] === null) {
            dropRoom($room); fail(400, '今は防御できません。');
        }
        $room = resolveExchange($room, (string)($body['judgment'] ?? ''));
        $state = publicState($room, $seat);
        saveRoom($room);
        echo json_encode($state, JSON_UNESCAPED_UNICODE);
        break;
    }
    case 'rematch': {
        $room = loadRoom((string)($body['code'] ?? ''));
        $seat = mySeat($room, $me);
        if ($seat === null || $room['phase'] !== 'finished') { dropRoom($room); fail(400, '再戦できません。'); }
        $room['rematchAck'][$seat] = true;
        if ($room['rematchAck'][0] && $room['rematchAck'][1]) {
            $room['hp'] = $room['maxHp'];
            $room['phase'] = 'opening'; $room['awaiting'] = null;
            $room['ack'] = [false, false];
            $room['attacker'] = random_int(0, 1);
            $room['turn'] = 0; $room['rally'] = 0;
            $room['attack'] = null; $room['lastResult'] = null; $room['winner'] = null;
            $room['rematchAck'] = [false, false];
        }
        $state = publicState($room, $seat);
        saveRoom($room);
        echo json_encode($state, JSON_UNESCAPED_UNICODE);
        break;
    }
    case 'leave': {
        $code = (string)($body['code'] ?? '');
        if (is_file(roomPath($code))) {
            $room = loadRoom($code);
            $seat = mySeat($room, $me);
            if ($seat !== null) {
                $room['seats'][$seat] = null;
                if ($room['seats'][0] === null && $room['seats'][1] === null) {
                    $p = roomPath($code);
                    dropRoom($room);
                    @unlink($p);
                    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
                    break;
                }
            }
            $state = publicState($room, $seat);
            saveRoom($room);
            echo json_encode($state, JSON_UNESCAPED_UNICODE);
            break;
        }
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        break;
    }
    default:
        fail(400, '操作が不正です。');
}
