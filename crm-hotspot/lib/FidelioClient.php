<?php
// ============================================================
// Fidelio PMS İstemcisi
// FIAS (Fidelio Interface API Server) TCP veya Opera REST API
// ============================================================

class FidelioClient
{
    private string $mode;

    public function __construct()
    {
        $this->mode = FIDELIO_MODE; // 'fias' | 'rest'
    }

    // -------------------------------------------------------
    // Ana senkronizasyon: aktif konaklamaları çek
    // -------------------------------------------------------
    public function syncGuests(mysqli $conn): array
    {
        if ($this->mode === 'fias') {
            return $this->syncViaFias($conn);
        }
        return $this->syncViaRest($conn);
    }

    // -------------------------------------------------------
    // FIAS TCP Soket Modu
    // -------------------------------------------------------
    private function syncViaFias(mysqli $conn): array
    {
        $processed = 0;
        $updated   = 0;
        $error     = null;

        $sock = @fsockopen(FIDELIO_FIAS_HOST, FIDELIO_FIAS_PORT, $errno, $errstr, 5);
        if (!$sock) {
            return ['success' => false, 'error' => "FIAS bağlantı hatası: $errstr ($errno)"];
        }

        stream_set_timeout($sock, 5);

        // FIAS link başlatma mesajı
        $link_start = "\x02LS|STYPE=CRM|HOTEL=" . FIDELIO_HOTEL_CODE . "|\x03\r\n";
        fwrite($sock, $link_start);

        // Rezervasyon sorgulama
        $query = "\x02RQ|STATUS=I|\x03\r\n"; // I = checked in
        fwrite($sock, $query);

        $buffer  = '';
        $timeout = time() + 5;
        while (!feof($sock) && time() < $timeout) {
            $chunk = fread($sock, 4096);
            if ($chunk === false || $chunk === '') {
                if (stream_get_meta_data($sock)['timed_out']) break;
                continue;
            }
            $buffer .= $chunk;
        }
        fclose($sock);

        // FIAS mesajları STX...ETX formatında gelir
        preg_match_all('/\x02(.*?)\x03/s', $buffer, $matches);
        foreach ($matches[1] as $msg) {
            $fields = $this->parseFiasMessage($msg);
            if (empty($fields['RN'])) continue; // Oda numarası yoksa atla
            $processed++;
            $updated += (int)$this->upsertGuest($conn, $fields);
        }

        $this->logSync($conn, 'auto', $error ? 'error' : 'success', $processed, $updated, $error);
        return ['success' => !$error, 'processed' => $processed, 'updated' => $updated, 'error' => $error];
    }

    // -------------------------------------------------------
    // Opera REST API Modu (v5+)
    // -------------------------------------------------------
    private function syncViaRest(mysqli $conn): array
    {
        $processed = 0;
        $updated   = 0;
        $error     = null;

        $url     = FIDELIO_REST_URL . '/reservations?hotelCode=' . FIDELIO_HOTEL_CODE . '&reservationStatus=INHOUSE&limit=200';
        $headers = [
            'Authorization: Bearer ' . FIDELIO_REST_TOKEN,
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            $error = $curl_err;
            $this->logSync($conn, 'auto', 'error', 0, 0, $error);
            return ['success' => false, 'error' => $error];
        }

        $data         = json_decode($response, true);
        $reservations = $data['reservations'] ?? $data['data'] ?? [];

        foreach ($reservations as $res) {
            $processed++;
            $fields = $this->mapRestReservation($res);
            $updated += (int)$this->upsertGuest($conn, $fields);
        }

        $this->logSync($conn, 'auto', 'success', $processed, $updated, null);
        return ['success' => true, 'processed' => $processed, 'updated' => $updated];
    }

    // -------------------------------------------------------
    // FIAS mesaj ayrıştırıcı: "KEY=VALUE|KEY=VALUE|..."
    // -------------------------------------------------------
    private function parseFiasMessage(string $msg): array
    {
        $fields = [];
        foreach (explode('|', $msg) as $pair) {
            if (strpos($pair, '=') !== false) {
                [$k, $v] = explode('=', $pair, 2);
                $fields[trim($k)] = trim($v);
            }
        }
        return $fields;
    }

