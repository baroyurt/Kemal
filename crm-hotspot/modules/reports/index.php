<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_role('admin','it_staff','reception');

$page_title = 'Raporlar';
$active_nav = 'reports';

// Tarih aralığı — strict validation to prevent SQL injection and invalid dates
function validate_date(string $input, string $fallback): string {
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $input, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return $input;
    }
    return $fallback;
}
$date_from = validate_date($_GET['from'] ?? '', date('Y-m-01'));
$date_to   = validate_date($_GET['to']   ?? '', date('Y-m-d'));
// Ensure from <= to
if ($date_from > $date_to) { [$date_from, $date_to] = [$date_to, $date_from]; }

// Helper: run a date-range prepared statement and return result
function report_query(mysqli $conn, string $sql, string $from, string $to): mysqli_result|false {
    $st = $conn->prepare($sql);
    $st->bind_param('ss', $from, $to);
    $st->execute();
    $res = $st->get_result();
    $st->close();
    return $res;
}

// --- Misafir İstatistikleri ---
$r = report_query($conn,
    "SELECT DATE(checkin_date) AS day, COUNT(*) AS cnt
     FROM guests WHERE DATE(checkin_date) BETWEEN ? AND ?
     GROUP BY DATE(checkin_date) ORDER BY day",
    $date_from, $date_to);
$checkin_by_day = [];
while ($row = $r->fetch_assoc()) $checkin_by_day[$row['day']] = $row['cnt'];

$r = report_query($conn,
    "SELECT status, COUNT(*) AS cnt FROM guests
     WHERE DATE(checkin_date) BETWEEN ? AND ? GROUP BY status",
    $date_from, $date_to);
$status_breakdown = [];
while ($row = $r->fetch_assoc()) $status_breakdown[$row['status']] = $row['cnt'];

$r = report_query($conn,
    "SELECT nationality, COUNT(*) AS cnt FROM guests
     WHERE DATE(checkin_date) BETWEEN ? AND ?
       AND nationality IS NOT NULL AND nationality != ''
     GROUP BY nationality ORDER BY cnt DESC LIMIT 10",
    $date_from, $date_to);
$by_nationality = [];
while ($row = $r->fetch_assoc()) $by_nationality[$row['nationality']] = $row['cnt'];

// --- Hotspot İstatistikleri ---
$r = report_query($conn,
    "SELECT DATE(started_at) AS day, COUNT(*) AS sessions,
            SUM(bytes_in+bytes_out) AS total_bytes
     FROM hotspot_sessions WHERE DATE(started_at) BETWEEN ? AND ?
     GROUP BY DATE(started_at) ORDER BY day",
    $date_from, $date_to);
$hs_by_day = [];
while ($row = $r->fetch_assoc()) $hs_by_day[$row['day']] = $row;

$r = report_query($conn,
    "SELECT bandwidth_profile, COUNT(*) AS cnt
     FROM hotspot_sessions WHERE DATE(started_at) BETWEEN ? AND ?
     GROUP BY bandwidth_profile",
    $date_from, $date_to);
$hs_profiles = [];
while ($row = $r->fetch_assoc()) $hs_profiles[$row['bandwidth_profile']] = $row['cnt'];

$r = report_query($conn,
    "SELECT SUM(bytes_in) AS total_in, SUM(bytes_out) AS total_out,
            COUNT(*) AS total_sessions
     FROM hotspot_sessions WHERE DATE(started_at) BETWEEN ? AND ?",
    $date_from, $date_to);
$hs_summary = $r->fetch_assoc();

// --- WhatsApp İstatistikleri ---
$r = report_query($conn,
    "SELECT direction, status, COUNT(*) AS cnt
     FROM whatsapp_messages WHERE DATE(created_at) BETWEEN ? AND ?
     GROUP BY direction, status",
    $date_from, $date_to);
$wa_stats = [];
while ($row = $r->fetch_assoc()) {
    $wa_stats[$row['direction']][$row['status']] = $row['cnt'];
}

$r = report_query($conn,
    "SELECT DATE(created_at) AS day, COUNT(*) AS cnt
     FROM whatsapp_messages WHERE DATE(created_at) BETWEEN ? AND ?
     GROUP BY DATE(created_at) ORDER BY day",
    $date_from, $date_to);
