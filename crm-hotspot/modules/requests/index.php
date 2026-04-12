<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_login();

$page_title = 'Arıza & Talepler';
$active_nav = 'requests';
$message = '';
$error   = '';

// --- Durum Güncelle ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    csrf_verify();
    $req_id     = (int)$_POST['req_id'];
    $new_status = $_POST['new_status'];
    $assign_to  = isset($_POST['assign_to']) && $_POST['assign_to'] !== '' ? (int)$_POST['assign_to'] : null;
    $allowed    = ['open', 'in_progress', 'resolved', 'cancelled'];

    if (in_array($new_status, $allowed, true)) {
        $resolved_at = ($new_status === 'resolved') ? date('Y-m-d H:i:s') : null;
        $st = $conn->prepare(
            'UPDATE service_requests SET status=?, assigned_to=?, resolved_at=?, updated_at=NOW() WHERE id=?'
        );
        $st->bind_param('sisi', $new_status, $assign_to, $resolved_at, $req_id);
        $st->execute();
        $st->close();

        audit_log($conn, 'update_request_status', 'service_requests', $req_id, "Durum: $new_status");
        $message = "Talep #$req_id güncellendi.";
    } else {
        $error = 'Geçersiz durum.';
    }
}

// --- Filtreler ---
$filter_type   = $_GET['type']   ?? '';
$filter_status = $_GET['status'] ?? 'open';

$allowed_types   = ['fault', 'request', 'wish', ''];
$allowed_statuses = ['open', 'in_progress', 'resolved', 'cancelled', ''];
if (!in_array($filter_type, $allowed_types, true))    $filter_type   = '';
if (!in_array($filter_status, $allowed_statuses, true)) $filter_status = 'open';

// Build query
$where_parts = [];
$params      = [];
$types       = '';

if ($filter_type !== '') {
    $where_parts[] = 'sr.type = ?';
    $params[]       = $filter_type;
    $types         .= 's';
}
if ($filter_status !== '') {
    $where_parts[] = 'sr.status = ?';
    $params[]       = $filter_status;
    $types         .= 's';
}

$where_sql = $where_parts ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

$list_sql = "
    SELECT sr.id, sr.type, sr.status, sr.priority, sr.created_at, sr.resolved_at,
           sc.name AS category_name, sc.icon AS category_icon,
           CONCAT(g.first_name,' ',g.last_name) AS guest_name, g.room_no, g.phone AS guest_phone,
           u.full_name AS assigned_name
    FROM service_requests sr
    LEFT JOIN service_categories sc ON sc.id = sr.category_id
    LEFT JOIN guests g ON g.id = sr.guest_id
    LEFT JOIN users  u ON u.id = sr.assigned_to
    $where_sql
    ORDER BY
        FIELD(sr.status,'open','in_progress','resolved','cancelled'),
        FIELD(sr.priority,'urgent','high','normal','low'),
        sr.created_at DESC
    LIMIT 200
";

$lst_st = $conn->prepare($list_sql);
if ($params) $lst_st->bind_param($types, ...$params);
$lst_st->execute();
$requests = $lst_st->get_result();
$lst_st->close();

// Counts per status
$count_st = $conn->query(
    "SELECT status, COUNT(*) AS c FROM service_requests GROUP BY status"
);
$counts = ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'cancelled' => 0];
while ($cr = $count_st->fetch_assoc()) {
    $counts[$cr['status']] = (int)$cr['c'];
}

// Staff list for assignment dropdown
$staff_res = $conn->query(
    "SELECT id, full_name, role FROM users WHERE is_active=1 ORDER BY full_name"
);
$staff_list = $staff_res->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/../../modules/auth/layout.php';
?>

<?php if ($message): ?><div class="alert alert-success">✅ <?php echo h($message); ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger">❌ <?php echo h($error); ?></div><?php endif; ?>

<!-- Özet Kartlar -->
<div class="stats-grid" style="margin-bottom:20px;">
    <a href="?status=open" class="stat-card" style="text-decoration:none;">
        <div class="stat-icon">🔴</div>
        <div class="stat-value"><?php echo $counts['open']; ?></div>
        <div class="stat-label">Açık</div>
    </a>
    <a href="?status=in_progress" class="stat-card" style="text-decoration:none;">
        <div class="stat-icon">🟡</div>
        <div class="stat-value"><?php echo $counts['in_progress']; ?></div>
        <div class="stat-label">İşlemde</div>
    </a>
    <a href="?status=resolved" class="stat-card" style="text-decoration:none;">
        <div class="stat-icon">🟢</div>
        <div class="stat-value"><?php echo $counts['resolved']; ?></div>
        <div class="stat-label">Çözüldü</div>
    </a>
    <a href="?status=" class="stat-card" style="text-decoration:none;">
        <div class="stat-icon">📋</div>
        <div class="stat-value"><?php echo array_sum($counts); ?></div>
        <div class="stat-label">Toplam</div>
    </a>
</div>