    // -------------------------------------------------------
    // Opera REST rezervasyon verisini iç formata dönüştür
    // -------------------------------------------------------
    private function mapRestReservation(array $res): array
    {
        $guest = $res['guests'][0] ?? [];
        return [
            'FI'  => $res['reservationIdList']['id'][0]['id'] ?? '',   // Fidelio ID
            'RN'  => $res['roomNumber'] ?? '',
            'GN'  => $guest['firstName'] ?? '',
            'GSN' => $guest['lastName'] ?? '',
            'PT'  => $guest['phones'][0]['phoneNumber'] ?? '',
            'EM'  => $guest['emails'][0]['emailAddress'] ?? '',
            'NA'  => $guest['nationality'] ?? '',
            'VIP' => !empty($res['vipCode']) ? 1 : 0,
            'AR'  => isset($res['timeSpan']['startDate']) ? date('Y-m-d H:i:s', strtotime($res['timeSpan']['startDate'])) : null,
            'DEP' => isset($res['timeSpan']['endDate'])   ? date('Y-m-d H:i:s', strtotime($res['timeSpan']['endDate']))   : null,
            'ST'  => 'checked_in',
            'RESNO' => $res['reservationIdList']['id'][1]['id'] ?? '',
        ];
    }

    // -------------------------------------------------------
    // Misafiri guests tablosuna upsert et
    // Returns true if record was inserted/updated
    // -------------------------------------------------------
    private function upsertGuest(mysqli $conn, array $f): bool
    {
        $fid    = $f['FI'] ?? '';
        $room   = $f['RN'] ?? '';
        $first  = $f['GN'] ?? '';
        $last   = $f['GSN'] ?? '';
        $phone  = $f['PT'] ?? '';
        $email  = $f['EM'] ?? '';
        $nation = $f['NA'] ?? '';
        $vip    = (int)($f['VIP'] ?? 0);
        $ci     = $f['AR'] ?? null;
        $co     = $f['DEP'] ?? null;
        $status = $f['ST'] ?? 'checked_in';
        $res_no = $f['RESNO'] ?? '';

        if ($fid) {
            $st = $conn->prepare('SELECT id FROM guests WHERE fidelio_id=? LIMIT 1');
            $st->bind_param('s', $fid);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
        } else {
            $row = null;
        }

        if ($row) {
            $st = $conn->prepare(
                'UPDATE guests SET room_no=?,first_name=?,last_name=?,phone=?,email=?,nationality=?,
                 vip_status=?,status=?,checkin_date=?,checkout_date=?,reservation_no=? WHERE id=?'
            );
            $st->bind_param('sssssisssssi',
                $room,$first,$last,$phone,$email,$nation,$vip,$status,$ci,$co,$res_no,$row['id']);
            $st->execute();
            $changed = $conn->affected_rows > 0;
            $st->close();
            return $changed;
        } else {
            $st = $conn->prepare(
                'INSERT INTO guests (fidelio_id,room_no,first_name,last_name,phone,email,nationality,
                 vip_status,status,checkin_date,checkout_date,reservation_no)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $st->bind_param('sssssssissss',
                $fid,$room,$first,$last,$phone,$email,$nation,$vip,$status,$ci,$co,$res_no);
            $st->execute();
            $st->close();
            return true;
        }
    }

    // -------------------------------------------------------
    // Senkronizasyon kaydı
    // -------------------------------------------------------
    public function logSync(mysqli $conn, string $type, string $status, int $processed, int $updated, ?string $error): void
    {
        $st = $conn->prepare(
            'INSERT INTO fidelio_sync_log (sync_type,status,records_processed,records_updated,error_message)
             VALUES (?,?,?,?,?)'
        );
        $st->bind_param('ssiss', $type, $status, $processed, $updated, $error);
        $st->execute();
        $st->close();
    }
}
