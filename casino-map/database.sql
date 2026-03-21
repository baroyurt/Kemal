CREATE DATABASE casino_map;

USE casino_map;

CREATE TABLE machines(
    id INT AUTO_INCREMENT PRIMARY KEY,
    machine_no VARCHAR(20),
    ip VARCHAR(50),
    mac VARCHAR(50),
    pos_x INT DEFAULT 50,
    pos_y INT DEFAULT 50,
    pos_z INT DEFAULT 0,
    rotation INT DEFAULT 0,
    note TEXT DEFAULT NULL
);

CREATE TABLE users(
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE,
    password VARCHAR(255),
    role ENUM('admin', 'personel') DEFAULT 'personel',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE machine_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE machine_group_relations (
    machine_id INT,
    group_id INT,
    FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES machine_groups(id) ON DELETE CASCADE,
    PRIMARY KEY (machine_id, group_id)
);

INSERT INTO users (username, password, role) VALUES 
('admin', '$2y$10$fZY9qXc/gXg9S1at/dUfZeW7NsJrcXZWTOIMYXD4qlOQGaFAehaWC', 'admin'),
('personel', '$2y$10$MH5JF9JToUY4uZhH8.9BYeLi46BCvu2zFFH9d.7KR9V38XzrbPcP6', 'personel');

-- Migration: group colors and hub switch support
ALTER TABLE machine_groups ADD COLUMN IF NOT EXISTS color VARCHAR(20) DEFAULT '#4CAF50';
ALTER TABLE machines ADD COLUMN IF NOT EXISTS hub_sw TINYINT(1) DEFAULT 0;
ALTER TABLE machines ADD COLUMN IF NOT EXISTS hub_sw_cable VARCHAR(255) DEFAULT NULL;

-- Migration: machine brand / model / game type (CSV import)
ALTER TABLE machines ADD COLUMN IF NOT EXISTS brand VARCHAR(100) DEFAULT NULL;
ALTER TABLE machines ADD COLUMN IF NOT EXISTS model VARCHAR(100) DEFAULT NULL;
ALTER TABLE machines ADD COLUMN IF NOT EXISTS game_type VARCHAR(100) DEFAULT NULL;

-- Migration: DRscreen IP (eski ad) → screen_ip
ALTER TABLE machines ADD COLUMN IF NOT EXISTS drscreen_ip VARCHAR(50) DEFAULT NULL;

-- Migration: ip → smibb_ip, drscreen_ip → screen_ip, machine_type, area_id
ALTER TABLE machines CHANGE COLUMN ip smibb_ip VARCHAR(50);
ALTER TABLE machines CHANGE COLUMN drscreen_ip screen_ip VARCHAR(50);
ALTER TABLE machines ADD COLUMN IF NOT EXISTS machine_type VARCHAR(100) DEFAULT NULL;
ALTER TABLE machines ADD COLUMN IF NOT EXISTS area_id INT DEFAULT NULL;

-- Migration: users created_at (eğer eski şemadan geçiş yapıyorsanız)
ALTER TABLE users ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

-- Migration: bölgeler (regions) tablosu
CREATE TABLE IF NOT EXISTS regions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(20) DEFAULT '#607D8B',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Migration: makine gruplarına bölge ilişkisi
ALTER TABLE machine_groups ADD COLUMN IF NOT EXISTS region_id INT DEFAULT NULL;
ALTER TABLE machine_groups ADD CONSTRAINT fk_group_region FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL;
-- Migration: casino canlı masa katmanı (pos_z=10) ve masa tipi
-- pos_z=10 canlı masa (casino) alanı için ayrılmıştır; game_type değerleri: poker, rulet, barbut
