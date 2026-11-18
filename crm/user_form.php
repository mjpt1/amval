<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$user = ['id' => 0, 'full_name' => '', 'username' => '', 'role' => 'staff', 'client_id' => '', 'is_active' => 1,
    'perm_view_prices' => 1, 'perm_manage_clients' => 1, 'perm_manage_payments' => 1, 'perm_manage_invoices' => 1,
    'perm_client_create_job' => 0, 'perm_client_edit_job' => 0, 'perm_client_suggest_price' => 0];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) {
        flash_set('danger', 'کاربر یافت نشد.');
        redirect('users.php');
    }
    $user = $found;
}

$clients = db()->query('SELECT id, name FROM clients ORDER BY name')->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $user['full_name'] = trim($_POST['full_name'] ?? '');
    $user['username'] = trim($_POST['username'] ?? '');
    $user['role'] = $_POST['role'] ?? 'staff';
    $user['client_id'] = $_POST['client_id'] ?? '';
    $password = (string)($_POST['password'] ?? '');
    foreach (array_keys(STAFF_PERMISSIONS) as $permKey) {
        $user[$permKey] = isset($_POST[$permKey]) ? 1 : 0;
    }
    foreach (array_keys(CLIENT_PERMISSIONS) as $permKey) {
        $user[$permKey] = isset($_POST[$permKey]) ? 1 : 0;
    }

    if ($user['full_name'] === '') $errors[] = 'نام کامل الزامی است.';
    if ($user['username'] === '') $errors[] = 'نام کاربری الزامی است.';
    if (!in_array($user['role'], ['admin', 'staff', 'client'], true)) $errors[] = 'نقش نامعتبر است.';
    if ($user['role'] === 'client' && $user['client_id'] === '') $errors[] = 'برای نقش «کارفرما» انتخاب کارفرمای متصل الزامی است.';
    if (!$id && $password === '') $errors[] = 'برای کاربر جدید تعیین رمز عبور الزامی است.';
    if ($password !== '' && strlen($password) < 6) $errors[] = 'رمز عبور باید حداقل ۶ کاراکتر باشد.';

    if (!$errors) {
        $chk = db()->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
        $chk->execute([$user['username'], $id]);
        if ($chk->fetch()) {
            $errors[] = 'این نام کاربری قبلا استفاده شده است.';
        }
    }

    $clientIdVal = $user['role'] === 'client' && $user['client_id'] !== '' ? (int)$user['client_id'] : null;

    if (!$errors) {
        if ($id) {
            if ($password !== '') {
                $stmt = db()->prepare('UPDATE users SET full_name=?, username=?, role=?, client_id=?, password_hash=?, perm_view_prices=?, perm_manage_clients=?, perm_manage_payments=?, perm_manage_invoices=?, perm_client_create_job=?, perm_client_edit_job=?, perm_client_suggest_price=? WHERE id=?');
                $stmt->execute([$user['full_name'], $user['username'], $user['role'], $clientIdVal, password_hash($password, PASSWORD_BCRYPT), $user['perm_view_prices'], $user['perm_manage_clients'], $user['perm_manage_payments'], $user['perm_manage_invoices'], $user['perm_client_create_job'], $user['perm_client_edit_job'], $user['perm_client_suggest_price'], $id]);
            } else {
                $stmt = db()->prepare('UPDATE users SET full_name=?, username=?, role=?, client_id=?, perm_view_prices=?, perm_manage_clients=?, perm_manage_payments=?, perm_manage_invoices=?, perm_client_create_job=?, perm_client_edit_job=?, perm_client_suggest_price=? WHERE id=?');
                $stmt->execute([$user['full_name'], $user['username'], $user['role'], $clientIdVal, $user['perm_view_prices'], $user['perm_manage_clients'], $user['perm_manage_payments'], $user['perm_manage_invoices'], $user['perm_client_create_job'], $user['perm_client_edit_job'], $user['perm_client_suggest_price'], $id]);
            }
            log_activity('update', 'user', $id, $user['full_name']);
            flash_set('success', 'اطلاعات کاربر به‌روزرسانی شد.');
        } else {
            $stmt = db()->prepare('INSERT INTO users (full_name, username, password_hash, role, client_id, is_active, perm_view_prices, perm_manage_clients, perm_manage_payments, perm_manage_invoices, perm_client_create_job, perm_client_edit_job, perm_client_suggest_price) VALUES (?,?,?,?,?,1,?,?,?,?,?,?,?)');
            $stmt->execute([$user['full_name'], $user['username'], password_hash($password, PASSWORD_BCRYPT), $user['role'], $clientIdVal, $user['perm_view_prices'], $user['perm_manage_clients'], $user['perm_manage_payments'], $user['perm_manage_invoices'], $user['perm_client_create_job'], $user['perm_client_edit_job'], $user['perm_client_suggest_price']]);
            $id = (int)db()->lastInsertId();
            log_activity('create', 'user', $id, $user['full_name']);
            flash_set('success', 'کاربر جدید ایجاد شد.');
        }
        redirect('users.php');
    }
}

