<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();
    $id = (int)$_POST['id'];
    if ($id === (int)current_user()['id']) {
        flash_set('danger', 'نمی‌توانید حساب کاربری خودتان را حذف کنید.');
        redirect('users.php');
    }
    $nameStmt = db()->prepare('SELECT full_name FROM users WHERE id = ?');
    $nameStmt->execute([$id]);
    $name = (string)$nameStmt->fetchColumn();
    $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
    log_activity('delete', 'user', $id, $name ?: ('#' . $id));
    flash_set('success', 'کاربر حذف شد.');
    redirect('users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    csrf_verify();
    $id = (int)$_POST['id'];
    if ($id !== (int)current_user()['id']) {
        $stmt = db()->prepare('UPDATE users SET is_active = NOT is_active WHERE id = ?');
        $stmt->execute([$id]);
    }
    redirect('users.php');
}

$users = db()->query('
    SELECT u.*, c.name AS client_name
    FROM users u LEFT JOIN clients c ON c.id = u.client_id
    ORDER BY FIELD(u.role, "admin", "staff", "client"), u.full_name
')->fetchAll();

$roleLabels = ['admin' => 'مدیر کل', 'staff' => 'همکار/کارمند', 'client' => 'کارفرما (پورتال مشتری)'];

$pageTitle = 'کاربران';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">مدیریت کاربران</h4>
    <a href="<?= e(base_url('user_form.php')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> کاربر جدید</a>
</div>

<div class="card p-3">
<div class="table-responsive">
<table class="table align-middle">
<thead class="table-light">
<tr><th>نام</th><th>نام کاربری</th><th>نقش</th><th>کارفرمای متصل</th><th>وضعیت</th><th>آخرین ورود</th><th></th></tr>
</thead>
<tbody>
<?php foreach ($users as $u): ?>
<tr>
    <td><?= e($u['full_name']) ?></td>
    <td><?= e($u['username']) ?></td>
    <td>
        <span class="badge text-bg-<?= $u['role'] === 'admin' ? 'danger' : ($u['role'] === 'staff' ? 'primary' : 'info') ?>"><?= e($roleLabels[$u['role']] ?? $u['role']) ?></span>
        <?php if ($u['role'] === 'staff' && !$u['perm_view_prices']): ?>
            <span class="badge text-bg-warning" title="این کارمند قیمت‌های کارفرما را نمی‌بیند">همکار محدود</span>
        <?php endif; ?>
        <?php if ($u['role'] === 'client'):
            $grantedClientPerms = array_filter(array_keys(CLIENT_PERMISSIONS), fn($k) => !empty($u[$k]));
        ?>
            <?php if ($grantedClientPerms): ?>
                <span class="badge text-bg-success" title="این کارفرما دسترسی‌های ویژه دارد"><?= fa_digits((string)count($grantedClientPerms)) ?> دسترسی ویژه</span>
            <?php endif; ?>
        <?php endif; ?>
    </td>
    <td><?= $u['client_name'] ? e($u['client_name']) : '-' ?></td>
    <td>
        <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn btn-sm <?= $u['is_active'] ? 'btn-outline-success' : 'btn-outline-secondary' ?>" <?= (int)$u['id'] === (int)current_user()['id'] ? 'disabled' : '' ?>>
                <?= $u['is_active'] ? 'فعال' : 'غیرفعال' ?>
            </button>
        </form>
    </td>
    <td class="small text-muted"><?= $u['last_login'] ? format_date_fa($u['last_login']) : '-' ?></td>
    <td class="text-nowrap">
        <a href="<?= e(base_url('user_form.php?id=' . $u['id'])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
        <?php if ((int)$u['id'] !== (int)current_user()['id']): ?>
        <form method="post" class="d-inline" onsubmit="return confirm('این کاربر حذف شود؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
        </form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
