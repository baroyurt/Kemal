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

// ── CSV Şablon İndirme (Format C: Sıra + tam sütunlar) ───────────────────────
if(isset($_GET['download_csv_template'])){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="makine_sablonu.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM — Excel'in Türkçe karakterleri doğru okuması için
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Sıra','machine_no','smibb_ip','screen_ip','mac','machine_type','game_type','pos_z','pos_x','pos_y','rotation','note']);
    fputcsv($out, [1, '2925', '10.1.3.25',  '10.129.3.25',  '69-44', 'Apex / Curve', 'Elements x100', 1, 430, 1732,  90, '']);
    fputcsv($out, [2, '2926', '10.1.3.26',  '10.129.3.26',  '68-4f', 'Apex / Curve', 'Elements x100', 1, 430, 1795,  90, '']);
    fputcsv($out, [3, '2935', '10.5.7.35',  '10.133.7.35',  '61-f3', 'Apex / Curve', 'Elements x25',  5, 2931, 1347,  0, '']);
    fclose($out);
    exit;
}

// ── Excel Şablon İndirme (Format A: makine_no + tam sütunlar) ────────────────
if(isset($_GET['download_template'])){
    include_once("xlsx_helper.php");
    $headers = ['machine_no', 'smibb_ip', 'screen_ip', 'mac', 'machine_type', 'game_type', 'pos_z', 'pos_x', 'pos_y', 'rotation', 'note'];
    $rows = [
        ['MAKINE001', '192.168.1.100', '192.168.1.200', 'AA:BB:CC:DD:EE:FF', 'Slot', 'Green General', 0, 2036, 1340, 90, ''],
        ['MAKINE002', '192.168.1.101', '192.168.1.201', 'AA:BB:CC:DD:EE:00', 'Slot', 'Fruits Collection 2', 1, 2037, 1265, 90, ''],
    ];
    send_xlsx('makine_sablonu.xlsx', $headers, $rows);
}

