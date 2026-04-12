<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_login();

$page_title = 'Fidelio PMS Entegrasyonu';
$active_nav = 'fidelio';
$message = '';
$error   = '';

// --- Manuel senkronizasyon ---
if ((isset($_POST['manual_sync']) || isset($_GET['action']) && $_GET['action']==='manual') && has_role('admin','it_staff')) {
    if (isset($_POST['manual_sync'])) csrf_verify();
    require_once APP_ROOT . '/lib/FidelioClient.php';
    $fidelio = new FidelioClient();
    $result  = $fidelio->syncGuests($conn);
    if ($result['success']) {
        audit_log($conn, 'fidelio_manual_sync', 'fidelio', 0,
            "İşlenen: {$result['processed']}, Güncellenen: {$result['updated']}");
        $message = "Senkronizasyon tamamlandı. İşlenen: {$result['processed']}, Güncellenen: {$result['updated']}";
    } else {
        $error = 'Senkronizasyon hatası: ' . ($result['error'] ?? 'Bilinmiyor');
    }
}

// Son 20 sync kaydı
$sync_logs = $conn->query(
    'SELECT * FROM fidelio_sync_log ORDER BY synced_at DESC LIMIT 20'
);

// Özet
$r = $conn->query("SELECT COUNT(*) AS c FROM guests WHERE fidelio_id IS NOT NULL AND fidelio_id!=''");
$fidelio_guests = (int)$r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) AS c FROM fidelio_sync_log WHERE status='success' AND DATE(synced_at)=CURDATE()");
$today_syncs = (int)$r->fetch_assoc()['c'];
$r = $conn->query("SELECT * FROM fidelio_sync_log WHERE status='error' ORDER BY synced_at DESC LIMIT 1");
$last_error = $r->fetch_assoc();

include __DIR__ . '/../../modules/auth/layout.php';
?>

<?php if ($message): ?><div class="alert alert-success">✅ <?php echo h($message); ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger">❌ <?php echo h($error); ?></div><?php endif; ?>

<!-- Durum Kartları -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#e3f2fd;">🏨</div>
        <div><div class="value"><?php echo $fidelio_guests; ?></div><div class="label">Fidelio Misafiri</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#e8f5e9;">✅</div>
        <div><div class="value"><?php echo $today_syncs; ?></div><div class="label">Bugün Başarılı Sync</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:<?php echo $last_error ? '#ffebee' : '#e8f5e9'; ?>;">
            <?php echo $last_error ? '❌' : '✅'; ?>
        </div>
        <div>
            <div class="value" style="font-size:14px;"><?php echo $last_error ? 'Hata Var' : 'Normal'; ?></div>
            <div class="label">Son Durum</div>
        </div>
    </div>
</div>

<!-- Yapılandırma Bilgisi -->
<div class="card">
    <div class="card-title">⚙️ Fidelio Bağlantı Ayarları</div>
    <table style="max-width:500px;">
        <tbody>
        <tr>
            <td style="padding:6px 12px; font-weight:700; color:#546e7a; width:160px;">Mod:</td>
            <td>
                <?php if (FIDELIO_MODE === 'fias'): ?>
                    <span class="badge badge-info">FIAS TCP Soket</span>
                <?php else: ?>
                    <span class="badge badge-success">Opera REST API</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td style="padding:6px 12px; font-weight:700; color:#546e7a;">Sunucu:</td>
            <td>
                <?php if (FIDELIO_MODE === 'fias'): ?>
                    <?php echo h(FIDELIO_FIAS_HOST . ':' . FIDELIO_FIAS_PORT); ?>
                <?php else: ?>
                    <?php echo h(FIDELIO_REST_URL); ?>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td style="padding:6px 12px; font-weight:700; color:#546e7a;">Otel Kodu:</td>
            <td><?php echo h(FIDELIO_HOTEL_CODE); ?></td>
        </tr>
        <tr>
            <td style="padding:6px 12px; font-weight:700; color:#546e7a;">Cron Sıklığı:</td>
            <td>Her 5 dakika <code style="background:#f5f5f5; padding:2px 6px; border-radius:4px;">*/5 * * * * php <?php echo APP_ROOT; ?>/modules/fidelio/sync.php</code></td>
        </tr>
        </tbody>
    </table>
</div>

<!-- Manuel Sync -->
<?php if (has_role('admin','it_staff')): ?>
<div class="card">
    <div class="card-title">🔄 Manuel Senkronizasyon</div>
    <p style="color:#78909c; font-size:13px; margin-bottom:14px;">
        Fidelio'dan aktif konaklamaları şimdi çekmek için tıklayın.
    </p>
    <form method="post">
        <?php echo csrf_field(); ?>
        <button type="submit" name="manual_sync" class="btn btn-primary">
            🔄 Şimdi Senkronize Et
        </button>
    </form>
</div>
<?php endif; ?>

<!-- Sync Geçmişi -->
<div class="card">
    <div class="card-title">📋 Senkronizasyon Geçmişi</div>
    <div class="table-wrapper">
    <table>
        <thead><tr><th>Zaman</th><th>Tür</th><th>Durum</th><th>İşlenen</th><th>Güncellenen</th><th>Hata Mesajı</th></tr></thead>
        <tbody>
        <?php while ($log = $sync_logs->fetch_assoc()): ?>
        <tr>
            <td><?php echo date('d.m.Y H:i:s', strtotime($log['synced_at'])); ?></td>
            <td><?php echo h($log['sync_type']); ?></td>
            <td>
                <?php
                $st_map = ['success'=>'badge-success','error'=>'badge-danger','partial'=>'badge-warning'];
                echo '<span class="badge ' . ($st_map[$log['status']] ?? 'badge-secondary') . '">' . h($log['status']) . '</span>';
                ?>
            </td>
            <td><?php echo $log['records_processed']; ?></td>
            <td><?php echo $log['records_updated']; ?></td>
            <td style="color:#c62828; font-size:12px;"><?php echo h($log['error_message'] ?? ''); ?></td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    </div>
</div>

</div></body></html>
