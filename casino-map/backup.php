<?php
session_start();

if (!isset($_SESSION['login']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

include("config.php");

$backup_dir = __DIR__ . '/backups';
$keep_days  = 30;
$message    = '';
$error      = '';

// Yedekleme dizinini oluştur
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0750, true);
}
if (!file_exists($backup_dir . '/.htaccess')) {
    file_put_contents($backup_dir . '/.htaccess', "Order deny,allow\nDeny from all\n");
}

// ── İndirme ───────────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    $file = basename($_GET['file'] ?? '');
    $fp   = $backup_dir . '/' . $file;
    if (preg_match('/^backup_[\d_-]+\.sql(\.gz)?$/', $file) && is_file($fp)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($fp));
        readfile($fp);
        exit;
    }
    $error = "Geçersiz dosya adı.";
}

// ── Silme ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();
    $file = basename($_POST['file'] ?? '');
    $fp   = $backup_dir . '/' . $file;
    if (preg_match('/^backup_[\d_-]+\.sql(\.gz)?$/', $file) && is_file($fp)) {
        unlink($fp);
        $message = "Yedek silindi: " . htmlspecialchars($file);
    } else {
        $error = "Geçersiz veya bulunamayan dosya.";
    }
}

// ── Yedek Oluştur ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup') {
    csrf_verify();
    $result = perform_backup($host, $user, $pass, $db, $conn, $backup_dir, $keep_days);
    if ($result['success']) {
        $message = "✅ Yedek oluşturuldu: " . htmlspecialchars($result['file'])
            . " (" . format_size($result['size']) . ")";
    } else {
        $error = "❌ Yedekleme başarısız: " . implode('; ', array_map('htmlspecialchars', $result['log']));
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Yedekleme fonksiyonu
// ─────────────────────────────────────────────────────────────────────────────
function perform_backup(
    string $host, string $user, string $pass, string $db,
    mysqli $conn, string $backup_dir, int $keep_days
): array {
    $date     = date('Y-m-d_H-i-s');
    $filename = "backup_{$date}.sql";
    $filepath = "{$backup_dir}/{$filename}";
    $success  = false;
    $logs     = [];

    // 1. mysqldump dene
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
            $logs[]  = "mysqldump kullanıldı.";
        }
    }

    // 2. PHP fallback
    if (!$success) {
        $sql  = "-- Casino Map Veritabanı Yedeği\n";
        $sql .= "-- Tarih: " . date('Y-m-d H:i:s') . "\n\n";
        $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

        $tables_res = $conn->query("SHOW TABLES");
        while ($trow = $tables_res->fetch_row()) {
            $table = $trow[0];
            $cr    = $conn->query("SHOW CREATE TABLE `{$table}`")->fetch_row();
            $sql  .= "DROP TABLE IF EXISTS `{$table}`;\n" . $cr[1] . ";\n\n";

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
            $logs[]  = "PHP yöntemi kullanıldı.";
        } else {
            $logs[] = "Dosya yazma hatası: {$filepath}";
        }
    }

    // 3. Gzip sıkıştırma
    $final_file = $filepath;
    if ($success && file_exists($filepath)) {
        $gz_path = $filepath . '.gz';
        $gz = gzopen($gz_path, 'wb9');
        if ($gz) {
            gzwrite($gz, file_get_contents($filepath));
            gzclose($gz);
            unlink($filepath);
            $final_file = $gz_path;
            $logs[] = "Gzip ile sıkıştırıldı.";
        }
    }

    // 4. Eski yedekleri temizle
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
        $logs[] = "{$deleted} eski yedek silindi.";
    }

    return [
        'success' => $success,
        'file'    => basename($final_file),
        'size'    => (file_exists($final_file) ? filesize($final_file) : 0),
        'log'     => $logs,
    ];
}