// ── Mevcut Makineleri Excel Olarak Dışa Aktar ─────────────────────────────────
if(isset($_GET['export_machines'])){
    include_once("xlsx_helper.php");
    $headers = ['machine_no', 'smibb_ip', 'screen_ip', 'mac', 'machine_type', 'game_type', 'pos_z', 'pos_x', 'pos_y', 'rotation', 'note'];
    $rows = [];
    $exp = $conn->query("SELECT machine_no,smibb_ip,screen_ip,mac,machine_type,game_type,pos_z,pos_x,pos_y,rotation,note FROM machines ORDER BY pos_z, machine_no");
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
    // application/octet-stream dahil edildi: Windows/Excel'den kaydedilen CSV dosyaları
    $allowedTypes = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'];
    $mimeType = mime_content_type($file['tmp_name']);

    if($ext !== 'csv' || !in_array($mimeType, $allowedTypes)){
        $upload_error = "Sadece CSV dosyaları kabul edilir! (Algılanan tür: $mimeType)";
    } elseif($file['size'] > 2 * 1024 * 1024){
        $upload_error = "Dosya boyutu 2MB sınırını aşıyor!";
    } else {
        $handle = fopen($file['tmp_name'], "r");
        // UTF-8 BOM varsa atla
        $firstChunk = fread($handle, 3);
        if($firstChunk !== "\xEF\xBB\xBF") rewind($handle);

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $existingCount = intval($conn->query("SELECT COUNT(*) as cnt FROM machines")->fetch_assoc()['cnt']);
        $rowIndex  = 0;

        // ── Format tespiti: ilk satırı oku ──────────────────────────────────
        // Format A (tam):       machine_no, smibb_ip, screen_ip, mac, machine_type, game_type, pos_z, pos_x, pos_y, rotation, note
        // Format B (basit):     Sıra, Salon, Makine No, Marka, Model, Oyun Türü
        // Format C (tam+sıra):  Sıra, machine_no, smibb_ip, screen_ip, mac, machine_type, game_type, pos_z, pos_x, pos_y, rotation, note
        $firstRow = fgetcsv($handle, 1000, ",");
        $csvFormat = 'full'; // varsayılan
        if($firstRow !== false){
            $col0 = isset($firstRow[0]) ? mb_strtolower(trim($firstRow[0]), 'UTF-8') : '';
            $col1 = isset($firstRow[1]) ? mb_strtolower(trim($firstRow[1]), 'UTF-8') : '';
            $col2 = isset($firstRow[2]) ? mb_strtolower(trim($firstRow[2]), 'UTF-8') : '';
            if($col0 === 'machine_no'){
                // Format A başlık satırı — atla
                $csvFormat = 'full';
            } elseif(($col0 === 'sıra' || $col0 === 'sira') && $col1 === 'machine_no'){
                // Format C başlık satırı: Sıra + tam format — atla
                $csvFormat = 'full_shifted';
            } elseif($col0 === 'sıra' || $col0 === 'sira' || $col2 === 'makine no'){
                // Format B başlık satırı: Sıra, Salon, Makine No, ... — atla
                $csvFormat = 'simple';
            } else {
                // Başlık satırı değil, ilk veri satırı — işaretle ve formatı tahmin et
                $firstRow['__process'] = true;
                if(is_numeric(trim($firstRow[0] ?? '')) && isset($firstRow[2])){
                    // col[0] sayısal; col[2]'ye bak: IP adresi gibi görünüyorsa Format C, değilse Format B
                    $col2val = trim($firstRow[2] ?? '');
                    if(preg_match('/^\d{1,3}\.\d{1,3}/', $col2val)){
                        $csvFormat = 'full_shifted';
                    } else {
                        $csvFormat = 'simple';
                    }
                } else {
                    $csvFormat = 'full';
                }
            }
        }

        // ── Prepared statement'lar ──────────────────────────────────────────
        $check_stmt  = $conn->prepare("SELECT id FROM machines WHERE machine_no = ?");
        $update_full = $conn->prepare("UPDATE machines SET smibb_ip=?, screen_ip=?, mac=?, machine_type=?, game_type=?, brand=?, model=?, pos_z=?, pos_x=?, pos_y=?, rotation=?, note=? WHERE id=?");
        $insert_full = $conn->prepare("INSERT INTO machines(machine_no, smibb_ip, screen_ip, mac, machine_type, game_type, brand, model, pos_z, pos_x, pos_y, rotation, note) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $update_simple = $conn->prepare("UPDATE machines SET brand=?, model=?, game_type=? WHERE id=?");
        $insert_simple = $conn->prepare("INSERT INTO machines(machine_no, brand, model, game_type, pos_x, pos_y) VALUES(?,?,?,?,?,?)");

        // İşlenecek satırlar: önce firstRow (eğer işaretlendiyse), sonra geri kalanlar
        $processRows = [];
        if(isset($firstRow['__process']) && $firstRow['__process']){
            unset($firstRow['__process']);
            $processRows[] = $firstRow;
        }

        while(($data = fgetcsv($handle, 1000, ",")) !== FALSE){
            $processRows[] = $data;
        }
        fclose($handle);

        foreach($processRows as $data){
            if($csvFormat === 'simple'){
                // Format B: Sıra, Salon, Makine No, Marka, Model, Oyun Türü
                if(count($data) < 3) { $skipped++; continue; }
                $mn        = trim($data[2] ?? '');
                $brand     = trim($data[3] ?? '');
                $model_val = trim($data[4] ?? '');
                $game_type = trim($data[5] ?? '');
                // Geçersiz veya boş makine no'yu atla
                if($mn === '' || !is_numeric($mn)) { $skipped++; continue; }

                $existing_id = null;
                $check_stmt->bind_param("s", $mn);
                $check_stmt->execute();
                $check_stmt->bind_result($existing_id);
                $check_stmt->fetch();
                $check_stmt->free_result();

                if($existing_id){
                    $update_simple->bind_param("sssi", $brand, $model_val, $game_type, $existing_id);
                    $update_simple->execute();
                    $updated++;
                } else {
                    $seqIndex = $existingCount + $rowIndex;
                    $gridCol  = $seqIndex % 15;
                    $gridRow  = intval($seqIndex / 15);
                    $px = 50 + $gridCol * 68;
                    $py = 50 + $gridRow * 68;
                    $insert_simple->bind_param("ssssii", $mn, $brand, $model_val, $game_type, $px, $py);
                    $insert_simple->execute();
                    $inserted++;
                    $rowIndex++;
                }
            } elseif($csvFormat === 'full_shifted'){
                // Format C: Sıra, machine_no, smibb_ip, screen_ip, mac, machine_type, game_type, pos_z, pos_x, pos_y, rotation, note
                if(count($data) < 5) { $skipped++; continue; }
                $mn           = trim($data[1]);
                $smibb_ip     = trim($data[2]);
                $screen_ip    = isset($data[3]) ? trim($data[3]) : '';
                $mac          = isset($data[4]) ? trim($data[4]) : '';
                $machine_type = isset($data[5]) ? trim($data[5]) : '';
                $game_type    = isset($data[6]) ? trim($data[6]) : '';
                $pz           = isset($data[7])  && $data[7]  !== '' ? intval($data[7])  : 0;
                $px           = isset($data[8])  && $data[8]  !== '' ? intval($data[8])  : null;
                $py           = isset($data[9])  && $data[9]  !== '' ? intval($data[9])  : null;
                $rot          = isset($data[10]) && $data[10] !== '' ? intval($data[10]) : 0;
                $note         = isset($data[11]) ? trim($data[11]) : '';
                $brand        = isset($data[12]) ? trim($data[12]) : '';
                $model_val    = isset($data[13]) ? trim($data[13]) : '';
                if($mn === '') { $skipped++; continue; }

                $existing_id = null;
                $check_stmt->bind_param("s", $mn);
                $check_stmt->execute();
                $check_stmt->bind_result($existing_id);
                $check_stmt->fetch();
                $check_stmt->free_result();

                if($existing_id){
                    if($px === null || $py === null){
                        $cur = $conn->query("SELECT pos_x, pos_y FROM machines WHERE id=" . intval($existing_id))->fetch_assoc();
                        if($px === null) $px = intval($cur['pos_x']);
                        if($py === null) $py = intval($cur['pos_y']);
                    }
                    $update_full->bind_param("sssssssiiiisi", $smibb_ip, $screen_ip, $mac, $machine_type, $game_type, $brand, $model_val, $pz, $px, $py, $rot, $note, $existing_id);
                    $update_full->execute();
                    $updated++;
                } else {
                    if($px === null || $py === null){
                        $seqIndex = $existingCount + $rowIndex;
                        $gridCol  = $seqIndex % 15;
                        $gridRow  = intval($seqIndex / 15);
                        if($px === null) $px = 50 + $gridCol * 68;
                        if($py === null) $py = 50 + $gridRow * 68;
                    }
                    $insert_full->bind_param("ssssssssiiiis", $mn, $smibb_ip, $screen_ip, $mac, $machine_type, $game_type, $brand, $model_val, $pz, $px, $py, $rot, $note);
                    $insert_full->execute();
                    $inserted++;
                    $rowIndex++;
                }
            } else {
                if(count($data) < 4) { $skipped++; continue; }
                $mn           = trim($data[0]);
                $smibb_ip     = trim($data[1]);
                $screen_ip    = isset($data[2]) ? trim($data[2]) : '';
                $mac          = trim($data[3]);
                $machine_type = isset($data[4]) ? trim($data[4]) : '';
                $game_type    = isset($data[5]) ? trim($data[5]) : '';
                $pz           = isset($data[6]) && $data[6] !== '' ? intval($data[6]) : 0;
                $px  = isset($data[7])  && $data[7]  !== '' ? intval($data[7])  : null;
                $py  = isset($data[8])  && $data[8]  !== '' ? intval($data[8])  : null;
                $rot = isset($data[9])  && $data[9]  !== '' ? intval($data[9])  : 0;
                $note         = isset($data[10]) ? trim($data[10]) : '';
                $brand        = isset($data[11]) ? trim($data[11]) : '';
                $model_val    = isset($data[12]) ? trim($data[12]) : '';

                $existing_id = null;
                $check_stmt->bind_param("s", $mn);
                $check_stmt->execute();
                $check_stmt->bind_result($existing_id);
                $check_stmt->fetch();
                $check_stmt->free_result();

                if($existing_id){
                    if($px === null || $py === null){
                        $cur = $conn->query("SELECT pos_x, pos_y FROM machines WHERE id=" . intval($existing_id))->fetch_assoc();
                        if($px === null) $px = intval($cur['pos_x']);
                        if($py === null) $py = intval($cur['pos_y']);
                    }
                    $update_full->bind_param("sssssssiiiisi", $smibb_ip, $screen_ip, $mac, $machine_type, $game_type, $brand, $model_val, $pz, $px, $py, $rot, $note, $existing_id);
                    $update_full->execute();
                    $updated++;
                } else {
                    if($px === null || $py === null){
                        $seqIndex = $existingCount + $rowIndex;
                        $gridCol  = $seqIndex % 15;
                        $gridRow  = intval($seqIndex / 15);
                        if($px === null) $px = 50 + $gridCol * 68;
                        if($py === null) $py = 50 + $gridRow * 68;
                    }
                    $insert_full->bind_param("ssssssssiiiis", $mn, $smibb_ip, $screen_ip, $mac, $machine_type, $game_type, $brand, $model_val, $pz, $px, $py, $rot, $note);
                    $insert_full->execute();
                    $inserted++;
                    $rowIndex++;
                }
            }
        }
        $check_stmt->close();
        $update_full->close();
        $insert_full->close();
        $update_simple->close();
        $insert_simple->close();

        $upload_success = "$inserted makine eklendi, $updated makine güncellendi." . ($skipped > 0 ? " $skipped satır atlandı." : "");

        // ── Otomatik Koordinat Düzeltme (fix_positions) ──────────────────────
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
            <strong>Format A (tam):</strong> <code>machine_no, smibb_ip, screen_ip, mac, machine_type, game_type, pos_z, pos_x, pos_y, rotation, note</code><br>
            <strong>Format B (basit):</strong> <code>Sıra, Salon, Makine No, Marka, Model, Oyun Türü</code><br>
            <strong>Format C (tam+sıra):</strong> <code>Sıra, machine_no, smibb_ip, screen_ip, mac, machine_type, game_type, pos_z, pos_x, pos_y, rotation, note</code><br>
            Format otomatik algılanır. Makine zaten varsa bilgileri <strong>güncellenir</strong>; yoksa yeni olarak eklenir. Koordinat sütunları boş bırakılabilir.
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
            <a href="?download_csv_template=1" class="btn btn-green">📄 CSV Şablonu İndir (Örnek)</a>
            <a href="?download_template=1" class="btn btn-blue">📋 Excel Şablonu İndir</a>
            <a href="?export_machines=1" class="btn btn-gray">💾 Tüm Makineleri Excel Olarak İndir</a>
        </div>
    </div>
</div>
</body>
</html>