$wa_by_day = [];
while ($row = $r->fetch_assoc()) $wa_by_day[$row['day']] = $row['cnt'];

// --- Özet Sayılar ---
$r = report_query($conn, "SELECT COUNT(*) AS c FROM guests WHERE DATE(checkin_date) BETWEEN ? AND ?", $date_from, $date_to);
$total_guests = (int)$r->fetch_assoc()['c'];
$r = report_query($conn, "SELECT COUNT(*) AS c FROM guests WHERE vip_status=1 AND DATE(checkin_date) BETWEEN ? AND ?", $date_from, $date_to);
$vip_guests = (int)$r->fetch_assoc()['c'];
$r = report_query($conn, "SELECT COUNT(*) AS c FROM whatsapp_messages WHERE DATE(created_at) BETWEEN ? AND ?", $date_from, $date_to);
$total_wa = (int)$r->fetch_assoc()['c'];

include __DIR__ . '/../../modules/auth/layout.php';
?>

<!-- Tarih Filtresi -->
<div class="card" style="margin-bottom:20px;">
    <form method="get" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
        <div class="form-group" style="margin:0;">
            <label>Başlangıç</label>
            <input type="date" name="from" class="form-control" value="<?php echo h($date_from); ?>">
        </div>
        <div class="form-group" style="margin:0;">
            <label>Bitiş</label>
            <input type="date" name="to" class="form-control" value="<?php echo h($date_to); ?>">
        </div>
        <div style="display:flex; gap:6px;">
            <button type="submit" class="btn btn-primary">Filtrele</button>
            <a href="?from=<?php echo date('Y-m-01'); ?>&to=<?php echo date('Y-m-d'); ?>" class="btn btn-secondary">Bu Ay</a>
            <a href="?from=<?php echo date('Y-m-d'); ?>&to=<?php echo date('Y-m-d'); ?>" class="btn btn-secondary">Bugün</a>
        </div>
    </form>
</div>

<!-- Özet Kartlar -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#e3f2fd;">🛎️</div>
        <div><div class="value"><?php echo $total_guests; ?></div><div class="label">Toplam Check-in</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fff8e1;">⭐</div>
        <div><div class="value"><?php echo $vip_guests; ?></div><div class="label">VIP Misafir</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#e8f5e9;">📶</div>
        <div><div class="value"><?php echo $hs_summary['total_sessions'] ?? 0; ?></div><div class="label">Hotspot Oturum</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fce4ec;">💬</div>
        <div><div class="value"><?php echo $total_wa; ?></div><div class="label">WhatsApp Mesaj</div></div>
    </div>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">

<!-- Misafir Durumu -->
<div class="card">
    <div class="card-title">👥 Misafir Durumu Dağılımı</div>
    <?php
    $labels = ['checked_in'=>'Check-in','reserved'=>'Rezervasyon','checked_out'=>'Check-out','cancelled'=>'İptal'];
    $colors = ['checked_in'=>'badge-success','reserved'=>'badge-warning','checked_out'=>'badge-secondary','cancelled'=>'badge-danger'];
    foreach ($labels as $k => $v):
        $cnt = $status_breakdown[$k] ?? 0;
        $pct = $total_guests > 0 ? round($cnt / $total_guests * 100) : 0;
    ?>
    <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
        <span class="badge <?php echo $colors[$k]; ?>" style="min-width:90px; text-align:center;"><?php echo $v; ?></span>
        <div style="flex:1; background:#f5f5f5; border-radius:4px; height:18px; overflow:hidden;">
            <div style="width:<?php echo $pct; ?>%; background:#1565c0; height:100%; border-radius:4px;"></div>
        </div>
        <span style="min-width:40px; font-size:13px; font-weight:700; color:#333;"><?php echo $cnt; ?></span>
    </div>
    <?php endforeach; ?>
</div>

