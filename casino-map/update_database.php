<?php
/**
 * update_database.php
 * Mevcut bir casino_map kurulumuna bölge (regions) altyapısını ekler.
 *
 * Güvenli çalışır: tablo / kolon zaten varsa hata vermez, atlar.
 * Sadece admin yetkisiyle çalıştırılabilir.
 */
session_start();

if (!isset($_SESSION['login'])) {
    header("Location: login.php");
    exit;
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

include("config.php");

$messages = [];

/**
 * SQL çalıştır ve sonucu $messages dizisine ekle.
 * MySQL hata 1060 (duplicate column), 1050 (table exists), 1061 (duplicate key) bilgi olarak işaretlenir.
 * PHP mysqli exception modu aktifse (XAMPP varsayılanı) mysqli_sql_exception da yakalanır.
 */
function runMigration(mysqli $conn, string $sql, string $description): void
{
    global $messages;
    try {
        if ($conn->query($sql)) {
            $messages[] = ['type' => 'success', 'text' => "✅ $description"];
        } else {
            $errNo = $conn->errno;
            if ($errNo === 1060 || $errNo === 1050 || $errNo === 1061) {
                $messages[] = ['type' => 'info', 'text' => "ℹ️ $description (zaten mevcut, atlandı)"];
            } else {
                $messages[] = ['type' => 'error', 'text' => "❌ $description: " . $conn->error];
            }
        }
    } catch (mysqli_sql_exception $e) {
        $errNo = $e->getCode();
        // 1060 Duplicate column, 1050 Table already exists, 1061 Duplicate key name
        if ($errNo === 1060 || $errNo === 1050 || $errNo === 1061) {
            $messages[] = ['type' => 'info', 'text' => "ℹ️ $description (zaten mevcut, atlandı)"];
        } else {
            $messages[] = ['type' => 'error', 'text' => "❌ $description: " . $e->getMessage()];
        }
    }
}

// ─── 1. Önceki migrationlar (bağımlılık sırası) ────────────────────────────

runMigration($conn,
    "ALTER TABLE machine_groups ADD COLUMN color VARCHAR(20) DEFAULT '#4CAF50'",
    "machine_groups → color kolonu"
);

runMigration($conn,
    "ALTER TABLE machines ADD COLUMN hub_sw TINYINT(1) DEFAULT 0",
    "machines → hub_sw kolonu"
);

runMigration($conn,
    "ALTER TABLE machines ADD COLUMN hub_sw_cable VARCHAR(255) DEFAULT NULL",
    "machines → hub_sw_cable kolonu"
);

runMigration($conn,
    "ALTER TABLE machines ADD COLUMN brand VARCHAR(100) DEFAULT NULL",
    "machines → brand kolonu"
);

runMigration($conn,
    "ALTER TABLE machines ADD COLUMN model VARCHAR(100) DEFAULT NULL",
    "machines → model kolonu"
);

runMigration($conn,
    "ALTER TABLE machines ADD COLUMN game_type VARCHAR(100) DEFAULT NULL",
    "machines → game_type kolonu"
);

runMigration($conn,
    "ALTER TABLE machines ADD COLUMN drscreen_ip VARCHAR(50) DEFAULT NULL",
    "machines → drscreen_ip kolonu (eski ad)"
);

// ─── 3. ip → smibb_ip, drscreen_ip → screen_ip, machine_type, area_id ─────

runMigration($conn,
    "ALTER TABLE machines CHANGE COLUMN ip smibb_ip VARCHAR(50)",
    "machines → ip kolonu smibb_ip olarak yeniden adlandırıldı"
);

runMigration($conn,
    "ALTER TABLE machines CHANGE COLUMN drscreen_ip screen_ip VARCHAR(50)",
    "machines → drscreen_ip kolonu screen_ip olarak yeniden adlandırıldı"
);

runMigration($conn,
    "ALTER TABLE machines ADD COLUMN machine_type VARCHAR(100) DEFAULT NULL",
    "machines → machine_type kolonu"
);

runMigration($conn,
    "ALTER TABLE machines ADD COLUMN area_id INT DEFAULT NULL",
    "machines → area_id kolonu"
);

runMigration($conn,
    "ALTER TABLE users ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    "users → created_at kolonu"
);

// ─── 2. Bölge (Regions) altyapısı ──────────────────────────────────────────

runMigration($conn,
    "CREATE TABLE IF NOT EXISTS regions (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(100) NOT NULL,
        color      VARCHAR(20)  DEFAULT '#607D8B',
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
    "regions tablosu oluşturuldu"
);

runMigration($conn,
    "ALTER TABLE machine_groups ADD COLUMN region_id INT DEFAULT NULL",
    "machine_groups → region_id kolonu"
);

runMigration($conn,
    "ALTER TABLE machine_groups
        ADD CONSTRAINT fk_group_region
        FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL",
    "machine_groups → regions yabancı anahtar (fk_group_region)"
);

// ─── Özet ──────────────────────────────────────────────────────────────────

$successCount = count(array_filter($messages, fn($m) => $m['type'] === 'success'));
$skipCount    = count(array_filter($messages, fn($m) => $m['type'] === 'info'));
$errorCount   = count(array_filter($messages, fn($m) => $m['type'] === 'error'));
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Veritabanı Güncelleme</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f0f0f0; margin: 0; padding: 24px; }
        .container { max-width: 720px; margin: 0 auto; }
        .header {
            background: white; padding: 20px 24px; border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 16px;
            display: flex; align-items: center; gap: 14px;
        }
        .header h2 { margin: 0; color: #333; font-size: 20px; }
        .header p  { margin: 4px 0 0; color: #666; font-size: 13px; }
        .summary {
            background: white; padding: 14px 20px; border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 16px;
            display: flex; gap: 20px; flex-wrap: wrap;
        }
        .summary-item { font-size: 14px; font-weight: bold; }
        .summary-item.s { color: #2e7d32; }
        .summary-item.i { color: #1565c0; }
        .summary-item.e { color: #c62828; }
        .msg {
            padding: 11px 18px; border-radius: 8px; margin-bottom: 8px;
            font-size: 14px; background: white;
            box-shadow: 0 1px 4px rgba(0,0,0,0.07);
        }
        .msg.success { border-left: 4px solid #4CAF50; color: #2e7d32; }
        .msg.info    { border-left: 4px solid #2196F3; color: #1565c0; }
        .msg.error   { border-left: 4px solid #f44336; color: #c62828; }
        .actions { margin-top: 20px; display: flex; gap: 10px; }
        .btn {
            padding: 10px 20px; border-radius: 6px; text-decoration: none;
            font-size: 14px; font-weight: bold; display: inline-block;
        }
        .btn-green  { background: #4CAF50; color: white; }
        .btn-blue   { background: #2196F3; color: white; }
        .btn-gray   { background: #607D8B; color: white; }
    </style>
</head>
<body>
<div class="container">

    <div class="header">
        <div>
            <h2>🔧 Veritabanı Güncelleme</h2>
            <p>Bölge (regions) altyapısı ve ilgili migration'lar uygulandı.</p>
        </div>
    </div>

    <div class="summary">
        <span class="summary-item s">✅ <?php echo $successCount; ?> başarılı</span>
        <span class="summary-item i">ℹ️ <?php echo $skipCount; ?> atlandı (zaten mevcut)</span>
        <?php if ($errorCount > 0): ?>
        <span class="summary-item e">❌ <?php echo $errorCount; ?> hata</span>
        <?php endif; ?>
    </div>

    <?php foreach ($messages as $msg): ?>
        <div class="msg <?php echo htmlspecialchars($msg['type']); ?>">
            <?php echo htmlspecialchars($msg['text']); ?>
        </div>
    <?php endforeach; ?>

    <div class="actions">
        <a href="dashboard.php"           class="btn btn-green">← Panoya Dön</a>
        <a href="machine_settings.php?tab=groups" class="btn btn-blue">👥 Gruplara Git</a>
        <a href="map.php"                 class="btn btn-gray">🗺️ Haritaya Git</a>
    </div>

</div>
</body>
</html>
