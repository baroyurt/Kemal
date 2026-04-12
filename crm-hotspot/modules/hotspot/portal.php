<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Otel WiFi — Bağlanın</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #1a237e 0%, #283593 100%);
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 16px;
        }
        .portal-box {
            background: #fff; border-radius: 16px; padding: 36px 32px;
            width: 380px; max-width: 100%; box-shadow: 0 10px 40px rgba(0,0,0,.3);
            text-align: center;
        }
        .hotel-logo { font-size: 48px; margin-bottom: 8px; }
        h2 { color: #1a237e; font-size: 20px; margin-bottom: 6px; }
        p  { color: #78909c; font-size: 13px; margin-bottom: 24px; }
        .form-group { text-align: left; margin-bottom: 14px; }
        label { display: block; font-size: 12px; font-weight: 700; color: #546e7a; margin-bottom: 4px; }
        input[type="text"] {
            width: 100%; padding: 11px 14px; border: 2px solid #e0e0e0;
            border-radius: 8px; font-size: 16px; text-align: center;
            letter-spacing: 3px; font-weight: 700; transition: border-color .2s;
        }
        input[type="text"]:focus { outline: none; border-color: #1565c0; }
        .btn-connect {
            width: 100%; padding: 13px; background: #1565c0; color: #fff;
            border: none; border-radius: 8px; font-size: 15px; font-weight: 700;
            cursor: pointer; margin-top: 8px; transition: background .2s;
        }
        .btn-connect:hover { background: #0d47a1; }
        .error-box {
            background: #ffebee; color: #c62828; border-radius: 8px;
            padding: 10px 14px; font-size: 13px; margin-bottom: 14px;
        }
        .success-box {
            background: #e8f5e9; color: #2e7d32; border-radius: 8px;
            padding: 14px; font-size: 14px; margin-bottom: 14px;
        }
        .wifi-icon { font-size: 32px; margin-bottom: 8px; }
        footer { margin-top: 20px; font-size: 11px; color: #b0bec5; }
    </style>
</head>
<body>
<div class="portal-box">
    <div class="hotel-logo">🏨</div>
    <h2>Otel WiFi</h2>
    <p>Oda numaranızı girerek internete bağlanın</p>

    <?php
    session_start();
    require_once __DIR__ . '/../../config/config.php';

    $error   = '';
    $success = false;
    $wifi_password = '';

    if (isset($_POST['room_no'])) {
        csrf_verify();
        $room_no = trim($_POST['room_no']);

        if (empty($room_no)) {
            $error = 'Oda numarasını giriniz.';
        } else {
            // Fidelio/veritabanında oda kontrolü
            $st = $conn->prepare(
                "SELECT id, first_name, room_no, vip_status
                 FROM guests WHERE room_no=? AND status='checked_in' LIMIT 1"
            );
            $st->bind_param('s', $room_no);
            $st->execute();
            $guest = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$guest) {
                $error = 'Bu oda numarasına ait aktif bir konaklama bulunamadı.';
            } else {
                // WiFi şifresi: oda numarası (Mikrotik hotspot kullanıcı şifresi)
                $wifi_password = $room_no;
                $success       = true;

                // MAC adresini al (NAS-Port üzerinden captive portal MAC)
                $mac    = $_SERVER['HTTP_X_MAC_ADDRESS'] ?? 'unknown';
                $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
                $prof   = $guest['vip_status'] ? 'vip' : 'standard';
                $gid    = $guest['id'];

                // Oturum kaydı oluştur
                $chk = $conn->prepare(
                    "SELECT id FROM hotspot_sessions WHERE mac_address=? AND status='active'"
                );
                $chk->bind_param('s', $mac);
                $chk->execute();
                $existing = $chk->get_result()->fetch_assoc();
                $chk->close();

                if (!$existing) {
                    $st = $conn->prepare(
                        'INSERT INTO hotspot_sessions (guest_id,mac_address,ip_address,bandwidth_profile,started_at)
                         VALUES (?,?,?,?,NOW())'
                    );
                    $st->bind_param('isss', $gid, $mac, $ip, $prof);
                    $st->execute();
                    $st->close();

                    // Mikrotik ile hotspot kullanıcısı oluştur
                    try {
                        require_once APP_ROOT . '/lib/MikrotikClient.php';
                        $mikro = new MikrotikClient();
                        if ($mikro->isConnected()) {
                            $mikro->addUser($room_no, $room_no, $prof);
                        }
                    } catch (Exception $e) {
                        // Sessizce devam et
                    }
                }

                // WhatsApp: WiFi bilgisi gönder (sadece ilk bağlantıda)
                if (!$existing && $guest) {
                    $g_phone_st = $conn->prepare("SELECT phone FROM guests WHERE id=?");
                    $g_phone_st->bind_param('i', $gid);
                    $g_phone_st->execute();
                    $g_row = $g_phone_st->get_result()->fetch_assoc();
                    $g_phone_st->close();
                    if (!empty($g_row['phone'])) {
                        try {
                            require_once APP_ROOT . '/lib/WhatsAppClient.php';
                            $wa = new WhatsAppClient();
                            $wa->sendText(
                                $conn, $gid, $g_row['phone'],
                                "📶 WiFi bağlantınız aktif edildi!\nAğ: Otel_Guest\nŞifre: $room_no\nİyi günler! 🏨"
                            );
                        } catch (Exception $e) {
                            // Sessizce devam et
                        }
                    }
                }
            }
        }
    }
    ?>

    <?php if ($error): ?>
    <div class="error-box">⚠️ <?php echo h($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="success-box">
        <div class="wifi-icon">✅</div>
        <strong>Bağlantı Hazır!</strong><br><br>
        Ağ Adı: <strong>Otel_Guest</strong><br>
        Şifre: <strong style="letter-spacing:2px; font-size:18px;"><?php echo h($wifi_password); ?></strong><br><br>
        <small>Bu şifreyi WiFi ayarlarınıza girin.</small>
    </div>
    <?php else: ?>
    <form method="post">
        <?php echo csrf_field(); ?>
        <div class="form-group">
            <label>ODA NUMARASI</label>
            <input type="text" name="room_no" placeholder="101" maxlength="20"
                   value="<?php echo h($_POST['room_no'] ?? ''); ?>" autofocus>
        </div>
        <button type="submit" class="btn-connect">📶 Bağlan</button>
    </form>
    <?php endif; ?>

    <footer>Yardım için resepsiyonu arayın.</footer>
</div>
</body>
</html>
