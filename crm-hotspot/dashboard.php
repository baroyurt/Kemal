<?php
session_start();
require_once __DIR__ . '/config/config.php';
require_login();

$page_title = 'Dashboard';
$active_nav = 'dashboard';

// --- Özet İstatistikler ---
$stats = [];

// Aktif check-in misafir sayısı
$r = $conn->query("SELECT COUNT(*) AS c FROM guests WHERE status='checked_in'");
$stats['checkedin'] = (int)$r->fetch_assoc()['c'];

// Bugün check-out
$r = $conn->query("SELECT COUNT(*) AS c FROM guests WHERE status='checked_in' AND DATE(checkout_date)=CURDATE()");
$stats['checkout_today'] = (int)$r->fetch_assoc()['c'];

// Aktif hotspot oturumu
$r = $conn->query("SELECT COUNT(*) AS c FROM hotspot_sessions WHERE status='active'");
$stats['hotspot_active'] = (int)$r->fetch_assoc()['c'];

// Bekleyen WhatsApp mesajları (okunmamış gelen)
$r = $conn->query("SELECT COUNT(*) AS c FROM whatsapp_messages WHERE direction='inbound' AND status='received'");
$stats['wa_pending'] = (int)$r->fetch_assoc()['c'];

// Son Fidelio senkronizasyonu
$r = $conn->query("SELECT * FROM fidelio_sync_log ORDER BY synced_at DESC LIMIT 1");
$last_sync = $r->fetch_assoc();

// Son check-in misafirler
$recent_guests = $conn->query(
    "SELECT id, first_name, last_name, room_no, phone, checkin_date, checkout_date, vip_status, status
     FROM guests WHERE status='checked_in'
     ORDER BY checkin_date DESC LIMIT 8"
);

// Son WhatsApp mesajları
$recent_wa = $conn->query(
    "SELECT m.*, CONCAT(g.first_name,' ',g.last_name) AS guest_name, g.room_no
     FROM whatsapp_messages m
     LEFT JOIN guests g ON g.id = m.guest_id
     ORDER BY m.created_at DESC LIMIT 5"
);

// Aktif hotspot oturumları
$active_hs = $conn->query(
    "SELECT hs.*, CONCAT(g.first_name,' ',g.last_name) AS guest_name, g.room_no
     FROM hotspot_sessions hs
     LEFT JOIN guests g ON g.id = hs.guest_id
     WHERE hs.status='active'
     ORDER BY hs.started_at DESC LIMIT 5"
);

include __DIR__ . '/modules/auth/layout.php';
?>

