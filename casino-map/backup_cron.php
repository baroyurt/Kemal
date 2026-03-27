<?php
/**
 * Casino Map — Otomatik Yedekleme Scripti
 *
 * CLI kullanımı (cron):
 *   php /var/www/html/casino-map/backup_cron.php
 *
 * Örnek cron ayarı (her gece 02:00):
 *   0 2 * * * php /var/www/html/casino-map/backup_cron.php >> /var/www/html/casino-map/backups/backup.log 2>&1
 *
 * Doğrudan web tarayıcısından erişim engellenmektedir.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Bu script yalnızca komut satırından çalıştırılabilir.\n");
}

// config.php'yi dahil et — $host, $user, $pass, $db ve $conn sağlar
// CLI modunda session ve CSRF kodları çalışmaz (PHP_SESSION_ACTIVE değil)
require_once __DIR__ . '/config.php';

$backup_dir = __DIR__ . '/backups';
$keep_days  = 30;

// ── 1. Yedekleme dizinini hazırla ────────────────────────────────────────────
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0750, true);
}
if (!file_exists($backup_dir . '/.htaccess')) {
    file_put_contents($backup_dir . '/.htaccess', "Order deny,allow\nDeny from all\n");
}

$date     = date('Y-m-d_H-i-s');
$filename = "backup_{$date}.sql";
$filepath = "{$backup_dir}/{$filename}";
$success  = false;
$logs     = [];

// ── 2. mysqldump ile yedekle (önce dene) ─────────────────────────────────────
$mysqldump = '';
foreach (['/usr/bin/mysqldump', '/usr/local/bin/mysqldump'] as $bin) {
    if (is_executable($bin)) { $mysqldump = $bin; break; }
}
if (empty($mysqldump) && function_exists('exec')) {
    exec('which mysqldump 2>/dev/null', $wout, $wrc);
    if ($wrc === 0 && !empty($wout[0])) {
        $mysqldump = trim($wout[0]);
    }
}

if (!empty($mysqldump) && function_exists('exec')) {
    $cmd = escapeshellcmd($mysqldump)
        . ' -h ' . escapeshellarg($host)
        . ' -u ' . escapeshellarg($user)
        . (empty($pass) ? '' : ' -p' . escapeshellarg($pass))
        . ' --single-transaction --routines --add-drop-table'
        . ' ' . escapeshellarg($db)
        . ' 2>/dev/null';
    exec($cmd, $dump_lines, $rc);
    if ($rc === 0 && !empty($dump_lines)) {
        file_put_contents($filepath, implode("\n", $dump_lines));
        $success = true;
        $logs[]  = "mysqldump ile yedek alındı.";
    } else {
        $logs[] = "mysqldump başarısız (rc={$rc}), PHP yöntemine geçiliyor...";
    }
}

// ── 3. PHP tabanlı dump (fallback) ───────────────────────────────────────────
if (!$success) {
    $sql  = "-- Casino Map Veritabanı Yedeği\n";
    $sql .= "-- Tarih   : " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Sunucu  : {$host}\n";
    $sql .= "-- Veritabanı: {$db}\n\n";
    $sql .= "SET NAMES utf8mb4;\n";
    $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tables_res = $conn->query("SHOW TABLES");
    while ($trow = $tables_res->fetch_row()) {
        $table = $trow[0];
        $cr    = $conn->query("SHOW CREATE TABLE `{$table}`")->fetch_row();

        $sql .= "-- ─── Tablo: `{$table}` ───\n";
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $sql .= $cr[1] . ";\n\n";

        $data_res = $conn->query("SELECT * FROM `{$table}`");
        if ($data_res && $data_res->num_rows > 0) {
            $fields = array_column($data_res->fetch_fields(), 'name');
            $data_res->data_seek(0);
            $sql .= "INSERT INTO `{$table}` (`" . implode('`,`', $fields) . "`) VALUES\n";
            $rows = [];
            while ($row = $data_res->fetch_row()) {
                $vals = array_map(function ($v) use ($conn) {
                    return $v === null ? 'NULL' : "'" . $conn->real_escape_string((string)$v) . "'";
                }, $row);
                $rows[] = "  (" . implode(', ', $vals) . ")";
            }
            $sql .= implode(",\n", $rows) . ";\n\n";
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    if (file_put_contents($filepath, $sql) !== false) {
        $success = true;
        $logs[]  = "PHP yöntemi ile yedek alındı.";
    } else {
        $logs[] = "HATA: Yedek dosyası yazılamadı: {$filepath}";
    }
}

// ── 4. Gzip sıkıştırma ───────────────────────────────────────────────────────
$final_file = $filepath;
if ($success && file_exists($filepath)) {
    $gz_path = $filepath . '.gz';
    $gz = gzopen($gz_path, 'wb9');
    if ($gz) {
        gzwrite($gz, file_get_contents($filepath));
        gzclose($gz);
        unlink($filepath);
        $final_file = $gz_path;
        $logs[] = "Sıkıştırıldı: " . basename($final_file) . " (" . format_size(filesize($final_file)) . ")";
    }
}

// ── 5. Eski yedekleri temizle ─────────────────────────────────────────────────
$old_files = glob($backup_dir . '/backup_*.sql*') ?: [];
$cutoff    = time() - $keep_days * 86400;
$deleted   = 0;
foreach ($old_files as $f) {
    if ($f !== $final_file && filemtime($f) < $cutoff) {
        unlink($f);
        $deleted++;
    }
}
if ($deleted > 0) {
    $logs[] = "{$deleted} eski yedek silindi ({$keep_days} günden eskiler).";
}

// ── Çıktı ─────────────────────────────────────────────────────────────────────
foreach ($logs as $log) {
    echo "[" . date('H:i:s') . "] {$log}\n";
}
$status = $success ? "TAMAMLANDI" : "HATA";
echo "[" . date('H:i:s') . "] [{$status}] Yedekleme bitti.\n";

exit($success ? 0 : 1);

// ─────────────────────────────────────────────────────────────────────────────
function format_size(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