<!-- Uyruk Dağılımı -->
<div class="card">
    <div class="card-title">🌍 En Çok Gelen Uyruklar</div>
    <?php if ($by_nationality): ?>
        <?php $max_nat = max($by_nationality); ?>
        <?php foreach ($by_nationality as $nat => $cnt):
            $pct = round($cnt / $max_nat * 100);
        ?>
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
            <span style="min-width:35px; font-size:13px; font-weight:700; color:#333;"><?php echo h($nat); ?></span>
            <div style="flex:1; background:#f5f5f5; border-radius:4px; height:16px; overflow:hidden;">
                <div style="width:<?php echo $pct; ?>%; background:#43a047; height:100%; border-radius:4px;"></div>
            </div>
            <span style="min-width:30px; font-size:13px; color:#666;"><?php echo $cnt; ?></span>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p style="color:#90a4ae; font-size:13px;">Veri bulunamadı.</p>
    <?php endif; ?>
</div>

<!-- Hotspot Özeti -->
<div class="card">
    <div class="card-title">📶 Hotspot Kullanımı</div>
    <table>
        <tbody>
        <tr><td style="color:#546e7a; padding:6px 0;">Toplam Oturum</td><td style="font-weight:700;"><?php echo $hs_summary['total_sessions'] ?? 0; ?></td></tr>
        <tr><td style="color:#546e7a; padding:6px 0;">Toplam İndirme</td><td style="font-weight:700;"><?php echo format_bytes((int)($hs_summary['total_in'] ?? 0)); ?></td></tr>
        <tr><td style="color:#546e7a; padding:6px 0;">Toplam Yükleme</td><td style="font-weight:700;"><?php echo format_bytes((int)($hs_summary['total_out'] ?? 0)); ?></td></tr>
        </tbody>
    </table>
    <div style="margin-top:14px;">
        <div style="font-size:12px; font-weight:700; color:#546e7a; margin-bottom:8px;">Profil Dağılımı</div>
        <?php foreach ($hs_profiles as $prof => $cnt):
            $tot = array_sum($hs_profiles);
            $pct = $tot > 0 ? round($cnt / $tot * 100) : 0;
        ?>
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px; font-size:12px;">
            <span style="min-width:70px;"><?php echo h(ucfirst($prof)); ?></span>
            <div style="flex:1; background:#f5f5f5; border-radius:3px; height:14px;">
                <div style="width:<?php echo $pct; ?>%; background:#0277bd; height:100%; border-radius:3px;"></div>
            </div>
            <span><?php echo $cnt; ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- WhatsApp Özeti -->
<div class="card">
    <div class="card-title">💬 WhatsApp Mesaj İstatistikleri</div>
    <?php
    $out_total  = array_sum($wa_stats['outbound'] ?? []);
    $in_total   = array_sum($wa_stats['inbound']  ?? []);
    $failed     = ($wa_stats['outbound']['failed'] ?? 0);
    $delivered  = ($wa_stats['outbound']['delivered'] ?? 0) + ($wa_stats['outbound']['read'] ?? 0);
    ?>
    <table>
        <tbody>
        <tr><td style="color:#546e7a; padding:6px 0;">📤 Giden Mesaj</td><td style="font-weight:700;"><?php echo $out_total; ?></td></tr>
        <tr><td style="color:#546e7a; padding:6px 0;">✅ Teslim Edilen</td><td style="font-weight:700; color:#2e7d32;"><?php echo $delivered; ?></td></tr>
        <tr><td style="color:#546e7a; padding:6px 0;">❌ Başarısız</td><td style="font-weight:700; color:#c62828;"><?php echo $failed; ?></td></tr>
        <tr><td style="color:#546e7a; padding:6px 0;">📨 Gelen Mesaj</td><td style="font-weight:700;"><?php echo $in_total; ?></td></tr>
        </tbody>
    </table>
</div>

</div><!-- grid -->

<!-- Günlük Check-in Tablosu -->
<?php if ($checkin_by_day): ?>
<div class="card">
    <div class="card-title">📅 Günlük Check-in</div>
    <div class="table-wrapper">
    <table>
        <thead><tr><th>Tarih</th><th>Check-in Sayısı</th><th>Hotspot Oturumu</th><th>WhatsApp Mesaj</th></tr></thead>
        <tbody>
        <?php foreach ($checkin_by_day as $day => $cnt): ?>
        <tr>
            <td><?php echo date('d.m.Y (l)', strtotime($day)); ?></td>
            <td><?php echo $cnt; ?></td>
            <td><?php echo $hs_by_day[$day]['sessions'] ?? 0; ?></td>
            <td><?php echo $wa_by_day[$day] ?? 0; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

</div></body></html>
