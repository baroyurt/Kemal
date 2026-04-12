# CRM + Hotspot Yönetim Sistemi

Otel için tam entegre CRM, Hotspot, WhatsApp ve Fidelio PMS yönetim sistemi.

## Gereksinimler

- PHP 8.1+
- MySQL / MariaDB 8+
- Web sunucu (Apache / Nginx)
- Mikrotik RouterOS (hotspot için)
- Meta WhatsApp Business hesabı

## Kurulum

### 1. Veritabanı

```bash
mysql -u root -p < database.sql
```

### 2. Yapılandırma

`config/config.php` dosyasını düzenleyin:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'sifre');
define('DB_NAME', 'crm_hotspot');

// WhatsApp Meta Business API
define('WA_PHONE_NUMBER_ID', 'PHONE_NUMBER_ID');
define('WA_ACCESS_TOKEN',    'EAAxxxx...');

// Fidelio (FIAS veya REST)
define('FIDELIO_MODE',      'fias');       // 'fias' | 'rest'
define('FIDELIO_FIAS_HOST', '192.168.1.100');
define('FIDELIO_FIAS_PORT',  5010);

// Mikrotik RouterOS API
define('MIKROTIK_HOST', '192.168.88.1');
define('MIKROTIK_USER', 'admin');
define('MIKROTIK_PASS', 'sifre');
```

### 3. Cron Job (Fidelio otomatik senkronizasyon)

```
*/5 * * * * php /var/www/html/crm-hotspot/modules/fidelio/sync.php >> /var/log/fidelio_sync.log 2>&1
```

### 4. WhatsApp Webhook

Meta Developer Console → WhatsApp → Configuration:
- **Callback URL:** `https://sizindomain.com/crm-hotspot/modules/whatsapp/webhook.php`
- **Verify Token:** `crm_hotspot_webhook_secret` (WA_VERIFY_TOKEN)
- **Subscribed fields:** `messages`

### 5. Hotspot Captive Portal (Mikrotik)

Mikrotik RouterOS'ta hotspot login sayfası olarak:
```
https://sizindomain.com/crm-hotspot/modules/hotspot/portal.php
```

## Kullanıcılar

Varsayılan kullanıcılar:

| Kullanıcı   | Şifre    | Rol         |
|-------------|----------|-------------|
| admin       | admin123 | Admin       |
| resepsiyon  | admin123 | Resepsiyon  |
| bthizmet    | admin123 | BT Personel |

> **Güvenlik:** İlk girişten sonra şifreleri değiştirin!

## Roller ve Yetkiler

| Yetki                    | admin | reception | it_staff | readonly |
|--------------------------|-------|-----------|----------|----------|
| Misafir görüntüleme      | ✅    | ✅        | ✅       | ✅       |
| Misafir ekleme/düzenleme | ✅    | ✅        | ❌       | ❌       |
| WhatsApp gönderme        | ✅    | ✅        | ❌       | ❌       |
| Hotspot yönetimi         | ✅    | ❌        | ✅       | ❌       |
| Fidelio senkronizasyon   | ✅    | ❌        | ✅       | ❌       |
| Raporlar                 | ✅    | ✅        | ✅       | ❌       |
| Personel yönetimi        | ✅    | ❌        | ❌       | ❌       |

## API Endpoints

Tüm API çağrıları `Authorization: Bearer <API_KEY>` header gerektirir.

### Misafirler
```
GET  /api/guests.php?room_no=101        — Odaya göre misafir
GET  /api/guests.php?status=checked_in  — Durum listesi
POST /api/guests.php  {"action":"create",...}  — Yeni misafir
POST /api/guests.php  {"action":"update","id":5,...}  — Güncelle
```

### Hotspot
```
GET  /api/hotspot.php                   — Aktif oturumlar
POST /api/hotspot.php {"action":"verify_room","room_no":"101"}  — Captive portal
POST /api/hotspot.php {"action":"start","mac_address":"aa:bb:..."}
POST /api/hotspot.php {"action":"stop","mac_address":"aa:bb:..."}
POST /api/hotspot.php {"action":"accounting","mac_address":"...", "bytes_in":..., "bytes_out":...}
```

## Dosya Yapısı

```
crm-hotspot/
├── config/
│   └── config.php           ← Tüm ayarlar
├── lib/
│   ├── WhatsAppClient.php   ← Meta Cloud API istemcisi
│   ├── FidelioClient.php    ← FIAS TCP + Opera REST istemcisi
│   └── MikrotikClient.php   ← RouterOS API istemcisi
├── modules/
│   ├── auth/
│   │   ├── layout.php       ← Sidebar şablonu
│   │   └── 403.php
│   ├── staff/
│   │   └── index.php        ← Personel yönetimi
│   ├── guests/
│   │   ├── index.php        ← CRM misafir listesi
│   │   └── view.php         ← Misafir detay (CRM + Hotspot + WhatsApp)
│   ├── hotspot/
│   │   ├── index.php        ← Hotspot oturum yönetimi
│   │   └── portal.php       ← Misafir captive portal
│   ├── whatsapp/
│   │   ├── index.php        ← Mesaj merkezi
│   │   └── webhook.php      ← Meta webhook alıcısı
│   ├── fidelio/
│   │   ├── index.php        ← Senkronizasyon paneli
│   │   └── sync.php         ← Cron script
│   └── reports/
│       └── index.php        ← Raporlama
├── api/
│   ├── guests.php           ← REST API: misafirler
│   └── hotspot.php          ← REST API: hotspot
├── assets/
│   └── style.css
├── dashboard.php            ← Ana panel
├── login.php
├── logout.php
└── database.sql
```
