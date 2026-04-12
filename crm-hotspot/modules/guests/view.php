<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

// Misafir verisini çek
$st = $conn->prepare('SELECT * FROM guests WHERE id=?');
$st->bind_param('i', $id);
$st->execute();
$guest = $st->get_result()->fetch_assoc();
$st->close();
if (!$guest) { header('Location: index.php'); exit; }

$page_title = h($guest['first_name'] . ' ' . $guest['last_name']);
$active_nav = 'guests';
$message = '';
$error   = '';

// --- Misafir Güncelle ---
if (isset($_POST['update_guest']) && has_role('admin','reception')) {
    csrf_verify();
    $fields = [
        'first_name'    => trim($_POST['first_name']),
        'last_name'     => trim($_POST['last_name']),
        'room_no'       => trim($_POST['room_no']),
        'phone'         => trim($_POST['phone']),
        'email'         => trim($_POST['email']),
        'nationality'   => trim($_POST['nationality']),
        'vip_status'    => isset($_POST['vip_status']) ? 1 : 0,
        'status'        => in_array($_POST['status'],['reserved','checked_in','checked_out','cancelled']) ? $_POST['status'] : $guest['status'],
        'checkin_date'  => $_POST['checkin_date'] ?: null,
        'checkout_date' => $_POST['checkout_date'] ?: null,
        'notes'         => trim($_POST['notes']),
    ];
    $st = $conn->prepare(
        'UPDATE guests SET first_name=?,last_name=?,room_no=?,phone=?,email=?,nationality=?,
         vip_status=?,status=?,checkin_date=?,checkout_date=?,notes=? WHERE id=?'
    );
    $st->bind_param(
        'ssssssissssi',
        $fields['first_name'], $fields['last_name'], $fields['room_no'],
        $fields['phone'], $fields['email'], $fields['nationality'],
        $fields['vip_status'], $fields['status'],
        $fields['checkin_date'], $fields['checkout_date'], $fields['notes'], $id
    );
    $st->execute();
    $st->close();
    audit_log($conn, 'update_guest', 'guest', $id);
    // Check-in mesajı gönder
    if ($fields['status'] === 'checked_in' && $guest['status'] !== 'checked_in' && $guest['phone']) {
        // WhatsApp karşılama mesajını tetikle
        require_once __DIR__ . '/../../lib/WhatsAppClient.php';
        $wa = new WhatsAppClient();
        $wa->sendWelcome($conn, $id, $guest['phone'], $fields['first_name'], $fields['room_no'] ?? '');
    }
    $message = 'Misafir bilgileri güncellendi.';
    // Refresh
    $st = $conn->prepare('SELECT * FROM guests WHERE id=?');
    $st->bind_param('i', $id);
    $st->execute();
    $guest = $st->get_result()->fetch_assoc();
    $st->close();
}

// --- WhatsApp mesajı gönder ---
if (isset($_POST['send_wa']) && has_role('admin','reception')) {
    csrf_verify();
    $msg = trim($_POST['wa_message']);
    if ($msg && $guest['phone']) {
        require_once __DIR__ . '/../../lib/WhatsAppClient.php';
        $wa = new WhatsAppClient();
        $wa->sendText($conn, $id, $guest['phone'], $msg, (int)$_SESSION['user_id']);
        $message = 'Mesaj gönderildi.';
    } else {
        $error = 'Mesaj boş olamaz veya misafirin telefonu kayıtlı değil.';
    }
}

// --- Hotspot oturumu başlat (Mikrotik) ---
if (isset($_POST['start_hotspot']) && has_role('admin','it_staff')) {
    csrf_verify();
    require_once __DIR__ . '/../../lib/MikrotikClient.php';
    $mac   = trim($_POST['hs_mac']);
    $prof  = in_array($_POST['hs_profile'], ['standard','premium','vip']) ? $_POST['hs_profile'] : 'standard';
    $mikro = new MikrotikClient();
    $result = $mikro->addUser($guest['room_no'] ?? 'guest', $guest['room_no'] ?? 'guest', $prof);
    if ($result['success']) {
        $st = $conn->prepare(
            'INSERT INTO hotspot_sessions (guest_id,mac_address,ip_address,bandwidth_profile,started_at)
             VALUES (?,?,?,?,NOW())'
        );
        $dummy_ip = '';
        $st->bind_param('isss', $id, $mac, $dummy_ip, $prof);
        $st->execute();
        $st->close();
        audit_log($conn, 'start_hotspot', 'guest', $id, "MAC: $mac Profile: $prof");
        $message = 'Hotspot oturumu başlatıldı.';
    } else {
        $error = 'Hotspot başlatılamadı: ' . ($result['error'] ?? 'Bilinmeyen hata');
    }
}

