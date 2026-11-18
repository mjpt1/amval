<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');

if (is_restricted_associate()) {
    $myJobsStmt = db()->prepare('
        SELECT j.*, c.name AS client_name, s.label AS status_label, s.color AS status_color
        FROM jobs j
        JOIN clients c ON c.id = j.client_id
        JOIN job_statuses s ON s.id = j.status_id
        WHERE j.assigned_to = ?
        ORDER BY (j.due_date IS NULL), j.due_date ASC, j.created_at DESC
    ');
    $myJobsStmt->execute([(int)current_user()['id']]);
    $myJobs = $myJobsStmt->fetchAll();
    $myTotal = array_sum(array_column($myJobs, 'associate_cost'));
    $myDueSoon = array_filter($myJobs, fn($j) => $j['due_date'] && $j['due_date'] <= date('Y-m-d', strtotime('+7 days')));

    $pageTitle = 'داشبورد من';
    require __DIR__ . '/includes/header.php';
    ?>
    <h4 class="mb-4">داشبورد من</h4>

    <div class="row g-3 mb-4">
        <div class="col-md-4 col-6">
            <div class="card stat-card p-3 h-100">
                <div class="text-muted small">تعداد کارهای محول‌شده به من</div>
                <div class="fs-3 fw-bold"><?= count($myJobs) ?></div>
            </div>
        </div>
        <div class="col-md-4 col-6">
            <div class="card stat-card p-3 h-100">
                <div class="text-muted small">جمع قیمت‌های ثبت‌شده من</div>
                <div class="fs-4 fw-bold"><?= money_fmt($myTotal) ?></div>
            </div>
        </div>
        <div class="col-md-4 col-6">
            <div class="card stat-card p-3 h-100">
                <div class="text-muted small">نزدیک به موعد (۷ روز آینده)</div>
                <div class="fs-3 fw-bold <?= count($myDueSoon) > 0 ? 'text-danger' : '' ?>"><?= count($myDueSoon) ?></div>
            </div>
        </div>
    </div>

    <div class="card p-3">
        <h6 class="fw-bold mb-3">کارهای محول‌شده به من</h6>
        <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light">
            <tr><th>عنوان کار</th><th>کارفرما</th><th>قیمت شما</th><th>وضعیت</th><th>موعد تحویل</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (!$myJobs): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">هنوز کاری به شما محول نشده است.</td></tr>
            <?php endif; ?>
            <?php foreach ($myJobs as $j): $isOverdue = $j['due_date'] && $j['due_date'] < date('Y-m-d'); ?>
                <tr>
                    <td><?= e($j['title']) ?></td>
                    <td><?= e($j['client_name']) ?></td>
                    <td><?= $j['associate_cost'] !== null ? money($j['associate_cost']) : '<span class="text-muted">ثبت نشده</span>' ?></td>
                    <td><span class="badge text-bg-<?= e($j['status_color']) ?>"><?= e($j['status_label']) ?></span></td>
                    <td class="<?= $isOverdue ? 'text-danger fw-bold' : '' ?>"><?= format_date_fa($j['due_date']) ?></td>
                    <td><a href="<?= e(base_url('job_form.php?id=' . $j['id'])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i> قیمت</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php require __DIR__ . '/includes/footer.php'; ?>
    <?php exit; ?>
<?php } ?>
<?php
$overall = overall_financials();

$clients = db()->query('SELECT id, name FROM clients ORDER BY name')->fetchAll();
$clientRows = [];
foreach ($clients as $c) {
    $f = client_financials((int)$c['id']);
    $clientRows[] = ['client' => $c, 'f' => $f];
}
// مرتب‌سازی بر اساس بیشترین مانده حساب
usort($clientRows, fn($a, $b) => $b['f']['remaining'] <=> $a['f']['remaining']);

$recentJobs = db()->query('
    SELECT j.*, c.name AS client_name, s.label AS status_label, s.color AS status_color
    FROM jobs j
    JOIN clients c ON c.id = j.client_id
    JOIN job_statuses s ON s.id = j.status_id
    ORDER BY j.created_at DESC
    LIMIT 8
')->fetchAll();

$dueJobs = upcoming_jobs(7);

$recentActivity = db()->query('
    SELECT a.*, u.full_name AS user_name
    FROM activity_log a
    LEFT JOIN users u ON u.id = a.user_id
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT 8
')->fetchAll();

$pageTitle = 'داشبورد';
require __DIR__ . '/includes/header.php';
?>

<h4 class="mb-4">داشبورد کلی</h4>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="card stat-card p-3 h-100">
            <div class="text-muted small">تعداد کارفرماها</div>
            <div class="fs-3 fw-bold"><?= (int)$overall['client_count'] ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card stat-card p-3 h-100">
            <div class="text-muted small">تعداد کارها</div>
            <div class="fs-3 fw-bold"><?= (int)$overall['job_count'] ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card stat-card p-3 h-100">
            <div class="text-muted small">مجموع دریافتی‌ها</div>
            <div class="fs-4 fw-bold text-success"><?= money_fmt($overall['paid']) ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card stat-card p-3 h-100">
            <div class="text-muted small">مانده کل حساب‌ها</div>
            <div class="fs-4 fw-bold <?= $overall['remaining'] > 0 ? 'text-danger' : 'text-success' ?>">
                <?= money_fmt($overall['remaining']) ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3">مانده حساب به تفکیک کارفرما</h6>
            <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="table-light">
                <tr>
                    <th>کارفرما</th>
                    <th>تعداد کار</th>
                    <th>مجموع قابل محاسبه</th>
                    <th>پرداختی</th>
                    <th>مانده</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$clientRows): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">هنوز کارفرمایی ثبت نشده است.</td></tr>
                <?php endif; ?>
                <?php foreach ($clientRows as $row): $c = $row['client']; $f = $row['f']; ?>
                    <tr>
                        <td><a href="<?= e(base_url('client_view.php?id=' . $c['id'])) ?>"><?= e($c['name']) ?></a></td>
                        <td><?= (int)$f['job_count'] ?></td>
                        <td><?= money($f['billable']) ?></td>
                        <td class="text-success"><?= money($f['paid']) ?></td>
                        <td class="fw-bold <?= $f['remaining'] > 0 ? 'text-danger' : 'text-success' ?>"><?= money($f['remaining']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3">وضعیت کارها</h6>
            <?php foreach ($overall['by_status'] as $s): ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="badge text-bg-<?= e($s['color']) ?>"><?= e($s['label']) ?></span>
                    <span class="small text-muted"><?= (int)$s['job_count'] ?> کار</span>
                    <span class="fw-bold"><?= money($s['total_cost']) ?></span>
                </div>
            <?php endforeach; ?>
            <hr>
            <div class="d-flex justify-content-between">
                <span>مجموع کل هزینه کارها</span>
                <span class="fw-bold"><?= money($overall['total_cost']) ?></span>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3"><i class="bi bi-alarm text-danger"></i> کارهای عقب‌افتاده / نزدیک به موعد (۷ روز آینده)</h6>
            <?php if (!$dueJobs): ?>
                <div class="text-muted small">کاری با موعد نزدیک یا عقب‌افتاده وجود ندارد.</div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($dueJobs as $dj): $overdue = $dj['due_date'] < date('Y-m-d'); ?>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                    <div>
                        <a href="<?= e(base_url('job_form.php?id=' . $dj['id'])) ?>"><?= e($dj['title']) ?></a>
                        <div class="text-muted small"><?= e($dj['client_name']) ?></div>
                    </div>
                    <span class="badge <?= $overdue ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= format_date_fa($dj['due_date']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold mb-0"><i class="bi bi-clock-history"></i> آخرین فعالیت‌ها</h6>
                <a href="<?= e(base_url('activity_log.php')) ?>" class="btn btn-sm btn-outline-primary">مشاهده همه</a>
            </div>
            <?php if (!$recentActivity): ?>
                <div class="text-muted small">فعالیتی ثبت نشده است.</div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($recentActivity as $l): ?>
                <li class="list-group-item px-0 small">
                    <span class="badge text-bg-<?= $l['action'] === 'create' ? 'success' : ($l['action'] === 'delete' ? 'danger' : 'secondary') ?>"><?= e(activity_action_label($l['action'])) ?></span>
                    <?= e(activity_entity_label($l['entity_type'])) ?> «<?= e($l['entity_label']) ?>»
                    <span class="text-muted">توسط <?= e($l['user_name'] ?? 'سیستم') ?> - <?= format_date_fa($l['created_at']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card p-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold mb-0">آخرین کارهای ثبت‌شده</h6>
        <a href="<?= e(base_url('jobs.php')) ?>" class="btn btn-sm btn-outline-primary">مشاهده همه</a>
    </div>
    <div class="table-responsive">
    <table class="table table-sm align-middle">
        <thead class="table-light">
        <tr><th>عنوان کار</th><th>کارفرما</th><th>هزینه</th><th>وضعیت</th><th>تاریخ ثبت</th></tr>
        </thead>
        <tbody>
        <?php if (!$recentJobs): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">کاری ثبت نشده است.</td></tr>
        <?php endif; ?>
        <?php foreach ($recentJobs as $j): ?>
            <tr>
                <td><a href="<?= e(base_url('job_form.php?id=' . $j['id'])) ?>"><?= e($j['title']) ?></a></td>
                <td><?= e($j['client_name']) ?></td>
                <td><?= money($j['cost']) ?></td>
                <td><span class="badge text-bg-<?= e($j['status_color']) ?>"><?= e($j['status_label']) ?></span></td>
                <td><?= format_date_fa($j['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
