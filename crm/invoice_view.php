<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM invoices WHERE id = ?');
$stmt->execute([$id]);
$invoice = $stmt->fetch();
if (!$invoice) {
    flash_set('danger', 'فاکتور یافت نشد.');
    redirect(can_manage() ? 'clients.php' : 'index.php');
}

if (is_client() && (int)current_user()['client_id'] !== (int)$invoice['client_id']) {
    http_response_code(403);
    die('شما اجازه دسترسی به این فاکتور را ندارید.');
}

if (is_staff() && !can_view_prices()) {
    http_response_code(403);
    require __DIR__ . '/403.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'convert_to_invoice' && can_manage() && can_manage_invoices_perm()) {
    csrf_verify();
    if ((int)$invoice['is_quote'] === 1) {
        $newNumber = generate_invoice_number($id, false);
        $upd = db()->prepare('UPDATE invoices SET is_quote = 0, invoice_number = ? WHERE id = ?');
        $upd->execute([$newNumber, $id]);
        log_activity('update', 'invoice', $id, $newNumber, 'تبدیل پیش‌فاکتور به فاکتور رسمی');
        flash_set('success', 'پیش‌فاکتور با موفقیت به فاکتور رسمی تبدیل شد.');
        redirect('invoice_view.php?id=' . $id);
    }
}

$clientStmt = db()->prepare('SELECT * FROM clients WHERE id = ?');
$clientStmt->execute([$invoice['client_id']]);
$client = $clientStmt->fetch();

$itemsStmt = db()->prepare('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id');
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

$instStmt = db()->prepare("
    SELECT i.*, COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_installment_id = i.id), 0) AS paid
    FROM invoice_installments i
    WHERE i.invoice_id = ?
    ORDER BY i.sort_order, i.id
");
$instStmt->execute([$id]);
$installments = $instStmt->fetchAll();

$discountAmount = round((float)$invoice['subtotal'] * (float)$invoice['discount_percent'] / 100);
$afterDiscount = (float)$invoice['subtotal'] - $discountAmount;
$taxAmount = round($afterDiscount * (float)$invoice['tax_percent'] / 100);
$invoiceTotal = (float)$invoice['total'] > 0 ? (float)$invoice['total'] : $afterDiscount + $taxAmount;

$fin = client_financials((int)$invoice['client_id']);

$companyName = get_setting('company_name', APP_NAME ?? '');
$companyAddress = get_setting('company_address', '');
$companyPhone = get_setting('company_phone', '');
$stampPath = get_setting('company_stamp_path', '');

$pageTitle = ($invoice['is_quote'] ? 'پیش‌فاکتور ' : 'فاکتور ') . $invoice['invoice_number'];
require __DIR__ . '/includes/header.php';
?>

