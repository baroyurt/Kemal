<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_login();

$page_title = 'Hotspot Oturumları';
$active_nav = 'hotspot';
$message = '';
$error   = '';

// --- Mikrotik'ten canlı veri çek ---
$live_sessions = [];
if (has_role('admin','it_staff')) {
    require_once APP_ROOT . '/lib/MikrotikClient.php';
    try {
        $mikro = new MikrotikClient();
        if ($mikro->isConnected()) {
            $live_sessions = $mikro->getActiveSessions();
            // Veritabanını güncelle
            foreach ($live_sessions as $ls) {
                $mac = $ls['mac'];
                $ip  = $ls['ip'];
                $bin = $ls['bytes_in'];
                $bot = $ls['bytes_out'];
                $st  = $conn->prepare(
                    'UPDATE hotspot_sessions SET ip_address=?,bytes_in=?,bytes_out=? WHERE mac_address=? AND status=\'active\''
                );
                $st->bind_param('siis', $ip, $bin, $bot, $mac);
                $st->execute();
                $st->close();
            }
        }
    } catch (Exception $e) {
        // Mikrotik bağlantısı yoksa sessizce devam et
    }
}

// --- Oturum Sonlandır ---
if (isset($_POST['disconnect']) && has_role('admin','it_staff')) {
    csrf_verify();
    $hs_id = (int)$_POST['hs_id'];
    $mac   = '';

    $st = $conn->prepare('SELECT mac_address FROM hotspot_sessions WHERE id=?');
    $st->bind_param('i', $hs_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row) {
        $mac = $row['mac_address'];
        $st  = $conn->prepare('UPDATE hotspot_sessions SET status=\'disconnected\', ended_at=NOW() WHERE id=?');
        $st->bind_param('i', $hs_id);
        $st->execute();
        $st->close();
        audit_log($conn, 'disconnect_hotspot', 'hotspot_session', $hs_id, "MAC: $mac");
        $message = 'Oturum sonlandırıldı.';
    }
}

// Oturum listesi
$allowed_statuses = ['active', 'disconnected', ''];
$status_filter = in_array($_GET['status'] ?? 'active', $allowed_statuses, true) ? ($_GET['status'] ?? 'active') : 'active';

if ($status_filter) {
    $sf_stmt = $conn->prepare(
        "SELECT hs.*, CONCAT(g.first_name,' ',g.last_name) AS guest_name, g.room_no, g.phone
         FROM hotspot_sessions hs
         LEFT JOIN guests g ON g.id = hs.guest_id
         WHERE hs.status=?
         ORDER BY hs.started_at DESC LIMIT 100"
    );
    $sf_stmt->bind_param('s', $status_filter);
    $sf_stmt->execute();
    $sessions = $sf_stmt->get_result();
    $sf_stmt->close();
} else {
    $sessions = $conn->query(
        "SELECT hs.*, CONCAT(g.first_name,' ',g.last_name) AS guest_name, g.room_no, g.phone
         FROM hotspot_sessions hs
         LEFT JOIN guests g ON g.id = hs.guest_id
         ORDER BY hs.started_at DESC LIMIT 100"
    );
}

// Özet
$r = $conn->query("SELECT COUNT(*) AS c FROM hotspot_sessions WHERE status='active'");
$active_count = (int)$r->fetch_assoc()['c'];
$r = $conn->query("SELECT SUM(bytes_in+bytes_out) AS total FROM hotspot_sessions WHERE status='active'");
$total_bytes  = (int)($r->fetch_assoc()['total'] ?? 0);

include __DIR__ . '/../../modules/auth/layout.php';
?>

<?php if ($message): ?><div class="alert alert-success">✅ <?php echo h($message); ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger">❌ <?php echo h($error); ?></div><?php endif; ?>

<!-- Özet -->
<div class="stat-grid" style="margin-bottom:20px;">
    <div class="stat-card">
        <div class="stat-icon" style="background:#e8f5e9;">📶</div>
        <div><div class="value"><?php echo $active_count; ?></div><div class="label">Aktif Oturum</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#e3f2fd;">💾</div>
        <div><div class="value"><?php echo format_bytes($total_bytes); ?></div><div class="label">Toplam Veri (Aktif)</div></div>
    </div>
    <?php if ($live_sessions): ?>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fff8e1;">🔴</div>
        <div><div class="value"><?php echo count($live_sessions); ?></div><div class="label">Mikrotik Canlı</div></div>
    </div>
    <?php endif; ?>
