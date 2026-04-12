<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Otel WiFi — Giriş</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #0d47a1 0%, #1565c0 50%, #283593 100%);
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 16px;
        }
        .portal-box {
            background: #fff; border-radius: 20px; padding: 36px 32px;
            width: 420px; max-width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,.35);
            text-align: center;
        }
        .hotel-logo { font-size: 52px; margin-bottom: 6px; }
        .hotel-name { font-size: 22px; font-weight: 800; color: #0d47a1; margin-bottom: 4px; }
        .subtitle   { color: #78909c; font-size: 13px; margin-bottom: 24px; }
        .form-group { text-align: left; margin-bottom: 14px; }
        label { display: block; font-size: 11px; font-weight: 700; color: #546e7a;
                margin-bottom: 4px; text-transform: uppercase; letter-spacing: .5px; }
        input[type="text"], input[type="date"] {
            width: 100%; padding: 11px 14px; border: 2px solid #e0e0e0;
            border-radius: 10px; font-size: 15px; transition: border-color .2s;
            background: #fafafa;
        }
        input:focus { outline: none; border-color: #1565c0; background: #fff; }
        .form-row { display: flex; gap: 10px; }
        .form-row .form-group { flex: 1; }
        .btn-connect {
            width: 100%; padding: 14px; background: linear-gradient(135deg, #1565c0, #0d47a1);
            color: #fff; border: none; border-radius: 10px; font-size: 16px; font-weight: 700;
            cursor: pointer; margin-top: 10px; letter-spacing: .5px; transition: opacity .2s;
        }
        .btn-connect:hover { opacity: .9; }
        .alert {
            border-radius: 10px; padding: 12px 16px; font-size: 13px;
            margin-bottom: 16px; text-align: left;
        }
        .alert-danger  { background: #ffebee; color: #c62828; border-left: 4px solid #ef5350; }
        .alert-success { background: #e8f5e9; color: #2e7d32; border-left: 4px solid #4caf50; }
        .wifi-info {
            background: #e3f2fd; border-radius: 10px; padding: 16px;
            margin-bottom: 16px; font-size: 14px; color: #1565c0;
        }
        .wifi-info .ssid  { font-size: 20px; font-weight: 800; margin: 6px 0; }
        .wifi-info .badge-vip {
            display: inline-block; background: #ffd700; color: #333;
            border-radius: 20px; padding: 2px 10px; font-size: 11px;
            font-weight: 700; margin-left: 6px;
        }
        .redirect-info {
            margin-top: 10px; font-size: 12px; color: #78909c;
        }
        footer { margin-top: 24px; font-size: 11px; color: #b0bec5; line-height: 1.6; }
        .provider-badge {
            display: inline-block; font-size: 10px; background: #e8eaf6;
            color: #3949ab; border-radius: 20px; padding: 2px 8px; margin-top: 4px;
        }
        .steps {
            display: flex; gap: 8px; margin-bottom: 20px; text-align: center;
        }
        .step { flex: 1; font-size: 11px; color: #90a4ae; }
        .step-icon { font-size: 20px; display: block; margin-bottom: 2px; }
    </style>
</head>
<body>
<div class="portal-box">
    <div class="hotel-logo">🏨</div>
    <div class="hotel-name">Otel WiFi</div>
    <p class="subtitle">Ücretsiz internet erişimi için kimliğinizi doğrulayın</p>

    <?php
    session_start();
    require_once __DIR__ . '/../../config/config.php';

    // -------------------------------------------------------
    // Meraki captive portal parametrelerini yakala ve sakla
    // -------------------------------------------------------
    if (!empty($_GET['base_grant_url'])) {
        $bgurl = filter_var($_GET['base_grant_url'], FILTER_SANITIZE_URL);
        // Only accept URLs that start with https:// to prevent open redirects
        if (str_starts_with($bgurl, 'https://')) {
            $_SESSION['meraki_base_grant_url'] = $bgurl;
        }
    }
    if (!empty($_GET['continue_url'])) {
        $curl = filter_var($_GET['continue_url'], FILTER_SANITIZE_URL);
        if (str_starts_with($curl, 'https://') || str_starts_with($curl, 'http://')) {
            $_SESSION['meraki_continue_url'] = $curl;
        }
    }
    if (!empty($_GET['client_mac'])) {
        $raw_mac = $_GET['client_mac'];
        if (preg_match('/^([0-9a-fA-F]{2}[:\-]){5}[0-9a-fA-F]{2}$/', $raw_mac)) {
            $_SESSION['meraki_client_mac'] = strtolower($raw_mac);
        }
    }
    if (!empty($_GET['client_ip'])) {
        $_SESSION['meraki_client_ip'] = filter_var($_GET['client_ip'], FILTER_VALIDATE_IP) ?: '';
    }

    $is_meraki = (HOTSPOT_PROVIDER === 'meraki');
    $error     = '';
    $success   = false;
    $guest     = null;

    // -------------------------------------------------------
    // Form POST — kimlik doğrulama
    // -------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();

        $id_number  = trim($_POST['id_number']  ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name']  ?? '');
        $birth_date = trim($_POST['birth_date'] ?? '');

        // Validate inputs
        if (empty($id_number) || empty($first_name) || empty($last_name) || empty($birth_date)) {
            $error = 'Lütfen tüm alanları doldurunuz.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
            $error = 'Geçersiz doğum tarihi formatı.';
        } else {
            // Match: id_number + first_name + last_name + birth_date (case-insensitive name)
            $st = $conn->prepare(
                "SELECT id, first_name, last_name, room_no, phone, vip_status
                 FROM guests
                 WHERE LOWER(id_number)  = LOWER(?)
                   AND LOWER(first_name) = LOWER(?)
                   AND LOWER(last_name)  = LOWER(?)
                   AND birth_date        = ?
                   AND status            = 'checked_in'
                 LIMIT 1"
            );
            $st->bind_param('ssss', $id_number, $first_name, $last_name, $birth_date);
            $st->execute();
            $guest = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$guest) {
                $error = 'Kimlik bilgileri eşleşmedi veya aktif bir konaklamanız bulunamadı. Lütfen resepsiyon ile görüşün.';
            } else {
                $success = true;
                $gid     = (int)$guest['id'];
                $prof    = $guest['vip_status'] ? 'vip' : 'standard';
                $room_no = $guest['room_no'];

                // Resolve MAC & IP
                $mac = $_SESSION['meraki_client_mac']
                    ?? (function () {
                        $raw = $_SERVER['HTTP_X_MAC_ADDRESS'] ?? '';
                        return preg_match('/^([0-9a-fA-F]{2}[:\-]){5}[0-9a-fA-F]{2}$/', $raw)
                            ? strtolower($raw) : 'unknown';
                    })();
                $ip = $_SESSION['meraki_client_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');

                // Check for existing session
                $chk = $conn->prepare(
                    "SELECT id FROM hotspot_sessions WHERE mac_address=? AND status='active'"
                );
                $chk->bind_param('s', $mac);
                $chk->execute();
                $existing = $chk->get_result()->fetch_assoc();
                $chk->close();

                if (!$existing) {
                    // Create hotspot session
                    $ins = $conn->prepare(
                        'INSERT INTO hotspot_sessions (guest_id,mac_address,ip_address,bandwidth_profile,started_at)
                         VALUES (?,?,?,?,NOW())'
                    );
                    $ins->bind_param('isss', $gid, $mac, $ip, $prof);
                    $ins->execute();
                    $ins->close();

                    // Hotspot activation — Mikrotik or Meraki
                    if ($is_meraki) {
                        // Meraki: optionally apply bandwidth group policy
                        // The actual access grant happens via the form POST below (client-side)
                        try {
                            require_once APP_ROOT . '/lib/MerakiClient.php';
                            $meraki = new MerakiClient();
                            // Apply bandwidth group policy if configured
                            $bw_profiles = unserialize(BW_PROFILES);
                            $groupPolicy = $bw_profiles[$prof] ?? null;
                            if ($groupPolicy && $mac !== 'unknown') {
                                $meraki->updateClientPolicy($mac, 'Group policy', $groupPolicy);
                            }
                        } catch (Exception $e) {
                            // Non-fatal: access is still granted via grant form POST
                        }
                    } else {
                        // Mikrotik: create hotspot user
                        try {
                            require_once APP_ROOT . '/lib/MikrotikClient.php';
                            $mikro = new MikrotikClient();
                            if ($mikro->isConnected()) {
                                $mikro->addUser($room_no, $room_no, $prof);
                            }
                        } catch (Exception $e) {
                            // Non-fatal
                        }
                    }

                    // WhatsApp: Hoş Geldiniz + Servis Menüsü (sadece ilk bağlantıda)
                    if (!empty($guest['phone'])) {
                        try {
                            require_once APP_ROOT . '/lib/WhatsAppClient.php';
                            $wa = new WhatsAppClient();
                            // 1. Hoş Geldiniz mesajı
                            $wa->sendWelcome($conn, $gid, $guest['phone'], $guest['first_name'], $room_no);
                            // 2. Servis menüsü — Arıza/Talep/İstek butonları
                            $wa->sendServiceMenu($conn, $gid, $guest['phone']);
                        } catch (Exception $e) {
                            // Non-fatal
                        }
                    }
                }
                // end !$existing
            }
        }
    }
    ?>

    <?php if ($error): ?>
    <div class="alert alert-danger">⚠️ <?php echo h($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <?php $vip_badge = $guest['vip_status'] ? '<span class="badge-vip">⭐ VIP</span>' : ''; ?>
        <div class="alert alert-success">
            ✅ Kimliğiniz doğrulandı. Hoş geldiniz,
            <strong><?php echo h($guest['first_name']); ?></strong>!
        </div>
        <div class="wifi-info">
            <div>Bağlı Ağ</div>
            <div class="ssid">📶 <?php echo h(WIFI_SSID); ?><?php echo $vip_badge; ?></div>
            <?php if (!$is_meraki): ?>
            <div style="margin-top:8px; font-size:13px;">
                Ağ Şifresi: <strong style="letter-spacing:2px;"><?php echo h($guest['room_no']); ?></strong>
            </div>
            <?php endif; ?>
        </div>
        <p style="font-size:13px; color:#555; margin-bottom:12px;">
            📱 WhatsApp numaranıza "<strong>Hoş Geldiniz</strong>" mesajı ve servis menüsü gönderildi.
            İhtiyacınız olduğunda doğrudan sohbet edebilirsiniz.
        </p>

        <?php if ($is_meraki && !empty($_SESSION['meraki_base_grant_url'])): ?>
            <?php
            require_once APP_ROOT . '/lib/MerakiClient.php';
            $grantUrl   = $_SESSION['meraki_base_grant_url'];
            $continueUrl = $_SESSION['meraki_continue_url'] ?? 'http://www.google.com';
            echo MerakiClient::buildGrantForm($grantUrl, $continueUrl);
            ?>
            <div class="redirect-info">⏳ 3 saniye içinde internet erişiminiz açılacak...</div>
        <?php endif; ?>

    <?php else: ?>
        <div class="steps">
            <div class="step">
                <span class="step-icon">🆔</span>
                Kimlik Gir
            </div>
            <div class="step" style="align-self:center; color:#ccc;">→</div>
            <div class="step">
                <span class="step-icon">✅</span>
                Doğrulama
            </div>
            <div class="step" style="align-self:center; color:#ccc;">→</div>
            <div class="step">
                <span class="step-icon">📶</span>
                İnternet
            </div>
        </div>

        <form method="post">
            <?php echo csrf_field(); ?>

            <div class="form-group">
                <label>TC Kimlik No veya Pasaport No</label>
                <input type="text" name="id_number"
                       placeholder="12345678901 veya P1234567"
                       maxlength="30"
                       value="<?php echo h($_POST['id_number'] ?? ''); ?>"
                       autofocus>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Ad</label>
                    <input type="text" name="first_name"
                           placeholder="Ahmet"
                           maxlength="60"
                           value="<?php echo h($_POST['first_name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Soyad</label>
                    <input type="text" name="last_name"
                           placeholder="Yılmaz"
                           maxlength="60"
                           value="<?php echo h($_POST['last_name'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Doğum Tarihi</label>
                <input type="date" name="birth_date"
                       max="<?php echo date('Y-m-d', strtotime('-1 year')); ?>"
                       value="<?php echo h($_POST['birth_date'] ?? ''); ?>">
            </div>

            <button type="submit" class="btn-connect">📶 Bağlan</button>
        </form>

        <div class="provider-badge">
            <?php echo $is_meraki ? '🟢 Cisco Meraki' : '🔵 Mikrotik RouterOS'; ?>
        </div>
    <?php endif; ?>

    <footer>
        Yardım için resepsiyonu arayın.<br>
        <small>Bu hizmet güvenlidir — bilgileriniz yalnızca kimlik doğrulama amacıyla kullanılır.</small>
    </footer>
</div>
</body>
</html>
