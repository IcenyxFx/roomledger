<?php
/**
 * ไฟล์: index.php
 * เข้า http://localhost/dorm/ แล้วส่งไปหน้าที่เหมาะสม: ล็อกอินแล้วไปหน้าห้องพัก ยังไม่ล็อกอินไปหน้า login
 */
session_start();
header('Location: ' . (isset($_SESSION['user_id']) ? 'rooms.php' : 'login.php'));
exit;