<style>
@media print {
    .app-sidebar, .navbar, .no-print, .offcanvas { display: none !important; }
    main { width: 100% !important; }
    body { background: #fff !important; }
}
.invoice-box { max-width: 800px; margin: 0 auto; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <h4 class="mb-0">
        <?= $invoice['is_quote'] ? 'پیش‌فاکتور' : 'فاکتور' ?> <?= e($invoice['invoice_number']) ?>
        <?php if ($invoice['is_quote']): ?><span class="badge text-bg-info align-middle">پیش‌فاکتور / استعلام</span><?php endif; ?>
    </h4>
    <div class="d-flex gap-2">
        <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> چاپ / ذخیره PDF</button>
        <?php if (can_manage() && can_manage_invoices_perm() && $invoice['is_quote']): ?>
        <form method="post" onsubmit="return confirm('این پیش‌فاکتور به فاکتور رسمی تبدیل شود؟ شماره فاکتور جدید صادر خواهد شد.');" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="convert_to_invoice">
            <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-arrow-repeat"></i> تبدیل به فاکتور رسمی</button>
        </form>
        <?php endif; ?>
        <?php if (can_manage()): ?>
        <a href="<?= e(base_url('client_view.php?id=' . $invoice['client_id'])) ?>" class="btn btn-outline-secondary btn-sm">بازگشت</a>
        <?php endif; ?>
    </div>
</div>

<div class="card p-4 invoice-box">
    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
        <div>
            <h5 class="fw-bold mb-1"><?= e($companyName) ?></h5>
            <?php if ($companyAddress): ?><div class="text-muted small"><?= e($companyAddress) ?></div><?php endif; ?>
            <?php if ($companyPhone): ?><div class="text-muted small" dir="ltr"><?= e($companyPhone) ?></div><?php endif; ?>
        </div>
        <div class="text-end">
            <div class="fs-5 fw-bold"><?= $invoice['is_quote'] ? 'پیش‌فاکتور' : 'فاکتور' ?></div>
            <?php if ($invoice['is_quote']): ?><span class="badge text-bg-info">استعلام قیمت - غیر رسمی</span><?php endif; ?>
            <div class="text-muted" dir="ltr"><?= e($invoice['invoice_number']) ?></div>
            <div class="text-muted small">تاریخ صدور: <?= format_date_fa($invoice['issue_date']) ?></div>
        </div>
    </div>

    <div class="mb-4">
        <div class="text-muted small">صورتحساب برای:</div>
        <div class="fw-bold fs-5"><?= e($client['name']) ?></div>
        <?php if ($client['contact_name']): ?><div class="text-muted small"><?= e($client['contact_name']) ?></div><?php endif; ?>
        <?php if (!empty($client['address'])): ?><div class="text-muted small"><?= e($client['address']) ?></div><?php endif; ?>
        <?php if ($client['phone']): ?><div class="text-muted small" dir="ltr"><?= e($client['phone']) ?></div><?php endif; ?>
        <?php if (!empty($client['email'])): ?><div class="text-muted small" dir="ltr"><?= e($client['email']) ?></div><?php endif; ?>
    </div>

    <?php if ($invoice['note']): ?>
        <div class="alert alert-light border small"><?= nl2br(e($invoice['note'])) ?></div>
    <?php endif; ?>

    <table class="table table-bordered">
        <thead class="table-light">
        <tr><th>#</th><th>شرح</th><th class="text-end">مبلغ (<?= e(currency_label()) ?>)</th></tr>
        </thead>
        <tbody>
        <?php foreach ($items as $i => $it): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= e($it['title']) ?></td>
                <td class="text-end"><?= money($it['amount']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr>
            <td colspan="2" class="text-end">جمع اقلام</td>
            <td class="text-end"><?= money($invoice['subtotal']) ?></td>
        </tr>
        <?php if ((float)$invoice['discount_percent'] > 0): ?>
        <tr>
            <td colspan="2" class="text-end">تخفیف (<?= fa_digits(rtrim(rtrim(number_format((float)$invoice['discount_percent'], 2), '0'), '.')) ?>٪)</td>
            <td class="text-end text-danger">-<?= money($discountAmount) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ((float)$invoice['tax_percent'] > 0): ?>
        <tr>
            <td colspan="2" class="text-end">مالیات (<?= fa_digits(rtrim(rtrim(number_format((float)$invoice['tax_percent'], 2), '0'), '.')) ?>٪)</td>
            <td class="text-end"><?= money($taxAmount) ?></td>
        </tr>
        <?php endif; ?>
        <tr class="fw-bold">
            <td colspan="2" class="text-end">مبلغ نهایی قابل پرداخت</td>
            <td class="text-end"><?= money($invoiceTotal) ?></td>
        </tr>
        </tfoot>
    </table>

    <div class="text-muted small mb-3">مبلغ به حروف: <?= e(amount_in_words_fmt($invoiceTotal)) ?></div>

    <?php if ($installments): ?>
    <div class="mb-4">
        <h6 class="fw-bold mb-2">جدول اقساط / مراحل پرداخت</h6>
        <div class="table-responsive">
        <table class="table table-sm table-bordered">
            <thead class="table-light">
            <tr><th>عنوان</th><th>درصد</th><th class="text-end">مبلغ</th><th>سررسید</th><th class="text-end">پرداخت‌شده</th><th class="text-end">مانده</th><th class="no-print"></th></tr>
            </thead>
            <tbody>
            <?php foreach ($installments as $inst):
                $instRemaining = (float)$inst['amount'] - (float)$inst['paid'];
            ?>
                <tr>
                    <td><?= e($inst['label']) ?></td>
                    <td><?= $inst['percent'] !== null ? fa_digits(rtrim(rtrim(number_format((float)$inst['percent'], 2), '0'), '.')) . '٪' : '—' ?></td>
                    <td class="text-end"><?= money($inst['amount']) ?></td>
                    <td><?= $inst['due_date'] ? format_date_fa($inst['due_date']) : '—' ?></td>
                    <td class="text-end text-success"><?= money($inst['paid']) ?></td>
                    <td class="text-end <?= $instRemaining > 0 ? 'text-danger' : 'text-success' ?>"><?= money(max(0, $instRemaining)) ?></td>
                    <td class="no-print">
                        <?php if (can_manage() && can_manage_payments_perm() && $instRemaining > 0): ?>
                        <a href="<?= e(base_url('payment_form.php?client_id=' . $invoice['client_id'] . '&invoice_installment_id=' . $inst['id'])) ?>" class="btn btn-sm btn-outline-primary">ثبت پرداخت</a>
                        <?php elseif ($instRemaining <= 0): ?>
                        <span class="badge text-bg-success">تسویه شده</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-2 mt-3">
        <div class="col-md-4">
            <div class="border rounded p-2 text-center">
                <div class="text-muted small">مجموع پرداختی تا امروز</div>
                <div class="fw-bold text-success"><?= money($fin['paid']) ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="border rounded p-2 text-center">
                <div class="text-muted small">مبلغ قابل محاسبه کل حساب</div>
                <div class="fw-bold"><?= money($fin['billable']) ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="border rounded p-2 text-center">
                <div class="text-muted small">مانده کل حساب</div>
                <div class="fw-bold <?= $fin['remaining'] > 0 ? 'text-danger' : 'text-success' ?>"><?= money($fin['remaining']) ?></div>
            </div>
        </div>
    </div>

    <?php if ($stampPath): ?>
    <div class="text-end mt-4">
        <img src="<?= e(base_url($stampPath)) ?>" alt="مهر و امضا" style="max-height:110px;">
        <div class="text-muted small mt-1">مهر و امضا</div>
    </div>
    <?php endif; ?>

    <div class="text-muted small text-center mt-4">این <?= $invoice['is_quote'] ? 'پیش‌فاکتور' : 'فاکتور' ?> به‌صورت خودکار از <?= e($companyName) ?> صادر شده است.</div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
