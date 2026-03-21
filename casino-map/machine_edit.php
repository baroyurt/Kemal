<?php
session_start();

if(!isset($_SESSION['login']) || $_SESSION['role'] != 'admin'){
    header("Location: login.php");
    exit;
}

include("config.php");

// Toplu silme
if(isset($_POST['bulk_delete'])){
    if(isset($_POST['selected_machines']) && is_array($_POST['selected_machines'])){
        $selected_ids = implode(',', array_map('intval', $_POST['selected_machines']));
        $conn->query("DELETE FROM machines WHERE id IN ($selected_ids)");
        $deleted_count = $conn->affected_rows;
        header("Location: machine_edit.php?deleted=$deleted_count");
        exit;
    }
}

// Toplu Z koordinatı güncelleme
if(isset($_POST['bulk_update_z'])){
    if(isset($_POST['selected_machines']) && is_array($_POST['selected_machines']) && isset($_POST['new_z'])){
        $new_z = intval($_POST['new_z']);
        $selected_ids = implode(',', array_map('intval', $_POST['selected_machines']));
        $conn->query("UPDATE machines SET pos_z = $new_z WHERE id IN ($selected_ids)");
        $updated_count = $conn->affected_rows;
        header("Location: machine_edit.php?updated_z=$updated_count");
        exit;
    }
}

// Toplu not ekleme/güncelleme
if(isset($_POST['bulk_update_note'])){
    if(isset($_POST['selected_machines']) && is_array($_POST['selected_machines']) && isset($_POST['bulk_note'])){
        $bulk_note = $conn->real_escape_string($_POST['bulk_note']);
        $selected_ids = implode(',', array_map('intval', $_POST['selected_machines']));
        $conn->query("UPDATE machines SET note = '$bulk_note' WHERE id IN ($selected_ids)");
        $updated_count = $conn->affected_rows;
        header("Location: machine_edit.php?updated_note=$updated_count");
        exit;
    }
}

