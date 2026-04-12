-- ============================================================
-- CRM + Hotspot Yönetim Sistemi — Veritabanı Şeması
-- ============================================================

CREATE DATABASE IF NOT EXISTS crm_hotspot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE crm_hotspot;

-- ----------------------------------------------------------
-- Kullanıcılar / Personel
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(50)  UNIQUE NOT NULL,
    full_name   VARCHAR(100) NOT NULL DEFAULT '',
    email       VARCHAR(100) DEFAULT NULL,
    phone       VARCHAR(30)  DEFAULT NULL,
    password    VARCHAR(255) NOT NULL,
    role        ENUM('admin','reception','it_staff','readonly') NOT NULL DEFAULT 'reception',
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------
-- Misafirler / CRM
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS guests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    fidelio_id      VARCHAR(50)  DEFAULT NULL,
    reservation_no  VARCHAR(50)  DEFAULT NULL,
    first_name      VARCHAR(100) NOT NULL DEFAULT '',
    last_name       VARCHAR(100) NOT NULL DEFAULT '',
    room_no         VARCHAR(20)  DEFAULT NULL,
    phone           VARCHAR(30)  DEFAULT NULL,
    email           VARCHAR(100) DEFAULT NULL,
    nationality     VARCHAR(50)  DEFAULT NULL,
    vip_status      TINYINT(1)   NOT NULL DEFAULT 0,
    checkin_date    DATETIME     DEFAULT NULL,
    checkout_date   DATETIME     DEFAULT NULL,
    status          ENUM('reserved','checked_in','checked_out','cancelled') NOT NULL DEFAULT 'reserved',
    notes           TEXT         DEFAULT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fidelio_id (fidelio_id),
    INDEX idx_room_no (room_no),
    INDEX idx_status (status)
);

-- ----------------------------------------------------------
-- Hotspot Oturumları
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS hotspot_sessions (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    guest_id            INT           DEFAULT NULL,
    mac_address         VARCHAR(20)   NOT NULL,
    ip_address          VARCHAR(50)   DEFAULT NULL,
    device_name         VARCHAR(100)  DEFAULT NULL,
    started_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at            DATETIME      DEFAULT NULL,
    bytes_in            BIGINT        NOT NULL DEFAULT 0,
    bytes_out           BIGINT        NOT NULL DEFAULT 0,
    status              ENUM('active','disconnected') NOT NULL DEFAULT 'active',
    bandwidth_profile   VARCHAR(50)   DEFAULT 'standard',
    FOREIGN KEY (guest_id) REFERENCES guests(id) ON DELETE SET NULL,
    INDEX idx_mac (mac_address),
    INDEX idx_status (status),
    INDEX idx_guest_id (guest_id)
);

-- ----------------------------------------------------------
-- WhatsApp Mesajları
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS whatsapp_messages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    guest_id        INT          DEFAULT NULL,
    direction       ENUM('inbound','outbound') NOT NULL,
    from_number     VARCHAR(30)  DEFAULT NULL,
    to_number       VARCHAR(30)  DEFAULT NULL,
    message_type    ENUM('text','template','image','document') NOT NULL DEFAULT 'text',
    content         TEXT         DEFAULT NULL,
    template_name   VARCHAR(100) DEFAULT NULL,
    status          ENUM('sent','delivered','read','failed','received') NOT NULL DEFAULT 'sent',
    wa_message_id   VARCHAR(100) DEFAULT NULL,
    staff_id        INT          DEFAULT NULL,
    sent_at         DATETIME     DEFAULT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (guest_id) REFERENCES guests(id) ON DELETE SET NULL,
    FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_guest_id (guest_id),
    INDEX idx_direction (direction),
    INDEX idx_status (status)
);

-- ----------------------------------------------------------
-- Fidelio Senkronizasyon Kaydı
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS fidelio_sync_log (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    sync_type           ENUM('checkin','checkout','reservation','manual','auto') NOT NULL DEFAULT 'auto',
    status              ENUM('success','error','partial') NOT NULL DEFAULT 'success',
    records_processed   INT    NOT NULL DEFAULT 0,
    records_updated     INT    NOT NULL DEFAULT 0,
    error_message       TEXT   DEFAULT NULL,
    synced_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------
-- Personel İşlem Kaydı (Audit Trail)
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS staff_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT         DEFAULT NULL,
    action      VARCHAR(100) NOT NULL,
    target_type VARCHAR(50)  DEFAULT NULL,
    target_id   INT          DEFAULT NULL,
    description TEXT         DEFAULT NULL,
    ip_address  VARCHAR(50)  DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
);

-- ----------------------------------------------------------
-- Varsayılan admin kullanıcısı (şifre: admin123)
-- ----------------------------------------------------------
INSERT INTO users (username, full_name, email, password, role) VALUES
('admin', 'Sistem Yöneticisi', 'admin@otel.com',
 '$2y$10$fZY9qXc/gXg9S1at/dUfZeW7NsJrcXZWTOIMYXD4qlOQGaFAehaWC', 'admin'),
('resepsiyon', 'Resepsiyon Personeli', 'resepsiyon@otel.com',
 '$2y$10$MH5JF9JToUY4uZhH8.9BYeLi46BCvu2zFFH9d.7KR9V38XzrbPcP6', 'reception'),
('bthizmet', 'BT Hizmetleri', 'bt@otel.com',
 '$2y$10$MH5JF9JToUY4uZhH8.9BYeLi46BCvu2zFFH9d.7KR9V38XzrbPcP6', 'it_staff')
ON DUPLICATE KEY UPDATE username = username;

-- ----------------------------------------------------------
-- Örnek misafir verisi
-- ----------------------------------------------------------
INSERT INTO guests (fidelio_id, reservation_no, first_name, last_name, room_no, phone, email, nationality, vip_status, checkin_date, checkout_date, status) VALUES
('FID-001', 'RES-2024-001', 'Ahmet',   'Yılmaz',  '101', '+905551234567', 'ahmet@example.com',  'TR', 0, NOW(), DATE_ADD(NOW(), INTERVAL 3 DAY), 'checked_in'),
('FID-002', 'RES-2024-002', 'Fatma',   'Kaya',    '205', '+905559876543', 'fatma@example.com',  'TR', 1, NOW(), DATE_ADD(NOW(), INTERVAL 5 DAY), 'checked_in'),
('FID-003', 'RES-2024-003', 'John',    'Smith',   '310', '+441234567890', 'john@example.com',   'GB', 1, NOW(), DATE_ADD(NOW(), INTERVAL 2 DAY), 'checked_in'),
('FID-004', 'RES-2024-004', 'Maria',   'Garcia',  '412', '+34612345678',  'maria@example.com',  'ES', 0, DATE_ADD(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 4 DAY), 'reserved');
