<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');
require_permission('perm_view_prices');
require_permission('perm_manage_clients');

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$client = ['id' => 0, 'name' => '', 'contact_name' => '', 'phone' => '', 'email' => '', 'address' => '', 'notes' => ''];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) {
        flash_set('danger', 'کارفرما یافت نشد.');
        redirect('clients.php');
    }
    $client = $found;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $client['name'] = trim($_POST['name'] ?? '');
    $client['contact_name'] = trim($_POST['contact_name'] ?? '');
    $client['phone'] = trim($_POST['phone'] ?? '');
    $client['email'] = trim($_POST['email'] ?? '');
    $client['address'] = trim($_POST['address'] ?? '');
    $client['notes'] = trim($_POST['notes'] ?? '');

    if ($client['name'] === '') {
        $errors[] = 'نام کارفرما الزامی است.';
    }

    if (!$errors) {
        if ($id) {
            $stmt = db()->prepare('UPDATE clients SET name=?, contact_name=?, phone=?, email=?, address=?, notes=? WHERE id=?');
            $stmt->execute([$client['name'], $client['contact_name'], $client['phone'], $client['email'], $client['address'], $client['notes'], $id]);
            log_activity('update', 'client', $id, $client['name']);
            flash_set('success', 'اطلاعات کارفرما به‌روزرسانی شد.');
        } else {
            $stmt = db()->prepare('INSERT INTO clients (name, contact_name, phone, email, address, notes, created_by) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$client['name'], $client['contact_name'], $client['phone'], $client['email'], $client['address'], $client['notes'], current_user()['id']]);
            $id = (int)db()->lastInsertId();
            log_activity('create', 'client', $id, $client['name']);
            flash_set('success', 'کارفرمای جدید ثبت شد.');
        }
        redirect('client_view.php?id=' . $id);
    }
}

$pageTitle = $id ? 'ویرایش کارفرما' : 'کارفرمای جدید';
require __DIR__ . '/includes/header.php';
?>

<h4 class="mb-3"><?= e($pageTitle) ?></h4>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger py-2"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="card p-4" style="max-width:640px">
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$id ?>">

    <div class="mb-3">
        <label class="form-label">نام کارفرما / پروژه <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control" required value="<?= e($client['name']) ?>">
    </div>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">نام شخص تماس</label>
            <input type="text" name="contact_name" class="form-control" value="<?= e($client['contact_name']) ?>">
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">تلفن</label>
            <input type="text" name="phone" class="form-control" value="<?= e($client['phone']) ?>">
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label">ایمیل</label>
        <input type="email" name="email" class="form-control" value="<?= e($client['email']) ?>">
    </div>
    <div class="mb-3">
        <label class="form-label">آدرس</label>
        <input type="text" name="address" class="form-control" value="<?= e($client['address'] ?? '') ?>">
    </div>
    <div class="mb-3">
        <label class="form-label">یادداشت</label>
        <textarea name="notes" class="form-control" rows="3"><?= e($client['notes']) ?></textarea>
    </div>

    <button type="submit" class="btn btn-primary">ذخیره</button>
    <a href="<?= e(base_url('clients.php')) ?>" class="btn btn-outline-secondary">انصراف</a>
</form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
