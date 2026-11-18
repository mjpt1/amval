<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();
    require_role('admin');
    $id = (int)$_POST['id'];
    $titleStmt = db()->prepare('SELECT title FROM jobs WHERE id = ?');
    $titleStmt->execute([$id]);
    $title = (string)$titleStmt->fetchColumn();
    $stmt = db()->prepare('DELETE FROM jobs WHERE id = ?');
    $stmt->execute([$id]);
    log_activity('delete', 'job', $id, $title ?: ('#' . $id));
    flash_set('success', 'کار حذف شد.');
    redirect('jobs.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_status') {
    csrf_verify();
    $id = (int)$_POST['id'];
    $statusId = (int)$_POST['status_id'];
    if (is_restricted_associate()) {
        $ownerStmt = db()->prepare('SELECT assigned_to FROM jobs WHERE id = ?');
        $ownerStmt->execute([$id]);
        $assignedTo = (int)$ownerStmt->fetchColumn();
        if ($assignedTo !== (int)current_user()['id']) {
            flash_set('danger', 'شما به این کار دسترسی ندارید.');
            redirect('jobs.php');
        }
    }
    $stmt = db()->prepare('UPDATE jobs SET status_id = ? WHERE id = ?');
    $stmt->execute([$statusId, $id]);
    $titleStmt = db()->prepare('SELECT title FROM jobs WHERE id = ?');
    $titleStmt->execute([$id]);
    log_activity('update', 'job', $id, (string)$titleStmt->fetchColumn(), 'تغییر وضعیت');
    flash_set('success', 'وضعیت کار به‌روزرسانی شد.');
    redirect('jobs.php' . (!empty($_POST['back_qs']) ? '?' . $_POST['back_qs'] : ''));
}

$statuses = db()->query('SELECT * FROM job_statuses ORDER BY sort_order')->fetchAll();
$clientsList = db()->query('SELECT id, name FROM clients ORDER BY name')->fetchAll();

$isAssociateView = is_restricted_associate();

$clientId = (int)($_GET['client_id'] ?? 0);
$statusId = (int)($_GET['status_id'] ?? 0);
$q = trim($_GET['q'] ?? '');

$sql = '
    SELECT j.*, c.name AS client_name, s.label AS status_label, s.color AS status_color
    FROM jobs j
    JOIN clients c ON c.id = j.client_id
    JOIN job_statuses s ON s.id = j.status_id
    WHERE 1=1
';
$params = [];
if ($isAssociateView) { $sql .= ' AND j.assigned_to = ?'; $params[] = (int)current_user()['id']; }
if ($clientId) { $sql .= ' AND j.client_id = ?'; $params[] = $clientId; }
if ($statusId) { $sql .= ' AND j.status_id = ?'; $params[] = $statusId; }
if ($q !== '') { $sql .= ' AND j.title LIKE ?'; $params[] = "%$q%"; }
$sql .= ' ORDER BY j.created_at DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll();

$totalCost = $isAssociateView ? array_sum(array_column($jobs, 'associate_cost')) : array_sum(array_column($jobs, 'cost'));
$backQs = http_build_query($_GET);

$pageTitle = 'کارها';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><?= $isAssociateView ? 'کارهای محول‌شده به من' : 'لیست کارها' ?></h4>
    <div class="d-flex gap-2 no-print">
        <?php if (!$isAssociateView): ?>
        <a href="<?= e(base_url('export_csv.php?type=jobs&' . http_build_query($_GET))) ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-spreadsheet"></i> خروجی اکسل</a>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> چاپ / PDF</button>
        <?php if (!$isAssociateView): ?>
        <a href="<?= e(base_url('job_form.php')) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> کار جدید</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$isAssociateView): ?>
<form class="row g-2 mb-3 no-print" method="get">
    <div class="col-md-3">
        <select name="client_id" class="form-select" onchange="this.form.submit()">
            <option value="0">همه کارفرماها</option>
            <?php foreach ($clientsList as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $clientId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <select name="status_id" class="form-select" onchange="this.form.submit()">
            <option value="0">همه وضعیت‌ها</option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $statusId === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4">
        <input type="text" name="q" class="form-control" placeholder="جستجوی عنوان کار..." value="<?= e($q) ?>">
    </div>
    <div class="col-md-2">
        <button class="btn btn-outline-secondary w-100" type="submit"><i class="bi bi-search"></i> فیلتر</button>
    </div>
</form>
<?php endif; ?>

<div class="card p-3">
<div class="d-flex justify-content-between align-items-center mb-2">
    <span class="text-muted small">تعداد نتایج: <?= count($jobs) ?></span>
    <span class="fw-bold"><?= $isAssociateView ? 'جمع قیمت‌های من' : 'جمع هزینه' ?>: <?= money_fmt($totalCost) ?></span>
</div>
<div class="table-responsive">
<table class="table align-middle">
<thead class="table-light">
<tr>
    <th>عنوان کار</th>
    <th>کارفرما</th>
    <th><?= $isAssociateView ? 'قیمت شما' : 'هزینه' ?></th>
    <th>وضعیت</th>
    <th>موعد تحویل</th>
    <th>تاریخ ثبت</th>
    <th></th>
</tr>
</thead>
<tbody>
<?php if (!$jobs): ?>
    <tr><td colspan="7" class="text-center text-muted py-4">کاری یافت نشد.</td></tr>
<?php endif; ?>
<?php foreach ($jobs as $j): ?>
<?php $isOverdue = $j['due_date'] && $j['due_date'] < date('Y-m-d'); ?>
<tr>
    <td><?= e($j['title']) ?></td>
    <td><?= $isAssociateView ? e($j['client_name']) : '<a href="' . e(base_url('client_view.php?id=' . $j['client_id'])) . '">' . e($j['client_name']) . '</a>' ?></td>
    <td><?= $isAssociateView ? money($j['associate_cost'] ?? 0) : money($j['cost']) ?></td>
    <td style="min-width:170px">
        <form method="post" class="d-flex gap-1">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_status">
            <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
            <input type="hidden" name="back_qs" value="<?= e($backQs) ?>">
            <select name="status_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($statuses as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= (int)$j['status_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </td>
    <td class="<?= $isOverdue ? 'text-danger fw-bold' : '' ?>"><?= format_date_fa($j['due_date']) ?><?= $isOverdue ? ' <i class="bi bi-exclamation-triangle-fill"></i>' : '' ?></td>
    <td><?= format_date_fa($j['created_at']) ?></td>
    <td class="text-nowrap">
        <a href="<?= e(base_url('job_form.php?id=' . $j['id'])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
        <?php if (is_admin()): ?>
        <form method="post" class="d-inline" onsubmit="return confirm('این کار حذف شود؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
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
