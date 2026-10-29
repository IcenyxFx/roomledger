<?php
/**
 * ไฟล์: room_view.php  (CRUD: READ รายห้อง)
 * แสดงข้อมูลห้อง, ผู้เช่าปัจจุบัน และประวัติบิล  —  room_view.php?id=1
 */
require_once __DIR__ . '/includes/auth.php';

// รับ id จาก URL และตรวจว่าเป็นจำนวนเต็มบวกเท่านั้น
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    flash('error', 'ไม่พบห้องที่ต้องการ');
    redirect('rooms.php');
}

// ---------- ข้อมูลห้อง ----------
$stmt = $conn->prepare('SELECT id, room_number, price, status, water_type FROM rooms WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$room) {
    flash('error', 'ไม่พบห้องที่ต้องการ อาจถูกลบไปแล้ว');
    redirect('rooms.php');
}

// ---------- ผู้เช่าปัจจุบัน (ยังไม่ย้ายออก) ----------
$stmt = $conn->prepare('SELECT full_name, phone, move_in_date FROM tenants
                        WHERE room_id = ? AND move_out_date IS NULL
                        ORDER BY move_in_date DESC LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$tenant = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ---------- ประวัติบิล 12 เดือนล่าสุด ----------
$stmt = $conn->prepare('SELECT billing_month, water_prev, water_curr, elec_prev, elec_curr, total_amount, status
                        FROM bills WHERE room_id = ?
                        ORDER BY billing_month DESC LIMIT 12');
$stmt->bind_param('i', $id);
$stmt->execute();
$bills = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$unpaid = 0.0;
foreach ($bills as $bill) {
    if ($bill['status'] === 'unpaid') $unpaid += (float) $bill['total_amount'];
}
$viewStatus = room_view_status($room['status'], $unpaid);

$pageTitle = 'ห้อง ' . $room['room_number'];
$activeNav = 'rooms';
require __DIR__ . '/includes/header.php';
?>

<a class="back-link" href="rooms.php">ห้องพักทั้งหมด</a>

<header class="page-head">
    <div class="drawer-title">
        <h1>ห้อง <?= e($room['room_number']) ?></h1>
        <span class="pill pill-<?= $viewStatus ?>"><?= VIEW_STATUS_LABEL[$viewStatus] ?></span>
    </div>
    <div class="actions">
        <a class="btn" href="room_edit.php?id=<?= (int) $room['id'] ?>">แก้ไขห้อง</a>
        <a class="btn btn-danger" href="room_delete.php?id=<?= (int) $room['id'] ?>">ลบห้อง</a>
    </div>
</header>

<div class="detail-grid">
    <section class="panel">
        <h2 class="h-sm">ข้อมูลห้อง</h2>
        <dl class="facts">
            <dt>ชั้น</dt><dd><?= room_floor($room['room_number']) ?: '-' ?></dd>
            <dt>ค่าเช่า</dt><dd><?= baht($room['price']) ?> / เดือน</dd>
            <dt>ค่าน้ำ</dt><dd><?= WATER_TYPE[$room['water_type']] ?></dd>
            <dt>สถานะห้อง</dt><dd><?= ROOM_STATUS[$room['status']] ?></dd>
        </dl>
    </section>

    <section class="panel">
        <h2 class="h-sm">ผู้เช่า</h2>
        <?php if ($tenant): ?>
            <dl class="facts">
                <dt>ชื่อ</dt><dd><?= e($tenant['full_name']) ?></dd>
                <dt>โทร</dt><dd><?= e($tenant['phone']) ?></dd>
                <dt>เข้าพักตั้งแต่</dt><dd><?= thai_date($tenant['move_in_date']) ?></dd>
            </dl>
        <?php else: ?>
            <p class="muted"><?= $room['status'] === 'maintenance' ? 'ยังไม่มีผู้เช่า ห้องนี้อยู่ระหว่างซ่อม' : 'ห้องนี้ว่าง ยังไม่มีผู้เช่า' ?></p>
        <?php endif; ?>
    </section>
</div>

<?php if ($unpaid > 0): ?>
    <div class="flash flash-error">ห้องนี้ค้างชำระ <?= baht($unpaid) ?></div>
<?php endif; ?>

<section class="stack">
    <h2 class="h-sm">ประวัติบิล</h2>
    <?php if ($bills): ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>เดือน</th><th class="right">ไฟ (หน่วย)</th><th class="right">น้ำ</th><th class="right">ยอดรวม</th><th>สถานะ</th></tr></thead>
                <tbody>
                    <?php foreach ($bills as $bill): ?>
                        <tr>
                            <td><?= thai_date($bill['billing_month'], true) ?></td>
                            <td class="right num"><?= number_format($bill['elec_curr'] - $bill['elec_prev']) ?></td>
                            <td class="right num"><?= $bill['water_curr'] === null ? 'เหมาจ่าย' : number_format($bill['water_curr'] - $bill['water_prev']) . ' หน่วย' ?></td>
                            <td class="right num"><?= baht($bill['total_amount']) ?></td>
                            <td><?= $bill['status'] === 'paid' ? '<span class="pill pill-paid">ชำระแล้ว</span>' : '<span class="pill pill-overdue">ค้างชำระ</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p class="muted">ยังไม่มีบิลของห้องนี้</p>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
