<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');
require_permission('perm_view_prices');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();
    require_permission('perm_manage_payments');
    $id = (int)$_POST['id'];
    $stmt = db()->prepare('DELETE FROM payments WHERE id = ?');
    $stmt->execute([$id]);
    log_activity('delete', 'payment', $id, '#' . $id);
    flash_set('success', 'پرداخت حذف شد.');
    redirect('payments.php');
}

$clientsList = db()->query('SELECT id, name FROM clients ORDER BY name')->fetchAll();
$clientId = (int)($_GET['client_id'] ?? 0);

$sql = '
    SELECT p.*, c.name AS client_name, j.title AS job_title
    FROM payments p
    JOIN clients c ON c.id = p.client_id
    LEFT JOIN jobs j ON j.id = p.job_id
    WHERE 1=1
';
$params = [];
if ($clientId) { $sql .= ' AND p.client_id = ?'; $params[] = $clientId; }
$sql .= ' ORDER BY COALESCE(p.payment_date, p.created_at) DESC, p.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();
$totalPaid = array_sum(array_column($payments, 'amount'));

$pageTitle = 'پرداخت‌ها';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">پرداخت‌ها</h4>
    <div class="d-flex gap-2 no-print">
        <a href="<?= e(base_url('export_csv.php?type=payments&' . http_build_query($_GET))) ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-spreadsheet"></i> خروجی اکسل</a>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> چاپ / PDF</button>
        <?php if (can_manage_payments_perm()): ?>
        <a href="<?= e(base_url('payment_form.php')) ?>" class="btn btn-success"><i class="bi bi-plus-lg"></i> ثبت پرداخت جدید</a>
        <?php endif; ?>
    </div>
</div>

<form class="row g-2 mb-3 no-print" method="get">
    <div class="col-md-4">
        <select name="client_id" class="form-select" onchange="this.form.submit()">
            <option value="0">همه کارفرماها</option>
            <?php foreach ($clientsList as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $clientId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<div class="card p-3">
<div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted small">تعداد نتایج: <?= count($payments) ?></span>
    <span class="fw-bold text-success">جمع پرداختی: <?= money_fmt($totalPaid) ?></span>
</div>
<div class="table-responsive">
<table class="table align-middle">
<thead class="table-light">
<tr><th>کارفرما</th><th>کار مرتبط</th><th>مبلغ</th><th>تاریخ</th><th>توضیح</th><th></th></tr>
</thead>
<tbody>
<?php if (!$payments): ?>
    <tr><td colspan="6" class="text-center text-muted py-4">پرداختی ثبت نشده است.</td></tr>
<?php endif; ?>
<?php foreach ($payments as $p): ?>
<tr>
    <td><a href="<?= e(base_url('client_view.php?id=' . $p['client_id'])) ?>"><?= e($p['client_name']) ?></a></td>
    <td class="text-muted small"><?= $p['job_title'] ? e($p['job_title']) : '—' ?></td>
    <td class="text-success fw-bold"><?= money($p['amount']) ?></td>
    <td><?= $p['date_label'] ? e($p['date_label']) : format_date_fa($p['payment_date']) ?></td>
    <td class="text-muted small"><?= e($p['note'] ?: '-') ?></td>
    <td class="text-nowrap">
        <?php if (can_manage_payments_perm()): ?>
        <a href="<?= e(base_url('payment_form.php?id=' . $p['id'])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
        <?php endif; ?>
        <?php if (is_admin()): ?>
        <form method="post" class="d-inline" onsubmit="return confirm('این پرداخت حذف شود؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
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
