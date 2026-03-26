<?php
session_start();

if(!isset($_SESSION['login'])){
    header("Location: login.php");
    exit;
}

$is_admin = ($_SESSION['role'] === 'admin');

// Aktif sekme: 'edit' veya 'groups'
// personel sadece 'groups' sekmesini görebilir
$allowed_tabs = $is_admin ? ['edit','groups'] : ['groups'];
$tab_default  = $is_admin ? 'edit' : 'groups';
$tab = isset($_GET['tab']) && in_array($_GET['tab'], $allowed_tabs) ? $_GET['tab'] : $tab_default;

// edit sekmesine personel erişemez
if(!$is_admin && $tab !== 'groups'){
    header("Location: machine_settings.php?tab=groups");
    exit;
}

// Her iki sekme için gereken tüm POST işlemleri burada

include("config.php");

// AJAX: tüm makine ID'lerini JSON olarak döndür (toplu seçim için)
if ($tab === 'edit' && $is_admin && isset($_GET['get_all_ids'])) {
    $search_term = trim($_GET['search'] ?? '');
    $ids = [];
    if (!empty($search_term)) {
        $like = '%' . $search_term . '%';
        $stmt = $conn->prepare("SELECT id FROM machines WHERE machine_no LIKE ? OR smibb_ip LIKE ? OR mac LIKE ? OR note LIKE ? ORDER BY pos_z, machine_no");
        $stmt->bind_param("ssss", $like, $like, $like, $like);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
    } else {
        $result = $conn->query("SELECT id FROM machines ORDER BY pos_z, machine_no");
    }
    while ($row = $result->fetch_assoc()) $ids[] = $row['id'];
    header('Content-Type: application/json');
    echo json_encode($ids);
    exit;
}

// AJAX: belirli bir grubun makine ID'lerini döndür
if ($tab === 'edit' && $is_admin && isset($_GET['get_group_ids'])) {
    $gid = intval($_GET['get_group_ids']);
    $ids = [];
    $result = $conn->query("SELECT machine_id FROM machine_group_relations WHERE group_id = $gid");
    while ($row = $result->fetch_assoc()) $ids[] = $row['machine_id'];
    header('Content-Type: application/json');
    echo json_encode($ids);
    exit;
}

/* ══════════════════════════════════════════════
   TAB: MAKİNE DÜZENLE (edit) — POST işlemleri
   ══════════════════════════════════════════════ */