<!-- Stat Cards -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#e3f2fd;">🛎️</div>
        <div>
            <div class="value"><?php echo $stats['checkedin']; ?></div>
            <div class="label">Aktif Misafir</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fff8e1;">🧳</div>
        <div>
            <div class="value"><?php echo $stats['checkout_today']; ?></div>
            <div class="label">Bugün Check-out</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#e8f5e9;">📶</div>
        <div>
            <div class="value"><?php echo $stats['hotspot_active']; ?></div>
            <div class="label">Aktif Hotspot</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f3e5f5;">💬</div>
        <div>
            <div class="value"><?php echo $stats['wa_pending']; ?></div>
            <div class="label">Bekleyen Mesaj</div>
        </div>
    </div>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; flex-wrap:wrap;">

    <!-- Aktif Misafirler -->
    <div class="card" style="grid-column: span 2;">
        <div class="card-title">
            🛎️ Aktif Misafirler
            <a href="<?php echo APP_URL; ?>/modules/guests/index.php" style="float:right; font-size:12px; color:#1565c0;">Tümünü Gör →</a>
        </div>
        <div class="table-wrapper">
        <table>
            <thead><tr>
                <th>Ad Soyad</th><th>Oda</th><th>Telefon</th>
                <th>Check-in</th><th>Check-out</th><th>VIP</th><th>İşlem</th>
            </tr></thead>
            <tbody>
            <?php while ($g = $recent_guests->fetch_assoc()): ?>
            <tr>
                <td><?php echo h($g['first_name'] . ' ' . $g['last_name']); ?></td>
                <td><strong><?php echo h($g['room_no'] ?? '-'); ?></strong></td>
                <td><?php echo h($g['phone'] ?? '-'); ?></td>
                <td><?php echo $g['checkin_date'] ? date('d.m.Y', strtotime($g['checkin_date'])) : '-'; ?></td>
                <td><?php echo $g['checkout_date'] ? date('d.m.Y', strtotime($g['checkout_date'])) : '-'; ?></td>
                <td><?php echo $g['vip_status'] ? '<span class="badge badge-warning">⭐ VIP</span>' : ''; ?></td>
                <td>
                    <a href="<?php echo APP_URL; ?>/modules/guests/view.php?id=<?php echo $g['id']; ?>"
                       class="btn btn-info btn-sm">Detay</a>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Son WhatsApp Mesajları -->
    <div class="card">
        <div class="card-title">
            💬 Son WhatsApp Mesajları
            <a href="<?php echo APP_URL; ?>/modules/whatsapp/index.php" style="float:right; font-size:12px; color:#1565c0;">Tümü →</a>
        </div>
        <?php $wa_count = 0; while ($wa = $recent_wa->fetch_assoc()): $wa_count++; ?>
        <div style="display:flex; align-items:flex-start; gap:10px; padding:8px 0; border-bottom:1px solid #f5f5f5;">
            <div style="font-size:20px;"><?php echo $wa['direction']==='inbound' ? '📨' : '📤'; ?></div>
            <div style="flex:1; min-width:0;">
                <div style="font-size:12px; font-weight:600; color:#333;">
                    <?php echo h($wa['guest_name'] ?? 'Bilinmiyor'); ?>
                    <?php if ($wa['room_no']): ?>
                        <span style="color:#90a4ae; font-weight:400;">· Oda <?php echo h($wa['room_no']); ?></span>
                    <?php endif; ?>
                </div>
                <div style="font-size:12px; color:#666; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                    <?php echo h(mb_substr($wa['content'] ?? '', 0, 60)); ?>
                </div>
                <div style="font-size:10px; color:#b0bec5;"><?php echo time_ago($wa['created_at']); ?></div>
            </div>
            <?php if ($wa['direction']==='inbound' && $wa['status']==='received'): ?>
                <span class="badge badge-warning">Yeni</span>
            <?php endif; ?>
        </div>
        <?php endwhile; if (!$wa_count): ?>
            <p style="color:#90a4ae; font-size:13px;">Henüz mesaj yok.</p>
        <?php endif; ?>
    </div>

    <!-- Aktif Hotspot Oturumları -->
    <div class="card">
        <div class="card-title">
            📶 Aktif Hotspot Oturumları
            <a href="<?php echo APP_URL; ?>/modules/hotspot/index.php" style="float:right; font-size:12px; color:#1565c0;">Tümü →</a>
        </div>
        <?php $hs_count = 0; while ($hs = $active_hs->fetch_assoc()): $hs_count++; ?>
        <div style="display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #f5f5f5;">
            <div style="font-size:20px;">📱</div>
            <div style="flex:1;">
                <div style="font-size:12px; font-weight:600;"><?php echo h($hs['guest_name'] ?? $hs['mac_address']); ?></div>
                <div style="font-size:11px; color:#90a4ae;">
                    <?php echo h($hs['ip_address'] ?? ''); ?> · <?php echo h($hs['mac_address']); ?><br>
                    ↓<?php echo format_bytes($hs['bytes_in']); ?> ↑<?php echo format_bytes($hs['bytes_out']); ?>
                </div>
            </div>
            <span class="badge badge-success">Aktif</span>
        </div>
        <?php endwhile; if (!$hs_count): ?>
            <p style="color:#90a4ae; font-size:13px;">Aktif oturum yok.</p>
        <?php endif; ?>
    </div>

</div>

<!-- Fidelio Senkronizasyon Durumu -->
<div class="card">
    <div class="card-title">🔄 Fidelio PMS Bağlantı Durumu</div>
    <?php if ($last_sync): ?>
    <div style="display:flex; align-items:center; gap:12px; font-size:13px;">
        <span style="font-size:22px;">
            <?php echo $last_sync['status']==='success' ? '✅' : ($last_sync['status']==='partial' ? '⚠️' : '❌'); ?>
        </span>
        <div>
            <strong>Son Senkronizasyon:</strong> <?php echo date('d.m.Y H:i', strtotime($last_sync['synced_at'])); ?><br>
            Tür: <?php echo h($last_sync['sync_type']); ?> ·
            İşlenen: <?php echo $last_sync['records_processed']; ?> ·
            Güncellenen: <?php echo $last_sync['records_updated']; ?>
            <?php if ($last_sync['error_message']): ?>
                <br><span style="color:#c62828;"><?php echo h($last_sync['error_message']); ?></span>
            <?php endif; ?>
        </div>
        <div style="margin-left:auto;">
            <a href="<?php echo APP_URL; ?>/modules/fidelio/index.php" class="btn btn-info btn-sm">Detay</a>
            <?php if (has_role('admin','it_staff')): ?>
            <a href="<?php echo APP_URL; ?>/modules/fidelio/sync.php?action=manual" class="btn btn-primary btn-sm">🔄 Manuel Sync</a>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <p style="color:#90a4ae; font-size:13px;">Henüz senkronizasyon yapılmamış.</p>
    <?php endif; ?>
</div>

</div><!-- .main-content -->
</body>
</html>
