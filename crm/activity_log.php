<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');
require_permission('perm_view_prices');

$entityType = trim($_GET['entity_type'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 40;
$offset = ($page - 1) * $perPage;

$sql = '
    SELECT a.*, u.full_name AS user_name
    FROM activity_log a
    LEFT JOIN users u ON u.id = a.user_id
    WHERE 1=1
';
$params = [];
if ($entityType !== '') {
    $sql .= ' AND a.entity_type = ?';
    $params[] = $entityType;
}
$countStmt = db()->prepare(str_replace('SELECT a.*, u.full_name AS user_name', 'SELECT COUNT(*)', $sql));
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql .= ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$stmt = db()->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$totalPages = max(1, (int)ceil($total / $perPage));

$pageTitle = 'لاگ فعالیت';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">لاگ فعالیت کاربران</h4>
</div>

<form class="row g-2 mb-3" method="get">
    <div class="col-md-3">
        <select name="entity_type" class="form-select" onchange="this.form.submit()">
            <option value="">همه موارد</option>
            <option value="client" <?= $entityType === 'client' ? 'selected' : '' ?>>کارفرماها</option>
            <option value="job" <?= $entityType === 'job' ? 'selected' : '' ?>>کارها</option>
            <option value="payment" <?= $entityType === 'payment' ? 'selected' : '' ?>>پرداخت‌ها</option>
            <option value="user" <?= $entityType === 'user' ? 'selected' : '' ?>>کاربران</option>
            <option value="invoice" <?= $entityType === 'invoice' ? 'selected' : '' ?>>فاکتورها</option>
            <option value="quote" <?= $entityType === 'quote' ? 'selected' : '' ?>>پیش‌فاکتورها</option>
            <option value="backup" <?= $entityType === 'backup' ? 'selected' : '' ?>>پشتیبان‌گیری</option>
        </select>
    </div>
</form>

<div class="card p-3">
<div class="table-responsive">
<table class="table table-sm align-middle">
<thead class="table-light">
<tr><th>زمان</th><th>کاربر</th><th>عملیات</th><th>مورد</th><th>جزئیات</th></tr>
</thead>
<tbody>
<?php if (!$logs): ?>
    <tr><td colspan="5" class="text-center text-muted py-4">فعالیتی ثبت نشده است.</td></tr>
<?php endif; ?>
<?php foreach ($logs as $l): ?>
<tr>
    <td class="text-nowrap small text-muted"><?= format_date_fa($l['created_at']) ?> <?= date('H:i', strtotime($l['created_at'])) ?></td>
    <td><?= e($l['user_name'] ?? 'سیستم') ?></td>
    <td>
        <span class="badge text-bg-<?= $l['action'] === 'create' ? 'success' : ($l['action'] === 'delete' ? 'danger' : 'secondary') ?>">
            <?= e(activity_action_label($l['action'])) ?>
        </span>
        <?= e(activity_entity_label($l['entity_type'])) ?>
    </td>
    <td><?= e($l['entity_label']) ?></td>
    <td class="text-muted small"><?= e($l['details'] ?: '-') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-2">
    <ul class="pagination pagination-sm mb-0">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= e(base_url('activity_log.php?page=' . $p . ($entityType ? '&entity_type=' . urlencode($entityType) : ''))) ?>"><?= $p ?></a>
            </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
