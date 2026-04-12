<?php
// ============================================================
// REST API — Misafirler
// GET  /api/guests.php?room_no=101
// GET  /api/guests.php?status=checked_in
// POST /api/guests.php  (JSON body: create/update)
// Yetkilendirme: Bearer token veya API key
// ============================================================

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');

// Basit API key doğrulama (config'den ya da Authorization header)
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

if (!api_auth()) {
    api_error('Yetkisiz erişim.', 401);
}

$method = $_SERVER['REQUEST_METHOD'];

// -------------------------------------------------------
// GET — Misafir Sorgula
// -------------------------------------------------------
if ($method === 'GET') {
    $id      = isset($_GET['id'])      ? (int)$_GET['id']                        : 0;
    $room    = isset($_GET['room_no']) ? trim($_GET['room_no'])                   : '';
    $status  = isset($_GET['status'])  ? trim($_GET['status'])                    : '';
    $fid     = isset($_GET['fidelio_id']) ? trim($_GET['fidelio_id'])             : '';

    if ($id) {
        $st = $conn->prepare('SELECT * FROM guests WHERE id=?');
        $st->bind_param('i', $id);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) api_error('Misafir bulunamadı.', 404);
        api_success($row);
    }

    if ($room) {
        $st = $conn->prepare("SELECT * FROM guests WHERE room_no=? AND status='checked_in' LIMIT 1");
        $st->bind_param('s', $room);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) api_error('Bu odada aktif misafir bulunamadı.', 404);
        api_success($row);
    }

    if ($fid) {
        $st = $conn->prepare('SELECT * FROM guests WHERE fidelio_id=? LIMIT 1');
        $st->bind_param('s', $fid);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) api_error('Fidelio ID ile misafir bulunamadı.', 404);
        api_success($row);
    }

    // Liste
    $allowed_status = ['checked_in','checked_out','reserved','cancelled'];
    $rows = [];
    if ($status && in_array($status, $allowed_status, true)) {
        $lst = $conn->prepare(
            'SELECT id,first_name,last_name,room_no,phone,status,vip_status,checkin_date,checkout_date
             FROM guests WHERE status=? ORDER BY checkin_date DESC LIMIT 200'
        );
        $lst->bind_param('s', $status);
        $lst->execute();
        $res = $lst->get_result();
        $lst->close();
    } else {
        $res = $conn->query(
            'SELECT id,first_name,last_name,room_no,phone,status,vip_status,checkin_date,checkout_date
             FROM guests ORDER BY checkin_date DESC LIMIT 200'
        );
    }
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    api_success($rows);
}

// -------------------------------------------------------
// POST — Misafir Oluştur veya Güncelle
// -------------------------------------------------------
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) api_error('Geçersiz JSON gövdesi.');

    $action = $body['action'] ?? 'create';

    if ($action === 'create') {
        $required = ['first_name', 'last_name'];
        foreach ($required as $f) {
            if (empty($body[$f])) api_error("Zorunlu alan eksik: $f");
        }
        $st = $conn->prepare(
            'INSERT INTO guests (first_name,last_name,room_no,phone,email,nationality,vip_status,status,checkin_date,checkout_date,fidelio_id,reservation_no)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $fn     = $body['first_name']    ?? '';
        $ln     = $body['last_name']     ?? '';
        $rn     = $body['room_no']       ?? '';
        $ph     = $body['phone']         ?? '';
        $em     = $body['email']         ?? '';
        $na     = $body['nationality']   ?? '';
        $vip    = (int)($body['vip_status'] ?? 0);
        $st_s   = $body['status']        ?? 'reserved';
        $ci     = $body['checkin_date']  ?? null;
        $co     = $body['checkout_date'] ?? null;
        $fid    = $body['fidelio_id']    ?? '';
        $res_no = $body['reservation_no']?? '';
        $st->bind_param('ssssssisssss', $fn,$ln,$rn,$ph,$em,$na,$vip,$st_s,$ci,$co,$fid,$res_no);
        $st->execute();
        $new_id = (int)$conn->insert_id;
        $st->close();

        // WhatsApp karşılama (check_in durumundaysa)
        if ($st_s === 'checked_in' && $ph) {
            require_once APP_ROOT . '/lib/WhatsAppClient.php';
            $wa = new WhatsAppClient();
            $wa->sendWelcome($conn, $new_id, $ph, $fn, $rn);
        }

        api_success(['id' => $new_id, 'message' => 'Misafir oluşturuldu.']);
    }

    if ($action === 'update') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) api_error('id alanı zorunludur.');

        $allowed_fields = ['first_name','last_name','room_no','phone','email','nationality','vip_status','status','checkin_date','checkout_date','notes'];
        $sets  = [];
        $vals  = [];
        $types = '';
        foreach ($allowed_fields as $f) {
            if (isset($body[$f])) {
                $sets[]  = "$f=?";
                $vals[]  = $body[$f];
                $types  .= $f === 'vip_status' ? 'i' : 's';
            }
        }
        if (!$sets) api_error('Güncellenecek alan bulunamadı.');
        $vals[]  = $id;
        $types  .= 'i';
        $st      = $conn->prepare('UPDATE guests SET ' . implode(',', $sets) . ' WHERE id=?');
        $st->bind_param($types, ...$vals);
        $st->execute();
        $st->close();
        api_success(['message' => 'Güncellendi.']);
    }

    api_error('Geçersiz action.');
}

api_error('Desteklenmeyen HTTP metodu.', 405);
