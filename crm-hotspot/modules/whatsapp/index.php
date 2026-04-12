<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_login();

$page_title = 'WhatsApp Mesajları';
$active_nav = 'whatsapp';
$message = '';
$error   = '';

// --- Toplu mesaj gönder ---
if (isset($_POST['send_bulk']) && has_role('admin','reception')) {
    csrf_verify();
    $template  = $_POST['bulk_template'];
    $target    = $_POST['bulk_target']; // all_checkedin | vip | room_no
    $custom_msg= trim($_POST['bulk_custom'] ?? '');
    $room_f    = trim($_POST['bulk_room'] ?? '');

    require_once APP_ROOT . '/lib/WhatsAppClient.php';
    $wa = new WhatsAppClient();

    $base_sql = "SELECT id, first_name, last_name, room_no, phone FROM guests WHERE status='checked_in' AND phone IS NOT NULL AND phone!=''";
    $params   = [];
    $types    = '';

    if ($target === 'vip') {
        $base_sql .= ' AND vip_status=1';
    } elseif ($target === 'room_no' && $room_f !== '') {
        $base_sql .= ' AND room_no=?';
        $params[]  = $room_f;
        $types    .= 's';
    }

    $bulk_st = $conn->prepare($base_sql);
    if ($params) $bulk_st->bind_param($types, ...$params);
    $bulk_st->execute();
    $res   = $bulk_st->get_result();
    $bulk_st->close();

    $count = 0;
    while ($g = $res->fetch_assoc()) {
        $msg = match($template) {
            'welcome'  => "Merhaba {$g['first_name']}! Otelinize hoş geldiniz. Odanız: {$g['room_no']}. İyi konaklamalar! 🏨",
            'checkout' => "Sayın {$g['first_name']}, check-out hatırlatması: lütfen oda anahtarınızı teslim edin. Güle güle! 🧳",
            'survey'   => "Sayın {$g['first_name']}, konaklamanızı değerlendirmek ister misiniz? Görüşünüz bizim için değerli! ⭐",
            'wifi'     => "📶 WiFi Bilgisi — Ağ: Otel_Guest | Şifre: {$g['room_no']}",
            'custom'   => $custom_msg,
            default    => $custom_msg,
        };
        if (empty($msg)) continue;
        $wa->sendText($conn, $g['id'], $g['phone'], $msg, (int)$_SESSION['user_id']);
        $count++;
    }
    audit_log($conn, 'bulk_whatsapp', 'guests', 0, "Şablon: $template, Hedef: $target, Gönderilen: $count");
    $message = "$count misafire mesaj gönderildi.";
}

// --- Tek misafire mesaj gönder ---
if (isset($_POST['send_single']) && has_role('admin','reception')) {
    csrf_verify();
    $guest_id = (int)$_POST['guest_id'];
    $msg      = trim($_POST['message']);

    $st = $conn->prepare('SELECT phone FROM guests WHERE id=?');
    $st->bind_param('i', $guest_id);
    $st->execute();
    $grow = $st->get_result()->fetch_assoc();
    $st->close();

    if ($grow && $grow['phone'] && $msg) {
        require_once APP_ROOT . '/lib/WhatsAppClient.php';
        $wa = new WhatsAppClient();
        $wa->sendText($conn, $guest_id, $grow['phone'], $msg, (int)$_SESSION['user_id']);
        $message = 'Mesaj gönderildi.';
    } else {
        $error = 'Mesaj boş veya misafir telefonu eksik.';
    }
}

// Mesaj listesi
$search    = trim($_GET['q'] ?? '');
$dir_f     = $_GET['dir'] ?? '';
$status_f  = $_GET['status'] ?? '';

$where  = ['1=1'];
$params = [];
$types  = '';

if ($search) {
    $like     = "%$search%";
    $where[]  = '(g.first_name LIKE ? OR g.last_name LIKE ? OR g.room_no LIKE ? OR m.content LIKE ?)';
    $params   = array_merge($params, [$like,$like,$like,$like]);
    $types   .= 'ssss';
}
if ($dir_f) {
    $where[]  = 'm.direction=?';
    $params[] = $dir_f;
    $types   .= 's';
}
if ($status_f) {
    $where[]  = 'm.status=?';
    $params[] = $status_f;
    $types   .= 's';
}