// --- Hotspot oturumu sonlandır ---
if (isset($_POST['end_hotspot']) && has_role('admin','it_staff')) {
    csrf_verify();
    $hs_id = (int)$_POST['hs_id'];
    $st = $conn->prepare('UPDATE hotspot_sessions SET status=\'disconnected\', ended_at=NOW() WHERE id=? AND guest_id=?');
    $st->bind_param('ii', $hs_id, $id);
    $st->execute();
    $st->close();
    audit_log($conn, 'end_hotspot', 'hotspot_session', $hs_id);
    $message = 'Hotspot oturumu sonlandırıldı.';
}

// Hotspot oturumları
$hotspot_sessions = $conn->prepare('SELECT * FROM hotspot_sessions WHERE guest_id=? ORDER BY started_at DESC');
$hotspot_sessions->bind_param('i', $id);
$hotspot_sessions->execute();
$sessions = $hotspot_sessions->get_result();
$hotspot_sessions->close();

// WhatsApp mesajları
$wa_st = $conn->prepare('SELECT m.*, u.full_name AS staff_name FROM whatsapp_messages m LEFT JOIN users u ON u.id=m.staff_id WHERE m.guest_id=? ORDER BY m.created_at ASC');
$wa_st->bind_param('i', $id);
$wa_st->execute();
$messages = $wa_st->get_result();
$wa_st->close();

// Okunmamış mesajları okundu yap
$upd_read = $conn->prepare("UPDATE whatsapp_messages SET status='read' WHERE guest_id=? AND direction='inbound' AND status='received'");
$upd_read->bind_param('i', $id);
$upd_read->execute();
$upd_read->close();

include __DIR__ . '/../../modules/auth/layout.php';
?>

<?php if ($message): ?><div class="alert alert-success">✅ <?php echo h($message); ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger">❌ <?php echo h($error); ?></div><?php endif; ?>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">

