<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_login();

$page_title = 'Misafirler / CRM';
$active_nav = 'guests';
$message = '';
$error   = '';

// --- Yeni misafir (manuel) ekle ---
if (isset($_POST['create_guest']) && has_role('admin','reception')) {
    csrf_verify();
    $data = [
        'first_name'   => trim($_POST['first_name']),
        'last_name'    => trim($_POST['last_name']),
        'room_no'      => trim($_POST['room_no']),
        'phone'        => trim($_POST['phone']),
        'email'        => trim($_POST['email']),
        'nationality'  => trim($_POST['nationality']),
        'vip_status'   => isset($_POST['vip_status']) ? 1 : 0,
        'checkin_date' => $_POST['checkin_date'] ?: null,
        'checkout_date'=> $_POST['checkout_date'] ?: null,
        'status'       => in_array($_POST['status'], ['reserved','checked_in','checked_out','cancelled']) ? $_POST['status'] : 'reserved',
        'notes'        => trim($_POST['notes']),
        'reservation_no' => trim($_POST['reservation_no']),
    ];
    if (empty($data['first_name']) || empty($data['last_name'])) {
        $error = 'Ad ve Soyad zorunludur.';
    } else {
        $st = $conn->prepare(
            'INSERT INTO guests (first_name,last_name,room_no,phone,email,nationality,vip_status,
                                  checkin_date,checkout_date,status,notes,reservation_no)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->bind_param(
            'ssssssisssss',
            $data['first_name'], $data['last_name'], $data['room_no'],
            $data['phone'], $data['email'], $data['nationality'],
            $data['vip_status'], $data['checkin_date'], $data['checkout_date'],
            $data['status'], $data['notes'], $data['reservation_no']
        );
        $st->execute();
        $gid = (int)$conn->insert_id;
        $st->close();
        audit_log($conn, 'create_guest', 'guest', $gid, $data['first_name'].' '.$data['last_name']);
        header('Location: index.php?created=1');
        exit;
    }
}

// --- Filtreler ---
$search    = trim($_GET['q'] ?? '');
$status_f  = $_GET['status'] ?? '';
$vip_f     = $_GET['vip'] ?? '';

$where  = ['1=1'];
$params = [];
$types  = '';

if ($search) {
    $like = "%$search%";
    $where[] = '(g.first_name LIKE ? OR g.last_name LIKE ? OR g.room_no LIKE ? OR g.phone LIKE ? OR g.email LIKE ? OR g.reservation_no LIKE ?)';
    $params  = array_merge($params, [$like,$like,$like,$like,$like,$like]);
    $types  .= 'ssssss';
}
if ($status_f) {
    $where[]  = 'g.status=?';
    $params[] = $status_f;
    $types   .= 's';
}
if ($vip_f !== '') {
    $where[]  = 'g.vip_status=?';
    $params[] = (int)$vip_f;
    $types   .= 'i';
}

