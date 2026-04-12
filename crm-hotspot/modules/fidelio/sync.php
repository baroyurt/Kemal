<?php
// ============================================================
// Fidelio Otomatik Senkronizasyon — Cron Script
// Kullanım (crontab):
//   */5 * * * * php /path/to/crm-hotspot/modules/fidelio/sync.php
// ============================================================

// Oturum gerektirmez; CLI veya cron'dan çalışır
define('RUNNING_AS_CRON', true);

require_once __DIR__ . '/../../config/config.php';
require_once APP_ROOT . '/lib/FidelioClient.php';

$fidelio = new FidelioClient();
$result  = $fidelio->syncGuests($conn);

$ts = date('Y-m-d H:i:s');
if ($result['success']) {
    echo "[$ts] ✅ Fidelio sync OK — işlenen: {$result['processed']}, güncellenen: {$result['updated']}\n";
} else {
    echo "[$ts] ❌ Fidelio sync HATA — {$result['error']}\n";
}

// Ayrıca check-out durumundaki misafirler için WhatsApp anket gönder
if ($result['success']) {
    require_once APP_ROOT . '/lib/WhatsAppClient.php';
    $wa = new WhatsAppClient();

    // Bugün checkout olanlar ve anket henüz gönderilmemişler
    $checkouts = $conn->query(
        "SELECT g.id, g.first_name, g.phone
         FROM guests g
         WHERE g.status='checked_out'
           AND DATE(g.checkout_date) = CURDATE()
           AND g.phone IS NOT NULL
           AND g.phone != ''
           AND NOT EXISTS (
               SELECT 1 FROM whatsapp_messages wm
               WHERE wm.guest_id = g.id
                 AND wm.template_name = 'checkout_survey'
                 AND DATE(wm.created_at) = CURDATE()
           )"
    );

    $survey_count = 0;
    while ($g = $checkouts->fetch_assoc()) {
        $wa->sendTemplate(
            $conn,
            $g['id'],
            $g['phone'],
            'checkout_survey',
            [
                ['type' => 'body', 'parameters' => [
                    ['type' => 'text', 'text' => $g['first_name']],
                ]],
            ]
        );
        $survey_count++;
    }
    if ($survey_count > 0) {
        echo "[$ts] 📤 $survey_count check-out anket mesajı gönderildi.\n";
    }
}
