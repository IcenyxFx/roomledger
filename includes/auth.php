<?php
/**
 * ไฟล์: includes/auth.php
 * ใส่เป็นบรรทัดแรกของทุกหน้าที่ต้องล็อกอินก่อน: require_once __DIR__ . '/includes/auth.php';
 * หน้าที่: เริ่ม Session, เชื่อมฐานข้อมูล ($conn), โหลดฟังก์ชันช่วย และเด้งไปหน้า login ถ้ายังไม่ล็อกอิน
 */

session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/functions.php';

// Protect Page: ยังไม่มี user_id ใน Session = ยังไม่ล็อกอิน
if (!isset($_SESSION['user_id'])) {
    redirect('login.php');
}
