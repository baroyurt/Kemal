<?php
// ============================================================
// REST API — Hotspot
// GET  /api/hotspot.php                    — aktif oturumlar
// GET  /api/hotspot.php?guest_id=5         — misafir oturumları
// GET  /api/hotspot.php?room_no=101        — odaya göre
// POST /api/hotspot.php action=start       — oturum başlat
// POST /api/hotspot.php action=stop        — oturum sonlandır
// POST /api/hotspot.php action=verify_room — captive portal oda doğrulama
// ============================================================

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');

function api_auth(): bool {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $key    = defined('API_SECRET_KEY') ? API_SECRET_KEY : 'crm_api_secret_2024';
    if (str_starts_with($header, 'Bearer ')) {
        return hash_equals($key, substr($header, 7));
    }
    return false;
}
function api_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function api_success(mixed $data): void {
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// verify_room is public (Mikrotik NAS kullanır)
$body = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
$is_verify = ($body['action'] ?? '') === 'verify_room';

if (!$is_verify && !api_auth()) {
    api_error('Yetkisiz erişim.', 401);
}

// -------------------------------------------------------
// GET — Oturum Listesi
// -------------------------------------------------------
if ($method === 'GET') {
    $guest_id = isset($_GET['guest_id']) ? (int)$_GET['guest_id'] : 0;
    $room_no  = trim($_GET['room_no'] ?? '');
    $status   = trim($_GET['status'] ?? 'active');

    $where  = [];
    $params = [];
    $types  = '';

    if ($guest_id) {
        $where[]  = 'hs.guest_id=?';
        $params[] = $guest_id;
        $types   .= 'i';
    }
    if ($room_no) {
        $where[]  = 'g.room_no=?';
        $params[] = $room_no;
        $types   .= 's';
    }
    if ($status) {
        $where[]  = 'hs.status=?';
        $params[] = $status;
        $types   .= 's';
    }

    $sql  = 'SELECT hs.*, g.first_name, g.last_name, g.room_no
             FROM hotspot_sessions hs
             LEFT JOIN guests g ON g.id = hs.guest_id';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY hs.started_at DESC LIMIT 100';

    $st = $conn->prepare($sql);
    if ($params) $st->bind_param($types, ...$params);
    $st->execute();
    $res  = $st->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $st->close();

    api_success($rows);
}

// -------------------------------------------------------
// POST — Oturum Yönet
// -------------------------------------------------------
if ($method === 'POST') {
    $action = $body['action'] ?? '';

    // Captive portal oda doğrulama (public)
    if ($action === 'verify_room') {
        $room_no = trim($body['room_no'] ?? '');
        if (!$room_no) api_error('room_no alanı zorunludur.');

        $st = $conn->prepare("SELECT id, first_name, room_no, vip_status FROM guests WHERE room_no=? AND status='checked_in' LIMIT 1");
        $st->bind_param('s', $room_no);
        $st->execute();
        $guest = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$guest) {
            api_error('Bu oda numarası ile aktif konaklama bulunamadı.', 404);
        }

        $bw_profiles = unserialize(BW_PROFILES);
        $profile     = $guest['vip_status'] ? 'vip' : 'standard';

        api_success([
            'guest_id'  => $guest['id'],
            'room_no'   => $guest['room_no'],
            'name'      => $guest['first_name'],
            'profile'   => $profile,
            'password'  => $room_no,
            'wifi_ssid' => 'Otel_Guest',
        ]);
    }

    // Oturum Başlat
    if ($action === 'start') {
        $guest_id = (int)($body['guest_id'] ?? 0);
        $mac      = trim($body['mac_address'] ?? '');
        $ip       = trim($body['ip_address']  ?? '');
        $profile  = in_array($body['bandwidth_profile'] ?? '', ['standard','premium','vip'])
                    ? $body['bandwidth_profile'] : 'standard';

        if (!$mac) api_error('mac_address zorunludur.');

        // Mevcut aktif oturumu kapat
        $upd = $conn->prepare("UPDATE hotspot_sessions SET status='disconnected',ended_at=NOW() WHERE mac_address=? AND status='active'");
        $upd->bind_param('s', $mac);
        $upd->execute();
        $upd->close();

        $st = $conn->prepare('INSERT INTO hotspot_sessions (guest_id,mac_address,ip_address,bandwidth_profile,started_at) VALUES (?,?,?,?,NOW())');
        $gid = $guest_id ?: null;
        $st->bind_param('isss', $gid, $mac, $ip, $profile);
        $st->execute();
        $session_id = (int)$conn->insert_id;
        $st->close();

        api_success(['session_id' => $session_id, 'message' => 'Oturum başlatıldı.']);
    }

    // Oturum Sonlandır
    if ($action === 'stop') {
        $mac        = trim($body['mac_address'] ?? '');
        $session_id = (int)($body['session_id'] ?? 0);
        $bytes_in   = (int)($body['bytes_in']  ?? 0);
        $bytes_out  = (int)($body['bytes_out'] ?? 0);

        if ($session_id) {
            $st = $conn->prepare("UPDATE hotspot_sessions SET status='disconnected',ended_at=NOW(),bytes_in=?,bytes_out=? WHERE id=?");
            $st->bind_param('iii', $bytes_in, $bytes_out, $session_id);
        } elseif ($mac) {
            $st = $conn->prepare("UPDATE hotspot_sessions SET status='disconnected',ended_at=NOW(),bytes_in=?,bytes_out=? WHERE mac_address=? AND status='active'");
            $st->bind_param('iis', $bytes_in, $bytes_out, $mac);
        } else {
            api_error('session_id veya mac_address zorunludur.');
        }
        $st->execute();
        $st->close();
        api_success(['message' => 'Oturum sonlandırıldı.']);
    }

    // Veri güncelle (Mikrotik RADIUS accounting)
    if ($action === 'accounting') {
        $mac      = trim($body['mac_address'] ?? '');
        $bytes_in = (int)($body['bytes_in']  ?? 0);
        $bytes_out= (int)($body['bytes_out'] ?? 0);
        if (!$mac) api_error('mac_address zorunludur.');
        $st = $conn->prepare("UPDATE hotspot_sessions SET bytes_in=?,bytes_out=? WHERE mac_address=? AND status='active'");
        $st->bind_param('iis', $bytes_in, $bytes_out, $mac);
        $st->execute();
        $st->close();
        api_success(['message' => 'Veri güncellendi.']);
    }

    api_error('Geçersiz action.');
}

api_error('Desteklenmeyen HTTP metodu.', 405);