<!-- Sol: Misafir Bilgileri -->
<div>
    <div class="card">
        <div class="card-title">
            👤 Misafir Bilgileri
            <?php echo guest_status_badge($guest['status']); ?>
            <?php if ($guest['vip_status']): ?><span class="badge badge-warning">⭐ VIP</span><?php endif; ?>
            <?php if ($guest['fidelio_id']): ?>
                <span class="badge badge-info" style="float:right;">Fidelio: <?php echo h($guest['fidelio_id']); ?></span>
            <?php endif; ?>
        </div>
        <?php if (has_role('admin','reception')): ?>
        <form method="post">
            <?php echo csrf_field(); ?>
            <div class="form-row">
                <div class="form-group">
                    <label>Ad</label>
                    <input type="text" name="first_name" class="form-control" value="<?php echo h($guest['first_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Soyad</label>
                    <input type="text" name="last_name" class="form-control" value="<?php echo h($guest['last_name']); ?>" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Oda No</label>
                    <input type="text" name="room_no" class="form-control" value="<?php echo h($guest['room_no'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Telefon (WhatsApp)</label>
                    <input type="text" name="phone" class="form-control" value="<?php echo h($guest['phone'] ?? ''); ?>" placeholder="+905...">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>E-posta</label>
                    <input type="email" name="email" class="form-control" value="<?php echo h($guest['email'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Uyruk</label>
                    <input type="text" name="nationality" class="form-control" value="<?php echo h($guest['nationality'] ?? ''); ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Check-in</label>
                    <input type="datetime-local" name="checkin_date" class="form-control"
                           value="<?php echo $guest['checkin_date'] ? date('Y-m-d\TH:i', strtotime($guest['checkin_date'])) : ''; ?>">
                </div>
                <div class="form-group">
                    <label>Check-out</label>
                    <input type="datetime-local" name="checkout_date" class="form-control"
                           value="<?php echo $guest['checkout_date'] ? date('Y-m-d\TH:i', strtotime($guest['checkout_date'])) : ''; ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Durum</label>
                    <select name="status" class="form-control">
                        <?php foreach (['reserved'=>'Rezervasyon','checked_in'=>'Check-in','checked_out'=>'Check-out','cancelled'=>'İptal'] as $k=>$v): ?>
                            <option value="<?php echo $k; ?>" <?php echo $guest['status']===$k?'selected':''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="justify-content:flex-end; align-items:flex-end; display:flex;">
                    <label><input type="checkbox" name="vip_status" <?php echo $guest['vip_status']?'checked':''; ?>> ⭐ VIP</label>
                </div>
            </div>
            <div class="form-group">
                <label>Notlar</label>
                <textarea name="notes" class="form-control" rows="3"><?php echo h($guest['notes'] ?? ''); ?></textarea>
            </div>
            <button type="submit" name="update_guest" class="btn btn-primary">💾 Kaydet</button>
            <a href="index.php" class="btn btn-secondary" style="margin-left:8px;">← Geri</a>
        </form>
        <?php else: ?>
            <p><strong>Ad Soyad:</strong> <?php echo h($guest['first_name'] . ' ' . $guest['last_name']); ?></p>
            <p><strong>Oda:</strong> <?php echo h($guest['room_no'] ?? '-'); ?></p>
            <p><strong>Telefon:</strong> <?php echo h($guest['phone'] ?? '-'); ?></p>
            <p><strong>Check-in:</strong> <?php echo $guest['checkin_date'] ? date('d.m.Y H:i', strtotime($guest['checkin_date'])) : '-'; ?></p>
            <p><strong>Check-out:</strong> <?php echo $guest['checkout_date'] ? date('d.m.Y H:i', strtotime($guest['checkout_date'])) : '-'; ?></p>
        <?php endif; ?>
    </div>

    <!-- Hotspot Oturumları -->
    <div class="card">
        <div class="card-title">📶 Hotspot Oturumları</div>

        <?php if (has_role('admin','it_staff')): ?>
        <form method="post" style="margin-bottom:14px; padding:12px; background:#f5f7fa; border-radius:8px;">
            <?php echo csrf_field(); ?>
            <div style="font-size:12px; font-weight:700; margin-bottom:8px;">➕ Yeni Oturum Başlat</div>
            <div class="form-row">
                <div class="form-group">
                    <label>MAC Adresi</label>
                    <input type="text" name="hs_mac" class="form-control" placeholder="aa:bb:cc:dd:ee:ff" required>
                </div>
                <div class="form-group" style="max-width:140px;">
                    <label>Profil</label>
                    <select name="hs_profile" class="form-control">
                        <option value="standard">Standard (10M)</option>
                        <option value="premium">Premium (50M)</option>
                        <option value="vip" <?php echo $guest['vip_status']?'selected':''; ?>>VIP (100M)</option>
                    </select>
                </div>
                <div class="form-group" style="max-width:120px; justify-content:flex-end; align-items:flex-end; display:flex;">
                    <button type="submit" name="start_hotspot" class="btn btn-success btn-sm">Başlat</button>
                </div>
            </div>
        </form>
        <?php endif; ?>

        <?php $hs_c = 0; while ($hs = $sessions->fetch_assoc()): $hs_c++; ?>
        <div style="display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #f5f5f5; font-size:12px;">
            <div style="flex:1;">
                <div style="font-weight:600;"><?php echo h($hs['mac_address']); ?></div>
                <div style="color:#90a4ae;">
                    <?php echo h($hs['ip_address'] ?? ''); ?> · <?php echo h($hs['bandwidth_profile']); ?><br>
                    ↓<?php echo format_bytes($hs['bytes_in']); ?> ↑<?php echo format_bytes($hs['bytes_out']); ?><br>
                    Başlangıç: <?php echo date('d.m.Y H:i', strtotime($hs['started_at'])); ?>
                    <?php if ($hs['ended_at']): ?> · Bitiş: <?php echo date('d.m.Y H:i', strtotime($hs['ended_at'])); ?><?php endif; ?>
                </div>
            </div>
            <?php if ($hs['status']==='active'): ?>
                <span class="badge badge-success">Aktif</span>
                <?php if (has_role('admin','it_staff')): ?>
                <form method="post" style="display:inline;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="hs_id" value="<?php echo $hs['id']; ?>">
                    <button type="submit" name="end_hotspot" class="btn btn-danger btn-sm">Kes</button>
                </form>
                <?php endif; ?>
            <?php else: ?>
                <span class="badge badge-secondary">Kapalı</span>
            <?php endif; ?>
        </div>
        <?php endwhile; if (!$hs_c): ?>
            <p style="color:#90a4ae; font-size:13px;">Hotspot kaydı bulunamadı.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Sağ: WhatsApp Konuşması -->