if($tab === 'edit'){

    // Toplu silme
    if(isset($_POST['bulk_delete'])){
        csrf_verify();
        if(isset($_POST['selected_machines']) && is_array($_POST['selected_machines'])){
            $selected_ids = implode(',', array_map('intval', $_POST['selected_machines']));
            $conn->query("DELETE FROM machines WHERE id IN ($selected_ids)");
            $deleted_count = $conn->affected_rows;
            header("Location: machine_settings.php?tab=edit&deleted=$deleted_count");
            exit;
        }
    }

    // Toplu Z koordinatı güncelleme
    if(isset($_POST['bulk_update_z'])){
        csrf_verify();
        if(isset($_POST['selected_machines']) && is_array($_POST['selected_machines']) && isset($_POST['new_z'])){
            $new_z = intval($_POST['new_z']);
            $selected_ids = implode(',', array_map('intval', $_POST['selected_machines']));
            $conn->query("UPDATE machines SET pos_z = $new_z WHERE id IN ($selected_ids)");
            $updated_count = $conn->affected_rows;
            header("Location: machine_settings.php?tab=edit&updated_z=$updated_count");
            exit;
        }
    }

    // Toplu not ekleme/güncelleme
    if(isset($_POST['bulk_update_note'])){
        csrf_verify();
        if(isset($_POST['selected_machines']) && is_array($_POST['selected_machines']) && isset($_POST['bulk_note'])){
            $bulk_note = $_POST['bulk_note'];
            $selected_ids = implode(',', array_map('intval', $_POST['selected_machines']));
            $stmt = $conn->prepare("UPDATE machines SET note = ? WHERE id IN ($selected_ids)");
            $stmt->bind_param("s", $bulk_note);
            $stmt->execute();
            $updated_count = $stmt->affected_rows;
            $stmt->close();
            header("Location: machine_settings.php?tab=edit&updated_note=$updated_count");
            exit;
        }
    }

    // Toplu kopyalama
    if(isset($_POST['bulk_duplicate'])){
        csrf_verify();
        if(isset($_POST['selected_machines']) && is_array($_POST['selected_machines'])){
            $dup_stmt = $conn->prepare(
                "INSERT INTO machines (machine_no, smibb_ip, screen_ip, mac, machine_type, game_type, pos_x, pos_y, pos_z, rotation, note)
                 SELECT CONCAT(machine_no, ' (Kopya)'), smibb_ip, screen_ip, mac, machine_type, game_type, pos_x + 20, pos_y + 20, pos_z, rotation, CONCAT(COALESCE(note,''), ' (Kopya)')
                 FROM machines WHERE id = ?"
            );
            foreach($_POST['selected_machines'] as $dup_id){
                $dup_id = intval($dup_id);
                $dup_stmt->bind_param("i", $dup_id);
                $dup_stmt->execute();
            }
            $dup_stmt->close();
            $duplicated_count = count($_POST['selected_machines']);
            header("Location: machine_settings.php?tab=edit&duplicated=$duplicated_count");
            exit;
        }
    }

    // Tekil silme
    if(isset($_GET['delete'])){
        $id = intval($_GET['delete']);
        $stmt = $conn->prepare("DELETE FROM machines WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        header("Location: machine_settings.php?tab=edit&deleted=1");
        exit;
    }

    // Tekil ekleme/güncelleme
    if(isset($_POST['save'])){
        csrf_verify();
        $machine_no   = $_POST['machine_no'];
        $smibb_ip     = $_POST['smibb_ip'];
        $screen_ip    = $_POST['screen_ip'] ?? '';
        $mac          = $_POST['mac'];
        $machine_type = $_POST['machine_type'] ?? '';
        $game_type    = $_POST['game_type'] ?? '';
        $pos_z        = intval($_POST['pos_z']);
        $note         = $_POST['note'];

        if(isset($_POST['id']) && !empty($_POST['id'])){
            $id = intval($_POST['id']);
            $stmt = $conn->prepare("UPDATE machines SET machine_no=?, smibb_ip=?, screen_ip=?, mac=?, machine_type=?, game_type=?, pos_z=?, note=? WHERE id=?");
            $stmt->bind_param("ssssssisi", $machine_no, $smibb_ip, $screen_ip, $mac, $machine_type, $game_type, $pos_z, $note, $id);
            $stmt->execute();
            $stmt->close();
            $message = "Makine güncellendi";
        } else {
            $stmt = $conn->prepare("INSERT INTO machines (machine_no, smibb_ip, screen_ip, mac, machine_type, game_type, pos_z, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssis", $machine_no, $smibb_ip, $screen_ip, $mac, $machine_type, $game_type, $pos_z, $note);
            $stmt->execute();
            $stmt->close();
            $message = "Yeni makine eklendi";
        }
        header("Location: machine_settings.php?tab=edit&success=" . urlencode($message));
        exit;
    }

    // Sayfalama & arama
    $page  = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $limit = 20;
    $offset = ($page - 1) * $limit;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    if(!empty($search)){
        $like = '%' . $search . '%';
        $cstmt = $conn->prepare("SELECT COUNT(*) as count FROM machines WHERE machine_no LIKE ? OR smibb_ip LIKE ? OR mac LIKE ? OR note LIKE ?");
        $cstmt->bind_param("ssss", $like, $like, $like, $like);
        $cstmt->execute();
        $total_machines = $cstmt->get_result()->fetch_assoc()['count'];
        $cstmt->close();
        $dstmt = $conn->prepare("SELECT * FROM machines WHERE machine_no LIKE ? OR smibb_ip LIKE ? OR mac LIKE ? OR note LIKE ? ORDER BY pos_z, machine_no LIMIT ?, ?");
        $dstmt->bind_param("ssssii", $like, $like, $like, $like, $offset, $limit);
        $dstmt->execute();
        $machines = $dstmt->get_result();
        $dstmt->close();
    } else {
        $total_machines = $conn->query("SELECT COUNT(*) as count FROM machines")->fetch_assoc()['count'];
        $dstmt = $conn->prepare("SELECT * FROM machines ORDER BY pos_z, machine_no LIMIT ?, ?");
        $dstmt->bind_param("ii", $offset, $limit);
        $dstmt->execute();
        $machines = $dstmt->get_result();
        $dstmt->close();
    }
    $total_pages = ceil($total_machines / $limit);

    $z_levels = [0=>'Yüksek Tavan', 1=>'Alçak Tavan', 2=>'Yeni Vip Salon', 3=>'Alt Salon'];
    // Grupları toplu araç çubuğu için yükle
    $groups_for_bulk = $conn->query("SELECT id, group_name FROM machine_groups ORDER BY group_name");
}

/* ══════════════════════════════════════════════
   TAB: MAKİNE GRUPLARI (groups) — POST işlemleri
   ══════════════════════════════════════════════ */
if($tab === 'groups'){

    if($is_admin && isset($_POST['create_group'])){
        csrf_verify();
        $stmt = $conn->prepare("INSERT INTO machine_groups (group_name, description) VALUES (?, ?)");
        $stmt->bind_param("ss", $_POST['group_name'], $_POST['description']);
        $stmt->execute();
        $stmt->close();
        $success = "Grup oluşturuldu!";
    }

    if($is_admin && isset($_GET['delete_group'])){
        $group_id = intval($_GET['delete_group']);
        $stmt = $conn->prepare("DELETE FROM machine_groups WHERE id = ?");
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $stmt->close();
        header("Location: machine_settings.php?tab=groups&deleted=1");
        exit;
    }

    if($is_admin && isset($_POST['update_group_machines'])){
        csrf_verify();
        $group_id = intval($_POST['group_id']);
        $stmt = $conn->prepare("DELETE FROM machine_group_relations WHERE group_id = ?");
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $stmt->close();
        if(isset($_POST['machines']) && is_array($_POST['machines'])){
            $stmt = $conn->prepare("INSERT INTO machine_group_relations (machine_id, group_id) VALUES (?, ?)");
            foreach($_POST['machines'] as $machine_id){
                $machine_id = intval($machine_id);
                $stmt->bind_param("ii", $machine_id, $group_id);
                $stmt->execute();
            }
            $stmt->close();
        }
        header("Location: machine_settings.php?tab=groups&updated=1&group_id=$group_id");
        exit;
    }

    $groups       = $conn->query("SELECT * FROM machine_groups ORDER BY group_name");
    $all_machines = $conn->query("SELECT * FROM machines ORDER BY machine_no");
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Makine Ayarları</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: white; padding: 16px 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 0; display: flex; justify-content: space-between; align-items: center; border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
        h2 { margin: 0; color: #333; font-size: 20px; }
        /* Tab bar */
        .tab-bar { display: flex; background: white; border-bottom: 2px solid #4CAF50; box-shadow: 0 2px 5px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .tab-link { padding: 12px 28px; color: #666; text-decoration: none; font-size: 14px; font-weight: bold; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: all 0.2s; display: flex; align-items: center; gap: 6px; }
        .tab-link:hover { color: #4CAF50; background: #f9f9f9; }
        .tab-link.active { color: #4CAF50; border-bottom: 3px solid #4CAF50; background: white; }
        /* Messages */
        .message { background: #4CAF50; color: white; padding: 10px 20px; border-radius: 5px; margin-bottom: 20px; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        /* ─── Edit tab styles ─── */
        .form-box { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 20px; }
        .form-grid .form-group-wide { grid-column: 1 / -1; }
        label { display: block; margin-bottom: 5px; color: #666; font-weight: bold; }
        input[type="text"], textarea, select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; }
        textarea { height: 100px; resize: vertical; }
        button { padding: 10px 20px; background: #4CAF50; color: white; border: none; border-radius: 5px; cursor: pointer; margin-right: 10px; transition: all 0.3s; }
        button:hover { background: #45a049; transform: translateY(-2px); box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
        .cancel-btn { background: #999; }
        .cancel-btn:hover { background: #777; }
        .bulk-toolbar { background: #2196F3; color: white; padding: 15px 20px; border-radius: 10px; margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 15px; align-items: center; animation: slideDown 0.3s ease; }
        .bulk-toolbar.hidden { display: none; }
        .bulk-toolbar select, .bulk-toolbar input, .bulk-toolbar textarea { padding: 8px; border: none; border-radius: 5px; min-width: 150px; }
        .bulk-toolbar textarea { width: 300px; height: 60px; }
        .bulk-toolbar button { background: white; color: #2196F3; margin: 0; }
        .bulk-toolbar .close-btn { background: #f44336; color: white; margin-left: auto; }
        .selection-info { font-weight: bold; margin-right: 15px; }
        .table-container { background: white; border-radius: 10px; overflow-x: auto; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; min-width: 1000px; }
        th { background: #4CAF50; color: white; padding: 12px; text-align: left; position: sticky; top: 0; }
        td { padding: 12px; border-bottom: 1px solid #eee; }
        tr:hover { background: #f9f9f9; }
        tr.selected { background: #e3f2fd; }
        input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        .action-btn { padding: 5px 10px; margin: 0 3px; border: none; border-radius: 3px; cursor: pointer; text-decoration: none; font-size: 12px; display: inline-block; }
        .edit-btn { background: #2196F3; color: white; }
        .delete-btn { background: #f44336; color: white; }
        .note-cell { max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .z-badge { background: #4CAF50; color: white; padding: 3px 8px; border-radius: 12px; font-size: 11px; display: inline-block; }
        .search-box { display: flex; gap: 10px; margin-bottom: 20px; }
        .search-box input { flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
        .search-box button { padding: 10px 20px; }
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 12px; background: white; border: 1px solid #ddd; border-radius: 5px; text-decoration: none; color: #333; }
        .pagination a:hover { background: #f0f0f0; }
        .pagination .active { background: #4CAF50; color: white; border-color: #4CAF50; }
        /* ─── Groups tab styles ─── */
        .grid { display: grid; grid-template-columns: 300px 1fr; gap: 20px; }
        .left-panel, .right-panel { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
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
        .group-stats { margin-top: 20px; padding: 15px; background: #f9f9f9; border-radius: 5px; }
    </style>
</head>
<body>
<div class="container">
    <!-- Header -->
    <div class="header">
        <h2>⚙️ Makine Ayarları</h2>
        <a href="dashboard.php" style="color: #666; text-decoration: none; font-size: 14px;">← Panoya Dön</a>
    </div>

    <!-- Tab Bar -->
    <div class="tab-bar">
        <?php if($is_admin): ?>
        <a href="machine_settings.php?tab=edit" class="tab-link <?php echo $tab==='edit'?'active':''; ?>">
            ✏️ Makine Düzenle
        </a>
        <?php endif; ?>
        <a href="machine_settings.php?tab=groups" class="tab-link <?php echo $tab==='groups'?'active':''; ?>">
            👥 Makine Grupları
        </a>
    </div>

<?php if($tab === 'edit'): ?>
    <!-- ═══════════════════════════════ MAKİNE DÜZENLE ═══════════════════════════════ -->

    <?php if(isset($_GET['success'])): ?>
        <div class="message"><?php echo htmlspecialchars($_GET['success']); ?></div>
    <?php endif; ?>
    <?php if(isset($_GET['deleted'])): ?>
        <div class="message"><?php echo intval($_GET['deleted']); ?> makine silindi.</div>
    <?php endif; ?>
    <?php if(isset($_GET['updated_z'])): ?>
        <div class="message"><?php echo intval($_GET['updated_z']); ?> makinenin Z koordinatı güncellendi.</div>
    <?php endif; ?>
    <?php if(isset($_GET['updated_note'])): ?>
        <div class="message"><?php echo intval($_GET['updated_note']); ?> makinenin notu güncellendi.</div>
    <?php endif; ?>
    <?php if(isset($_GET['duplicated'])): ?>
        <div class="message"><?php echo intval($_GET['duplicated']); ?> makine kopyalandı.</div>
    <?php endif; ?>

    <div id="bulkToolbar" class="bulk-toolbar hidden">
        <span class="selection-info" id="selectionCount">0 makine seçili</span>
        <select id="bulkZLevel">
            <option value="">Z Katmanı Seç</option>
            <?php foreach($z_levels as $z_value => $z_label): ?>
                <option value="<?php echo $z_value; ?>"><?php echo $z_label; ?> (Z:<?php echo $z_value; ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="button" onclick="bulkUpdateZ()">Z Katmanını Güncelle</button>
        <textarea id="bulkNote" placeholder="Toplu not girin..."></textarea>
        <button type="button" onclick="bulkUpdateNote()">Notları Güncelle</button>
        <button type="button" onclick="bulkDuplicate()">Seçilileri Kopyala</button>
        <button type="button" onclick="bulkDelete()" style="background: #f44336; color: white;">Seçilileri Sil</button>
        <button type="button" class="close-btn" onclick="hideBulkToolbar()">✕</button>
    </div>

    <!-- Grup bazlı seçim satırı -->
    <div style="display:flex; gap:10px; align-items:center; margin-bottom:10px; flex-wrap:wrap;">
        <select id="groupSelectBulk" style="padding:8px; border:1px solid #ddd; border-radius:5px; font-size:13px;">
            <option value="">— Gruba Göre Seç —</option>
            <?php if(isset($groups_for_bulk)): $groups_for_bulk->data_seek(0); while($g = $groups_for_bulk->fetch_assoc()): ?>
                <option value="<?php echo intval($g['id']); ?>"><?php echo htmlspecialchars($g['group_name']); ?></option>
            <?php endwhile; endif; ?>
        </select>
        <button type="button" onclick="selectByGroup()" style="padding:8px 14px; background:#9C27B0; color:white; border:none; border-radius:5px; cursor:pointer; font-size:13px;">👥 Grubu Seç</button>
        <button type="button" onclick="selectAllPages()" style="padding:8px 14px; background:#FF9800; color:white; border:none; border-radius:5px; cursor:pointer; font-size:13px;">☑️ Tüm <?php echo intval($total_machines); ?> Makineyi Seç</button>
    </div>

    <div class="form-box">
        <h3>Yeni Makine Ekle / Düzenle</h3>
        <form method="post" action="machine_settings.php?tab=edit" id="machineForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="id" id="edit_id">
            <div class="form-grid">
                <div class="form-group"><label>Makine No:</label><input type="text" name="machine_no" id="edit_machine_no" required></div>
                <div class="form-group"><label>SMIBB IP:</label><input type="text" name="smibb_ip" id="edit_smibb_ip" required></div>
                <div class="form-group"><label>Screen IP:</label><input type="text" name="screen_ip" id="edit_screen_ip"></div>
                <div class="form-group"><label>MAC Adresi:</label><input type="text" name="mac" id="edit_mac" required></div>
                <div class="form-group"><label>Makine Türü:</label><input type="text" name="machine_type" id="edit_machine_type"></div>
                <div class="form-group"><label>Oyun Türü:</label><input type="text" name="game_type" id="edit_game_type"></div>
                <div class="form-group">
                    <label>Z Koordinatı (Kat):</label>
                    <select name="pos_z" id="edit_z">
                        <?php foreach($z_levels as $z_value => $z_label): ?>
                            <option value="<?php echo $z_value; ?>"><?php echo $z_label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group form-group-wide"><label>Not:</label><textarea name="note" id="edit_note" placeholder="Makine hakkında notlar..."></textarea></div>
            </div>
            <button type="submit" name="save">Kaydet</button>
            <button type="button" class="cancel-btn" onclick="clearForm()">Temizle</button>
        </form>
    </div>

    <div class="search-box">
        <form method="get" action="machine_settings.php" style="flex: 1; display: flex; gap: 10px;">
            <input type="hidden" name="tab" value="edit">
            <input type="text" name="search" placeholder="Makine no, SMIBB IP, MAC veya not ile ara..." value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit">Ara</button>
            <?php if(!empty($search)): ?>
                <a href="machine_settings.php?tab=edit" style="padding: 10px 20px; background: #999; color: white; text-decoration: none; border-radius: 5px;">Temizle</a>
            <?php endif; ?>
        </form>
    </div>

    <div style="margin-bottom:10px; color:#666; font-size:14px;">Toplam <strong><?php echo intval($total_machines); ?></strong> makine</div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th style="width:40px; text-align:center;"><input type="checkbox" id="selectAll" onclick="toggleAll(this)"></th>
                    <th>ID</th><th>Makine No</th><th>SMIBB IP</th><th>Screen IP</th><th>MAC Adresi</th>
                    <th>Makine Türü</th><th>Oyun Türü</th><th>Kat (Z)</th><th>Not</th><th>Konum (X,Y)</th><th>İşlemler</th>
                </tr>
            </thead>
            <tbody>
                <?php if($machines->num_rows > 0): ?>
                    <?php while($row = $machines->fetch_assoc()): ?>
                    <tr data-id="<?php echo intval($row['id']); ?>" class="machine-row">
                        <td style="text-align:center;"><input type="checkbox" class="machine-checkbox" value="<?php echo intval($row['id']); ?>" onchange="updateSelection()"></td>
                        <td><?php echo intval($row['id']); ?></td>
                        <td><?php echo htmlspecialchars($row['machine_no']); ?></td>
                        <td><?php echo htmlspecialchars($row['smibb_ip'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['screen_ip'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['mac']); ?></td>
                        <td><?php echo htmlspecialchars($row['machine_type'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($row['game_type'] ?? ''); ?></td>
                        <td><span class="z-badge"><?php echo htmlspecialchars($z_levels[$row['pos_z']] ?? 'Kat '.intval($row['pos_z'])); ?></span></td>
                        <td class="note-cell">
                            <?php if(!empty($row['note'])): ?>
                                <span title="<?php echo htmlspecialchars($row['note']); ?>"><?php echo substr(htmlspecialchars($row['note']),0,30).(strlen($row['note'])>30?'...':''); ?></span>
                            <?php else: ?> - <?php endif; ?>
                        </td>
                        <td>(<?php echo intval($row['pos_x']); ?>, <?php echo intval($row['pos_y']); ?>)</td>
                        <td>
                            <button class="action-btn edit-btn" onclick="editMachine(<?php echo intval($row['id']); ?>,<?php echo htmlspecialchars(json_encode($row['machine_no']),ENT_QUOTES); ?>,<?php echo htmlspecialchars(json_encode($row['smibb_ip']??''),ENT_QUOTES); ?>,<?php echo htmlspecialchars(json_encode($row['screen_ip']??''),ENT_QUOTES); ?>,<?php echo htmlspecialchars(json_encode($row['mac']),ENT_QUOTES); ?>,<?php echo htmlspecialchars(json_encode($row['machine_type']??''),ENT_QUOTES); ?>,<?php echo htmlspecialchars(json_encode($row['game_type']??''),ENT_QUOTES); ?>,<?php echo intval($row['pos_z']); ?>,<?php echo htmlspecialchars(json_encode($row['note']??''),ENT_QUOTES); ?>)">Düzenle</button>
                            <a href="?tab=edit&amp;delete=<?php echo intval($row['id']); ?><?php echo !empty($search)?'&amp;search='.urlencode($search):''; ?>" class="action-btn delete-btn" onclick="return confirm('Bu makineyi silmek istediğinize emin misiniz?')">Sil</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="12" style="text-align:center; padding:40px;">Makine bulunamadı.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if($total_pages > 1): ?>
    <div class="pagination">
        <?php if($page > 1): ?><a href="?tab=edit&page=<?php echo $page-1; ?><?php echo !empty($search)?'&search='.urlencode($search):''; ?>">« Önceki</a><?php endif; ?>
        <?php for($i=1;$i<=$total_pages;$i++): ?>
            <?php if($i==$page): ?><span class="active"><?php echo $i; ?></span>
            <?php else: ?><a href="?tab=edit&page=<?php echo $i; ?><?php echo !empty($search)?'&search='.urlencode($search):''; ?>"><?php echo $i; ?></a><?php endif; ?>
        <?php endfor; ?>
        <?php if($page < $total_pages): ?><a href="?tab=edit&page=<?php echo $page+1; ?><?php echo !empty($search)?'&search='.urlencode($search):''; ?>">Sonraki »</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <script>
        const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
        function addCsrf(form){ const i=document.createElement('input'); i.type='hidden'; i.name='csrf_token'; i.value=CSRF_TOKEN; form.appendChild(i); }
        let selectedMachines = new Set();
        document.addEventListener('DOMContentLoaded', function(){ updateSelection(); });
        function toggleAll(cb){ const cbs=document.querySelectorAll('.machine-checkbox'); cbs.forEach(c=>{c.checked=cb.checked; if(cb.checked) selectedMachines.add(c.value); else selectedMachines.delete(c.value); highlightRow(c.value,cb.checked);}); updateSelection(); }
        function highlightRow(id,h){ const r=document.querySelector('tr[data-id="'+id+'"]'); if(r){ if(h) r.classList.add('selected'); else r.classList.remove('selected'); } }
        function updateSelection(){
            const cbs=document.querySelectorAll('.machine-checkbox'); const sa=document.getElementById('selectAll');
            // Update selectedMachines based on checkbox state — but do NOT clear IDs from other pages
            cbs.forEach(c=>{ if(c.checked){selectedMachines.add(c.value); highlightRow(c.value,true);} else {selectedMachines.delete(c.value); highlightRow(c.value,false);} });
            if(sa){ sa.checked=cbs.length>0&&Array.from(cbs).every(c=>c.checked); sa.indeterminate=Array.from(cbs).some(c=>c.checked)&&!sa.checked; }
            const tb=document.getElementById('bulkToolbar');
            if(selectedMachines.size>0){ tb.classList.remove('hidden'); document.getElementById('selectionCount').textContent=selectedMachines.size+' makine seçili'; }
            else tb.classList.add('hidden');
        }
        function hideBulkToolbar(){ document.querySelectorAll('.machine-checkbox').forEach(c=>c.checked=false); selectedMachines.clear(); updateSelection(); }
        function makeForm(){ const f=document.createElement('form'); f.method='POST'; f.action='machine_settings.php?tab=edit'; f.style.display='none'; addCsrf(f); return f; }
        function addIds(f){ selectedMachines.forEach(id=>{const i=document.createElement('input');i.type='hidden';i.name='selected_machines[]';i.value=id;f.appendChild(i);}); }
        function addHidden(f,n,v){ const i=document.createElement('input');i.type='hidden';i.name=n;i.value=v;f.appendChild(i); }
        function bulkUpdateZ(){ const z=document.getElementById('bulkZLevel').value; if(!z){alert('Lütfen bir Z katmanı seçin!');return;} if(!selectedMachines.size){alert('Lütfen makine seçin!');return;} if(!confirm(selectedMachines.size+' makinenin katını güncellemek istiyor musunuz?'))return; const f=makeForm(); addIds(f); addHidden(f,'new_z',z); addHidden(f,'bulk_update_z','1'); document.body.appendChild(f); f.submit(); }
        function bulkUpdateNote(){ const n=document.getElementById('bulkNote').value; if(!selectedMachines.size){alert('Lütfen makine seçin!');return;} if(!confirm(selectedMachines.size+' makinenin notunu güncellemek istiyor musunuz?'))return; const f=makeForm(); addIds(f); addHidden(f,'bulk_note',n); addHidden(f,'bulk_update_note','1'); document.body.appendChild(f); f.submit(); }
        function bulkDelete(){ if(!selectedMachines.size){alert('Lütfen makine seçin!');return;} if(!confirm(selectedMachines.size+' makineyi kalıcı olarak silmek istiyor musunuz?'))return; const f=makeForm(); addIds(f); addHidden(f,'bulk_delete','1'); document.body.appendChild(f); f.submit(); }
        function bulkDuplicate(){ if(!selectedMachines.size){alert('Lütfen makine seçin!');return;} if(!confirm(selectedMachines.size+' makineyi kopyalamak istiyor musunuz?'))return; const f=makeForm(); addIds(f); addHidden(f,'bulk_duplicate','1'); document.body.appendChild(f); f.submit(); }
        function editMachine(id,no,smibb_ip,screen_ip,mac,machine_type,game_type,z,note){ document.getElementById('edit_id').value=id; document.getElementById('edit_machine_no').value=no; document.getElementById('edit_smibb_ip').value=smibb_ip; document.getElementById('edit_screen_ip').value=screen_ip; document.getElementById('edit_mac').value=mac; document.getElementById('edit_machine_type').value=machine_type; document.getElementById('edit_game_type').value=game_type; document.getElementById('edit_z').value=z; document.getElementById('edit_note').value=note; document.querySelector('.form-box').scrollIntoView({behavior:'smooth'}); }
        function clearForm(){ ['edit_id','edit_machine_no','edit_smibb_ip','edit_screen_ip','edit_mac','edit_machine_type','edit_game_type','edit_note'].forEach(id=>document.getElementById(id).value=''); document.getElementById('edit_z').value='0'; }
        // Tüm sayfaları seç
        function selectAllPages(){
            const search=new URLSearchParams(window.location.search).get('search')||'';
            fetch('machine_settings.php?tab=edit&get_all_ids=1&search='+encodeURIComponent(search))
                .then(function(r){return r.json();})
                .then(function(ids){
                    ids.forEach(function(id){selectedMachines.add(String(id));});
                    document.querySelectorAll('.machine-checkbox').forEach(function(c){
                        if(ids.indexOf(parseInt(c.value))!==-1){c.checked=true;}
                    });
                    updateSelection();
                });
        }
        // Gruba göre seç
        function selectByGroup(){
            const gid=document.getElementById('groupSelectBulk').value;
            if(!gid){alert('Lütfen bir grup seçin!');return;}
            fetch('machine_settings.php?tab=edit&get_group_ids='+encodeURIComponent(gid))
                .then(function(r){return r.json();})
                .then(function(ids){
                    if(!ids.length){alert('Bu grupta makine yok!');return;}
                    ids.forEach(function(id){selectedMachines.add(String(id));});
                    document.querySelectorAll('.machine-checkbox').forEach(function(c){
                        if(ids.indexOf(parseInt(c.value))!==-1){c.checked=true;}
                    });
                    updateSelection();
                    if(typeof showMsg==='function') showMsg(ids.length+' makine seçildi.');
                });
        }
        document.addEventListener('keydown',function(e){ if(e.ctrlKey&&e.key==='a'){e.preventDefault(); selectAllPages();} if(e.key==='Escape')hideBulkToolbar(); });
    </script>

<?php elseif($tab === 'groups'): ?>
    <!-- ═══════════════════════════════ MAKİNE GRUPLARI ═══════════════════════════════ -->

    <?php if(isset($success)): ?><div class="message"><?php echo $success; ?></div><?php endif; ?>
    <?php if(isset($_GET['deleted'])): ?><div class="message">Grup silindi.</div><?php endif; ?>
    <?php if(isset($_GET['updated'])): ?><div class="message">Grup güncellendi.</div><?php endif; ?>

    <div class="grid">
        <div class="left-panel">

            <?php if($is_admin): ?>
            <!-- Grup Oluştur (admin only) -->
            <h3>Yeni Grup Oluştur</h3>
            <form method="post" action="machine_settings.php?tab=groups">
                <?php echo csrf_field(); ?>
                <div class="form-group"><label>Grup Adı:</label><input type="text" name="group_name" required></div>
                <div class="form-group"><label>Açıklama:</label><textarea name="description" rows="2"></textarea></div>
                <button type="submit" name="create_group">Grup Oluştur</button>
            </form>
            <hr style="margin:20px 0;">
            <?php endif; ?>

            <h3>Gruplar</h3>
            <?php
            $groups->data_seek(0);
            $found_any = false;
            while($group = $groups->fetch_assoc()):
                $found_any = true;
                $mc = $conn->query("SELECT COUNT(*) as count FROM machine_group_relations WHERE group_id = ".$group['id'])->fetch_assoc()['count'];
            ?>
            <div class="group-item <?php echo (isset($_GET['group_id'])&&$_GET['group_id']==$group['id'])?'active':''; ?>"
                 onclick="window.location='?tab=groups&group_id=<?php echo $group['id']; ?>'"
                 style="<?php echo $group['color'] ? 'border-left:3px solid '.htmlspecialchars($group['color']).';' : ''; ?>">
                <div class="group-name"><?php echo htmlspecialchars($group['group_name']); ?></div>
                <div class="group-info"><?php echo $mc; ?> makine<?php echo $group['description'] ? ' • '.htmlspecialchars($group['description']) : ''; ?></div>
                <div class="group-actions">
                    <a href="export_group.php?group_id=<?php echo $group['id']; ?>" target="_blank" style="color:#2196F3;">📥 Excel Aktar</a>
                    <?php if($is_admin): ?>
                    <a href="?tab=groups&delete_group=<?php echo $group['id']; ?>" onclick="return confirm('Grubu silmek istiyor musunuz?')" style="color:#f44336;">🗑️ Sil</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endwhile; ?>
            <?php if(!$found_any): ?>
            <div style="color:#999;text-align:center;padding:20px;font-size:13px;">Henüz grup yok.</div>
            <?php endif; ?>
        </div>

        <div class="right-panel">
            <?php if(isset($_GET['group_id'])):
                $group_id = intval($_GET['group_id']);
                $group = $conn->query("SELECT * FROM machine_groups WHERE id = $group_id")->fetch_assoc();
                $group_machines = [];
                $gm_result = $conn->query("SELECT machine_id FROM machine_group_relations WHERE group_id = $group_id");
                while($gm = $gm_result->fetch_assoc()) $group_machines[] = $gm['machine_id'];
            ?>
                <h3><?php echo htmlspecialchars($group['group_name']); ?></h3>
                <p><?php echo htmlspecialchars($group['description'] ?? ''); ?></p>

                <?php if($is_admin): ?>
                <form method="post" action="machine_settings.php?tab=groups&group_id=<?php echo $group_id; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="group_id" value="<?php echo $group_id; ?>">
                    <div style="margin-bottom:10px; display:flex; align-items:center; gap:10px;">
                        <button type="button" id="toggleAllMachinesBtn" onclick="toggleAllMachines()" style="font-size:12px; padding:5px 12px; background:#607D8B; color:white; border:none; border-radius:5px; cursor:pointer;">➕ Tüm Makineleri Göster</button>
                        <input type="text" id="machineSearchInput" placeholder="Makine ara..." oninput="filterMachineList()" style="padding:5px 10px; border:1px solid #ddd; border-radius:5px; font-size:13px; flex:1;">
                    </div>
                    <div class="machine-list">
                        <?php $all_machines->data_seek(0); while($machine = $all_machines->fetch_assoc()):
                            $checked = in_array($machine['id'], $group_machines) ? 'checked' : '';
                            $inGroup = in_array($machine['id'], $group_machines) ? 'data-in-group="1"' : 'data-in-group="0"';
                        ?>
                        <div class="machine-item" <?php echo $inGroup; ?> <?php echo !$checked ? 'style="display:none"' : ''; ?>>
                            <label>
                                <input type="checkbox" name="machines[]" value="<?php echo $machine['id']; ?>" <?php echo $checked; ?>>
                                <strong><?php echo htmlspecialchars($machine['machine_no']); ?></strong> — <?php echo htmlspecialchars($machine['smibb_ip'] ?? ''); ?> (Z:<?php echo $machine['pos_z']; ?>)
                            </label>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <div style="margin-top:20px; display:flex; gap:10px;">
                        <button type="submit" name="update_group_machines">Seçimi Kaydet</button>
                        <button type="button" onclick="window.location='export_group.php?group_id=<?php echo $group_id; ?>'" style="background:#2196F3;">📥 Excel Aktar</button>
                    </div>
                </form>
                <?php else: ?>
                <!-- personel: sadece izleme + excel aktar -->
                <div class="machine-list" style="max-height:500px;">
                    <?php foreach($group_machines as $mid):
                        $mrow = $conn->query("SELECT machine_no, smibb_ip, pos_z FROM machines WHERE id=".intval($mid))->fetch_assoc();
                        if(!$mrow) continue;
                    ?>
                    <div class="machine-item">
                        <strong><?php echo htmlspecialchars($mrow['machine_no']); ?></strong>
                        — <?php echo htmlspecialchars($mrow['smibb_ip'] ?? ''); ?> (Z:<?php echo intval($mrow['pos_z']); ?>)
                    </div>
                    <?php endforeach; ?>
                    <?php if(empty($group_machines)): ?>
                    <div style="color:#999;text-align:center;padding:20px;">Grupta makine yok.</div>
                    <?php endif; ?>
                </div>
                <div style="margin-top:15px;">
                    <button type="button" onclick="window.location='export_group.php?group_id=<?php echo $group_id; ?>'" style="background:#2196F3;color:white;border:none;border-radius:5px;padding:10px 20px;cursor:pointer;">📥 Excel Aktar</button>
                </div>
                <?php endif; ?>

                <script>
                let showingAll = false;
                function toggleAllMachines() {
                    showingAll = !showingAll;
                    const btn = document.getElementById('toggleAllMachinesBtn');
                    if(btn){ btn.textContent = showingAll ? '🔽 Sadece Grup Makineleri' : '➕ Tüm Makineleri Göster'; btn.style.background = showingAll ? '#4CAF50' : '#607D8B'; }
                    filterMachineList();
                }
                function filterMachineList() {
                    const query = document.getElementById('machineSearchInput') ? document.getElementById('machineSearchInput').value.toLowerCase() : '';
                    document.querySelectorAll('.machine-list .machine-item').forEach(function(item) {
                        const inGroup = item.getAttribute('data-in-group') === '1';
                        const label = item.textContent.toLowerCase();
                        const visible = (showingAll || inGroup) && (!query || label.includes(query));
                        item.style.display = visible ? '' : 'none';
                    });
                }
                </script>
                <div class="group-stats">
                    <strong>Grup İstatistikleri:</strong><br>
                    Toplam Makine: <?php echo count($group_machines); ?><br>
                    Oluşturulma: <?php echo htmlspecialchars($group['created_at'] ?? '-'); ?>
                </div>
            <?php else: ?>
                <div style="text-align:center; padding:50px; color:#999;">
                    <p>Soldan <?php echo $is_admin ? 'bir grup seçin veya yeni bir grup oluşturun.' : 'bir grup seçin.'; ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>
</div><!-- /.container -->
</body>
</html>
