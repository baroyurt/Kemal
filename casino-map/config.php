<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "casino_map";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Veritabanı bağlantı hatası.");
}

// Türkçe karakter desteği için UTF-8 charset
$conn->set_charset("utf8mb4");

// Oturum zaman aşımı kontrolü (30 dakika)
if (session_status() === PHP_SESSION_ACTIVE) {
    $timeout = 1800; // 30 dakika
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        session_unset();
        session_destroy();
        header("Location: login.php?timeout=1");
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// CSRF token yardımcı fonksiyonları
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
        die("Güvenlik doğrulama hatası. Lütfen sayfayı yenileyip tekrar deneyin.");
    }
}
?>