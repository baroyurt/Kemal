<?php
session_start();

if(!isset($_SESSION['login'])){
    header("Location: login.php");
    exit;
}

if($_SESSION['role'] != 'admin'){
    header("Location: login.php");
    exit;
}

include("config.php");

// ── CSV Şablon İndirme ────────────────────────────────────────────────────────
if(isset($_GET['download_template'])){
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="makine_sablonu.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    echo "machine_no,ip,mac\n";
    echo "MAKINE001,192.168.1.100,AA:BB:CC:DD:EE:FF\n";
    echo "MAKINE002,192.168.1.101,AA:BB:CC:DD:EE:00\n";
    exit;
}

// ── Mevcut Makineleri CSV Olarak Dışa Aktar ───────────────────────────────────
if(isset($_GET['export_machines'])){
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="makineler_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    echo "machine_no,ip,mac,pos_z,pos_x,pos_y,rotation,note\n";
    $exp = $conn->query("SELECT machine_no,ip,mac,pos_z,pos_x,pos_y,rotation,note FROM machines ORDER BY pos_z, machine_no");
    while($r = $exp->fetch_assoc()){
        echo implode(',', array_map(function($v){ return '"' . str_replace('"', '""', $v ?? '') . '"'; }, $r)) . "\n";
    }
    exit;
}

// ── CSV Yükleme ───────────────────────────────────────────────────────────────
if(isset($_POST['upload'])){
    csrf_verify();
    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedTypes = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];
    $mimeType = mime_content_type($file['tmp_name']);

    if($ext !== 'csv' || !in_array($mimeType, $allowedTypes)){
        $upload_error = "Sadece CSV dosyaları kabul edilir!";
    } elseif($file['size'] > 1024 * 1024){
        $upload_error = "Dosya boyutu 1MB sınırını aşıyor!";
    } else {
        $handle = fopen($file['tmp_name'], "r");
        // UTF-8 BOM varsa atla
        $firstChunk = fread($handle, 3);
        if($firstChunk !== "\xEF\xBB\xBF") rewind($handle);
        
        $count = 0;
        $skipped = 0;
        $maxRow = $conn->query("SELECT COUNT(*) as cnt FROM machines")->fetch_assoc();
        $existingCount = intval($maxRow['cnt']);

        while(($data = fgetcsv($handle, 1000, ",")) !== FALSE){
            if(count($data) < 3 || trim($data[0]) === 'machine_no') { $skipped++; continue; }
            $seqIndex = $existingCount + $count;
            $gridCol  = $seqIndex % 15;
            $gridRow  = intval($seqIndex / 15);
            $posX = 50 + $gridCol * 68;
            $posY = 50 + $gridRow * 68;
            $stmt = $conn->prepare("INSERT INTO machines(machine_no, ip, mac, pos_x, pos_y, rotation) VALUES(?, ?, ?, ?, ?, 0)");
            $stmt->bind_param("sssii", $data[0], $data[1], $data[2], $posX, $posY);
            $stmt->execute();
            $stmt->close();
            $count++;
        }
        fclose($handle);
        $upload_success = "$count makine yüklendi!" . ($skipped > 0 ? " $skipped satır atlandı." : "");

        // ── Otomatik Koordinat Düzeltme (fix_positions) ──────────────────────
        // Bilinen makinelerin koordinatlarını otomatik olarak güncelle
        include_once("fix_positions_data.php");
        $fixes_ok = 0;
        if(isset($fixes) && is_array($fixes)){
            $fixes_stmt = $conn->prepare("UPDATE machines SET pos_x = ?, pos_y = ?, rotation = ? WHERE machine_no = ?");
            foreach($fixes as $machineNo => [$fx, $fy, $frot]){
                $fixes_stmt->bind_param("iiis", $fx, $fy, $frot, $machineNo);
                $fixes_stmt->execute();
                if($fixes_stmt->affected_rows > 0) $fixes_ok++;
            }
            $fixes_stmt->close();
        }
        if($fixes_ok > 0){
            $upload_success .= " Koordinat düzeltme: $fixes_ok makine güncellendi.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>CSV / Excel Yükle & İndir</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 700px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        h2 { margin: 0; color: #333; }
        .card { background: white; padding: 24px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .card h3 { margin: 0 0 16px; color: #333; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .error { color: #c62828; background: #ffebee; padding: 10px 14px; border-radius: 5px; margin-bottom: 12px; border-left: 3px solid #f44336; }
        .success { color: #2e7d32; background: #e8f5e9; padding: 10px 14px; border-radius: 5px; margin-bottom: 12px; border-left: 3px solid #4CAF50; font-weight: bold; }
        .file-input-wrap { border: 2px dashed #4CAF50; border-radius: 8px; padding: 24px; text-align: center; cursor: pointer; transition: background 0.2s; margin-bottom: 12px; }
        .file-input-wrap:hover { background: #f0fff0; }
        .file-input-wrap input[type="file"] { display: none; }
        .file-input-wrap label { cursor: pointer; color: #4CAF50; font-size: 15px; display: block; }
        .file-input-wrap .file-name { margin-top: 8px; color: #666; font-size: 13px; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: bold; text-decoration: none; transition: all 0.2s; }
        .btn-green { background: #4CAF50; color: white; }
        .btn-green:hover { background: #388E3C; }
        .btn-blue { background: #2196F3; color: white; }
        .btn-blue:hover { background: #1565C0; }
        .btn-gray { background: #607D8B; color: white; }
        .btn-gray:hover { background: #455A64; }
        .btn-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
        .info-box { background: #e3f2fd; color: #1565c0; padding: 12px 16px; border-radius: 6px; font-size: 13px; border-left: 3px solid #2196F3; line-height: 1.6; margin-bottom: 12px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h2>📁 CSV / Excel Yükle &amp; İndir</h2>
        <a href="dashboard.php" style="color:#666; text-decoration:none; font-size:14px;">← Panoya Dön</a>
    </div>

    <!-- Yükleme Kartı -->
    <div class="card">
        <h3>📤 CSV Yükle</h3>
        <div class="info-box">
            CSV formatı: <strong>machine_no, ip, mac</strong> (başlık satırı opsiyonel)<br>
            Yükleme sonrası bilinen makinelerin koordinatları <strong>otomatik düzeltilir</strong>.
        </div>
        <?php if(isset($upload_error)): ?>
            <div class="error"><?php echo htmlspecialchars($upload_error); ?></div>
        <?php endif; ?>
        <?php if(isset($upload_success)): ?>
            <div class="success">✅ <?php echo htmlspecialchars($upload_success); ?></div>
            <div style="margin-top:10px;">
                <a href="map.php" class="btn btn-green">🗺️ Haritaya Git</a>
            </div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" id="uploadForm">
            <?php echo csrf_field(); ?>
            <div class="file-input-wrap" onclick="document.getElementById('csvFile').click()">
                <label>📂 CSV dosyası seçmek için tıklayın</label>
                <input type="file" id="csvFile" name="file" accept=".csv" required onchange="document.querySelector('.file-name').textContent = this.files[0]?.name || ''">
                <div class="file-name"></div>
            </div>
            <button type="submit" name="upload" class="btn btn-green">⬆️ Yükle ve Düzelt</button>
        </form>
    </div>

    <!-- İndirme Kartı -->
    <div class="card">
        <h3>📥 İndir</h3>
        <div class="btn-row">
            <a href="?download_template=1" class="btn btn-blue">📋 CSV Şablonu İndir</a>
            <a href="?export_machines=1" class="btn btn-gray">💾 Tüm Makineleri CSV Olarak İndir</a>
        </div>
    </div>
</div>
</body>
</html>