</div>

<!-- Filtre -->
<div style="margin-bottom:14px; display:flex; gap:8px;">
    <a href="?status=active"     class="btn <?php echo $status_filter==='active'?'btn-primary':'btn-secondary'; ?> btn-sm">Aktif</a>
    <a href="?status=disconnected" class="btn <?php echo $status_filter==='disconnected'?'btn-primary':'btn-secondary'; ?> btn-sm">Kapalı</a>
    <a href="?status="           class="btn <?php echo !$status_filter?'btn-primary':'btn-secondary'; ?> btn-sm">Tümü</a>
</div>

<!-- Oturum Tablosu -->
<div class="card">
    <div class="card-title">📶 Hotspot Oturumları</div>
    <div class="table-wrapper">
    <table>
        <thead><tr>
            <th>MAC Adresi</th><th>IP</th><th>Misafir</th><th>Oda</th>
            <th>Profil</th><th>↓ İndirme</th><th>↑ Yükleme</th>
            <th>Başlangıç</th><th>Bitiş</th><th>Durum</th>
            <?php if (has_role('admin','it_staff')): ?><th>İşlem</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php while ($s = $sessions->fetch_assoc()): ?>
        <tr>
            <td style="font-family:monospace;"><?php echo h($s['mac_address']); ?></td>
            <td><?php echo h($s['ip_address'] ?? '—'); ?></td>
            <td>
                <?php if ($s['guest_id']): ?>
                    <a href="<?php echo APP_URL; ?>/modules/guests/view.php?id=<?php echo $s['guest_id']; ?>">
                        <?php echo h($s['guest_name']); ?>
                    </a>
                <?php else: ?>
                    <span style="color:#90a4ae;">Anonim</span>
                <?php endif; ?>
            </td>
            <td><?php echo h($s['room_no'] ?? '—'); ?></td>
            <td><?php echo h($s['bandwidth_profile']); ?></td>
            <td><?php echo format_bytes($s['bytes_in']); ?></td>
            <td><?php echo format_bytes($s['bytes_out']); ?></td>
            <td><?php echo date('d.m H:i', strtotime($s['started_at'])); ?></td>
            <td><?php echo $s['ended_at'] ? date('d.m H:i', strtotime($s['ended_at'])) : '—'; ?></td>
            <td>
                <?php if ($s['status']==='active'): ?>
                    <span class="badge badge-success">Aktif</span>
                <?php else: ?>
                    <span class="badge badge-secondary">Kapalı</span>
                <?php endif; ?>
            </td>
            <?php if (has_role('admin','it_staff')): ?>
            <td>
                <?php if ($s['status']==='active'): ?>
                <form method="post" style="display:inline;" onsubmit="return confirm('Oturumu sonlandır?');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="hs_id" value="<?php echo $s['id']; ?>">
                    <button type="submit" name="disconnect" class="btn btn-danger btn-sm">Kes</button>
                </form>
                <?php endif; ?>
            </td>
            <?php endif; ?>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if ($live_sessions): ?>
<div class="card">
    <div class="card-title">🔴 Mikrotik Canlı Oturumlar</div>
    <div class="table-wrapper">
    <table>
        <thead><tr><th>MAC</th><th>IP</th><th>Kullanıcı</th><th>Uptime</th><th>↓ İndirme</th><th>↑ Yükleme</th></tr></thead>
        <tbody>
        <?php foreach ($live_sessions as $ls): ?>
        <tr>
            <td style="font-family:monospace;"><?php echo h($ls['mac']); ?></td>
            <td><?php echo h($ls['ip']); ?></td>
            <td><?php echo h($ls['user']); ?></td>
            <td><?php echo h($ls['uptime']); ?></td>
            <td><?php echo format_bytes($ls['bytes_in']); ?></td>
            <td><?php echo format_bytes($ls['bytes_out']); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

</div></body></html>
