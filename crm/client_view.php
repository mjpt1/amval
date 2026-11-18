<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

if (is_client()) {
    $clientId = (int)current_user()['client_id'];
    if (!$clientId) {
        die('حساب کاربری شما به هیچ کارفرمایی متصل نیست. لطفا با مدیر سیستم تماس بگیرید.');
    }
} else {
    $clientId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
}

if (!$clientId) {
    redirect('clients.php');
}

if (is_restricted_associate()) {
    flash_set('danger', 'شما به اطلاعات مالی کارفرماها دسترسی ندارید.');
    redirect('jobs.php');
}

if (can_manage() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'create_public_link') {
        ensure_client_public_token($clientId);
        flash_set('success', 'لینک عمومی ساخته شد.');
    } elseif ($action === 'revoke_public_link') {
        revoke_client_public_token($clientId);
        flash_set('success', 'لینک عمومی غیرفعال شد.');
    }
    redirect('client_view.php?id=' . $clientId);
}

$stmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
$client = $stmt->fetch();
if (!$client) {
    flash_set('danger', 'کارفرما یافت نشد.');
    redirect(can_manage() ? 'clients.php' : 'index.php');
}

$fin = client_financials($clientId);

$jobs = db()->prepare('
    SELECT j.*, s.label AS status_label, s.color AS status_color, s.billing_percent,
           (SELECT COUNT(*) FROM job_attachments a WHERE a.job_id = j.id) AS attachment_count,
           (SELECT COUNT(*) FROM job_subtasks st WHERE st.job_id = j.id) AS subtask_count,
           (SELECT COUNT(*) FROM job_subtasks st WHERE st.job_id = j.id AND st.is_done = 1) AS subtask_done_count
    FROM jobs j JOIN job_statuses s ON s.id = j.status_id
    WHERE j.client_id = ?
    ORDER BY j.created_at DESC
');
$jobs->execute([$clientId]);
$jobs = $jobs->fetchAll();

$payments = db()->prepare('
    SELECT p.*, j.title AS job_title
    FROM payments p LEFT JOIN jobs j ON j.id = p.job_id
    WHERE p.client_id = ?
    ORDER BY COALESCE(p.payment_date, p.created_at) DESC, p.id DESC
');
$payments->execute([$clientId]);
$payments = $payments->fetchAll();

$invoices = [];
if (can_manage() && can_manage_invoices_perm()) {
    $invStmt = db()->prepare('SELECT * FROM invoices WHERE client_id = ? ORDER BY issue_date DESC, id DESC');
    $invStmt->execute([$clientId]);
    $invoices = $invStmt->fetchAll();
}

$pageTitle = $client['name'];
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-1"><?= e($client['name']) ?></h4>
        <?php if ($client['contact_name'] || $client['phone']): ?>
        <div class="text-muted small">
            <?= e($client['contact_name']) ?><?= $client['phone'] ? ' - ' . e($client['phone']) : '' ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($client['email'])): ?>
        <div class="text-muted small" dir="ltr"><?= e($client['email']) ?></div>
        <?php endif; ?>
        <?php if (!empty($client['address'])): ?>
        <div class="text-muted small"><i class="bi bi-geo-alt"></i> <?= e($client['address']) ?></div>
        <?php endif; ?>
    </div>
    <?php if (can_manage()): ?>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= e(base_url('job_form.php?client_id=' . $clientId)) ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> کار جدید</a>
        <?php if (can_manage_payments_perm()): ?>
        <a href="<?= e(base_url('payment_form.php?client_id=' . $clientId)) ?>" class="btn btn-success btn-sm"><i class="bi bi-plus-lg"></i> ثبت پرداخت</a>
        <?php endif; ?>
        <?php if (can_manage_invoices_perm()): ?>
        <a href="<?= e(base_url('invoice_form.php?client_id=' . $clientId)) ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-receipt"></i> ساخت فاکتور</a>
        <?php endif; ?>
        <?php if (can_manage_clients_perm()): ?>
        <a href="<?= e(base_url('client_form.php?id=' . $clientId)) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i> ویرایش</a>
        <?php endif; ?>
    </div>
    <?php elseif (is_client() && client_can_create_job()): ?>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= e(base_url('client_job_form.php')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> ثبت کار جدید</a>
    </div>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="card stat-card p-3 h-100">
            <div class="text-muted small">مجموع کل کارها</div>
            <div class="fs-5 fw-bold"><?= money($fin['total_cost']) ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card stat-card p-3 h-100">
            <div class="text-muted small">مبلغ قابل محاسبه</div>
            <div class="fs-5 fw-bold"><?= money($fin['billable']) ?></div>
            <div class="text-muted" style="font-size:.7rem">با احتساب درصد پیشرفت هر وضعیت</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card stat-card p-3 h-100">
            <div class="text-muted small">مجموع پرداختی</div>
            <div class="fs-5 fw-bold text-success"><?= money($fin['paid']) ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card stat-card p-3 h-100">
            <div class="text-muted small">مانده حساب</div>
            <div class="fs-5 fw-bold <?= $fin['remaining'] > 0 ? 'text-danger' : 'text-success' ?>"><?= money($fin['remaining']) ?></div>
        </div>
    </div>
</div>

<div class="card p-3 mb-4">
    <h6 class="fw-bold mb-3">تفکیک بر اساس وضعیت</h6>
    <div class="row g-2">
    <?php foreach ($fin['by_status'] as $s): ?>
        <div class="col-md-4">
            <div class="border rounded p-2 d-flex justify-content-between align-items-center">
                <span class="badge text-bg-<?= e($s['color']) ?>"><?= e($s['label']) ?> (<?= (int)$s['billing_percent'] ?>%)</span>
                <span class="small text-muted"><?= (int)$s['job_count'] ?> کار</span>
                <span class="fw-bold"><?= money($s['total_cost']) ?></span>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</div>

<?php if (can_manage()): ?>
<div class="card p-3 mb-4">
    <h6 class="fw-bold mb-2"><i class="bi bi-link-45deg"></i> لینک عمومی برای کارفرما</h6>
    <p class="text-muted small mb-2">با این لینک، کارفرما بدون نیاز به نام کاربری/رمز عبور می‌تواند فقط وضعیت کارها و مانده حساب خودش را ببیند.</p>
    <?php if ($client['public_token']): ?>
        <div class="input-group mb-2" style="max-width:520px">
            <input type="text" class="form-control form-control-sm" readonly dir="ltr" value="<?= e(base_url('public_client.php?t=' . $client['public_token'])) ?>" id="publicLinkInput">
            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('publicLinkInput').value)"><i class="bi bi-clipboard"></i> کپی</button>
        </div>
        <form method="post" onsubmit="return confirm('لینک فعلی غیرفعال شود؟ (لینک قبلی دیگر کار نخواهد کرد)');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="revoke_public_link">
            <input type="hidden" name="id" value="<?= (int)$clientId ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger">غیرفعال‌سازی لینک</button>
        </form>
    <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_public_link">
            <input type="hidden" name="id" value="<?= (int)$clientId ?>">
            <button type="submit" class="btn btn-sm btn-primary">ساخت لینک عمومی</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card p-3 mb-4">
    <h6 class="fw-bold mb-3">لیست کارها</h6>
    <div class="table-responsive">
    <table class="table table-sm align-middle">
        <thead class="table-light">
        <tr><th>عنوان کار</th><th>هزینه</th><th>وضعیت</th><th>موعد تحویل</th><th>تاریخ ثبت</th><th></th></tr>
        </thead>
        <tbody>
        <?php if (!$jobs): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">کاری برای این کارفرما ثبت نشده است.</td></tr>
        <?php endif; ?>
        <?php foreach ($jobs as $j): $isOverdue = $j['due_date'] && $j['due_date'] < date('Y-m-d') && (float)$j['billing_percent'] < 100; ?>
            <tr>
                <td>
                    <?= e($j['title']) ?>
                    <?php if ($j['attachment_count'] > 0): ?>
                        <i class="bi bi-paperclip text-muted" title="<?= (int)$j['attachment_count'] ?> پیوست"></i>
                    <?php endif; ?>
                    <?php if ($j['subtask_count'] > 0): ?>
                        <span class="badge text-bg-light border text-muted" title="زیرکارها">
                            <i class="bi bi-check2-square"></i> <?= fa_digits((string)$j['subtask_done_count']) ?>/<?= fa_digits((string)$j['subtask_count']) ?>
                        </span>
                    <?php endif; ?>
                    <?= $j['description'] ? '<div class="text-muted small">' . e($j['description']) . '</div>' : '' ?>
                </td>
                <td><?= money($j['cost']) ?></td>
                <td><span class="badge text-bg-<?= e($j['status_color']) ?>"><?= e($j['status_label']) ?></span></td>
                <td class="<?= $isOverdue ? 'text-danger fw-bold' : '' ?>"><?= format_date_fa($j['due_date']) ?></td>
                <td><?= format_date_fa($j['created_at']) ?></td>
                <td class="text-nowrap">
                    <a href="<?= e(base_url('job_view.php?id=' . $j['id'])) ?>" class="btn btn-sm btn-outline-primary" title="گفتگو"><i class="bi bi-chat-dots"></i></a>
                    <?php if (can_manage()): ?>
                    <a href="<?= e(base_url('job_form.php?id=' . $j['id'])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                    <?php elseif (is_client() && (client_can_edit_job() || client_can_suggest_price())): ?>
                    <a href="<?= e(base_url('client_job_form.php?id=' . $j['id'])) ?>" class="btn btn-sm btn-outline-secondary" title="ویرایش"><i class="bi bi-pencil"></i></a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card p-3 mb-4">
    <h6 class="fw-bold mb-3">لیست پرداخت‌ها</h6>
    <div class="table-responsive">
    <table class="table table-sm align-middle">
        <thead class="table-light">
        <tr><th>مبلغ</th><th>کار مرتبط</th><th>تاریخ</th><th>توضیح</th><?php if (can_manage() && can_manage_payments_perm()): ?><th></th><?php endif; ?></tr>
        </thead>
        <tbody>
        <?php if (!$payments): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">پرداختی ثبت نشده است.</td></tr>
        <?php endif; ?>
        <?php foreach ($payments as $p): ?>
            <tr>
                <td class="text-success fw-bold"><?= money($p['amount']) ?></td>
                <td class="text-muted small"><?= $p['job_title'] ? e($p['job_title']) : '—' ?></td>
                <td><?= $p['date_label'] ? e($p['date_label']) : format_date_fa($p['payment_date']) ?></td>
                <td class="text-muted small"><?= e($p['note'] ?: '-') ?></td>
                <?php if (can_manage() && can_manage_payments_perm()): ?>
                <td class="text-nowrap">
                    <a href="<?= e(base_url('payment_form.php?id=' . $p['id'])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if (can_manage() && can_manage_invoices_perm()): ?>
<div class="card p-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold mb-0">فاکتورهای صادرشده</h6>
        <a href="<?= e(base_url('invoice_form.php?client_id=' . $clientId)) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg"></i> فاکتور جدید</a>
    </div>
    <div class="table-responsive">
    <table class="table table-sm align-middle">
        <thead class="table-light">
        <tr><th>شماره فاکتور</th><th>تاریخ صدور</th><th>مبلغ</th><th></th></tr>
        </thead>
        <tbody>
        <?php if (!$invoices): ?>
            <tr><td colspan="4" class="text-center text-muted py-4">فاکتوری صادر نشده است.</td></tr>
        <?php endif; ?>
        <?php foreach ($invoices as $inv): ?>
            <tr>
                <td dir="ltr" class="text-muted">
                    <?= e($inv['invoice_number']) ?>
                    <?php if ($inv['is_quote']): ?><span class="badge text-bg-info">پیش‌فاکتور</span><?php endif; ?>
                </td>
                <td><?= format_date_fa($inv['issue_date']) ?></td>
                <td class="fw-bold"><?= money($inv['total'] > 0 ? $inv['total'] : $inv['subtotal']) ?></td>
                <td><a href="<?= e(base_url('invoice_view.php?id=' . $inv['id'])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i> مشاهده</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
