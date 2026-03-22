<?php
session_start();

if(!isset($_SESSION['login']) || $_SESSION['role'] != 'admin'){
    header("Location: login.php");
    exit;
}

include("config.php");

$messages = [];

function runQuery($conn, $sql, $description) {
    global $messages;
    if ($conn->query($sql)) {
        $messages[] = ['type' => 'success', 'text' => "✅ $description"];
    } else {
        $errNo = $conn->errno;
        // 1060 = Duplicate column, 1050 = Table exists
        // 1054 = Unknown column (e.g. source column no longer exists, migration already done)
        if ($errNo == 1060 || $errNo == 1050) {
            $messages[] = ['type' => 'info', 'text' => "ℹ️ $description (zaten mevcut, atlandı)"];
        } elseif ($errNo == 1054) {
            $messages[] = ['type' => 'info', 'text' => "ℹ️ $description (kaynak kolon bulunamadı, zaten migrate edilmiş olabilir)"];
        } else {
            $messages[] = ['type' => 'error', 'text' => "❌ $description: " . $conn->error];
        }
    }
}

runQuery($conn,
    "ALTER TABLE machine_groups ADD COLUMN color VARCHAR(20) DEFAULT '#4CAF50'",
    "machine_groups tablosuna 'color' kolonu eklendi"
);

runQuery($conn,
    "ALTER TABLE machine_groups ADD COLUMN icon VARCHAR(50) DEFAULT 'fa-server'",
    "machine_groups tablosuna 'icon' kolonu eklendi"
);

runQuery($conn,
    "ALTER TABLE machines ADD COLUMN has_hub_sw TINYINT(1) DEFAULT 0",
    "machines tablosuna 'has_hub_sw' kolonu eklendi"
);

runQuery($conn,
    "ALTER TABLE machines ADD COLUMN hub_sw_target VARCHAR(100) DEFAULT NULL",
    "machines tablosuna 'hub_sw_target' kolonu eklendi"
);

runQuery($conn,
    "ALTER TABLE machines ADD COLUMN game_type VARCHAR(100) DEFAULT NULL",
    "machines tablosuna 'game_type' kolonu eklendi"
);

runQuery($conn,
    "ALTER TABLE machines ADD COLUMN smibb_ip VARCHAR(50) DEFAULT NULL",
    "machines tablosuna 'smibb_ip' kolonu eklendi"
);

// Mevcut 'ip' verisini 'smibb_ip'ye kopyala
runQuery($conn,
    "UPDATE machines SET smibb_ip = ip WHERE smibb_ip IS NULL AND ip IS NOT NULL",
    "ip verisi smibb_ip kolonuna kopyalandı"
);

runQuery($conn,
    "ALTER TABLE machines ADD COLUMN screen_ip VARCHAR(50) DEFAULT NULL",
    "machines tablosuna 'screen_ip' kolonu eklendi"
);

// Mevcut 'drscreen_ip' verisini 'screen_ip'ye kopyala
runQuery($conn,
    "UPDATE machines SET screen_ip = drscreen_ip WHERE screen_ip IS NULL AND drscreen_ip IS NOT NULL",
    "drscreen_ip verisi screen_ip kolonuna kopyalandı"
);

runQuery($conn,
    "ALTER TABLE machines ADD COLUMN area INT DEFAULT NULL",
    "machines tablosuna 'area' kolonu eklendi"
);

runQuery($conn,
    "ALTER TABLE machines ADD COLUMN machine_type VARCHAR(100) DEFAULT NULL",
    "machines tablosuna 'machine_type' kolonu eklendi"
);

runQuery($conn,
    "ALTER TABLE machines ADD COLUMN brand VARCHAR(100) DEFAULT NULL",
    "machines tablosuna 'brand' kolonu eklendi"
);

runQuery($conn,
    "ALTER TABLE machines ADD COLUMN model VARCHAR(100) DEFAULT NULL",
    "machines tablosuna 'model' kolonu eklendi"
);

runQuery($conn,
    "CREATE TABLE IF NOT EXISTS connections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_machine_id INT NOT NULL,
        target_machine_id INT DEFAULT NULL,
        target_label VARCHAR(100) DEFAULT NULL,
        connection_type ENUM('hub_sw','direct','cable') DEFAULT 'hub_sw',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (source_machine_id) REFERENCES machines(id) ON DELETE CASCADE,
        FOREIGN KEY (target_machine_id) REFERENCES machines(id) ON DELETE SET NULL
    )",
    "'connections' tablosu oluşturuldu"
);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Veritabanı Migrasyonu</title>
    <style>
        body { font-family: Arial; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 700px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        h2 { margin: 0; color: #333; }
        .msg { padding: 12px 20px; border-radius: 8px; margin-bottom: 10px; font-size: 14px; }
        .msg.success { background: #e8f5e9; color: #2e7d32; border-left: 4px solid #4CAF50; }
        .msg.info    { background: #e3f2fd; color: #1565c0; border-left: 4px solid #2196F3; }
        .msg.error   { background: #ffebee; color: #c62828; border-left: 4px solid #f44336; }
        .back { margin-top: 20px; }
        .back a { padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>🔧 Veritabanı Migrasyonu</h2>
            <p style="color:#666; margin-top:8px; margin-bottom:0;">Yeni özellikler için gerekli tablo ve kolon güncellemeleri</p>
        </div>
        <?php foreach($messages as $msg): ?>
            <div class="msg <?php echo $msg['type']; ?>"><?php echo htmlspecialchars($msg['text']); ?></div>
        <?php endforeach; ?>
        <div class="back">
            <a href="dashboard.php">← Panoya Dön</a>
        </div>
    </div>
</body>
</html>
