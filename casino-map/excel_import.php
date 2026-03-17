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

if(isset($_POST['upload'])){
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
        $count = 0;
        $skipped = 0;
        // Mevcut en büyük pozisyonu al; yeni makineleri onun ardına yerleştir
        $maxRow = $conn->query("SELECT COUNT(*) as cnt FROM machines")->fetch_assoc();
        $existingCount = intval($maxRow['cnt']);
        while(($data = fgetcsv($handle, 1000, ",")) !== FALSE){
            if(count($data) < 3){
                $skipped++;
                continue;
            }
            // Her makineyi 68px aralıklarla grid'e yerleştir (60px boyut + 8px boşluk)
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
        $upload_success = "$count satır yüklendi!" . ($skipped > 0 ? " $skipped satır yetersiz sütun sayısı nedeniyle atlandı." : "");
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>CSV Yükle</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        form { background: #f5f5f5; padding: 20px; border-radius: 5px; max-width: 500px; }
        button { background: #4CAF50; color: white; border: none; padding: 10px 20px; cursor: pointer; }
        button:hover { background: #45a049; }
        .error { color: red; margin-bottom: 10px; }
    </style>
</head>
<body>
    <h2>CSV / Excel Yükle</h2>
    
    <?php if(isset($upload_error)): ?>
        <p class="error"><?php echo htmlspecialchars($upload_error); ?></p>
    <?php endif; ?>
    <?php if(isset($upload_success)): ?>
        <p style='color:green; font-weight:bold;'><?php echo htmlspecialchars($upload_success); ?></p>
    <?php endif; ?>
    
    <form method="post" enctype="multipart/form-data">
        <input type="file" name="file" accept=".csv" required>
        <br><br>
        <button name="upload">Yükle</button>
    </form>
    
    <br>
    <a href="dashboard.php">← Panoya Dön</a>
</body>
</html>