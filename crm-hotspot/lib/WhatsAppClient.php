<?php
// ============================================================
// WhatsApp Business Cloud API İstemcisi
// Meta Cloud API v19.0
// ============================================================

class WhatsAppClient
{
    private string $phoneNumberId;
    private string $accessToken;
    private string $apiVersion;
    private string $baseUrl;

    public function __construct()
    {
        $this->phoneNumberId = WA_PHONE_NUMBER_ID;
        $this->accessToken   = WA_ACCESS_TOKEN;
        $this->apiVersion    = WA_API_VERSION;
        $this->baseUrl       = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
    }

    // -------------------------------------------------------
    // Serbest metin mesajı gönder
    // -------------------------------------------------------
    public function sendText(mysqli $conn, int $guest_id, string $to, string $text, int $staff_id = 0): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $this->normalizePhone($to),
            'type'              => 'text',
            'text'              => ['body' => $text],
        ];

        $result = $this->apiCall($payload);

        $wa_msg_id = $result['messages'][0]['id'] ?? null;
        $status    = $result['error'] ? 'failed' : 'sent';
        $this->saveMessage($conn, $guest_id, 'outbound', $to, 'text', $text, null, $status, $wa_msg_id, $staff_id);

        return $result;
    }

    // -------------------------------------------------------
    // Şablonlu mesaj gönder (Meta onaylı template)
    // -------------------------------------------------------
    public function sendTemplate(mysqli $conn, int $guest_id, string $to, string $templateName, array $components = [], string $language = 'tr'): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $this->normalizePhone($to),
            'type'              => 'template',
            'template'          => [
                'name'       => $templateName,
                'language'   => ['code' => $language],
                'components' => $components,
            ],
        ];

        $result = $this->apiCall($payload);

        $wa_msg_id = $result['messages'][0]['id'] ?? null;
        $status    = $result['error'] ? 'failed' : 'sent';
        $this->saveMessage($conn, $guest_id, 'outbound', $to, 'template', null, $templateName, $status, $wa_msg_id);

        return $result;
    }

    // -------------------------------------------------------
    // İnteraktif buton mesajı gönder (max 3 buton)
    // -------------------------------------------------------
    public function sendInteractiveButtons(
        mysqli $conn, int $guest_id, string $to,
        string $header_text, string $body_text,
        array  $buttons  // [['id'=>'...','title'=>'...'], ...]
    ): array {
        $btn_objects = [];
        foreach (array_slice($buttons, 0, 3) as $b) {
            $btn_objects[] = [
                'type'  => 'reply',
                'reply' => [
                    'id'    => substr($b['id'],    0, 256),
                    'title' => substr($b['title'], 0, 20),
                ],
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $this->normalizePhone($to),
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'button',
                'header' => ['type' => 'text', 'text' => substr($header_text, 0, 60)],
                'body'   => ['text' => substr($body_text, 0, 1024)],
                'action' => ['buttons' => $btn_objects],
            ],
        ];

        $result    = $this->apiCall($payload);
        $wa_msg_id = $result['messages'][0]['id'] ?? null;
        $status    = isset($result['error']) ? 'failed' : 'sent';
        $this->saveMessage($conn, $guest_id, 'outbound', $to, 'text', $body_text, null, $status, $wa_msg_id);
        return $result;
    }

    // -------------------------------------------------------
    // İnteraktif liste mesajı gönder (max 10 satır)
    // $sections: [['title'=>'...','rows'=>[['id'=>'...','title'=>'...','description'=>'...'],...]], ...]
    // -------------------------------------------------------
    public function sendInteractiveList(
        mysqli $conn, int $guest_id, string $to,
        string $body_text, string $button_label,
        array  $sections
    ): array {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $this->normalizePhone($to),
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'list',
                'body'   => ['text' => substr($body_text, 0, 1024)],
                'action' => [
                    'button'   => substr($button_label, 0, 20),
                    'sections' => $sections,
                ],
            ],
        ];

        $result    = $this->apiCall($payload);
        $wa_msg_id = $result['messages'][0]['id'] ?? null;
        $status    = isset($result['error']) ? 'failed' : 'sent';
        $this->saveMessage($conn, $guest_id, 'outbound', $to, 'text', $body_text, null, $status, $wa_msg_id);
        return $result;
    }

    // -------------------------------------------------------
    // Check-in karşılama mesajı
    // -------------------------------------------------------
    public function sendWelcome(mysqli $conn, int $guest_id, string $phone, string $first_name, string $room_no): array
    {
        $ssid = defined('WIFI_SSID') ? WIFI_SSID : 'Otel_Guest';
        $text = "🏨 *Hoş Geldiniz, $first_name!*\n\n"
              . "Otelinize güzel bir konaklama dileriz.\n\n"
              . "📶 *WiFi Bilgisi*\n"
              . "Ağ Adı: $ssid\n"
              . (HOTSPOT_PROVIDER === 'meraki' ? '' : "Şifre: $room_no\n\n")
              . "🛎️ Herhangi bir ihtiyacınızda aşağıdaki menüden bize ulaşabilirsiniz.";
        return $this->sendText($conn, $guest_id, $phone, $text);
    }

    // -------------------------------------------------------
    // Servis Menüsü — Arıza / Talep / İstek ana butonları
    // -------------------------------------------------------
    public function sendServiceMenu(mysqli $conn, int $guest_id, string $to): array
    {
        return $this->sendInteractiveButtons(
            $conn, $guest_id, $to,
            '🛎️ Servis Menüsü',
            "Size nasıl yardımcı olabiliriz?\n\nLütfen aşağıdaki seçeneklerden birini seçin:",
            [
                ['id' => 'type_fault',   'title' => '🔧 Arıza'],
                ['id' => 'type_request', 'title' => '📋 Talep'],
                ['id' => 'type_wish',    'title' => '💬 İstek'],
            ]
        );
    }

    // -------------------------------------------------------
    // Kategori Listesi gönder (type'a göre DB'den çeker)
    // -------------------------------------------------------
    public function sendCategoryMenu(mysqli $conn, int $guest_id, string $to, string $type): array
    {
        $type_labels = ['fault' => '🔧 Arıza', 'request' => '📋 Talep', 'wish' => '💬 İstek'];
        $type_label  = $type_labels[$type] ?? ucfirst($type);

        $st = $conn->prepare(
            "SELECT id, name, icon FROM service_categories
             WHERE type=? AND is_active=1 ORDER BY sort_order LIMIT 10"
        );
        $st->bind_param('s', $type);
        $st->execute();
        $cats = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();

        if (empty($cats)) {
            return $this->sendText($conn, $guest_id, $to, 'Üzgünüz, bu kategori şu an mevcut değil.');
        }

        $rows = [];
        foreach ($cats as $cat) {
            $rows[] = [
                'id'          => 'cat_' . $cat['id'],
                'title'       => substr(($cat['icon'] . ' ' . $cat['name']), 0, 24),
                'description' => '',
            ];
        }

        $body = "$type_label kategorisini seçtiniz.\n\nAlt kategoriyi belirtin:";

        return $this->sendInteractiveList(
            $conn, $guest_id, $to,
            $body,
            '📌 Seçiniz',
            [['title' => $type_label, 'rows' => $rows]]
        );
    }

    // -------------------------------------------------------
    // Chatbot — Gelen mesajı işle (state machine)
    // -------------------------------------------------------
    public function handleChatbot(mysqli $conn, string $from, string $msg_type, string $payload_id, string $payload_title): void
    {
        $phone = '+' . ltrim($from, '+');

        // Misafiri bul
        $st = $conn->prepare('SELECT id, first_name FROM guests WHERE phone=? AND status=\'checked_in\' LIMIT 1');
        $st->bind_param('s', $phone);
        $st->execute();
        $guest = $st->get_result()->fetch_assoc();
        $st->close();
        $guest_id = $guest ? (int)$guest['id'] : 0;

        // Chatbot oturumunu al veya oluştur
        $cs = $conn->prepare('SELECT state, pending_type, pending_category_id FROM guest_chat_sessions WHERE phone=? LIMIT 1');
        $cs->bind_param('s', $phone);
        $cs->execute();
        $session = $cs->get_result()->fetch_assoc();
        $cs->close();

        if (!$session) {
            $ins = $conn->prepare(
                'INSERT INTO guest_chat_sessions (guest_id, phone, state) VALUES (?,?,\'idle\')'
            );
            $ins->bind_param('is', $guest_id, $phone);
            $ins->execute();
            $ins->close();
            $session = ['state' => 'idle', 'pending_type' => null, 'pending_category_id' => null];
        }

        $state = $session['state'];

        // ---- State machine ----
        if ($msg_type === 'button_reply' && str_starts_with($payload_id, 'type_')) {
            // Guest selected Arıza / Talep / İstek
            $type_map = ['type_fault' => 'fault', 'type_request' => 'request', 'type_wish' => 'wish'];
            $selected_type = $type_map[$payload_id] ?? null;

            if ($selected_type) {
                $this->updateChatSession($conn, $phone, 'awaiting_category', $selected_type, null);
                $this->sendCategoryMenu($conn, $guest_id, $phone, $selected_type);
            }
            return;
        }

        if ($msg_type === 'list_reply' && str_starts_with($payload_id, 'cat_')) {
            // Guest selected a sub-category
            $cat_id = (int)substr($payload_id, 4);
            $pending_type = $session['pending_type'];

            if ($cat_id && $pending_type) {
                // Fetch category info
                $cst = $conn->prepare('SELECT name, route_role FROM service_categories WHERE id=? LIMIT 1');
                $cst->bind_param('i', $cat_id);
                $cst->execute();
                $cat = $cst->get_result()->fetch_assoc();
                $cst->close();

                if ($cat) {
                    // Create service request
                    $irst = $conn->prepare(
                        'INSERT INTO service_requests (guest_id, category_id, type, status)
                         VALUES (?,?,?,\'open\')'
                    );
                    $irst->bind_param('iis', $guest_id, $cat_id, $pending_type);
                    $irst->execute();
                    $request_id = $irst->insert_id;
                    $irst->close();

                    // Confirmation message to guest
                    $type_labels = ['fault' => 'Arıza', 'request' => 'Talep', 'wish' => 'İstek'];
                    $type_label  = $type_labels[$pending_type] ?? ucfirst($pending_type);
                    $confirm_msg = "✅ *{$type_label} Bildirimi Alındı*\n\n"
                                 . "Kategori: *{$cat['name']}*\n"
                                 . "Talep No: #$request_id\n\n"
                                 . "İlgili ekibimiz en kısa sürede size dönüş yapacaktır.\n"
                                 . "Teşekkür ederiz! 🏨";
                    $this->sendText($conn, $guest_id, $phone, $confirm_msg);

                    // Notify staff via WhatsApp (if configured)
                    $this->notifyStaff($conn, $pending_type, $cat['name'], $request_id, $guest, $phone);

                    // Reset chat state
                    $this->updateChatSession($conn, $phone, 'idle', null, null);

                    // Re-show service menu after a moment
                    $this->sendServiceMenu($conn, $guest_id, $phone);
                    return;
                }
            }
        }

        // Any text message → (re)show service menu
        if ($state === 'idle' || $msg_type === 'text') {
            $this->updateChatSession($conn, $phone, 'idle', null, null);
            $name = $guest['first_name'] ?? 'Sayın Misafir';
            $this->sendText($conn, $guest_id, $phone, "Merhaba $name! 😊 Size nasıl yardımcı olabiliriz?");
            $this->sendServiceMenu($conn, $guest_id, $phone);
        }
    }

    // -------------------------------------------------------
    // Personeli WhatsApp ile bilgilendir (yapılandırılmışsa)
    // -------------------------------------------------------
    private function notifyStaff(mysqli $conn, string $type, string $category_name, int $request_id, ?array $guest, string $guest_phone): void
    {
        $notify_numbers = [
            'fault'   => defined('WA_NOTIFY_FAULT')   ? WA_NOTIFY_FAULT   : '',
            'request' => defined('WA_NOTIFY_REQUEST')  ? WA_NOTIFY_REQUEST : '',
            'wish'    => defined('WA_NOTIFY_WISH')     ? WA_NOTIFY_WISH    : '',
        ];

        $staff_phone = $notify_numbers[$type] ?? '';
        if (empty($staff_phone)) return;

        $type_labels = ['fault' => '🔧 Arıza', 'request' => '📋 Talep', 'wish' => '💬 İstek'];
        $type_label  = $type_labels[$type] ?? ucfirst($type);
        $guest_name  = $guest ? ($guest['first_name'] . ' (Tel: ' . $guest_phone . ')') : $guest_phone;

        $msg = "🔔 *Yeni $type_label - #$request_id*\n\n"
             . "Misafir: $guest_name\n"
             . "Kategori: $category_name\n\n"
             . "Lütfen yönetim panelinden detayları kontrol edin.";

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $this->normalizePhone($staff_phone),
            'type'              => 'text',
            'text'              => ['body' => $msg],
        ];
        $this->apiCall($payload);
    }

    // -------------------------------------------------------
    // Chatbot oturum durumunu güncelle (upsert)
    // -------------------------------------------------------
    private function updateChatSession(mysqli $conn, string $phone, string $state, ?string $pending_type, ?int $pending_category_id): void
    {
        $st = $conn->prepare(
            'INSERT INTO guest_chat_sessions (phone, state, pending_type, pending_category_id)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE state=VALUES(state), pending_type=VALUES(pending_type),
                                     pending_category_id=VALUES(pending_category_id),
                                     updated_at=NOW()'
        );
        $st->bind_param('sssi', $phone, $state, $pending_type, $pending_category_id);
        $st->execute();
        $st->close();
    }

    public function processWebhook(mysqli $conn, array $body): void
    {
        $entries = $body['entry'] ?? [];
        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];
            foreach ($changes as $change) {
                $value    = $change['value'] ?? [];
                $msgs     = $value['messages'] ?? [];
                $contacts = $value['contacts'] ?? [];

                foreach ($msgs as $msg) {
                    $from      = $msg['from'] ?? '';
                    $wa_msg_id = $msg['id']   ?? '';
                    $type      = $msg['type'] ?? 'text';
                    $content   = '';

                    if ($type === 'text') {
                        $content = $msg['text']['body'] ?? '';
                    } elseif ($type === 'image') {
                        $content = '[Görsel]';
                    } elseif ($type === 'document') {
                        $content = '[Belge: ' . ($msg['document']['filename'] ?? '') . ']';
                    }

                    // Misafiri telefona göre bul
                    $phone  = '+' . ltrim($from, '+');
                    $st     = $conn->prepare('SELECT id FROM guests WHERE phone=? LIMIT 1');
                    $st->bind_param('s', $phone);
                    $st->execute();
                    $row     = $st->get_result()->fetch_assoc();
                    $st->close();
                    $guest_id = $row['id'] ?? null;

                    // ---- Chatbot handling ----
                    $chatbot_type  = '';
                    $chatbot_id    = '';
                    $chatbot_title = '';

                    if ($type === 'text') {
                        $content       = $msg['text']['body'] ?? '';
                        $chatbot_type  = 'text';
                        $chatbot_id    = $content;
                        $chatbot_title = $content;
                    } elseif ($type === 'interactive') {
                        $ia = $msg['interactive'] ?? [];
                        $ia_type = $ia['type'] ?? '';
                        if ($ia_type === 'button_reply') {
                            $chatbot_type  = 'button_reply';
                            $chatbot_id    = $ia['button_reply']['id']    ?? '';
                            $chatbot_title = $ia['button_reply']['title'] ?? '';
                            $content       = '[Buton: ' . $chatbot_title . ']';
                        } elseif ($ia_type === 'list_reply') {
                            $chatbot_type  = 'list_reply';
                            $chatbot_id    = $ia['list_reply']['id']    ?? '';
                            $chatbot_title = $ia['list_reply']['title'] ?? '';
                            $content       = '[Liste seçimi: ' . $chatbot_title . ']';
                        }
                    } elseif ($type === 'image') {
                        $content      = '[Görsel]';
                        $chatbot_type = 'text';
                    } elseif ($type === 'document') {
                        $content      = '[Belge: ' . ($msg['document']['filename'] ?? '') . ']';
                        $chatbot_type = 'text';
                    }

                    $this->saveMessage($conn, $guest_id, 'inbound', $from, $type, $content, null, 'received', $wa_msg_id);

                    // Trigger chatbot for interactive + regular text
                    if ($chatbot_type !== '') {
                        $this->handleChatbot($conn, $from, $chatbot_type, $chatbot_id, $chatbot_title);
                    }
                }
            }
        }
    }

    // -------------------------------------------------------
    // Yardımcılar
    // -------------------------------------------------------
    private function normalizePhone(string $phone): string
    {
        return ltrim(preg_replace('/\s+/', '', $phone), '+');
    }

    private function apiCall(array $payload): array
    {
        $ch = curl_init($this->baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->accessToken,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 10,
        ]);
        $response = curl_exec($ch);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['error' => $err];
        }
        return json_decode($response, true) ?? [];
    }

    private function saveMessage(
        mysqli $conn,
        ?int   $guest_id,
        string $direction,
        string $phone,
        string $type,
        ?string $content,
        ?string $template_name,
        string  $status,
        ?string $wa_message_id,
        int     $staff_id = 0
    ): void {
        $from = $direction === 'outbound' ? WA_FROM_NUMBER : $phone;
        $to   = $direction === 'outbound' ? $phone         : WA_FROM_NUMBER;
        $sid  = $staff_id > 0 ? $staff_id : null;
        $now  = date('Y-m-d H:i:s');

        $st = $conn->prepare(
            'INSERT INTO whatsapp_messages
             (guest_id,direction,from_number,to_number,message_type,content,template_name,status,wa_message_id,staff_id,sent_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->bind_param(
            'issssssssss',
            $guest_id, $direction, $from, $to, $type,
            $content, $template_name, $status, $wa_message_id, $sid, $now
        );
        $st->execute();
        $st->close();
    }
}
