<?php
// ============================================================
// CRM + Hotspot — Ana Yapılandırma Dosyası
// ============================================================

// Veritabanı
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'crm_hotspot');

// Uygulama kökü
define('APP_ROOT', dirname(__DIR__));
define('APP_URL',  'http://localhost/crm-hotspot');

// Oturum zaman aşımı (saniye)
define('SESSION_TIMEOUT', 1800);

// ============================================================
// WhatsApp (Meta Business Cloud API)
// ============================================================
define('WA_PHONE_NUMBER_ID', 'PHONE_NUMBER_ID_BURAYA');
define('WA_ACCESS_TOKEN',    'EAAxxxxYOUR_TOKEN_HERE');
define('WA_VERIFY_TOKEN',    'crm_hotspot_webhook_secret');
define('WA_API_VERSION',     'v19.0');
define('WA_FROM_NUMBER',     '+90XXXXXXXXXX');

// ============================================================
// Fidelio / Opera PMS
// Mod: 'fias' veya 'rest'
// ============================================================
define('FIDELIO_MODE',       'fias');          // 'fias' | 'rest'
define('FIDELIO_FIAS_HOST',  '192.168.1.100'); // FIAS sunucu IP
define('FIDELIO_FIAS_PORT',  5010);            // FIAS port
define('FIDELIO_REST_URL',   'https://opera.otel.com/api/v1');
define('FIDELIO_REST_TOKEN', 'OPERA_API_TOKEN_BURAYA');
define('FIDELIO_HOTEL_CODE', 'OTEL01');

// ============================================================
// Mikrotik RouterOS API
// ============================================================
define('MIKROTIK_HOST',      '192.168.88.1');
define('MIKROTIK_PORT',      8728);
define('MIKROTIK_USER',      'admin');
define('MIKROTIK_PASS',      'mikrotik_sifre');
define('MIKROTIK_HOTSPOT_SERVER', 'hotspot1');

// ============================================================
// Bant genişliği profilleri (Mikrotik hotspot profil adları)
// ============================================================
define('BW_PROFILES', serialize([
    'standard' => 'Standard-10M',
    'premium'  => 'Premium-50M',
    'vip'      => 'VIP-100M',
]));

// ============================================================
// Veritabanı bağlantısı
// ============================================================
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(503);
    die("Veritabanı bağlantı hatası.");
}
$conn->set_charset('utf8mb4');

// ============================================================
// Oturum yönetimi
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
    header('Location: ' . APP_URL . '/login.php?timeout=1');
    exit;
}
if (isset($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
}

// ============================================================
// CSRF
// ============================================================
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}
function csrf_verify(): void {
    if (!isset($_POST['csrf_token']) || !hash_equals(csrf_token(), $_POST['csrf_token'])) {
        http_response_code(403);
        die('Güvenlik doğrulama hatası. Lütfen sayfayı yenileyip tekrar deneyin.');
    }
}

// ============================================================
// Yetki kontrolü
// ============================================================
// Rol hiyerarşisi: admin > reception > it_staff > readonly
define('ROLE_HIERARCHY', serialize(['admin' => 4, 'reception' => 3, 'it_staff' => 2, 'readonly' => 1]));

function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}
function require_role(string ...$roles): void {
    require_login();
    if (!in_array($_SESSION['role'], $roles, true)) {
        http_response_code(403);
        include APP_ROOT . '/modules/auth/403.php';
        exit;
    }
}
function has_role(string ...$roles): bool {
    return !empty($_SESSION['role']) && in_array($_SESSION['role'], $roles, true);
}
function is_admin(): bool { return has_role('admin'); }

// ============================================================
// Audit log
// ============================================================
function audit_log(mysqli $conn, string $action, string $target_type = '', int $target_id = 0, string $desc = ''): void {
    $user_id = $_SESSION['user_id'] ?? null;
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt    = $conn->prepare(
        'INSERT INTO staff_logs (user_id, action, target_type, target_id, description, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ississs', $user_id, $action, $target_type, $target_id, $desc, $ip);
    $stmt->execute();
    $stmt->close();
}

// ============================================================
// Yardımcılar
// ============================================================
function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function format_bytes(int $bytes): string {
    if ($bytes < 1024)       return $bytes . ' B';
    if ($bytes < 1048576)    return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}
function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return $diff . ' sn önce';
    if ($diff < 3600)   return floor($diff / 60) . ' dk önce';
    if ($diff < 86400)  return floor($diff / 3600) . ' sa önce';
    return floor($diff / 86400) . ' gün önce';
}
function guest_status_badge(string $status): string {
    $map = [
        'checked_in'  => ['success', 'Check-in'],
        'checked_out' => ['secondary', 'Check-out'],
        'reserved'    => ['warning', 'Rezervasyon'],
        'cancelled'   => ['danger', 'İptal'],
    ];
    [$cls, $lbl] = $map[$status] ?? ['secondary', $status];
    return '<span class="badge bg-' . $cls . '">' . $lbl . '</span>';
}
