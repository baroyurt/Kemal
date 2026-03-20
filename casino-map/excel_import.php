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
    include_once("xlsx_helper.php");
    $headers = ['machine_no', 'ip', 'mac', 'pos_z', 'pos_x', 'pos_y', 'rotation', 'note'];
    $rows = [
        ['MAKINE001', '192.168.1.100', 'AA:BB:CC:DD:EE:FF', 0, 2036, 1340, 90, ''],
        ['MAKINE002', '192.168.1.101', 'AA:BB:CC:DD:EE:00', 0, 2037, 1265, 90, ''],
    ];
    send_xlsx('makine_sablonu.xlsx', $headers, $rows);
}

// ── Mevcut Makineleri Excel Olarak Dışa Aktar ─────────────────────────────────
if(isset($_GET['export_machines'])){
    include_once("xlsx_helper.php");
    $headers = ['machine_no', 'ip', 'mac', 'pos_z', 'pos_x', 'pos_y', 'rotation', 'note'];
    $rows = [];
    $exp = $conn->query("SELECT machine_no,ip,mac,pos_z,pos_x,pos_y,rotation,note FROM machines ORDER BY pos_z, machine_no");
    while($r = $exp->fetch_assoc()){
        $rows[] = array_values($r);
    }
    send_xlsx('makineler_' . date('Y-m-d') . '.xlsx', $headers, $rows);
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

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $existingCount = intval($conn->query("SELECT COUNT(*) as cnt FROM machines")->fetch_assoc()['cnt']);
        $rowIndex  = 0; // INSERT sırası için sayaç

        $check_stmt  = $conn->prepare("SELECT id FROM machines WHERE machine_no = ?");
        $update_stmt = $conn->prepare("UPDATE machines SET ip=?, mac=?, pos_z=?, pos_x=?, pos_y=?, rotation=?, note=? WHERE id=?");
        $insert_stmt = $conn->prepare("INSERT INTO machines(machine_no, ip, mac, pos_z, pos_x, pos_y, rotation, note) VALUES(?,?,?,?,?,?,?,?)");

        while(($data = fgetcsv($handle, 1000, ",")) !== FALSE){
            if(count($data) < 3 || trim($data[0]) === 'machine_no') { $skipped++; continue; }

            $mn  = trim($data[0]);
            $ip  = trim($data[1]);
            $mac = trim($data[2]);
            // CSV'deki koordinat sütunları (opsiyonel)
            $pz  = isset($data[3]) && $data[3] !== '' ? intval($data[3]) : 0;
            $px  = isset($data[4]) && $data[4] !== '' ? intval($data[4]) : null;
            $py  = isset($data[5]) && $data[5] !== '' ? intval($data[5]) : null;
            $rot = isset($data[6]) && $data[6] !== '' ? intval($data[6]) : 0;
            $note = isset($data[7]) ? trim($data[7]) : '';

            // Makine var mı kontrol et
            $check_stmt->bind_param("s", $mn);
            $check_stmt->execute();
            $check_stmt->bind_result($existing_id);
            $check_stmt->fetch();
            $check_stmt->free_result();

            if($existing_id){
                // Koordinat CSV'de yoksa mevcut değerleri koru
                if($px === null || $py === null){
                    $cur = $conn->query("SELECT pos_x, pos_y FROM machines WHERE id=" . intval($existing_id))->fetch_assoc();
                    if($px === null) $px = intval($cur['pos_x']);
                    if($py === null) $py = intval($cur['pos_y']);
                }
                $update_stmt->bind_param("ssiiiisi", $ip, $mac, $pz, $px, $py, $rot, $note, $existing_id);
                $update_stmt->execute();
                $updated++;
            } else {
                // Yeni makine — koordinat yoksa grid hesapla
                if($px === null || $py === null){
                    $seqIndex = $existingCount + $rowIndex;
                    $gridCol  = $seqIndex % 15;
                    $gridRow  = intval($seqIndex / 15);
                    if($px === null) $px = 50 + $gridCol * 68;
                    if($py === null) $py = 50 + $gridRow * 68;
                }
                $insert_stmt->bind_param("sssiiisi", $mn, $ip, $mac, $pz, $px, $py, $rot, $note);
                $insert_stmt->execute();
                $inserted++;
                $rowIndex++;
            }
        }
        $check_stmt->close();
        $update_stmt->close();
        $insert_stmt->close();
        fclose($handle);

        $upload_success = "$inserted makine eklendi, $updated makine güncellendi." . ($skipped > 0 ? " $skipped satır atlandı." : "");

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
            CSV formatı: <strong>machine_no, ip, mac, pos_z, pos_x, pos_y, rotation, note</strong> (başlık satırı opsiyonel)<br>
            Makine zaten varsa bilgileri <strong>güncellenir</strong>; yoksa yeni olarak eklenir. Koordinat sütunları boş bırakılabilir.
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
            <a href="?download_template=1" class="btn btn-blue">📋 Excel Şablonu İndir</a>
            <a href="?export_machines=1" class="btn btn-gray">💾 Tüm Makineleri Excel Olarak İndir</a>
        </div>
    </div>
</div>
</body>
</html>