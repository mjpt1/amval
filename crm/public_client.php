<?php
/**
 * صفحه عمومی مشاهده وضعیت کارها و مانده حساب یک کارفرما - بدون نیاز به لاگین
 * دسترسی فقط با داشتن توکن معتبر (لینک اختصاصی) ممکن است
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$token = trim($_GET['t'] ?? '');
$client = null;

if ($token !== '') {
    $stmt = db()->prepare('SELECT * FROM clients WHERE public_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $client = $stmt->fetch();
}

$appName = defined('APP_NAME') ? APP_NAME : 'سیستم مدیریت کارها و امور مالی';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $client ? e($client['name']) . ' - ' : '' ?><?= e($appName) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-dark bg-primary px-3">
    <div class="container-fluid">
        <span class="navbar-brand"><i class="bi bi-kanban-fill me-1"></i> <?= e($appName) ?></span>
    </div>
</nav>

<div class="container py-4">

<?php if (!$client): ?>
    <div class="alert alert-danger">این لینک معتبر نیست یا غیرفعال شده است.</div>
<?php else:
    $clientId = (int)$client['id'];
    $f = client_financials($clientId);
    $jobs = db()->prepare('
        SELECT j.*, s.label AS status_label, s.color AS status_color
        FROM jobs j JOIN job_statuses s ON s.id = j.status_id
        WHERE j.client_id = ?
        ORDER BY j.created_at DESC
    ');
    $jobs->execute([$clientId]);
    $jobs = $jobs->fetchAll();

    $payments = db()->prepare('SELECT * FROM payments WHERE client_id = ? ORDER BY COALESCE(payment_date, created_at) DESC, id DESC');
    $payments->execute([$clientId]);
    $payments = $payments->fetchAll();
?>

    <h4 class="mb-4"><?= e($client['name']) ?></h4>

    <div class="row g-3 mb-4">
        <div class="col-md-4 col-6">
            <div class="card stat-card p-3 h-100">
                <div class="text-muted small">مجموع پرداختی</div>
                <div class="fs-5 fw-bold text-success"><?= money($f['paid']) ?> <?= e(currency_label()) ?></div>
            </div>
        </div>
        <div class="col-md-4 col-6">
            <div class="card stat-card p-3 h-100">
                <div class="text-muted small">مبلغ قابل محاسبه</div>
                <div class="fs-5 fw-bold"><?= money($f['billable']) ?> <?= e(currency_label()) ?></div>
            </div>
        </div>
        <div class="col-md-4 col-6">
            <div class="card stat-card p-3 h-100">
                <div class="text-muted small">مانده حساب</div>
                <div class="fs-5 fw-bold <?= $f['remaining'] > 0 ? 'text-danger' : 'text-success' ?>"><?= money($f['remaining']) ?> <?= e(currency_label()) ?></div>
            </div>
        </div>
    </div>

    <div class="card p-3 mb-4">
        <h6 class="fw-bold mb-3">لیست کارها</h6>
        <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light">
            <tr><th>عنوان کار</th><th>هزینه</th><th>وضعیت</th><th>تاریخ</th></tr>
            </thead>
            <tbody>
            <?php if (!$jobs): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">کاری ثبت نشده است.</td></tr>
            <?php endif; ?>
            <?php foreach ($jobs as $j): ?>
                <tr>
                    <td><?= e($j['title']) ?></td>
                    <td><?= money($j['cost']) ?></td>
                    <td><span class="badge text-bg-<?= e($j['status_color']) ?>"><?= e($j['status_label']) ?></span></td>
                    <td><?= format_date_fa($j['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="card p-3">
        <h6 class="fw-bold mb-3">لیست پرداخت‌ها</h6>
        <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light">
            <tr><th>مبلغ</th><th>تاریخ</th></tr>
            </thead>
            <tbody>
            <?php if (!$payments): ?>
                <tr><td colspan="2" class="text-center text-muted py-4">پرداختی ثبت نشده است.</td></tr>
            <?php endif; ?>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td class="text-success fw-bold"><?= money($p['amount']) ?></td>
                    <td><?= $p['date_label'] ? e($p['date_label']) : format_date_fa($p['payment_date']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

<?php endif; ?>

</div>
</body>
</html>