$pageTitle = $id ? 'ویرایش کاربر' : 'کاربر جدید';
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
        <label class="form-label">نام کامل <span class="text-danger">*</span></label>
        <input type="text" name="full_name" class="form-control" required value="<?= e($user['full_name']) ?>">
    </div>
    <div class="mb-3">
        <label class="form-label">نام کاربری <span class="text-danger">*</span></label>
        <input type="text" name="username" class="form-control" required dir="ltr" value="<?= e($user['username']) ?>">
    </div>
    <div class="mb-3">
        <label class="form-label">رمز عبور <?= $id ? '(برای تغییر ندادن، خالی بگذارید)' : '<span class="text-danger">*</span>' ?></label>
        <input type="password" name="password" class="form-control" dir="ltr" <?= $id ? '' : 'required' ?>>
    </div>
    <div class="mb-3">
        <label class="form-label">نقش کاربری <span class="text-danger">*</span></label>
        <select name="role" id="roleSelect" class="form-select" required onchange="document.getElementById('clientWrap').style.display = this.value==='client' ? 'block' : 'none'; document.getElementById('permsWrap').style.display = this.value==='staff' ? 'block' : 'none'; document.getElementById('clientPermsWrap').style.display = this.value==='client' ? 'block' : 'none';">
            <option value="staff" <?= $user['role'] === 'staff' ? 'selected' : '' ?>>همکار/کارمند (دسترسی کامل به کارفرماها، کارها و پرداخت‌ها)</option>
            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>مدیر کل (دسترسی کامل + مدیریت کاربران و تنظیمات)</option>
            <option value="client" <?= $user['role'] === 'client' ? 'selected' : '' ?>>کارفرما (فقط مشاهده کارها و مانده حساب خودش)</option>
        </select>
    </div>
    <div class="mb-3" id="clientWrap" style="display: <?= $user['role'] === 'client' ? 'block' : 'none' ?>">
        <label class="form-label">کارفرمای متصل به این حساب</label>
        <select name="client_id" class="form-select">
            <option value="">انتخاب کنید...</option>
            <?php foreach ($clients as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (string)$user['client_id'] === (string)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-3 border rounded p-3" id="clientPermsWrap" style="display: <?= $user['role'] === 'client' ? 'block' : 'none' ?>">
        <label class="form-label fw-bold mb-2">دسترسی‌های این کارفرما</label>
        <div class="text-muted small mb-3">به‌صورت پیش‌فرض کارفرما فقط می‌تواند کارها و مانده حساب خودش را ببیند و در گفتگوی کارها شرکت کند. با فعال کردن هرکدام از موارد زیر، دسترسی بیشتری مثل یک همکار به او می‌دهید.</div>
        <?php foreach (CLIENT_PERMISSIONS as $permKey => $permLabel): ?>
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" name="<?= e($permKey) ?>" id="<?= e($permKey) ?>" value="1" <?= !empty($user[$permKey]) ? 'checked' : '' ?>>
            <label class="form-check-label" for="<?= e($permKey) ?>"><?= e($permLabel) ?></label>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="mb-3 border rounded p-3" id="permsWrap" style="display: <?= $user['role'] === 'staff' ? 'block' : 'none' ?>">
        <label class="form-label fw-bold mb-2">دسترسی‌های این کارمند</label>
        <div class="text-muted small mb-3">به‌صورت پیش‌فرض همه دسترسی‌ها فعال است (دسترسی کامل مثل قبل). برای همکارانی که فقط باید قیمت خودشان را برای کار وارد کنند و نباید قیمت کارفرما را ببینند، تیک «مشاهده قیمت‌ها» را بردارید.</div>
        <?php foreach (STAFF_PERMISSIONS as $permKey => $permLabel): ?>
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" name="<?= e($permKey) ?>" id="<?= e($permKey) ?>" value="1" <?= !empty($user[$permKey]) ? 'checked' : '' ?>>
            <label class="form-check-label" for="<?= e($permKey) ?>"><?= e($permLabel) ?></label>
        </div>
        <?php endforeach; ?>
        <?php if ($id): ?>
        <div class="text-muted small mt-2"><i class="bi bi-info-circle"></i> اگر «مشاهده قیمت‌ها» غیرفعال باشد، این کارمند فقط کارهایی را می‌بیند که به او محول شده و برای آن‌ها فقط می‌تواند «قیمت خودش» را وارد کند؛ نه هزینه‌ای که از کارفرما گرفته می‌شود.</div>
        <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary">ذخیره</button>
    <a href="<?= e(base_url('users.php')) ?>" class="btn btn-outline-secondary">انصراف</a>
</form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
