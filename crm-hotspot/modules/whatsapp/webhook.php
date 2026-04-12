<?php
// ============================================================
// WhatsApp Meta Business Cloud API Webhook Alıcısı
// URL: /crm-hotspot/modules/whatsapp/webhook.php
//
// Meta Dashboard'da Webhook URL olarak bu dosyayı gösterin.
// Verify Token: WA_VERIFY_TOKEN (config/config.php)
// ============================================================

require_once __DIR__ . '/../../config/config.php';

// -------------------------------------------------------
// GET: Meta webhook doğrulama
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode']          ?? '';
    $token     = $_GET['hub_verify_token']  ?? '';
    $challenge = $_GET['hub_challenge']     ?? '';

    if ($mode === 'subscribe' && $token === WA_VERIFY_TOKEN) {
        http_response_code(200);
        echo $challenge;
        exit;
    }
    http_response_code(403);
    echo 'Verification failed';
    exit;
}

// -------------------------------------------------------
// POST: Gelen mesaj/durum bildirimi
// -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);

    if (!$body) {
        http_response_code(400);
        exit;
    }

    require_once APP_ROOT . '/lib/WhatsAppClient.php';
    $wa = new WhatsAppClient();
    $wa->processWebhook($conn, $body);

    // Durum güncellemeleri (delivered, read)
    $entries = $body['entry'] ?? [];
    foreach ($entries as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            $statuses = $change['value']['statuses'] ?? [];
            foreach ($statuses as $st) {
                $wa_id  = $st['id'] ?? '';
                $status = $st['status'] ?? ''; // sent | delivered | read | failed
                if ($wa_id && $status) {
                    $upd = $conn->prepare(
                        "UPDATE whatsapp_messages SET status=? WHERE wa_message_id=?"
                    );
                    $upd->bind_param('ss', $status, $wa_id);
                    $upd->execute();
                    $upd->close();
                }
            }
        }
    }

    http_response_code(200);
    echo 'OK';
    exit;
}

http_response_code(405);
echo 'Method Not Allowed';