<!-- Filtreler -->
<div class="card" style="margin-bottom:16px;">
    <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
        <div class="form-group" style="margin:0;">
            <label>Tür</label>
            <select name="type" class="form-control" style="width:auto;">
                <option value="">Tümü</option>
                <option value="fault"   <?php echo $filter_type === 'fault'   ? 'selected' : ''; ?>>🔧 Arıza</option>
                <option value="request" <?php echo $filter_type === 'request' ? 'selected' : ''; ?>>📋 Talep</option>
                <option value="wish"    <?php echo $filter_type === 'wish'    ? 'selected' : ''; ?>>💬 İstek</option>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label>Durum</label>
            <select name="status" class="form-control" style="width:auto;">
                <option value="">Tümü</option>
                <option value="open"        <?php echo $filter_status === 'open'        ? 'selected' : ''; ?>>🔴 Açık</option>
                <option value="in_progress" <?php echo $filter_status === 'in_progress' ? 'selected' : ''; ?>>🟡 İşlemde</option>
                <option value="resolved"    <?php echo $filter_status === 'resolved'    ? 'selected' : ''; ?>>🟢 Çözüldü</option>
                <option value="cancelled"   <?php echo $filter_status === 'cancelled'   ? 'selected' : ''; ?>>⚫ İptal</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filtrele</button>
        <a href="?" class="btn btn-secondary">Sıfırla</a>
    </form>
</div>

<!-- Talep Listesi -->
<div class="card">
    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>Tür</th>
                <th>Kategori</th>
                <th>Misafir / Oda</th>
                <th>Durum</th>
                <th>Atanan</th>
                <th>Tarih</th>
                <th>İşlem</th>
            </tr>
        </thead>
        <tbody>
        <?php while ($req = $requests->fetch_assoc()): ?>
            <?php
            $type_badges = [
                'fault'   => ['danger',  '🔧 Arıza'],
                'request' => ['primary', '📋 Talep'],
                'wish'    => ['info',    '💬 İstek'],
            ];
            [$t_cls, $t_lbl] = $type_badges[$req['type']] ?? ['secondary', $req['type']];

            $status_badges = [
                'open'        => ['danger',    '🔴 Açık'],
                'in_progress' => ['warning',   '🟡 İşlemde'],
                'resolved'    => ['success',   '🟢 Çözüldü'],
                'cancelled'   => ['secondary', '⚫ İptal'],
            ];
            [$s_cls, $s_lbl] = $status_badges[$req['status']] ?? ['secondary', $req['status']];
            ?>
            <tr>
                <td><strong>#<?php echo $req['id']; ?></strong></td>
                <td><span class="badge bg-<?php echo h($t_cls); ?>"><?php echo h($t_lbl); ?></span></td>
                <td><?php echo h(($req['category_icon'] ?? '') . ' ' . ($req['category_name'] ?? '-')); ?></td>
                <td>
                    <strong><?php echo h($req['guest_name'] ?? '-'); ?></strong>
                    <?php if ($req['room_no']): ?>
                    <br><small style="color:#78909c;">Oda <?php echo h($req['room_no']); ?></small>
                    <?php endif; ?>
                </td>
                <td><span class="badge bg-<?php echo h($s_cls); ?>"><?php echo h($s_lbl); ?></span></td>
                <td><?php echo h($req['assigned_name'] ?? '—'); ?></td>
                <td>
                    <small><?php echo date('d.m.Y H:i', strtotime($req['created_at'])); ?></small>
                    <?php if ($req['resolved_at']): ?>
                    <br><small style="color:#4caf50;">✅ <?php echo date('d.m.Y H:i', strtotime($req['resolved_at'])); ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <button class="btn btn-secondary btn-sm"
                            onclick="openUpdateModal(<?php echo (int)$req['id']; ?>, <?php echo json_encode($req['status']); ?>, <?php echo $req['assigned_to'] ?? 'null'; ?>)">
                        ✏️ Güncelle
                    </button>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<!-- Güncelleme Modal -->
<div id="updateModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5);
     z-index:1000; display:none; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:16px; padding:28px; width:380px; max-width:90%;">
        <h3 style="margin-bottom:16px;">Talep Güncelle</h3>
        <form method="post" id="updateForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="update_status" value="1">
            <input type="hidden" name="req_id" id="modal_req_id">

            <div class="form-group">
                <label>Durum</label>
                <select name="new_status" id="modal_status" class="form-control">
                    <option value="open">🔴 Açık</option>
                    <option value="in_progress">🟡 İşlemde</option>
                    <option value="resolved">🟢 Çözüldü</option>
                    <option value="cancelled">⚫ İptal</option>
                </select>
            </div>
            <div class="form-group">
                <label>Personel Ata</label>
                <select name="assign_to" id="modal_assign" class="form-control">
                    <option value="">— Atanmadı —</option>
                    <?php foreach ($staff_list as $sf): ?>
                    <option value="<?php echo $sf['id']; ?>"><?php echo h($sf['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex; gap:8px; margin-top:16px;">
                <button type="submit" class="btn btn-primary" style="flex:1;">Kaydet</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal()" style="flex:1;">İptal</button>
            </div>
        </form>
    </div>
</div>

<script>
function openUpdateModal(id, status, assignedTo) {
    document.getElementById('modal_req_id').value    = id;
    document.getElementById('modal_status').value    = status;
    document.getElementById('modal_assign').value    = assignedTo || '';
    document.getElementById('updateModal').style.display = 'flex';
}
function closeModal() {
    document.getElementById('updateModal').style.display = 'none';
}
document.getElementById('updateModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php
// Close layout
echo '</div></div>';
// Normally layout.php closes the tags, but we need to close our content div
// The layout.php only opens main-content+topbar+content area
?>
