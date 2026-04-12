<?php
// ============================================================
// Mikrotik RouterOS API İstemcisi
// Bağlantı: TCP port 8728 (plain) veya 8729 (SSL)
// ============================================================

class MikrotikClient
{
    private $socket = null;
    private bool $connected = false;
    private string $lastError = '';

    public function __construct()
    {
        $this->connect();
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    // -------------------------------------------------------
    // Bağlantı kur
    // -------------------------------------------------------
    public function connect(): bool
    {
        $this->socket = @fsockopen(MIKROTIK_HOST, MIKROTIK_PORT, $errno, $errstr, 5);
        if (!$this->socket) {
            $this->lastError = "Bağlantı hatası: $errstr ($errno)";
            return false;
        }
        stream_set_timeout($this->socket, 5);

        // Login
        $this->sendWord('/login');
        $this->sendWord('=name=' . MIKROTIK_USER);
        $this->sendWord('=password=' . MIKROTIK_PASS);
        $this->sendWord('');  // EOS

        $response = $this->readSentence();
        if (($response[0] ?? '') !== '!done') {
            $this->lastError = 'Kimlik doğrulama başarısız.';
            return false;
        }

        $this->connected = true;
        return true;
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket    = null;
            $this->connected = false;
        }
    }

    public function isConnected(): bool { return $this->connected; }
    public function getLastError(): string { return $this->lastError; }

    // -------------------------------------------------------
    // Hotspot kullanıcısı ekle
    // -------------------------------------------------------
    public function addUser(string $username, string $password, string $profile = 'standard'): array
    {
        if (!$this->connected) return ['success' => false, 'error' => $this->lastError];

        $bw_profiles = unserialize(BW_PROFILES);
        $mk_profile  = $bw_profiles[$profile] ?? 'Standard-10M';
        $server      = MIKROTIK_HOTSPOT_SERVER;

        $this->sendWord('/ip/hotspot/user/add');
        $this->sendWord("=name=$username");
        $this->sendWord("=password=$password");
        $this->sendWord("=profile=$mk_profile");
        $this->sendWord("=server=$server");
        $this->sendWord('=comment=CRM-Auto');
        $this->sendWord('');

        $resp = $this->readSentence();
        if (($resp[0] ?? '') === '!done') {
            return ['success' => true];
        }
        $err = implode(' ', array_slice($resp, 1));
        $this->lastError = $err;
        return ['success' => false, 'error' => $err];
    }

    // -------------------------------------------------------
    // Hotspot kullanıcısını sil
    // -------------------------------------------------------
    public function removeUser(string $username): array
    {
        if (!$this->connected) return ['success' => false, 'error' => $this->lastError];

        // Önce ID bul
        $this->sendWord('/ip/hotspot/user/print');
        $this->sendWord('?name=' . $username);
        $this->sendWord('');
        $rows = $this->readAllSentences();
        $uid  = null;
        foreach ($rows as $row) {
            foreach ($row as $word) {
                if (str_starts_with($word, '=.id=')) {
                    $uid = substr($word, 5);
                    break 2;
                }
            }
        }
        if (!$uid) return ['success' => false, 'error' => 'Kullanıcı bulunamadı.'];

        $this->sendWord('/ip/hotspot/user/remove');
        $this->sendWord("=.id=$uid");
        $this->sendWord('');
        $resp = $this->readSentence();
        return ['success' => ($resp[0] ?? '') === '!done'];
    }

    // -------------------------------------------------------
    // Aktif hotspot oturumlarını listele
    // -------------------------------------------------------
    public function getActiveSessions(): array
    {
        if (!$this->connected) return [];

        $this->sendWord('/ip/hotspot/active/print');
        $this->sendWord('');
        $rows     = $this->readAllSentences();
        $sessions = [];
        foreach ($rows as $row) {
            if (empty($row)) continue;
            $s = [];
            foreach ($row as $word) {
                if (str_starts_with($word, '=') && str_contains($word, '=')) {
                    $parts = explode('=', ltrim($word, '='), 2);
                    $s[$parts[0]] = $parts[1] ?? '';
                }
            }
            if (!empty($s['mac-address'])) {
                $sessions[] = [
                    'mac'     => $s['mac-address'] ?? '',
                    'ip'      => $s['address'] ?? '',
                    'user'    => $s['user'] ?? '',
                    'uptime'  => $s['uptime'] ?? '',
                    'bytes_in'  => $this->parseBytes($s['bytes-in'] ?? '0'),
                    'bytes_out' => $this->parseBytes($s['bytes-out'] ?? '0'),
                ];
            }
        }
        return $sessions;
    }

    // -------------------------------------------------------
    // RouterOS API düşük seviye iletişim
    // -------------------------------------------------------
    private function sendWord(string $word): void
    {
        $len    = strlen($word);
        $prefix = '';
        if ($len < 0x80) {
            $prefix = chr($len);
        } elseif ($len < 0x4000) {
            $len |= 0x8000;
            $prefix = chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        } else {
            $len |= 0xC00000;
            $prefix = chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
        }
        fwrite($this->socket, $prefix . $word);
    }

    private function readLen(): int
    {
        $b = ord(fread($this->socket, 1));
        if (($b & 0x80) === 0)       return $b;
        if (($b & 0xC0) === 0x80)    return (($b & ~0x80) << 8) | ord(fread($this->socket, 1));
        if (($b & 0xE0) === 0xC0) {
            $c = fread($this->socket, 2);
            return (($b & ~0xC0) << 16) | (ord($c[0]) << 8) | ord($c[1]);
        }
        return 0;
    }

    private function readWord(): string
    {
        $len = $this->readLen();
        if ($len === 0) return '';
        $data = '';
        while (strlen($data) < $len) {
            $chunk = fread($this->socket, $len - strlen($data));
            if ($chunk === false || $chunk === '') break;
            $data .= $chunk;
        }
        return $data;
    }

    private function readSentence(): array
    {
        $words = [];
        while (true) {
            $w = $this->readWord();
            if ($w === '') break;
            $words[] = $w;
        }
        return $words;
    }

    private function readAllSentences(): array
    {
        $all = [];
        while (true) {
            $s = $this->readSentence();
            if (empty($s)) break;
            if ($s[0] === '!done') break;
            if ($s[0] === '!re') {
                $all[] = array_slice($s, 1);
            }
        }
        return $all;
    }

    private function parseBytes(string $val): int
    {
        return (int)preg_replace('/[^0-9]/', '', $val);
    }
}
