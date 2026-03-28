<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "casino_map";

// Önce veritabanı adı belirtmeden bağlan (casino_map henüz yoksa hata vermemesi için)
$conn = new mysqli($host, $user, $pass);

if ($conn->connect_error) {
    die("MySQL bağlantı hatası: " . $conn->connect_error);
}

// Türkçe karakter desteği için UTF-8 charset
$conn->set_charset("utf8mb4");

// Veritabanı yoksa oluştur
$conn->query("CREATE DATABASE IF NOT EXISTS `casino_map` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// Veritabanını seç
if (!$conn->select_db($db)) {
    die("Veritabanı seçilemedi: " . $conn->error);
}

// ── İlk Kurulum: Tablolar yoksa oluştur ─────────────────────────────────────

$conn->query("CREATE TABLE IF NOT EXISTS machines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    machine_no VARCHAR(20),
    smibb_ip VARCHAR(50),
    screen_ip VARCHAR(50) DEFAULT NULL,
    mac VARCHAR(50),
    area INT DEFAULT NULL,
    machine_type VARCHAR(100) DEFAULT NULL,
    game_type VARCHAR(100) DEFAULT NULL,
    pos_x INT DEFAULT 50,
    pos_y INT DEFAULT 50,
    pos_z INT DEFAULT 0,
    rotation INT DEFAULT 0,
    note TEXT DEFAULT NULL,
    hub_sw TINYINT(1) DEFAULT 0,
    hub_sw_cable VARCHAR(255) DEFAULT NULL,
    brand VARCHAR(100) DEFAULT NULL,
    model VARCHAR(100) DEFAULT NULL,
    drscreen_ip VARCHAR(50) DEFAULT NULL,
    machine_pc VARCHAR(100) DEFAULT NULL
)");

$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE,
    password VARCHAR(255),
    role ENUM('admin', 'personel') DEFAULT 'personel',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS machine_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_name VARCHAR(100) NOT NULL,
    description TEXT,
    color VARCHAR(20) DEFAULT '#4CAF50',
    icon VARCHAR(50) DEFAULT 'fa-server',
    region_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS machine_group_relations (
    machine_id INT,
    group_id INT,
    PRIMARY KEY (machine_id, group_id),
    FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES machine_groups(id) ON DELETE CASCADE
)");

$conn->query("CREATE TABLE IF NOT EXISTS regions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(20) DEFAULT '#607D8B',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_machine_id INT NOT NULL,
    target_machine_id INT DEFAULT NULL,
    target_label VARCHAR(100) DEFAULT NULL,
    connection_type ENUM('hub_sw','direct','cable') DEFAULT 'hub_sw',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (source_machine_id) REFERENCES machines(id) ON DELETE CASCADE,
    FOREIGN KEY (target_machine_id) REFERENCES machines(id) ON DELETE SET NULL
)");

$conn->query("CREATE TABLE IF NOT EXISTS settings (
    `key`   VARCHAR(80)  NOT NULL PRIMARY KEY,
    `value` VARCHAR(255) NOT NULL
)");

// Varsayılan renk ayarları — tablo boşsa ekle
$defaults = [
    'machine_color_normal' => '#4CAF50',
    'machine_color_note'   => '#40E0D0',
    'map_bg_color'         => '#e0e0e0',
];
foreach ($defaults as $k => $v) {
    $stmt = $conn->prepare("INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)");
    $stmt->bind_param("ss", $k, $v);
    $stmt->execute();
    $stmt->close();
}

// ── Otomatik Migrasyon: Eski kurulumlar için eksik sütunları ekle ────────────
// PHP 8.1+ varsayılan olarak mysqli hataları exception'a çeviriyor.
// SHOW COLUMNS ile önce kontrol edip, sadece eksik sütunu ALTER TABLE ile
// ekliyoruz. Bu sayede yeni ve eski kurulumlar hep çalışır.
function ensure_column(mysqli $conn, string $table, string $col, string $def): void {
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$col` $def");
    }
}

// machines tablosu — zamanla eklenen sütunlar
ensure_column($conn, 'machines', 'smibb_ip',    'VARCHAR(50) DEFAULT NULL');
ensure_column($conn, 'machines', 'screen_ip',   'VARCHAR(50) DEFAULT NULL');
ensure_column($conn, 'machines', 'drscreen_ip', 'VARCHAR(50) DEFAULT NULL');
ensure_column($conn, 'machines', 'area',         'INT DEFAULT NULL');
ensure_column($conn, 'machines', 'machine_type', 'VARCHAR(100) DEFAULT NULL');
ensure_column($conn, 'machines', 'game_type',    'VARCHAR(100) DEFAULT NULL');
ensure_column($conn, 'machines', 'hub_sw',       'TINYINT(1) DEFAULT 0');
ensure_column($conn, 'machines', 'hub_sw_cable', 'VARCHAR(255) DEFAULT NULL');
ensure_column($conn, 'machines', 'brand',        'VARCHAR(100) DEFAULT NULL');
ensure_column($conn, 'machines', 'model',        'VARCHAR(100) DEFAULT NULL');
ensure_column($conn, 'machines', 'machine_pc',        'VARCHAR(100) DEFAULT NULL');
ensure_column($conn, 'machines', 'machine_seri_number', 'VARCHAR(100) DEFAULT NULL');

// machine_groups tablosu — zamanla eklenen sütunlar
ensure_column($conn, 'machine_groups', 'color',     "VARCHAR(20) DEFAULT '#4CAF50'");
ensure_column($conn, 'machine_groups', 'icon',      "VARCHAR(50) DEFAULT 'fa-server'");
ensure_column($conn, 'machine_groups', 'region_id', 'INT DEFAULT NULL');

// Varsayılan admin kullanıcısı yoksa ekle
// admin / admin123  ve  personel / personel123
$res = $conn->query("SELECT COUNT(*) as cnt FROM users");
if ($res && $res->fetch_assoc()['cnt'] == 0) {
    $conn->query("INSERT INTO users (username, password, role) VALUES
        ('admin',    '\$2y\$10\$fZY9qXc/gXg9S1at/dUfZeW7NsJrcXZWTOIMYXD4qlOQGaFAehaWC', 'admin'),
        ('personel', '\$2y\$10\$MH5JF9JToUY4uZhH8.9BYeLi46BCvu2zFFH9d.7KR9V38XzrbPcP6', 'personel')");
}

define('SESSION_TIMEOUT_SECONDS', 1800); // 30 dakika

// Oturum zaman aşımı kontrolü
if (session_status() === PHP_SESSION_ACTIVE) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT_SECONDS) {
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