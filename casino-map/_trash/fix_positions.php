<?php
/**
 * fix_positions.php
 * Tüm salon makinelerinin harita koordinatlarını (pos_x, pos_y, rotation)
 * orijinal programdaki değerlerle günceller.
 *
 * ALT SALON (Z=3): 9 referans noktadan türetilen bölge bazlı formüller
 * YÜKSEK TAVAN (Z=0): 11 referans noktadan türetilen bölge bazlı formüller
 * ALÇAK TAVAN (Z=1): 17 referans noktadan türetilen satır/kolon bazlı formüller
 * YENİ VİP SALON (Z=2): 11 referans noktadan türetilen bölge bazlı formüller
 *
 * Sadece admin kullanıcıları çalıştırabilir.
 */
session_start();

if (!isset($_SESSION['login'])) {
    header("Location: login.php");
    exit;
}
if ($_SESSION['role'] != 'admin') {
    die("Bu sayfaya erişim için yönetici yetkisi gereklidir.");
}

include("config.php");

// ─── Koordinat verilerini dış dosyadan yükle ──────────────────────────────
include_once('fix_positions_data.php');

$results = [];

// Auto-execute: sayfaya erişildiği anda koordinatları uygula (GET veya POST farketmez)
{
    $stmt = $conn->prepare(
        "UPDATE machines SET pos_x = ?, pos_y = ?, rotation = ? WHERE machine_no = ?"
    );

    $ok = $warn = $err = 0;
    foreach ($fixes as $machineNo => [$x, $y, $rot]) {
        $stmt->bind_param("iiis", $x, $y, $rot, $machineNo);
        $stmt->execute();

        if ($stmt->errno) {
            $results[] = ['type' => 'error',   'text' => "❌ {$machineNo}: " . $stmt->error];
            $err++;
        } elseif ($stmt->affected_rows > 0) {
            $results[] = ['type' => 'success', 'text' => "✅ Makine {$machineNo} → X={$x}, Y={$y}, Rot={$rot}°"];
            $ok++;
        } else {
            $results[] = ['type' => 'warning', 'text' => "✔ Makine {$machineNo}: koordinatlar zaten doğru."];
            $warn++;
        }
    }

    $stmt->close();
    $summary = "Toplam: " . count($fixes) . " makine — ✅ {$ok} güncellendi, ⚠️ {$warn} atlandı, ❌ {$err} hata.";
    $ran = true;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Salon Koordinat Düzeltme</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 10px;
                  box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        h2 { margin: 0 0 6px; color: #333; }
        .subtitle { color: #666; font-size: 14px; margin: 0; }
        .info-box { background: #e3f2fd; color: #1565c0; padding: 14px 18px; border-radius: 8px;
                    border-left: 4px solid #2196F3; margin-bottom: 20px; font-size: 13px; line-height: 1.6; }
        .preview { background: white; padding: 20px; border-radius: 10px;
                   box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .preview h3 { margin: 0 0 4px; font-size: 15px; color: #444; }
        .preview p  { margin: 0 0 12px; font-size: 12px; color: #888; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { background: #4CAF50; color: white; padding: 8px 10px; text-align: left; }
        td { padding: 6px 10px; border-bottom: 1px solid #eee; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f9f9f9; }
        td.ref { background: #fffde7; }
        .msg { padding: 10px 14px; border-radius: 6px; margin-bottom: 6px; font-size: 13px; }
        .msg.success { background: #e8f5e9; color: #2e7d32; border-left: 3px solid #4CAF50; }
        .msg.error   { background: #ffebee; color: #c62828; border-left: 3px solid #f44336; }
        .msg.warning { background: #fff8e1; color: #e65100; border-left: 3px solid #FF9800; }
        .summary { font-weight: bold; padding: 14px 18px; background: #e8f5e9; color: #2e7d32;
                   border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .actions { display: flex; gap: 12px; align-items: center; margin-top: 16px; flex-wrap: wrap; }
        .btn-run  { padding: 11px 28px; background: #4CAF50; color: white; border: none;
                    border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: bold;
                    transition: background 0.2s; }
        .btn-run:hover { background: #388E3C; }
        .btn-back { padding: 11px 20px; background: #607D8B; color: white; border: none;
                    border-radius: 6px; cursor: pointer; font-size: 14px; text-decoration: none;
                    display: inline-flex; align-items: center; transition: background 0.2s; }
        .btn-back:hover { background: #455A64; }
        .badge-ref { display: inline-block; background: #FFC107; color: #333; padding: 1px 6px;
                     border-radius: 3px; font-size: 10px; margin-left: 4px; vertical-align: middle; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h2>📍 Salon Koordinat Düzeltme</h2>
        <p class="subtitle">Tüm salon makineleri — orijinal program koordinatlarıyla senkronizasyon (<?= count($fixes) ?> makine)</p>
    </div>

    <?php if ($ran): ?>
        <div class="summary"><?= htmlspecialchars($summary) ?></div>
        <div style="max-height:400px; overflow-y:auto;">
            <?php foreach ($results as $r): ?>
                <div class="msg <?= $r['type'] ?>"><?= htmlspecialchars($r['text']) ?></div>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:14px; padding:12px 16px; background:#e3f2fd; color:#1565c0;
                    border-radius:8px; font-size:13px; border-left:4px solid #2196F3;">
            ℹ️ Değişiklikler veritabanına uygulandı. Harita sayfasını yenilediğinizde yeni koordinatlar görünecektir.
        </div>
        <div class="actions" style="margin-top:20px;">
            <a href="map.php" class="btn-back">🗺️ Haritaya Git</a>
            <a href="dashboard.php" class="btn-back">← Panoya Dön</a>
        </div>
        <script>
            // 3 saniye sonra haritaya otomatik yönlendir
            setTimeout(function(){ window.location.href = 'map.php'; }, 3000);
        </script>
    <?php endif; ?>
</div>
</body>
</html>

