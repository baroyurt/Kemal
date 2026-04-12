<?php
// ============================================================
// Cisco Meraki Dashboard API İstemcisi
// API Referansı: https://developer.cisco.com/meraki/api-v1/
//
// Captive Portal (Splash Page) entegrasyonu:
//   1. Meraki Dashboard → Wireless → Access Control → Splash page:
//      "Hosted externally" seçin.
//   2. Splash URL olarak portal.php adresini girin:
//      https://yourserver.com/crm-hotspot/modules/hotspot/portal.php
//   3. Misafir WiFi'ye bağlandığında Meraki şu URL'e yönlendirir:
//      {splash_url}?base_grant_url={grant}&continue_url={cont}
//                  &node_mac={ap_mac}&client_ip={ip}&client_mac={mac}
//   4. Kimlik doğrulama sonrası tarayıcı base_grant_url'e POST yapar.
// ============================================================

class MerakiClient
{
    private string $apiKey;
    private string $networkId;
    private string $orgId;
    private string $baseUrl = 'https://api.meraki.com/api/v1';

    public function __construct()
    {
        $this->apiKey    = MERAKI_API_KEY;
        $this->networkId = MERAKI_NETWORK_ID;
        $this->orgId     = MERAKI_ORG_ID;
    }

    // -------------------------------------------------------
    // Ağdaki tüm aktif istemcileri listele
    // -------------------------------------------------------
    public function getNetworkClients(int $timespan = 86400): array
    {
        return $this->apiGet(
            "/networks/{$this->networkId}/clients?timespan=$timespan&perPage=100"
        );
    }

    // -------------------------------------------------------
    // Belirli bir istemcinin detayını getir (MAC veya client_id)
    // -------------------------------------------------------
    public function getClientDetails(string $clientId): array
    {
        return $this->apiGet(
            "/networks/{$this->networkId}/clients/" . urlencode($clientId)
        );
    }

    // -------------------------------------------------------
    // İstemciye politika uygula (bandwidth grubu / block / normal)
    // $policyType: 'Group policy' | 'Blocked' | 'Normal' | 'whitelisted'
    // $groupPolicyId: Meraki Dashboard'daki Group Policy ID (int string)
    // -------------------------------------------------------
    public function updateClientPolicy(string $clientMac, string $policyType, ?string $groupPolicyId = null): array
    {
        $body = ['devicePolicy' => $policyType];
        if ($groupPolicyId !== null) {
            $body['groupPolicyId'] = $groupPolicyId;
        }
        return $this->apiPut(
            "/networks/{$this->networkId}/clients/" . urlencode($clientMac) . '/policy',
            $body
        );
    }

    // -------------------------------------------------------
    // İstemciyi splash sayfasından muaf tut (bypass splash)
    // Kullanım: Fidelio'dan check-in olan misafirin MAC adresine
    // önceden yetki verilmesi gerektiğinde.
    // -------------------------------------------------------
    public function bypassSplash(string $clientMac): array
    {
        return $this->updateClientPolicy($clientMac, 'Normal');
    }

    // -------------------------------------------------------
    // Captive Portal — Misafire internet erişimi ver
    // Meraki splash redirect sonrası tarayıcı bu formu alır ve
    // portal.php bunu otomatik POST eder.
    // Bu metot doğrudan HTML üretir (portal.php çağırır).
    // -------------------------------------------------------
    public static function buildGrantForm(string $baseGrantUrl, string $continueUrl, int $duration = 0): string
    {
        // duration = 0 → Meraki varsayılan süreyi kullanır
        $safeGrantUrl   = htmlspecialchars($baseGrantUrl,  ENT_QUOTES, 'UTF-8');
        $safeContinueUrl = htmlspecialchars($continueUrl, ENT_QUOTES, 'UTF-8');
        $safeDuration    = (int)($duration > 0 ? $duration : MERAKI_SESSION_DURATION);

        return <<<HTML
        <form id="meraki_grant_form" action="{$safeGrantUrl}" method="POST">
            <input type="hidden" name="continue_url" value="{$safeContinueUrl}">
            <input type="hidden" name="duration"     value="{$safeDuration}">
        </form>
        <script>
            // Kısa gecikmeyle otomatik gönder (kullanıcı başarı mesajını görsün)
            setTimeout(function() {
                document.getElementById('meraki_grant_form').submit();
            }, 3000);
        </script>
        HTML;
    }

    // -------------------------------------------------------
    // Ağ SSID listesini getir
    // -------------------------------------------------------
    public function getSsids(): array
    {
        return $this->apiGet("/networks/{$this->networkId}/wireless/ssids");
    }

    // -------------------------------------------------------
    // Ağ trafiği özeti
    // -------------------------------------------------------
    public function getNetworkTraffic(int $timespan = 86400): array
    {
        return $this->apiGet(
            "/networks/{$this->networkId}/traffic?timespan=$timespan"
        );
    }

    // -------------------------------------------------------
    // Organizasyon altındaki ağları listele
    // -------------------------------------------------------
    public function getOrganizationNetworks(): array
    {
        return $this->apiGet("/organizations/{$this->orgId}/networks");
    }

    // -------------------------------------------------------
    // HTTP yardımcıları
    // -------------------------------------------------------
    private function apiGet(string $path): array
    {
        return $this->request('GET', $path);
    }

    private function apiPut(string $path, array $body): array
    {
        return $this->request('PUT', $path, $body);
    }

    private function apiPost(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);

        $headers = [
            'X-Cisco-Meraki-API-Key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($method === 'PUT') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $opts[CURLOPT_POSTFIELDS]    = json_encode($body ?? []);
        } elseif ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? []);
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['_error' => $curlErr, '_http_code' => 0];
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            return ['_error' => 'JSON parse error', '_raw' => $response, '_http_code' => $httpCode];
        }

        if ($httpCode >= 400) {
            return ['_error' => $decoded['errors'][0] ?? "HTTP $httpCode", '_http_code' => $httpCode, '_body' => $decoded];
        }

        return is_array($decoded) ? $decoded : ['_data' => $decoded, '_http_code' => $httpCode];
    }
}
