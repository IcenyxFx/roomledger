-- ============================================================
-- ไฟล์: database/setup.sql
-- ระบบจัดการหอพักและคำนวณบิลค่าเช่าออนไลน์
-- สร้างฐานข้อมูล 4 ตารางตาม ER Diagram ใน Proposal: users, rooms, tenants, bills
-- วิธีใช้: phpMyAdmin > แท็บ SQL > วางทั้งไฟล์ > Go
-- คำเตือน: รันซ้ำแล้วข้อมูลเดิมทั้งหมดจะถูกลบและสร้างใหม่
-- ============================================================

CREATE DATABASE IF NOT EXISTS dorm_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE dorm_db;

-- ลบตารางลูกก่อนตารางแม่ (ตารางที่มี Foreign Key ต้องลบก่อน)
DROP TABLE IF EXISTS bills;
DROP TABLE IF EXISTS tenants;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS users;

-- ------------------------------------------------------------
-- users : ผู้ดูแลระบบที่ล็อกอินได้
-- ------------------------------------------------------------
CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email         VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rooms : ห้องพัก (ชั้นของห้อง = หลักร้อยของเลขห้อง เช่น 204 อยู่ชั้น 2)
-- ------------------------------------------------------------
CREATE TABLE rooms (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    room_number VARCHAR(10)   NOT NULL,
    price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,                 -- ค่าเช่าต่อเดือน
    status      ENUM('available','occupied','maintenance')
                NOT NULL DEFAULT 'available',                         -- ว่าง / มีผู้เช่า / ซ่อมบำรุง
    water_type  ENUM('meter','flat_rate') NOT NULL DEFAULT 'meter',   -- ค่าน้ำตามมิเตอร์ / เหมาจ่าย
    PRIMARY KEY (id),
    UNIQUE KEY uq_rooms_room_number (room_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- tenants : ผู้เช่า (move_out_date เป็น NULL = ยังพักอยู่)
-- ------------------------------------------------------------
CREATE TABLE tenants (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id       INT UNSIGNED NOT NULL,
    full_name     VARCHAR(100) NOT NULL,
    phone         VARCHAR(20)  NOT NULL,
    move_in_date  DATE         NOT NULL,
    move_out_date DATE         NULL DEFAULT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tenants_room (room_id),
    -- RESTRICT: ลบห้องไม่ได้ถ้ายังมีประวัติผู้เช่า ป้องกันข้อมูลกำพร้า
    CONSTRAINT fk_tenants_room FOREIGN KEY (room_id) REFERENCES rooms (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- bills : บิลประจำเดือน (1 ห้อง 1 บิลต่อเดือน)
-- billing_month เก็บเป็นวันที่ 1 ของเดือน เช่น 2026-10-01 = บิลเดือน ต.ค. 2569
-- เก็บอัตราต่อหน่วยไว้ในบิลแต่ละใบ เมื่อปรับราคาภายหลัง บิลเก่าจะไม่เปลี่ยน
-- ห้องเหมาจ่าย: water_prev / water_curr เป็น NULL และ water_rate = ยอดเหมาจ่ายต่อเดือน
-- ------------------------------------------------------------
CREATE TABLE bills (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    room_id       INT UNSIGNED  NOT NULL,
    tenant_id     INT UNSIGNED  NOT NULL,
    created_by    INT UNSIGNED  NOT NULL,                 -- ผู้ดูแลที่ออกบิล
    billing_month DATE          NOT NULL,
    rent_amount   DECIMAL(10,2) NOT NULL,
    water_prev    INT UNSIGNED  NULL,
    water_curr    INT UNSIGNED  NULL,
    water_rate    DECIMAL(10,2) NOT NULL,
    elec_prev     INT UNSIGNED  NOT NULL,
    elec_curr     INT UNSIGNED  NOT NULL,
    elec_rate     DECIMAL(10,2) NOT NULL,
    total_amount  DECIMAL(10,2) NOT NULL,
    status        ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bills_room_month (room_id, billing_month),   -- ห้ามออกบิลซ้ำเดือนเดียวกัน
    KEY idx_bills_tenant (tenant_id),
    KEY idx_bills_creator (created_by),
    CONSTRAINT fk_bills_room    FOREIGN KEY (room_id)    REFERENCES rooms (id)   ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_bills_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_bills_creator FOREIGN KEY (created_by) REFERENCES users (id)   ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_elec  CHECK (elec_curr >= elec_prev),
    CONSTRAINT chk_water CHECK (water_curr IS NULL OR water_curr >= water_prev)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- บัญชีแอดมินตั้งต้น: admin@dorm.com / password123
-- (Hash จาก password_hash('password123', PASSWORD_DEFAULT) ทดสอบกับ password_verify() แล้ว)
-- ------------------------------------------------------------
INSERT INTO users (email, password_hash) VALUES
('admin@dorm.com', '$2y$10$EeBL8yiaWhkTDG6PweMz5uLO1o6mnIb7xejIvkUD7wk9vUqXi9gSe');

-- ------------------------------------------------------------
-- ข้อมูลตัวอย่างสำหรับทดสอบ: 18 ห้อง 3 ชั้น, ผู้เช่า 12 คน, บิลเดือน ก.ย. 2569
-- (ชื่อและเบอร์โทรสมมติทั้งหมด) อัตรา: ไฟ 8 บาท/หน่วย, น้ำ 18 บาท/หน่วย, เหมาจ่าย 150 บาท
-- ------------------------------------------------------------
INSERT INTO rooms (room_number, price, status, water_type) VALUES
('101', 3500.00, 'occupied', 'meter'),
('102', 3500.00, 'available', 'meter'),
('103', 3500.00, 'occupied', 'meter'),
('104', 3500.00, 'maintenance', 'meter'),
('105', 3500.00, 'occupied', 'flat_rate'),
('106', 3500.00, 'available', 'meter'),
('201', 3800.00, 'occupied', 'meter'),
('202', 3800.00, 'occupied', 'meter'),
('203', 3800.00, 'available', 'flat_rate'),
('204', 3800.00, 'occupied', 'meter'),
('205', 3800.00, 'occupied', 'meter'),
('206', 3800.00, 'occupied', 'flat_rate'),
('301', 4000.00, 'occupied', 'meter'),
('302', 4000.00, 'occupied', 'meter'),
('303', 4000.00, 'available', 'flat_rate'),
('304', 4000.00, 'occupied', 'meter'),
('305', 4000.00, 'maintenance', 'meter'),
('306', 4000.00, 'occupied', 'flat_rate');

INSERT INTO tenants (room_id, full_name, phone, move_in_date, move_out_date) VALUES
(1, 'ศุภกร รัตนพันธ์', '080-455-2219', '2024-09-01', NULL),
(3, 'พิมพ์ชนก สายทอง', '061-876-3354', '2025-12-01', NULL),
(5, 'กิตติพัฒน์ นาคสวัสดิ์', '098-120-4478', '2026-02-01', NULL),
(7, 'อรอุมา ทองดี', '086-778-1203', '2023-07-01', NULL),
(8, 'ภานุวัฒน์ ชัยมงคล', '083-901-5566', '2025-02-20', NULL),
(10, 'สุดารัตน์ พรหมมา', '064-221-0098', '2024-05-01', NULL),
(11, 'วีรยุทธ อินทร์แก้ว', '092-640-7731', '2026-03-01', NULL),
(12, 'ชลธิชา มีสุข', '087-312-6640', '2025-10-01', NULL),
(13, 'กนกพร ศรีสุข', '081-234-5501', '2025-06-01', NULL),
(14, 'ธนพล วงศ์ใหญ่', '089-110-2290', '2024-11-15', NULL),
(16, 'ปิยะธิดา แก้วมณี', '062-554-8812', '2026-01-10', NULL),
(18, 'ณัฐวุฒิ บุญมา', '095-003-4417', '2025-08-01', NULL);

INSERT INTO bills (room_id, tenant_id, created_by, billing_month, rent_amount,
                   water_prev, water_curr, water_rate, elec_prev, elec_curr, elec_rate, total_amount, status) VALUES
(1, 1, 1, '2026-09-01', 3500.00, 1677, 1688, 18.00, 7977, 8105, 8.00, 4722.00, 'paid'),
(3, 2, 1, '2026-09-01', 3500.00, 499, 512, 18.00, 2633, 2730, 8.00, 4510.00, 'unpaid'),
(5, 3, 1, '2026-09-01', 3500.00, NULL, NULL, 150.00, 3239, 3350, 8.00, 4538.00, 'paid'),
(7, 4, 1, '2026-09-01', 3800.00, 1517, 1530, 18.00, 7702, 7810, 8.00, 4898.00, 'paid'),
(8, 5, 1, '2026-09-01', 3800.00, 788, 802, 18.00, 3879, 3994, 8.00, 4972.00, 'paid'),
(10, 6, 1, '2026-09-01', 3800.00, 1093, 1102, 18.00, 5078, 5207, 8.00, 4994.00, 'unpaid'),
(11, 7, 1, '2026-09-01', 3800.00, 367, 377, 18.00, 1754, 1890, 8.00, 5068.00, 'paid'),
(12, 8, 1, '2026-09-01', 3800.00, NULL, NULL, 150.00, 4372, 4470, 8.00, 4734.00, 'paid'),
(13, 9, 1, '2026-09-01', 4000.00, 1196, 1204, 18.00, 5497, 5630, 8.00, 5208.00, 'paid'),
(14, 10, 1, '2026-09-01', 4000.00, 971, 980, 18.00, 4272, 4412, 8.00, 5282.00, 'unpaid'),
(16, 11, 1, '2026-09-01', 4000.00, 644, 655, 18.00, 2912, 3021, 8.00, 5070.00, 'paid'),
(18, 12, 1, '2026-09-01', 4000.00, NULL, NULL, 150.00, 5997, 6120, 8.00, 5134.00, 'paid');