$sql = 'SELECT m.*, CONCAT(g.first_name,\' \',g.last_name) AS guest_name, g.room_no,
               u.full_name AS staff_name
        FROM whatsapp_messages m
        LEFT JOIN guests g ON g.id = m.guest_id
        LEFT JOIN users u ON u.id = m.staff_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY m.created_at DESC LIMIT 150';

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$msgs = $stmt->get_result();
$stmt->close();

// Özet
$r = $conn->query("SELECT COUNT(*) AS c FROM whatsapp_messages WHERE direction='inbound' AND status='received'");
$unread = (int)$r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) AS c FROM whatsapp_messages WHERE DATE(created_at)=CURDATE()");
$today_count = (int)$r->fetch_assoc()['c'];

// Misafir listesi (mesaj gönderme için)
$guests_list = $conn->query("SELECT id, CONCAT(first_name,' ',last_name) AS name, room_no, phone FROM guests WHERE status='checked_in' AND phone IS NOT NULL AND phone!='' ORDER BY first_name");

include __DIR__ . '/../../modules/auth/layout.php';
?>

<?php if ($message): ?><div class="alert alert-success">✅ <?php echo h($message); ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger">❌ <?php echo h($error); ?></div><?php endif; ?>

<!-- Özet -->
<div class="stat-grid" style="margin-bottom:20px;">
    <div class="stat-card">
        <div class="stat-icon" style="background:#fff8e1;">📨</div>
        <div><div class="value"><?php echo $unread; ?></div><div class="label">Bekleyen Mesaj</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#e3f2fd;">💬</div>
        <div><div class="value"><?php echo $today_count; ?></div><div class="label">Bugünkü Mesaj</div></div>
    </div>
</div>

<div style="display:grid; grid-template-columns:2fr 1fr; gap:20px;">

<!-- Mesaj Listesi -->
<div>
    <!-- Filtre -->
    <div class="card" style="margin-bottom:14px;">
        <form method="get" style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
            <div class="form-group" style="flex:2; min-width:160px; margin:0;">
                <label>🔍 Ara</label>
                <input type="text" name="q" class="form-control" placeholder="Ad, Oda, Mesaj içeriği..." value="<?php echo h($search); ?>">
            </div>
            <div class="form-group" style="min-width:120px; margin:0;">
                <label>Yön</label>
                <select name="dir" class="form-control">
                    <option value="">Tümü</option>
                    <option value="inbound"  <?php echo $dir_f==='inbound'?'selected':''; ?>>📨 Gelen</option>
                    <option value="outbound" <?php echo $dir_f==='outbound'?'selected':''; ?>>📤 Giden</option>
                </select>
            </div>
            <div class="form-group" style="min-width:120px; margin:0;">
                <label>Durum</label>
                <select name="status" class="form-control">
                    <option value="">Tümü</option>
                    <option value="received" <?php echo $status_f==='received'?'selected':''; ?>>Okunmamış</option>
                    <option value="sent"     <?php echo $status_f==='sent'?'selected':''; ?>>Gönderildi</option>
                    <option value="failed"   <?php echo $status_f==='failed'?'selected':''; ?>>Başarısız</option>
                </select>
            </div>
            <div style="display:flex; align-items:flex-end; gap:6px;">
                <button type="submit" class="btn btn-primary btn-sm">Filtrele</button>
                <a href="index.php" class="btn btn-secondary btn-sm">Temizle</a>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-title">💬 Mesaj Merkezi</div>
        <div class="table-wrapper">
        <table>
            <thead><tr>
                <th>Misafir</th><th>Oda</th><th>Yön</th><th>Mesaj</th>
                <th>Durum</th><th>Personel</th><th>Zaman</th><th>İşlem</th>
            </tr></thead>
            <tbody>
            <?php while ($m = $msgs->fetch_assoc()): ?>
            <tr <?php echo ($m['direction']==='inbound' && $m['status']==='received') ? 'style="background:#fff8e1;"' : ''; ?>>
                <td>
                    <?php if ($m['guest_id']): ?>
                        <a href="<?php echo APP_URL; ?>/modules/guests/view.php?id=<?php echo $m['guest_id']; ?>">
                            <?php echo h($m['guest_name'] ?? '—'); ?>
                        </a>
                    <?php else: ?>
                        <span style="color:#90a4ae;"><?php echo h($m['from_number'] ?? '—'); ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo h($m['room_no'] ?? '—'); ?></td>
                <td>
                    <?php echo $m['direction']==='inbound'
                        ? '<span class="badge badge-info">📨 Gelen</span>'
                        : '<span class="badge badge-success">📤 Giden</span>'; ?>
                </td>
                <td style="max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    <?php if ($m['template_name']): ?>
                        <em style="color:#90a4ae; font-size:10px;">[<?php echo h($m['template_name']); ?>]</em>
                    <?php endif; ?>
                    <?php echo h(mb_substr($m['content'] ?? '', 0, 80)); ?>
                </td>
                <td>
                    <?php
                    $sbadge = ['sent'=>'info','delivered'=>'success','read'=>'success','failed'=>'danger','received'=>'warning'];
                    $sc     = $sbadge[$m['status']] ?? 'secondary';
                    echo "<span class='badge badge-$sc'>" . h($m['status']) . '</span>';
                    ?>
                </td>
                <td><?php echo h($m['staff_name'] ?? '—'); ?></td>
                <td style="white-space:nowrap;"><?php echo time_ago($m['created_at']); ?></td>
                <td>
                    <?php if ($m['guest_id']): ?>
                        <a href="<?php echo APP_URL; ?>/modules/guests/view.php?id=<?php echo $m['guest_id']; ?>"
                           class="btn btn-info btn-sm">Yanıt</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Sağ Panel: Gönder & Toplu -->