// Toplu kopyalama
if(isset($_POST['bulk_duplicate'])){
    if(isset($_POST['selected_machines']) && is_array($_POST['selected_machines'])){
        foreach($_POST['selected_machines'] as $id){
            $id = intval($id);
            $result = $conn->query("SELECT * FROM machines WHERE id = $id");
            if($row = $result->fetch_assoc()){
                $machine_no   = $conn->real_escape_string($row['machine_no'] . ' (Kopya)');
                $smibb_ip     = $conn->real_escape_string($row['smibb_ip'] ?? '');
                $screen_ip    = $conn->real_escape_string($row['screen_ip'] ?? '');
                $mac          = $conn->real_escape_string($row['mac']);
                $area         = $row['area'] !== null ? intval($row['area']) : 'NULL';
                $machine_type = $conn->real_escape_string($row['machine_type'] ?? '');
                $game_type    = $conn->real_escape_string($row['game_type'] ?? '');
                $pos_x = intval($row['pos_x']) + 20;
                $pos_y = intval($row['pos_y']) + 20;
                $pos_z = intval($row['pos_z']);
                $rotation = intval($row['rotation']);
                $note = $conn->real_escape_string($row['note'] . ' (Kopya)');
                
                $conn->query("INSERT INTO machines (machine_no, smibb_ip, screen_ip, mac, area, machine_type, game_type, pos_x, pos_y, pos_z, rotation, note) 
                              VALUES ('$machine_no', '$smibb_ip', '$screen_ip', '$mac', $area, '$machine_type', '$game_type', $pos_x, $pos_y, $pos_z, $rotation, '$note')");
            }
        }
        $duplicated_count = count($_POST['selected_machines']);
        header("Location: machine_edit.php?duplicated=$duplicated_count");
        exit;
    }
}

// Tekil silme
if(isset($_GET['delete'])){
    $id = intval($_GET['delete']);
    $conn->query("DELETE FROM machines WHERE id = $id");
    header("Location: machine_edit.php?deleted=1");
    exit;
}

// Tekil ekleme/güncelleme
if(isset($_POST['save'])){
    $machine_no   = $conn->real_escape_string($_POST['machine_no']);
    $smibb_ip     = $conn->real_escape_string($_POST['smibb_ip']);
    $screen_ip    = $conn->real_escape_string($_POST['screen_ip'] ?? '');
    $mac          = $conn->real_escape_string($_POST['mac']);
    $area         = isset($_POST['area']) && $_POST['area'] !== '' ? intval($_POST['area']) : null;
    $machine_type = $conn->real_escape_string($_POST['machine_type'] ?? '');
    $game_type    = $conn->real_escape_string($_POST['game_type'] ?? '');
    $pos_z        = intval($_POST['pos_z']);
    $note         = $conn->real_escape_string($_POST['note']);
    $area_sql     = $area !== null ? $area : 'NULL';
    
    if(isset($_POST['id']) && !empty($_POST['id'])){
        $id = intval($_POST['id']);
        $conn->query("UPDATE machines SET machine_no='$machine_no', smibb_ip='$smibb_ip', screen_ip='$screen_ip', mac='$mac', area=$area_sql, machine_type='$machine_type', game_type='$game_type', pos_z=$pos_z, note='$note' WHERE id=$id");
        $message = "Makine güncellendi";
    } else {
        $conn->query("INSERT INTO machines (machine_no, smibb_ip, screen_ip, mac, area, machine_type, game_type, pos_z, note) VALUES ('$machine_no', '$smibb_ip', '$screen_ip', '$mac', $area_sql, '$machine_type', '$game_type', $pos_z, '$note')");
        $message = "Yeni makine eklendi";
    }
    
    header("Location: machine_edit.php?success=" . urlencode($message));
    exit;
}

$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$where = '';
if(!empty($search)){
    $where = "WHERE machine_no LIKE '%$search%' OR smibb_ip LIKE '%$search%' OR mac LIKE '%$search%' OR note LIKE '%$search%'";
}

$total_result = $conn->query("SELECT COUNT(*) as count FROM machines $where");
$total_row = $total_result->fetch_assoc();
$total_machines = $total_row['count'];
$total_pages = ceil($total_machines / $limit);

$machines = $conn->query("SELECT * FROM machines $where ORDER BY pos_z, machine_no LIMIT $offset, $limit");

$z_levels = [
    0 => 'Yüksek Tavan',
    1 => 'Alçak Tavan',
    2 => 'Yeni Vip Salon',
    3 => 'Alt Salon'
];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Makine Düzenle</title>
    <style>
        body { font-family: Arial; background: #f5f5f5; margin: 0; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        h2 { margin: 0; color: #333; }
        .message { background: #4CAF50; color: white; padding: 10px 20px; border-radius: 5px; margin-bottom: 20px; animation: slideDown 0.3s ease; }
        @keyframes slideDown { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .form-box { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; color: #666; font-weight: bold; }
        input, textarea, select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; }
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
        table { width: 100%; border-collapse: collapse; min-width: 1200px; }
        th { background: #4CAF50; color: white; padding: 12px; text-align: left; position: sticky; top: 0; }
        th.checkbox-column { width: 40px; text-align: center; }
        td { padding: 12px; border-bottom: 1px solid #eee; }
        tr:hover { background: #f9f9f9; }
        tr.selected { background: #e3f2fd; }
        .checkbox-cell { text-align: center; }
        input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        .action-btn { padding: 5px 10px; margin: 0 5px; border: none; border-radius: 3px; cursor: pointer; text-decoration: none; font-size: 12px; display: inline-block; }
        .edit-btn { background: #2196F3; color: white; }
        .delete-btn { background: #f44336; color: white; }
        .note-cell { max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .has-note-badge { display: inline-block; width: 10px; height: 10px; background: #40E0D0; border-radius: 50%; margin-left: 5px; }
        .z-badge { background: #4CAF50; color: white; padding: 3px 8px; border-radius: 12px; font-size: 11px; display: inline-block; }
        .search-box { display: flex; gap: 10px; margin-bottom: 20px; }
        .search-box input { flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
        .search-box button { padding: 10px 20px; }
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 12px; background: white; border: 1px solid #ddd; border-radius: 5px; text-decoration: none; color: #333; }
        .pagination a:hover { background: #f0f0f0; }
        .pagination .active { background: #4CAF50; color: white; border-color: #4CAF50; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h2>Makine Düzenle</h2>
                <small>Toplam <?php echo intval($total_machines); ?> makine</small>
            </div>
            <a href="dashboard.php" style="color: #666; text-decoration: none;">← Panoya Dön</a>
        </div>
        
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
            <button onclick="bulkUpdateZ()">Z Katmanını Güncelle</button>
            <textarea id="bulkNote" placeholder="Toplu not girin..."></textarea>
            <button onclick="bulkUpdateNote()">Notları Güncelle</button>
            <button onclick="bulkDuplicate()">Seçilileri Kopyala</button>
            <button onclick="bulkDelete()" style="background: #f44336; color: white;">Seçilileri Sil</button>
            <button class="close-btn" onclick="hideBulkToolbar()">✕</button>
        </div>
        
        <div class="form-box">
            <h3>Yeni Makine Ekle / Düzenle</h3>
            <form method="post" id="machineForm">
                <input type="hidden" name="id" id="edit_id">
                <div class="form-group">
                    <label>Makine No:</label>
                    <input type="text" name="machine_no" id="edit_machine_no" required>
                </div>
                <div class="form-group">
                    <label>SMIBB IP:</label>
                    <input type="text" name="smibb_ip" id="edit_smibb_ip" required>
                </div>
                <div class="form-group">
                    <label>Screen IP:</label>
                    <input type="text" name="screen_ip" id="edit_screen_ip">
                </div>
                <div class="form-group">
                    <label>MAC Adresi:</label>
                    <input type="text" name="mac" id="edit_mac" required>
                </div>
                <div class="form-group">
                    <label>Bölge (Area):</label>
                    <select name="area" id="edit_area">
                        <option value="">— Seçiniz —</option>
                        <option value="1">1 - Yüksek Tavan</option>
                        <option value="3">3 - Alçak Tavan</option>
                        <option value="4">4 - Yeni Vip Salon</option>
                        <option value="5">5 - Alt Salon</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Makine Türü:</label>
                    <input type="text" name="machine_type" id="edit_machine_type">
                </div>
                <div class="form-group">
                    <label>Oyun Türü:</label>
                    <input type="text" name="game_type" id="edit_game_type">
                </div>
                <div class="form-group">
                    <label>Z Koordinatı (Kat):</label>
                    <select name="pos_z" id="edit_z">
                        <?php foreach($z_levels as $z_value => $z_label): ?>
                            <option value="<?php echo $z_value; ?>"><?php echo $z_label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Not:</label>
                    <textarea name="note" id="edit_note" placeholder="Makine hakkında notlar..."></textarea>
                </div>
                <button type="submit" name="save">Kaydet</button>
                <button type="button" class="cancel-btn" onclick="clearForm()">Temizle</button>
            </form>
        </div>
        
        <div class="search-box">
            <form method="get" style="flex: 1; display: flex; gap: 10px;">
                <input type="text" name="search" placeholder="Makine no, IP, MAC veya not ile ara..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit">Ara</button>
                <?php if(!empty($search)): ?>
                    <a href="machine_edit.php" style="padding: 10px 20px; background: #999; color: white; text-decoration: none; border-radius: 5px;">Temizle</a>
                <?php endif; ?>
            </form>
        </div>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th class="checkbox-column"><input type="checkbox" id="selectAll" onclick="toggleAll(this)"></th>
                        <th>ID</th>
                        <th>Makine No</th>
                        <th>SMIBB IP</th>
                        <th>Screen IP</th>
                        <th>MAC Adresi</th>
                        <th>Bölge</th>
                        <th>Makine Türü</th>
                        <th>Oyun Türü</th>
                        <th>Kat (Z)</th>
                        <th>Not</th>
                        <th>Konum (X,Y)</th>
                        <th>Döndürme</th>
                        <th>İşlemler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($machines->num_rows > 0): ?>
                        <?php while($row = $machines->fetch_assoc()): ?>
                        <tr data-id="<?php echo intval($row['id']); ?>" class="machine-row">
                            <td class="checkbox-cell"><input type="checkbox" class="machine-checkbox" value="<?php echo intval($row['id']); ?>" onchange="updateSelection()"></td>
                            <td><?php echo intval($row['id']); ?></td>
                            <td><?php echo htmlspecialchars($row['machine_no']); ?></td>
                            <td><?php echo htmlspecialchars($row['smibb_ip'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['screen_ip'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['mac']); ?></td>
                            <td><?php echo $row['area'] !== null ? intval($row['area']) : '-'; ?></td>
                            <td><?php echo htmlspecialchars($row['machine_type'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['game_type'] ?? ''); ?></td>
                            <td><span class="z-badge"><?php echo htmlspecialchars($z_levels[$row['pos_z']] ?? 'Kat '.intval($row['pos_z'])); ?></span></td>
                            <td class="note-cell">
                                <?php if(!empty($row['note'])): ?>
                                    <span title="<?php echo htmlspecialchars($row['note']); ?>">
                                        <?php echo substr(htmlspecialchars($row['note']), 0, 30) . (strlen($row['note']) > 30 ? '...' : ''); ?>
                                        <span class="has-note-badge"></span>
                                    </span>
                                <?php else: ?> - <?php endif; ?>
                            </td>
                            <td>(<?php echo intval($row['pos_x']); ?>, <?php echo intval($row['pos_y']); ?>)</td>
                            <td><?php echo intval($row['rotation']); ?>°</td>
                            <td>
                                <button class="action-btn edit-btn" onclick="editMachine(<?php echo intval($row['id']); ?>, '<?php echo htmlspecialchars($row['machine_no'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['smibb_ip'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['screen_ip'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['mac'], ENT_QUOTES); ?>', <?php echo $row['area'] !== null ? intval($row['area']) : 'null'; ?>, '<?php echo htmlspecialchars($row['machine_type'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['game_type'] ?? '', ENT_QUOTES); ?>', <?php echo intval($row['pos_z']); ?>, '<?php echo htmlspecialchars($row['note'] ?? '', ENT_QUOTES); ?>')">Düzenle</button>
                                <a href="?delete=<?php echo intval($row['id']); ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?><?php echo isset($_GET['page']) ? '&page='.intval($_GET['page']) : ''; ?>" class="action-btn delete-btn" onclick="return confirm('Bu makineyi silmek istediğinize emin misiniz?')">Sil</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="14" style="text-align: center; padding: 40px;">Makine bulunamadı.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if($total_pages > 1): ?>
        <div class="pagination">
            <?php if($page > 1): ?>
                <a href="?page=<?php echo ($page-1); ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>">« Önceki</a>
            <?php endif; ?>
            <?php for($i = 1; $i <= $total_pages; $i++): ?>
                <?php if($i == $page): ?>
                    <span class="active"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if($page < $total_pages): ?>
                <a href="?page=<?php echo ($page+1); ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>">Sonraki »</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        let selectedMachines = new Set();
        document.addEventListener('DOMContentLoaded', function() { updateSelection(); });
        
        function toggleAll(checkbox) {
            const checkboxes = document.querySelectorAll('.machine-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
                const id = cb.value;
                if(checkbox.checked) { selectedMachines.add(id); highlightRow(id, true); }
                else { selectedMachines.delete(id); highlightRow(id, false); }
            });
            updateSelection();
        }
        
        function highlightRow(id, highlight) {
            const row = document.querySelector(`tr[data-id="${id}"]`);
            if(row) { if(highlight) row.classList.add('selected'); else row.classList.remove('selected'); }
        }
        
        function updateSelection() {
            const checkboxes = document.querySelectorAll('.machine-checkbox');
            const selectAll = document.getElementById('selectAll');
            selectedMachines.clear();
            checkboxes.forEach(cb => {
                if(cb.checked) { selectedMachines.add(cb.value); highlightRow(cb.value, true); }
                else { highlightRow(cb.value, false); }
            });
            if(selectAll) {
                const allChecked = Array.from(checkboxes).every(cb => cb.checked);
                const anyChecked = Array.from(checkboxes).some(cb => cb.checked);
                selectAll.checked = allChecked;
                selectAll.indeterminate = anyChecked && !allChecked;
            }
            const toolbar = document.getElementById('bulkToolbar');
            const selectionCount = document.getElementById('selectionCount');
            if(selectedMachines.size > 0) {
                toolbar.classList.remove('hidden');
                selectionCount.textContent = `${selectedMachines.size} makine seçili`;
            } else { toolbar.classList.add('hidden'); }
        }
        
        function hideBulkToolbar() {
            document.querySelectorAll('.machine-checkbox').forEach(cb => cb.checked = false);
            selectedMachines.clear();
            updateSelection();
        }
        
        function bulkUpdateZ() {
            const zLevel = document.getElementById('bulkZLevel').value;
            if(!zLevel) { alert('Lütfen bir Z katmanı seçin!'); return; }
            if(selectedMachines.size === 0) { alert('Lütfen en az bir makine seçin!'); return; }
            if(confirm(`${selectedMachines.size} makinenin Z katmanını güncellemek istediğinize emin misiniz?`)) {
                const form = document.createElement('form');
                form.method = 'POST'; form.style.display = 'none';
                selectedMachines.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden'; input.name = 'selected_machines[]'; input.value = id;
                    form.appendChild(input);
                });
                const zInput = document.createElement('input');
                zInput.type = 'hidden'; zInput.name = 'new_z'; zInput.value = zLevel;
                form.appendChild(zInput);
                const submitInput = document.createElement('input');
                submitInput.type = 'hidden'; submitInput.name = 'bulk_update_z'; submitInput.value = '1';
                form.appendChild(submitInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function bulkUpdateNote() {
            const note = document.getElementById('bulkNote').value;
            if(selectedMachines.size === 0) { alert('Lütfen en az bir makine seçin!'); return; }
            if(confirm(`${selectedMachines.size} makinenin notunu güncellemek istediğinize emin misiniz?`)) {
                const form = document.createElement('form');
                form.method = 'POST'; form.style.display = 'none';
                selectedMachines.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden'; input.name = 'selected_machines[]'; input.value = id;
                    form.appendChild(input);
                });
                const noteInput = document.createElement('input');
                noteInput.type = 'hidden'; noteInput.name = 'bulk_note'; noteInput.value = note;
                form.appendChild(noteInput);
                const submitInput = document.createElement('input');
                submitInput.type = 'hidden'; submitInput.name = 'bulk_update_note'; submitInput.value = '1';
                form.appendChild(submitInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function bulkDelete() {
            if(selectedMachines.size === 0) { alert('Lütfen en az bir makine seçin!'); return; }
            if(confirm(`${selectedMachines.size} makineyi kalıcı olarak silmek istediğinize emin misiniz?`)) {
                const form = document.createElement('form');
                form.method = 'POST'; form.style.display = 'none';
                selectedMachines.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden'; input.name = 'selected_machines[]'; input.value = id;
                    form.appendChild(input);
                });
                const submitInput = document.createElement('input');
                submitInput.type = 'hidden'; submitInput.name = 'bulk_delete'; submitInput.value = '1';
                form.appendChild(submitInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function bulkDuplicate() {
            if(selectedMachines.size === 0) { alert('Lütfen en az bir makine seçin!'); return; }
            if(confirm(`${selectedMachines.size} makineyi kopyalamak istediğinize emin misiniz?`)) {
                const form = document.createElement('form');
                form.method = 'POST'; form.style.display = 'none';
                selectedMachines.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden'; input.name = 'selected_machines[]'; input.value = id;
                    form.appendChild(input);
                });
                const submitInput = document.createElement('input');
                submitInput.type = 'hidden'; submitInput.name = 'bulk_duplicate'; submitInput.value = '1';
                form.appendChild(submitInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function editMachine(id, machine_no, smibb_ip, screen_ip, mac, area, machine_type, game_type, z, note) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_machine_no').value = machine_no;
            document.getElementById('edit_smibb_ip').value = smibb_ip;
            document.getElementById('edit_screen_ip').value = screen_ip;
            document.getElementById('edit_mac').value = mac;
            document.getElementById('edit_area').value = area !== null ? area : '';
            document.getElementById('edit_machine_type').value = machine_type;
            document.getElementById('edit_game_type').value = game_type;
            document.getElementById('edit_z').value = z;
            document.getElementById('edit_note').value = note;
            document.querySelector('.form-box').scrollIntoView({ behavior: 'smooth' });
        }
        
        function clearForm() {
            document.getElementById('edit_id').value = '';
            document.getElementById('edit_machine_no').value = '';
            document.getElementById('edit_smibb_ip').value = '';
            document.getElementById('edit_screen_ip').value = '';
            document.getElementById('edit_mac').value = '';
            document.getElementById('edit_area').value = '';
            document.getElementById('edit_machine_type').value = '';
            document.getElementById('edit_game_type').value = '';
            document.getElementById('edit_z').value = '0';
            document.getElementById('edit_note').value = '';
        }
        
        document.addEventListener('keydown', function(e) {
            if(e.ctrlKey && e.key === 'a') {
                e.preventDefault();
                const checkboxes = document.querySelectorAll('.machine-checkbox');
                checkboxes.forEach(cb => { cb.checked = true; selectedMachines.add(cb.value); });
                updateSelection();
            }
            if(e.key === 'Escape') { hideBulkToolbar(); }
        });
    </script>
</body>
</html>