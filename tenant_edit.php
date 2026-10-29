<?php
/**
 * ไฟล์: tenant_edit.php  (CRUD ผู้เช่า: UPDATE)
 * แก้ชื่อ เบอร์โทร และวันเข้าพัก พร้อมปุ่มไปบันทึกย้ายออก / ลบ   —  tenant_edit.php?id=1
 */
require_once __DIR__ . '/includes/auth.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    flash('error', 'ไม่พบผู้เช่าที่ต้องการ');
    redirect('tenants.php');
}

$stmt = $conn->prepare('SELECT t.id, t.room_id, t.full_name, t.phone, t.move_in_date, t.move_out_date, r.room_number
                        FROM project_tenants t JOIN project_rooms r ON r.id = t.room_id
                        WHERE t.id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$tenant = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$tenant) {
    flash('error', 'ไม่พบผู้เช่าที่ต้องการ อาจถูกลบไปแล้ว');
    redirect('tenants.php');
}

$data = [
    'room_id'      => (int) $tenant['room_id'],
    'full_name'    => $tenant['full_name'],
    'phone'        => $tenant['phone'],
    'move_in_date' => $tenant['move_in_date'],
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$data, $errors] = validate_tenant($_POST);   // ตอนแก้ไขไม่ให้ย้ายห้อง (ใช้ ย้ายออก แล้วเพิ่มใหม่แทน)

    if (!isset($errors['move_in_date']) && $tenant['move_out_date'] !== null && $data['move_in_date'] > $tenant['move_out_date']) {
        $errors['move_in_date'] = 'วันเข้าพักต้องไม่หลังวันย้ายออก (' . thai_date($tenant['move_out_date']) . ')';
    }

    if (!$errors) {
        $stmt = $conn->prepare('UPDATE project_tenants SET full_name = ?, phone = ?, move_in_date = ? WHERE id = ? LIMIT 1');
        $stmt->bind_param('sssi', $data['full_name'], $data['phone'], $data['move_in_date'], $id);
        $stmt->execute();
        $stmt->close();

        flash('success', 'บันทึกข้อมูล ' . $data['full_name'] . ' แล้ว');
        redirect('tenants.php' . ($tenant['move_out_date'] !== null ? '?show=past' : ''));
    }
}

$pageTitle = 'แก้ไขผู้เช่า';
$activeNav = 'tenants';
require __DIR__ . '/includes/header.php';
?>

<a class="back-link" href="tenants.php">ผู้เช่าทั้งหมด</a>
<header class="page-head">
    <div>
        <h1><?= e($tenant['full_name']) ?></h1>
        <p>ห้อง <a href="room_view.php?id=<?= (int) $tenant['room_id'] ?>"><?= e($tenant['room_number']) ?></a>
           · <?= $tenant['move_out_date'] === null ? 'พักอยู่' : 'ย้ายออกเมื่อ ' . thai_date($tenant['move_out_date']) ?></p>
    </div>
    <div class="actions">
        <?php if ($tenant['move_out_date'] === null): ?>
            <a class="btn" href="tenant_moveout.php?id=<?= (int) $id ?>">บันทึกย้ายออก</a>
        <?php endif; ?>
        <a class="btn btn-danger" href="tenant_delete.php?id=<?= (int) $id ?>">ลบผู้เช่า</a>
    </div>
</header>

<?php
$roomChoices = null;
$submitLabel = 'บันทึกการแก้ไข';
$cancelUrl   = 'tenants.php';
require __DIR__ . '/includes/tenant_form.php';
?>

<?php require __DIR__ . '/includes/footer.php'; ?>