<div>

    <!-- Tek Misafir Mesaj -->
    <?php if (has_role('admin','reception')): ?>
    <div class="card">
        <div class="card-title">📤 Mesaj Gönder</div>
        <form method="post">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Misafir</label>
                <select name="guest_id" class="form-control" required>
                    <option value="">— Seçin —</option>
                    <?php while ($gl = $guests_list->fetch_assoc()): ?>
                        <option value="<?php echo $gl['id']; ?>">
                            Oda <?php echo h($gl['room_no']); ?> — <?php echo h($gl['name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Mesaj</label>
                <textarea name="message" class="form-control" rows="3" required placeholder="Mesajınızı yazın..."></textarea>
            </div>
            <button type="submit" name="send_single" class="btn btn-success" style="width:100%; justify-content:center;">📤 Gönder</button>
        </form>
    </div>

    <!-- Toplu Mesaj -->
    <div class="card">
        <div class="card-title">📢 Toplu Mesaj</div>
        <form method="post">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Hedef Kitle</label>
                <select name="bulk_target" class="form-control" id="bulkTarget">
                    <option value="all_checkedin">Tüm Check-in Misafirler</option>
                    <option value="vip">Sadece VIP</option>
                    <option value="room_no">Belirli Oda</option>
                </select>
            </div>
            <div class="form-group" id="roomInput" style="display:none;">
                <label>Oda No</label>
                <input type="text" name="bulk_room" class="form-control" placeholder="101">
            </div>
            <div class="form-group">
                <label>Şablon</label>
                <select name="bulk_template" class="form-control" id="bulkTemplate">
                    <option value="welcome">🏨 Karşılama</option>
                    <option value="checkout">🧳 Check-out Hatırlatma</option>
                    <option value="survey">⭐ Anket</option>
                    <option value="wifi">📶 WiFi Bilgisi</option>
                    <option value="custom">✏️ Özel Mesaj</option>
                </select>
            </div>
            <div class="form-group" id="customInput" style="display:none;">
                <label>Özel Mesaj</label>
                <textarea name="bulk_custom" class="form-control" rows="3" placeholder="Mesaj içeriği..."></textarea>
            </div>
            <button type="submit" name="send_bulk" class="btn btn-warning" style="width:100%; justify-content:center;"
                    onclick="return confirm('Toplu mesaj gönderilecek. Emin misiniz?');">
                📢 Toplu Gönder
            </button>
        </form>
    </div>
    <?php endif; ?>

</div><!-- sağ panel -->
</div><!-- grid -->

<script>
document.getElementById('bulkTarget').addEventListener('change', function() {
    document.getElementById('roomInput').style.display = this.value === 'room_no' ? 'block' : 'none';
});
document.getElementById('bulkTemplate').addEventListener('change', function() {
    document.getElementById('customInput').style.display = this.value === 'custom' ? 'block' : 'none';
});
</script>

</div></body></html>
