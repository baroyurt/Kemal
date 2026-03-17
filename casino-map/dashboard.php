<?php
session_start();

if(!isset($_SESSION['login'])){
    header("Location: login.php");
    exit;
}

$role = $_SESSION['role'];
$username = $_SESSION['username'];
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Casino Map Panel</title>
    <style>
        body {
            font-family: Arial;
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        h2 {
            margin: 0;
            color: #333;
        }
        .user-info {
            color: #666;
            margin-top: 10px;
            font-size: 14px;
        }
        .menu {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .menu-item {
            border-bottom: 1px solid #eee;
        }
        .menu-item:last-child {
            border-bottom: none;
        }
        .menu-item a {
            display: block;
            padding: 15px 20px;
            color: #333;
            text-decoration: none;
            transition: background 0.3s;
        }
        .menu-item a:hover {
            background: #f9f9f9;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            margin-left: 10px;
        }
        .badge.admin {
            background: #4CAF50;
            color: white;
        }
        .badge.personel {
            background: #2196F3;
            color: white;
        }
        .logout {
            margin-top: 20px;
            text-align: right;
        }
        .logout a {
            color: #f44336;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Casino Map Panel</h2>
            <div class="user-info">
                Hoşgeldiniz, <strong><?php echo htmlspecialchars($username); ?></strong>
                <span class="badge <?php echo htmlspecialchars($role); ?>"><?php echo htmlspecialchars($role); ?></span>
            </div>
        </div>
        
        <div class="menu">
            <div class="menu-item">
                <a href="map.php">
                    🗺️ Makine Haritası
                    <small style="color: #666; display: block;">Makineleri görüntüle ve düzenle</small>
                </a>
            </div>
            
            <?php if($role == 'admin'): ?>
                <div class="menu-item">
                    <a href="excel_import.php">
                        📁 CSV / Excel Yükle & İndir
                        <small style="color: #666; display: block;">Makineleri toplu yükle veya dışa aktar</small>
                    </a>
                </div>
                
                <div class="menu-item">
                    <a href="machine_settings.php">
                        ⚙️ Makine Ayarları
                        <small style="color: #666; display: block;">Makine düzenle ve grup yönetimi</small>
                    </a>
                </div>

                <div class="menu-item">
                    <a href="users.php">
                        👤 Kullanıcı Yönetimi
                        <small style="color: #666; display: block;">Kullanıcı ekle, sil ve şifre değiştir</small>
                    </a>
                </div>

                <div class="menu-item">
                    <a href="update_database.php">
                        🔧 Veritabanı Güncelle
                        <small style="color: #666; display: block;">Yeni sürüm migration'larını uygula (bölge altyapısı vb.)</small>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="logout">
            <a href="logout.php">🚪 Çıkış Yap</a>
        </div>
    </div>
</body>
</html>