function format_size(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// ── Yedek listesi ─────────────────────────────────────────────────────────────
$backups = [];
$files   = glob($backup_dir . '/backup_*.sql*') ?: [];
usort($files, fn($a, $b) => filemtime($b) - filemtime($a)); // yeniden eskiye
foreach ($files as $f) {
    $backups[] = ['name' => basename($f), 'size' => filesize($f), 'mtime' => filemtime($f)];
}

// Cron komutu bilgisi
$php_bin     = PHP_BINARY ?: 'php';
$cron_script = realpath(__DIR__ . '/backup_cron.php');
$log_file    = realpath($backup_dir) . '/backup.log';
$cron_line   = "0 2 * * * {$php_bin} {$cron_script} >> {$log_file} 2>&1";
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Yedekleme Yönetimi</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 860px; margin: 0 auto; }
        h2 { margin: 0 0 4px; color: #333; }
        .subtitle { color: #666; font-size: 13px; }

        .card {
            background: white; border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1); padding: 20px; margin-bottom: 20px;
        }
        .card h3 { margin: 0 0 14px; color: #444; font-size: 15px; }

        .msg  { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
        .msg.ok  { background: #e8f5e9; color: #2e7d32; border-left: 4px solid #4CAF50; }
        .msg.err { background: #ffebee; color: #c62828; border-left: 4px solid #f44336; }

        .btn {
            display: inline-block; padding: 9px 18px; border-radius: 6px;
            border: none; cursor: pointer; font-size: 13px; font-weight: bold;
            text-decoration: none; transition: opacity .2s;
        }
        .btn:hover { opacity: .85; }
        .btn-green  { background: #4CAF50; color: white; }
        .btn-blue   { background: #2196F3; color: white; }
        .btn-red    { background: #f44336; color: white; padding: 5px 10px; font-size: 12px; }
        .btn-back   { background: #607D8B; color: white; }

        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: #f5f5f5; padding: 9px 10px; text-align: left; border-bottom: 2px solid #eee; color: #555; }
        td { padding: 8px 10px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fafafa; }

        .empty { text-align: center; color: #999; padding: 30px; font-size: 14px; }

        .cron-box {
            background: #263238; color: #80CBC4; font-family: monospace; font-size: 13px;
            padding: 14px 16px; border-radius: 6px; word-break: break-all;
            position: relative; margin-top: 10px;
        }
        .copy-btn {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            background: #37474F; color: #B0BEC5; border: none; border-radius: 4px;
            padding: 4px 10px; cursor: pointer; font-size: 11px;
        }
        .copy-btn:hover { background: #455A64; }

        .steps { padding-left: 20px; font-size: 13px; color: #555; line-height: 1.8; }
        .note  { font-size: 12px; color: #888; margin-top: 8px; }

        .header-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="container">

    <div class="card">
        <div class="header-row">
            <div>
                <h2>💾 Yedekleme Yönetimi</h2>
                <div class="subtitle">Veritabanı yedeklerini oluşturun, indirin ve yönetin</div>
            </div>
            <a href="dashboard.php" class="btn btn-back">← Panele Dön</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="msg ok"><?= $message ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="msg err"><?= $error ?></div>
    <?php endif; ?>

    <!-- Manuel Yedek -->
    <div class="card">
        <h3>📦 Manuel Yedek Al</h3>
        <p style="color:#555; font-size:13px; margin:0 0 14px;">
            Şu anki veritabanı durumunun tam yedeğini alır (gzip sıkıştırmalı .sql.gz).
        </p>
        <form method="POST" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='⏳ Yedekleniyor...';">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="backup">
            <button type="submit" class="btn btn-green">🔒 Şimdi Yedekle</button>
        </form>
    </div>

    <!-- Mevcut Yedekler -->
    <div class="card">
        <h3>📋 Mevcut Yedekler (Son <?= $keep_days ?> Gün)</h3>

        <?php if (empty($backups)): ?>
            <div class="empty">Henüz yedek bulunmuyor. Yukarıdan manuel yedek alabilir veya cron ayarlayabilirsiniz.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Dosya Adı</th>
                        <th>Tarih</th>
                        <th>Boyut</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $b): ?>
                    <tr>
                        <td><?= htmlspecialchars($b['name']) ?></td>
                        <td><?= date('d.m.Y H:i:s', $b['mtime']) ?></td>
                        <td><?= format_size($b['size']) ?></td>
                        <td>
                            <a href="?action=download&file=<?= urlencode($b['name']) ?>"
                               class="btn btn-blue">⬇ İndir</a>
                            &nbsp;
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('<?= htmlspecialchars($b['name']) ?> silinsin mi?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="file"   value="<?= htmlspecialchars($b['name']) ?>">
                                <button type="submit" class="btn btn-red">🗑 Sil</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Cron Kurulum Talimatları -->
    <div class="card">
        <h3>⏰ Otomatik Günlük Yedek (Cron Kurulumu)</h3>
        <p style="color:#555; font-size:13px; margin:0 0 14px;">
            Sunucunuzda cron ile her gece otomatik yedek almak için aşağıdaki adımları izleyin:
        </p>

        <ol class="steps">
            <li>SSH ile sunucuya bağlanın.</li>
            <li>Crontab düzenleyiciyi açın:
                <div class="cron-box">crontab -e</div>
            </li>
            <li>Aşağıdaki satırı ekleyin (her gece saat 02:00'da çalışır):
                <div class="cron-box" id="cronLine">
                    <?= htmlspecialchars($cron_line) ?>
                    <button class="copy-btn" onclick="copyCron()">Kopyala</button>
                </div>
            </li>
            <li>Kaydedin ve çıkın (<code>Ctrl+O</code>, <code>Enter</code>, <code>Ctrl+X</code>).</li>
            <li>Cron servisinin çalıştığını kontrol edin: <code>crontab -l</code></li>
        </ol>

        <p class="note">
            ℹ️ Yedekler <strong>backups/</strong> klasörüne kaydedilir. Son <?= $keep_days ?> günden eski yedekler otomatik silinir.
            Cron loglara erişmek için: <code><?= htmlspecialchars($log_file) ?></code>
        </p>
        <p class="note">
            ℹ️ Cron script konumu: <code><?= htmlspecialchars($cron_script) ?></code>
        </p>
    </div>

</div>

<script>
function copyCron() {
    const text = <?= json_encode($cron_line) ?>;
    navigator.clipboard.writeText(text).then(() => {
        const btn = document.querySelector('.copy-btn');
        btn.textContent = 'Kopyalandı!';
        setTimeout(() => btn.textContent = 'Kopyala', 2000);
    });
}
</script>
</body>
</html>
