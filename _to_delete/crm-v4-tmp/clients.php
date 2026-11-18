<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');
require_permission('perm_view_prices');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();
    require_role('admin');
    $id = (int)$_POST['id'];
    $nameStmt = db()->prepare('SELECT name FROM clients WHERE id = ?');
    $nameStmt->execute([$id]);
    $name = (string)$nameStmt->fetchColumn();
    $stmt = db()->prepare('DELETE FROM clients WHERE id = ?');
    $stmt->execute([$id]);
    log_activity('delete', 'client', $id, $name ?: ('#' . $id));
    flash_set('success', 'کارفرما و تمام کارها/پرداخت‌های مرتبط با آن حذف شد.');
    redirect('clients.php');
}

$q = trim($_GET['q'] ?? '');
$sql = 'SELECT * FROM clients';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE name LIKE ? OR contact_name LIKE ?';
    $params = ["%$q%", "%$q%"];
}
$sql .= ' ORDER BY name';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll();

$pageTitle = 'کارفرماها';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">کارفرماها</h4>
    <div class="d-flex gap-2 no-print">
        <a href="<?= e(base_url('export_csv.php?type=clients&q=' . urlencode($q))) ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-spreadsheet"></i> خروجی اکسل</a>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> چاپ / PDF</button>
        <?php if (can_manage_clients_perm()): ?>
        <a href="<?= e(base_url('client_form.php')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> کارفرمای جدید</a>
        <?php endif; ?>
    </div>
</div>

<form class="mb-3 no-print" method="get">
    <div class="input-group" style="max-width:320px">
        <input type="text" name="q" class="form-control" placeholder="جستجوی نام کارفرما..." value="<?= e($q) ?>">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
    </div>
</form>

<div class="card p-3">
<div class="table-responsive">
<table class="table align-middle">
<thead class="table-light">
<tr>
    <th>نام کارفرما</th>
    <th>تماس</th>
    <th>تعداد کار</th>
    <th>مانده حساب</th>
    <th></th>
</tr>
</thead>
<tbody>
<?php if (!$clients): ?>
    <tr><td colspan="5" class="text-center text-muted py-4">کارفرمایی یافت نشد.</td></tr>
<?php endif; ?>
<?php foreach ($clients as $c): $f = client_financials((int)$c['id']); ?>
<tr>
    <td><a href="<?= e(base_url('client_view.php?id=' . $c['id'])) ?>" class="fw-bold text-decoration-none"><?= e($c['name']) ?></a></td>
    <td class="text-muted small"><?= e($c['contact_name'] ?: '-') ?><?= $c['phone'] ? ' / ' . e($c['phone']) : '' ?></td>
    <td><?= (int)$f['job_count'] ?></td>
    <td class="fw-bold <?= $f['remaining'] > 0 ? 'text-danger' : 'text-success' ?>"><?= money_fmt($f['remaining']) ?></td>
    <td class="text-nowrap">
        <a href="<?= e(base_url('client_view.php?id=' . $c['id'])) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
        <?php if (can_manage_clients_perm()): ?>
        <a href="<?= e(base_url('client_form.php?id=' . $c['id'])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (is_admin()): ?>
        <form method="post" class="d-inline" onsubmit="return confirm('حذف این کارفرما به همراه تمام کارها و پرداخت‌های آن انجام می‌شود. ادامه می‌دهید؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
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
