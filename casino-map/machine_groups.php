<?php
session_start();

if(!isset($_SESSION['login']) || $_SESSION['role'] != 'admin'){
    header("Location: login.php");
    exit;
}

include("config.php");

if(isset($_POST['create_group'])){
    $group_name = $conn->real_escape_string($_POST['group_name']);
    $description = $conn->real_escape_string($_POST['description']);
    $conn->query("INSERT INTO machine_groups (group_name, description) VALUES ('$group_name', '$description')");
    $success = "Grup oluşturuldu!";
}

if(isset($_GET['delete_group'])){
    $group_id = intval($_GET['delete_group']);
    $conn->query("DELETE FROM machine_groups WHERE id = $group_id");
    header("Location: machine_groups.php?deleted=1");
    exit;
}

if(isset($_POST['update_group_machines'])){
    $group_id = intval($_POST['group_id']);
    $conn->query("DELETE FROM machine_group_relations WHERE group_id = $group_id");
    if(isset($_POST['machines']) && is_array($_POST['machines'])){
        foreach($_POST['machines'] as $machine_id){
            $machine_id = intval($machine_id);
            $conn->query("INSERT INTO machine_group_relations (machine_id, group_id) VALUES ($machine_id, $group_id)");
        }
    }
    header("Location: machine_groups.php?updated=1&group_id=$group_id");
    exit;
}

$groups = $conn->query("SELECT * FROM machine_groups ORDER BY group_name");
$all_machines = $conn->query("SELECT * FROM machines ORDER BY machine_no");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Makine Grupları</title>
    <style>
        body { font-family: Arial; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        h2 { margin: 0; color: #333; }
        .message { background: #4CAF50; color: white; padding: 10px 20px; border-radius: 5px; margin-bottom: 20px; }
        .grid { display: grid; grid-template-columns: 300px 1fr; gap: 20px; }
        .left-panel, .right-panel { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; color: #666; font-weight: bold; }
        input, textarea, select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; }
        button { padding: 10px 20px; background: #4CAF50; color: white; border: none; border-radius: 5px; cursor: pointer; }
        button:hover { background: #45a049; }
        .group-list { list-style: none; padding: 0; }
        .group-item { border-bottom: 1px solid #eee; padding: 10px; cursor: pointer; }
        .group-item:hover { background: #f9f9f9; }
        .group-item.active { background: #e3f2fd; border-left: 3px solid #4CAF50; }
        .group-name { font-weight: bold; margin-bottom: 5px; }
        .group-info { font-size: 12px; color: #666; }
        .group-actions { margin-top: 5px; }
        .group-actions a { color: #f44336; text-decoration: none; font-size: 12px; margin-right: 10px; }
        .machine-list { max-height: 400px; overflow-y: auto; border: 1px solid #eee; border-radius: 5px; padding: 10px; }
        .machine-item { padding: 5px; border-bottom: 1px solid #eee; }
        .machine-item label { display: flex; align-items: center; gap: 10px; cursor: pointer; }
        .export-btn { background: #2196F3; margin-top: 20px; width: 100%; padding: 15px; font-size: 16px; }
        .export-btn:hover { background: #1976D2; }
        .back-link a { color: #666; text-decoration: none; }
        .group-stats { margin-top: 20px; padding: 15px; background: #f9f9f9; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Makine Grupları Yönetimi</h2>
            <a href="dashboard.php" style="color: #666; text-decoration: none;">← Panoya Dön</a>
        </div>
        
        <?php if(isset($success)): ?><div class="message"><?php echo $success; ?></div><?php endif; ?>
        <?php if(isset($_GET['deleted'])): ?><div class="message">Grup silindi.</div><?php endif; ?>
        <?php if(isset($_GET['updated'])): ?><div class="message">Grup güncellendi.</div><?php endif; ?>
        
        <div class="grid">
            <div class="left-panel">
                <h3>Yeni Grup Oluştur</h3>
                <form method="post">
                    <div class="form-group"><label>Grup Adı:</label><input type="text" name="group_name" required></div>
                    <div class="form-group"><label>Açıklama:</label><textarea name="description" rows="3"></textarea></div>
                    <button type="submit" name="create_group">Grup Oluştur</button>
                </form>
                
                <h3 style="margin-top: 30px;">Mevcut Gruplar</h3>
                <div class="group-list">
                    <?php while($group = $groups->fetch_assoc()): 
                        $machine_count = $conn->query("SELECT COUNT(*) as count FROM machine_group_relations WHERE group_id = " . $group['id'])->fetch_assoc()['count'];
                    ?>
                    <div class="group-item <?php echo (isset($_GET['group_id']) && $_GET['group_id'] == $group['id']) ? 'active' : ''; ?>" onclick="window.location='?group_id=<?php echo $group['id']; ?>'">
                        <div class="group-name"><?php echo htmlspecialchars($group['group_name']); ?></div>
                        <div class="group-info"><?php echo $machine_count; ?> makine • <?php echo htmlspecialchars($group['description']); ?></div>
                        <div class="group-actions">
                            <a href="export_group.php?group_id=<?php echo $group['id']; ?>" target="_blank" style="color: #2196F3;">📥 Excel Aktar</a>
                            <a href="?delete_group=<?php echo $group['id']; ?>" onclick="return confirm('Grubu silmek istediğinize emin misiniz?')" style="color: #f44336;">🗑️ Sil</a>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
            
            <div class="right-panel">
                <?php if(isset($_GET['group_id'])): 
                    $group_id = intval($_GET['group_id']);
                    $group = $conn->query("SELECT * FROM machine_groups WHERE id = $group_id")->fetch_assoc();
                    $group_machines = [];
                    $result = $conn->query("SELECT machine_id FROM machine_group_relations WHERE group_id = $group_id");
                    while($row = $result->fetch_assoc()){ $group_machines[] = $row['machine_id']; }
                ?>
                    <h3><?php echo htmlspecialchars($group['group_name']); ?></h3>
                    <p><?php echo htmlspecialchars($group['description']); ?></p>
                    
                    <form method="post">
                        <input type="hidden" name="group_id" value="<?php echo $group_id; ?>">
                        <div class="machine-list">
                            <?php $all_machines->data_seek(0); while($machine = $all_machines->fetch_assoc()): 
                                $checked = in_array($machine['id'], $group_machines) ? 'checked' : '';
                            ?>
                            <div class="machine-item">
                                <label>
                                    <input type="checkbox" name="machines[]" value="<?php echo $machine['id']; ?>" <?php echo $checked; ?>>
                                    <strong><?php echo htmlspecialchars($machine['machine_no']); ?></strong> - <?php echo htmlspecialchars($machine['smibb_ip'] ?? ''); ?> (Z:<?php echo $machine['pos_z']; ?>)
                                </label>
                            </div>
                            <?php endwhile; ?>
                        </div>
                        <div style="margin-top: 20px; display: flex; gap: 10px;">
                            <button type="submit" name="update_group_machines">Seçimi Kaydet</button>
                            <button type="button" onclick="window.location='export_group.php?group_id=<?php echo $group_id; ?>'" style="background: #2196F3;">📥 Excel Aktar</button>
                        </div>
                    </form>
                    
                    <div class="group-stats">
                        <strong>Grup İstatistikleri:</strong><br>
                        Toplam Makine: <?php echo count($group_machines); ?><br>
                        Oluşturulma: <?php echo $group['created_at']; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 50px; color: #999;">
                        <p>Soldan bir grup seçin veya yeni bir grup oluşturun.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>