$sql = 'SELECT g.*, 
        (SELECT COUNT(*) FROM hotspot_sessions hs WHERE hs.guest_id=g.id AND hs.status=\'active\') AS active_hs,
        (SELECT COUNT(*) FROM whatsapp_messages wm WHERE wm.guest_id=g.id AND wm.direction=\'inbound\' AND wm.status=\'received\') AS unread_wa
        FROM guests g WHERE ' . implode(' AND ', $where) . '
        ORDER BY FIELD(g.status,\'checked_in\',\'reserved\',\'checked_out\',\'cancelled\'), g.checkin_date DESC
        LIMIT 100';

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$guests = $stmt->get_result();
$stmt->close();

include __DIR__ . '/../../modules/auth/layout.php';
?>

<?php if (isset($_GET['created'])): ?><div class="alert alert-success">✅ Misafir eklendi.</div><?php endif; ?>
<?php if ($message): ?><div class="alert alert-success">✅ <?php echo h($message); ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger">❌ <?php echo h($error); ?></div><?php endif; ?>

<!-- Filtre & Ara -->
<div class="card" style="margin-bottom:16px;">
    <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
        <div class="form-group" style="flex:2; min-width:180px; margin:0;">
            <label>🔍 Ara</label>
            <input type="text" name="q" class="form-control" placeholder="Ad, Oda, Telefon, Rezervasyon..." value="<?php echo h($search); ?>">
        </div>
        <div class="form-group" style="min-width:150px; margin:0;">
            <label>Durum</label>
            <select name="status" class="form-control">
                <option value="">Tümü</option>
                <option value="checked_in"  <?php echo $status_f==='checked_in'?'selected':''; ?>>Check-in</option>
                <option value="reserved"    <?php echo $status_f==='reserved'?'selected':''; ?>>Rezervasyon</option>
                <option value="checked_out" <?php echo $status_f==='checked_out'?'selected':''; ?>>Check-out</option>
                <option value="cancelled"   <?php echo $status_f==='cancelled'?'selected':''; ?>>İptal</option>
            </select>
        </div>
        <div class="form-group" style="min-width:120px; margin:0;">
            <label>VIP</label>
            <select name="vip" class="form-control">
                <option value="">Tümü</option>
                <option value="1" <?php echo $vip_f==='1'?'selected':''; ?>>⭐ VIP</option>
                <option value="0" <?php echo $vip_f==='0'?'selected':''; ?>>Standart</option>
            </select>
        </div>
        <div style="margin-bottom:0; display:flex; align-items:flex-end; gap:6px;">
            <button type="submit" class="btn btn-primary">Filtrele</button>
            <a href="index.php" class="btn btn-secondary">Temizle</a>
        </div>
        <?php if (has_role('admin','reception')): ?>
        <div style="margin-left:auto; display:flex; align-items:flex-end;">
            <button type="button" class="btn btn-success" onclick="openAddModal()">➕ Yeni Misafir</button>
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- Misafir Tablosu -->
<div class="card">
    <div class="card-title">👥 Misafir Listesi</div>
    <div class="table-wrapper">
    <table>
        <thead><tr>
            <th>Ad Soyad</th><th>Oda</th><th>Rezervasyon No</th>
            <th>Telefon</th><th>Uyruk</th><th>Check-in</th><th>Check-out</th>
            <th>Durum</th><th>Hotspot</th><th>WhatsApp</th><th>İşlemler</th>
        </tr></thead>
        <tbody>
        <?php while ($g = $guests->fetch_assoc()): ?>
        <tr>
            <td>
                <?php if ($g['vip_status']): ?><span title="VIP">⭐</span> <?php endif; ?>
                <?php echo h($g['first_name'] . ' ' . $g['last_name']); ?>
            </td>
            <td><strong><?php echo h($g['room_no'] ?? '-'); ?></strong></td>
            <td><?php echo h($g['reservation_no'] ?? '-'); ?></td>
            <td><?php echo h($g['phone'] ?? '-'); ?></td>
            <td><?php echo h($g['nationality'] ?? '-'); ?></td>
            <td><?php echo $g['checkin_date'] ? date('d.m.Y', strtotime($g['checkin_date'])) : '-'; ?></td>
            <td><?php echo $g['checkout_date'] ? date('d.m.Y', strtotime($g['checkout_date'])) : '-'; ?></td>
            <td><?php echo guest_status_badge($g['status']); ?></td>
            <td>
                <?php if ($g['active_hs'] > 0): ?>
                    <span class="badge badge-success">📶 <?php echo $g['active_hs']; ?></span>
                <?php else: ?>
                    <span style="color:#ccc;">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($g['unread_wa'] > 0): ?>
                    <span class="badge badge-warning">💬 <?php echo $g['unread_wa']; ?></span>
                <?php else: ?>
                    <span style="color:#ccc;">—</span>
                <?php endif; ?>
            </td>
            <td>
                <a href="view.php?id=<?php echo $g['id']; ?>" class="btn btn-info btn-sm">Detay</a>
            </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Yeni Misafir Modal -->
<div class="modal-overlay" id="addModal">
    <div class="modal-box">
        <div class="modal-title">➕ Yeni Misafir Ekle</div>
        <form method="post">
            <?php echo csrf_field(); ?>
            <div class="form-row">
                <div class="form-group">
                    <label>Ad *</label>
                    <input type="text" name="first_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Soyad *</label>
                    <input type="text" name="last_name" class="form-control" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Oda No</label>
                    <input type="text" name="room_no" class="form-control" placeholder="101">
                </div>
                <div class="form-group">
                    <label>Rezervasyon No</label>
                    <input type="text" name="reservation_no" class="form-control">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Telefon (WhatsApp)</label>
                    <input type="text" name="phone" class="form-control" placeholder="+905...">
                </div>
                <div class="form-group">
                    <label>E-posta</label>
                    <input type="email" name="email" class="form-control">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Uyruk</label>
                    <input type="text" name="nationality" class="form-control" placeholder="TR">
                </div>
                <div class="form-group">
                    <label>Durum</label>
                    <select name="status" class="form-control">
                        <option value="reserved">Rezervasyon</option>
                        <option value="checked_in">Check-in</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Check-in Tarihi</label>
                    <input type="datetime-local" name="checkin_date" class="form-control">
                </div>
                <div class="form-group">
                    <label>Check-out Tarihi</label>
                    <input type="datetime-local" name="checkout_date" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" name="vip_status"> ⭐ VIP Misafir
                </label>
            </div>
            <div class="form-group">
                <label>Notlar</label>
                <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddModal()">İptal</button>
                <button type="submit" name="create_guest" class="btn btn-success">Kaydet</button>
            </div>
        </form>
    </div>
</div>
<script>
function openAddModal()  { document.getElementById('addModal').classList.add('open'); }
function closeAddModal() { document.getElementById('addModal').classList.remove('open'); }
document.getElementById('addModal').addEventListener('click', function(e){ if(e.target===this) closeAddModal(); });
</script>
</div></body></html>
