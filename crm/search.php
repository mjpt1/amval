<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');
require_permission('perm_view_prices');

$q = trim($_GET['q'] ?? '');

$clients = [];
$jobs = [];
$payments = [];
$invoices = [];

if (mb_strlen($q) >= 2) {
    $like = "%$q%";

    $stmt = db()->prepare('SELECT * FROM clients WHERE name LIKE ? OR contact_name LIKE ? OR phone LIKE ? OR email LIKE ? OR address LIKE ? ORDER BY name LIMIT 30');
    $stmt->execute([$like, $like, $like, $like, $like]);
    $clients = $stmt->fetchAll();

    $stmt = db()->prepare('
        SELECT j.*, c.name AS client_name
        FROM jobs j JOIN clients c ON c.id = j.client_id
        WHERE j.title LIKE ? OR j.description LIKE ?
        ORDER BY j.created_at DESC LIMIT 30
    ');
    $stmt->execute([$like, $like]);
    $jobs = $stmt->fetchAll();

    $stmt = db()->prepare('
        SELECT p.*, c.name AS client_name
        FROM payments p JOIN clients c ON c.id = p.client_id
        WHERE p.note LIKE ? OR p.date_label LIKE ?
        ORDER BY COALESCE(p.payment_date, p.created_at) DESC LIMIT 30
    ');
    $stmt->execute([$like, $like]);
    $payments = $stmt->fetchAll();

    $stmt = db()->prepare('
        SELECT i.*, c.name AS client_name
        FROM invoices i JOIN clients c ON c.id = i.client_id
        WHERE i.invoice_number LIKE ? OR i.note LIKE ? OR c.name LIKE ?
        ORDER BY i.issue_date DESC LIMIT 30
    ');
    $stmt->execute([$like, $like, $like]);
    $invoices = $stmt->fetchAll();
}

$totalResults = count($clients) + count($jobs) + count($payments) + count($invoices);

$pageTitle = 'جست‌وجوی سراسری';
require __DIR__ . '/includes/header.php';
?>

<h4 class="mb-3">جست‌وجوی سراسری</h4>

<form method="get" class="mb-4">
    <div class="input-group" style="max-width:480px">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" name="q" class="form-control" placeholder="نام کارفرما، عنوان کار، شماره فاکتور، توضیح پرداخت..." value="<?= e($q) ?>" autofocus>
        <button class="btn btn-primary" type="submit">جست‌وجو</button>
    </div>
</form>

<?php if ($q !== '' && mb_strlen($q) < 2): ?>
    <div class="alert alert-warning">لطفا حداقل ۲ حرف وارد کنید.</div>
<?php elseif ($q !== ''): ?>
    <div class="text-muted small mb-3">
        <?= $totalResults > 0 ? fa_digits((string)$totalResults) . ' نتیجه برای «' . e($q) . '» یافت شد.' : 'نتیجه‌ای برای «' . e($q) . '» یافت نشد.' ?>
    </div>

    <?php if ($clients): ?>
    <div class="card p-3 mb-3">
        <h6 class="fw-bold mb-3"><i class="bi bi-people"></i> کارفرماها (<?= fa_digits((string)count($clients)) ?>)</h6>
        <div class="list-group list-group-flush">
        <?php foreach ($clients as $c): ?>
            <a href="<?= e(base_url('client_view.php?id=' . $c['id'])) ?>" class="list-group-item list-group-item-action">
                <div class="fw-bold"><?= e($c['name']) ?></div>
                <div class="text-muted small"><?= e($c['contact_name'] ?: '') ?><?= $c['phone'] ? ' - ' . e($c['phone']) : '' ?><?= !empty($c['email']) ? ' - ' . e($c['email']) : '' ?></div>
            </a>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($jobs): ?>
    <div class="card p-3 mb-3">
        <h6 class="fw-bold mb-3"><i class="bi bi-list-task"></i> کارها (<?= fa_digits((string)count($jobs)) ?>)</h6>
        <div class="list-group list-group-flush">
        <?php foreach ($jobs as $j): ?>
            <a href="<?= e(base_url('job_form.php?id=' . $j['id'])) ?>" class="list-group-item list-group-item-action">
                <div class="fw-bold"><?= e($j['title']) ?></div>
                <div class="text-muted small">کارفرما: <?= e($j['client_name']) ?> - <?= money_fmt($j['cost']) ?></div>
            </a>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($invoices): ?>
    <div class="card p-3 mb-3">
        <h6 class="fw-bold mb-3"><i class="bi bi-receipt"></i> فاکتورها (<?= fa_digits((string)count($invoices)) ?>)</h6>
        <div class="list-group list-group-flush">
        <?php foreach ($invoices as $inv): ?>
            <a href="<?= e(base_url('invoice_view.php?id=' . $inv['id'])) ?>" class="list-group-item list-group-item-action">
                <div class="fw-bold" dir="ltr"><?= e($inv['invoice_number']) ?><?= $inv['is_quote'] ? ' (پیش‌فاکتور)' : '' ?></div>
                <div class="text-muted small">کارفرما: <?= e($inv['client_name']) ?> - <?= money_fmt($inv['total'] > 0 ? $inv['total'] : $inv['subtotal']) ?></div>
            </a>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($payments): ?>
    <div class="card p-3 mb-3">
        <h6 class="fw-bold mb-3"><i class="bi bi-cash-coin"></i> پرداخت‌ها (<?= fa_digits((string)count($payments)) ?>)</h6>
        <div class="list-group list-group-flush">
        <?php foreach ($payments as $p): ?>
            <a href="<?= e(base_url('client_view.php?id=' . $p['client_id'])) ?>" class="list-group-item list-group-item-action">
                <div class="fw-bold"><?= money_fmt($p['amount']) ?> — <?= e($p['client_name']) ?></div>
                <div class="text-muted small"><?= e($p['note'] ?: $p['date_label'] ?: '') ?></div>
            </a>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
