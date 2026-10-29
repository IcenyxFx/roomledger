<?php
/**
 * ไฟล์: includes/functions.php
 * ฟังก์ชันช่วยที่ทุกหน้าใช้ร่วมกัน: แสดงผลอย่างปลอดภัย, CSRF, Flash message, Redirect
 */

// ข้อความภาษาไทยของค่าใน ENUM ของฐานข้อมูล
const ROOM_STATUS = [
    'available'   => 'ว่าง',
    'occupied'    => 'มีผู้เช่า',
    'maintenance' => 'ซ่อมบำรุง',
];
const WATER_TYPE = [
    'meter'     => 'ตามมิเตอร์',
    'flat_rate' => 'เหมาจ่าย',
];

/** แสดงข้อความลง HTML อย่างปลอดภัย (ป้องกัน XSS) ใช้ทุกครั้งที่ echo ข้อมูลจากผู้ใช้หรือฐานข้อมูล */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** แสดงจำนวนเงิน เช่น 3500 -> ฿3,500 และ 3500.5 -> ฿3,500.50 */
function baht($amount): string
{
    $amount = (float) $amount;
    $decimals = floor($amount) == $amount ? 0 : 2;
    return '฿' . number_format($amount, $decimals);
}

/** ชั้นของห้อง = หลักร้อยของเลขห้อง เช่น "204" -> 2 (เลขห้องที่มีตัวอักษรจะคืน 0) */
function room_floor(string $roomNumber): int
{
    return ctype_digit($roomNumber) ? intdiv((int) $roomNumber, 100) : 0;
}

/** วันที่ภาษาไทยแบบย่อ เช่น "2026-09-01" -> "1 ก.ย. 2569", เลือกแสดงแค่เดือนได้ */
function thai_date(string $isoDate, bool $monthOnly = false): string
{
    $months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    [$y, $m, $d] = array_map('intval', explode('-', substr($isoDate, 0, 10)));
    $text = $months[$m - 1] . ' ' . ($y + 543);
    return $monthOnly ? $text : $d . ' ' . $text;
}

/** Redirect แล้วหยุดการทำงานทันที */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

// ---------------- CSRF Token ----------------
// ฟอร์มที่เปลี่ยนข้อมูล (เพิ่ม/แก้ไข/ลบ) ต้องแนบ Token นี้ เพื่อกันเว็บอื่นแอบส่งฟอร์มแทนเรา

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** ใส่ใน <form> ทุกอันที่ใช้ method="post" */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** เรียกเป็นบรรทัดแรกของการประมวลผล POST */
function csrf_check(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('ฟอร์มหมดอายุหรือไม่ถูกต้อง กรุณากลับไปหน้าก่อนหน้า รีเฟรช แล้วลองอีกครั้ง');
    }
}

// ---------------- Flash message ----------------
// ข้อความแจ้งผลที่แสดงครั้งเดียวหลัง Redirect เช่น "เพิ่มห้อง 107 แล้ว"

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];  // type: success | error
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

// ---------------- ข้อมูลห้อง ----------------

/**
 * สถานะที่ใช้แสดงผล: ค้างชำระ (overdue) เป็นกรณีย่อยของ "มีผู้เช่า"
 * คืนค่า: free | rented | overdue | repair (ตรงกับคลาส CSS room--xxx และ pill-xxx)
 */
function room_view_status(string $status, float $unpaid): string
{
    if ($status === 'maintenance') return 'repair';
    if ($status === 'available')   return 'free';
    return $unpaid > 0 ? 'overdue' : 'rented';
}

const VIEW_STATUS_LABEL = [
    'free'    => 'ว่าง',
    'rented'  => 'มีผู้เช่า',
    'overdue' => 'ค้างชำระ',
    'repair'  => 'ซ่อมบำรุง',
];

/**
 * ตรวจข้อมูลฟอร์มห้อง คืนค่า [ข้อมูลที่ผ่านการตรวจ, รายการ error]
 * $hasTenant = ห้องนี้มีผู้เช่าอยู่ตอนนี้หรือไม่ (ใช้ตอนแก้ไข)
 */
function validate_room(array $input, bool $hasTenant = false): array
{
    $data = [
        'room_number' => strtoupper(trim((string) ($input['room_number'] ?? ''))),
        'price'       => trim(str_replace(',', '', (string) ($input['price'] ?? ''))),
        'water_type'  => (string) ($input['water_type'] ?? ''),
        'status'      => (string) ($input['status'] ?? ''),
    ];
    $errors = [];

    if ($data['room_number'] === '') {
        $errors['room_number'] = 'กรอกเลขห้อง';
    } elseif (!preg_match('/^[0-9A-Z-]{1,10}$/', $data['room_number'])) {
        $errors['room_number'] = 'ใช้ได้เฉพาะตัวเลข ตัวอักษรอังกฤษ และขีด (-) ไม่เกิน 10 ตัว';
    }

    if ($data['price'] === '') {
        $errors['price'] = 'กรอกค่าเช่าต่อเดือน';
    } elseif (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $data['price'])) {
        $errors['price'] = 'กรอกเป็นตัวเลข ทศนิยมไม่เกิน 2 ตำแหน่ง';
    } elseif ((float) $data['price'] <= 0 || (float) $data['price'] > 100000) {
        $errors['price'] = 'ค่าเช่าต้องมากกว่า 0 และไม่เกิน 100,000 บาท';
    }

    if (!array_key_exists($data['water_type'], WATER_TYPE)) {
        $errors['water_type'] = 'เลือกวิธีคิดค่าน้ำ';
    }

    if ($hasTenant) {
        // ห้องที่มีผู้เช่าอยู่ สถานะต้องเป็น "มีผู้เช่า" เสมอ จะเปลี่ยนได้เมื่อผู้เช่าย้ายออก
        $data['status'] = 'occupied';
    } elseif (!in_array($data['status'], ['available', 'maintenance'], true)) {
        $errors['status'] = 'เลือกสถานะ "ว่าง" หรือ "ซ่อมบำรุง" (สถานะมีผู้เช่าจะตั้งให้อัตโนมัติเมื่อเพิ่มผู้เช่า)';
    }

    return [$data, $errors];
}
