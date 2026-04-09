CREATE DATABASE casino_map;

USE casino_map;

CREATE TABLE machines(
    id INT AUTO_INCREMENT PRIMARY KEY,
    machine_no VARCHAR(20),
    smibb_ip VARCHAR(50),
    screen_ip VARCHAR(50) DEFAULT NULL,
    mac VARCHAR(50),
    machine_type VARCHAR(100) DEFAULT NULL,
    game_type VARCHAR(100) DEFAULT NULL,
    pos_x INT DEFAULT 50,
    pos_y INT DEFAULT 50,
    pos_z INT DEFAULT 0,
    rotation INT DEFAULT 0,
    note TEXT DEFAULT NULL,
    hub_sw TINYINT(1) DEFAULT 0,
    hub_sw_cable VARCHAR(255) DEFAULT NULL,
    brand VARCHAR(100) DEFAULT NULL,
    model VARCHAR(100) DEFAULT NULL,
    machine_pc VARCHAR(100) DEFAULT NULL
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

-- Migration: DRscreen IP (renamed to screen_ip)
ALTER TABLE machines ADD COLUMN IF NOT EXISTS drscreen_ip VARCHAR(50) DEFAULT NULL;

-- Migration: new fields smibb_ip, screen_ip, machine_type
ALTER TABLE machines ADD COLUMN IF NOT EXISTS smibb_ip VARCHAR(50) DEFAULT NULL;
ALTER TABLE machines ADD COLUMN IF NOT EXISTS screen_ip VARCHAR(50) DEFAULT NULL;
ALTER TABLE machines ADD COLUMN IF NOT EXISTS machine_type VARCHAR(100) DEFAULT NULL;

-- Migration: machine_pc
ALTER TABLE machines ADD COLUMN IF NOT EXISTS machine_pc VARCHAR(100) DEFAULT NULL;

-- Migration: users created_at (eğer eski şemadan geçiş yapıyorsanız)
ALTER TABLE users ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;