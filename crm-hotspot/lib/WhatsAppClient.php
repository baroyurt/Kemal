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
    // Check-in karşılama mesajı
    // -------------------------------------------------------
    public function sendWelcome(mysqli $conn, int $guest_id, string $phone, string $first_name, string $room_no): array
    {
        $text = "Merhaba $first_name! Otelinize hoş geldiniz. 🏨\n"
              . "Odanız: $room_no\n"
              . "WiFi şifreniz: $room_no\n"
              . "Herhangi bir ihtiyacınızda bu numaradan bize ulaşabilirsiniz.";
        return $this->sendText($conn, $guest_id, $phone, $text);
    }

    // -------------------------------------------------------
    // Webhook'tan gelen mesajı kaydet
    // -------------------------------------------------------
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

                    $this->saveMessage($conn, $guest_id, 'inbound', $from, $type, $content, null, 'received', $wa_msg_id);
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
