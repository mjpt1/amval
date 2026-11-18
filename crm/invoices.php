<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');
require_permission('perm_view_prices');

$clientsList = db()->query('SELECT id, name FROM clients ORDER BY name')->fetchAll();
$clientId = (int)($_GET['client_id'] ?? 0);

$sql = '
    SELECT i.*, c.name AS client_name
    FROM invoices i JOIN clients c ON c.id = i.client_id
    WHERE 1=1
';
$params = [];
if ($clientId) { $sql .= ' AND i.client_id = ?'; $params[] = $clientId; }
$sql .= ' ORDER BY i.issue_date DESC, i.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();
$total = array_sum(array_map(fn($i) => $i['total'] > 0 ? $i['total'] : $i['subtotal'], $invoices));

$pageTitle = 'فاکتورها';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">فاکتورها</h4>
    <div class="d-flex gap-2 no-print">
        <a href="<?= e(base_url('export_csv.php?type=invoices&' . http_build_query($_GET))) ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-spreadsheet"></i> خروجی اکسل</a>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> چاپ / PDF</button>
        <?php if (can_manage_invoices_perm()): ?>
        <a href="<?= e(base_url('invoice_form.php')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> فاکتور جدید</a>
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
    <span class="text-muted small">تعداد نتایج: <?= count($invoices) ?></span>
    <span class="fw-bold">جمع مبلغ فاکتورها: <?= money_fmt($total) ?></span>
</div>
<div class="table-responsive">
<table class="table align-middle">
<thead class="table-light">
<tr><th>شماره فاکتور</th><th>کارفرما</th><th>تاریخ صدور</th><th>مبلغ</th><th></th></tr>
</thead>
<tbody>
<?php if (!$invoices): ?>
    <tr><td colspan="5" class="text-center text-muted py-4">فاکتوری صادر نشده است.</td></tr>
<?php endif; ?>
<?php foreach ($invoices as $inv): ?>
<tr>
    <td dir="ltr">
        <?= e($inv['invoice_number']) ?>
        <?php if ($inv['is_quote']): ?><span class="badge text-bg-info">پیش‌فاکتور</span><?php endif; ?>
    </td>
    <td><a href="<?= e(base_url('client_view.php?id=' . $inv['client_id'])) ?>"><?= e($inv['client_name']) ?></a></td>
    <td><?= format_date_fa($inv['issue_date']) ?></td>
    <td class="fw-bold"><?= money($inv['total'] > 0 ? $inv['total'] : $inv['subtotal']) ?></td>
    <td><a href="<?= e(base_url('invoice_view.php?id=' . $inv['id'])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i> مشاهده</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