<div>
    <div class="card" style="display:flex; flex-direction:column; height:100%;">
        <div class="card-title">💬 WhatsApp Konuşması
            <?php if ($guest['phone']): ?>
                <span style="float:right; font-size:12px; color:#90a4ae;"><?php echo h($guest['phone']); ?></span>
            <?php endif; ?>
        </div>

        <?php if (!$guest['phone']): ?>
            <div class="alert alert-warning">Bu misafir için telefon numarası kayıtlı değil.</div>
        <?php endif; ?>

        <div class="chat-container" id="chatContainer">
            <?php $msg_c = 0; while ($m = $messages->fetch_assoc()): $msg_c++; ?>
            <div>
                <div class="chat-bubble <?php echo $m['direction']; ?>">
                    <?php if ($m['template_name']): ?>
                        <em style="font-size:10px; color:#888;">[Şablon: <?php echo h($m['template_name']); ?>]</em><br>
                    <?php endif; ?>
                    <?php echo nl2br(h($m['content'] ?? '')); ?>
                    <div class="chat-meta">
                        <?php echo date('d.m H:i', strtotime($m['created_at'])); ?>
                        <?php if ($m['direction']==='outbound'): ?>
                            · <?php echo h($m['staff_name'] ?? 'Sistem'); ?>
                            · <?php echo $m['status']; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endwhile; if (!$msg_c): ?>
                <p style="color:#90a4ae; font-size:13px; text-align:center; padding:20px 0;">Henüz mesaj yok.</p>
            <?php endif; ?>
        </div>

        <?php if (has_role('admin','reception') && $guest['phone']): ?>
        <form method="post" style="margin-top:14px; display:flex; gap:8px;">
            <?php echo csrf_field(); ?>
            <textarea name="wa_message" class="form-control" rows="2"
                      placeholder="Mesaj yazın..." style="flex:1; resize:none;"></textarea>
            <button type="submit" name="send_wa" class="btn btn-success" style="align-self:flex-end;">📤 Gönder</button>
        </form>

        <!-- Şablonlu Mesajlar -->
        <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="wa_message" value="Merhaba <?php echo h($guest['first_name']); ?>! Otelinize hoş geldiniz. Odanız: <?php echo h($guest['room_no'] ?? '-'); ?>. WiFi şifreniz oda numaranızdır. İyi konaklamalar! 🏨">
                <button type="submit" name="send_wa" class="btn btn-info btn-sm">🏨 Karşılama</button>
            </form>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="wa_message" value="Sayın <?php echo h($guest['first_name']); ?>, check-out tarihiniz <?php echo $guest['checkout_date'] ? date('d.m.Y', strtotime($guest['checkout_date'])) : '—'; ?>. Konaklamanız nasıldı? Değerlendirmenizi bekliyoruz. 🙏">
                <button type="submit" name="send_wa" class="btn btn-warning btn-sm">🧳 Check-out Hatırlatma</button>
            </form>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="wa_message" value="📶 WiFi Bağlantı Bilgisi:\nAğ: Otel_Guest\nŞifre: <?php echo h($guest['room_no'] ?? 'odaniz'); ?>\n\nYardım için resepsiyonu arayın.">
                <button type="submit" name="send_wa" class="btn btn-secondary btn-sm">📶 WiFi Bilgisi</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

</div><!-- grid -->

<script>
// Otomatik chat scroll
var cc = document.getElementById('chatContainer');
if (cc) cc.scrollTop = cc.scrollHeight;
</script>

</div></body></html>
