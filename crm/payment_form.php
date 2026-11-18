<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');
require_permission('perm_view_prices');
require_permission('perm_manage_payments');

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$installmentId = isset($_GET['invoice_installment_id']) ? (int)$_GET['invoice_installment_id'] : (isset($_POST['invoice_installment_id']) ? (int)$_POST['invoice_installment_id'] : 0);
$payment = ['id' => 0, 'client_id' => (int)($_GET['client_id'] ?? 0), 'job_id' => '', 'amount' => '', 'payment_date' => '', 'date_label' => '', 'note' => '', 'invoice_installment_id' => $installmentId ?: null];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM payments WHERE id = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) {
        flash_set('danger', 'پرداخت یافت نشد.');
        redirect('payments.php');
    }
    $payment = $found;
    if (!$installmentId && $_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($found['invoice_installment_id'])) {
        $installmentId = (int)$found['invoice_installment_id'];
    }
}

$installmentInfo = null;
if ($installmentId) {
    $instStmt = db()->prepare('
        SELECT i.*, inv.invoice_number, inv.client_id AS invoice_client_id,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_installment_id = i.id), 0) AS already_paid
        FROM invoice_installments i JOIN invoices inv ON inv.id = i.invoice_id
        WHERE i.id = ?
    ');
    $instStmt->execute([$installmentId]);
    $installmentInfo = $instStmt->fetch();
    if ($installmentInfo && !$id && !$payment['amount']) {
        $remaining = (float)$installmentInfo['amount'] - (float)$installmentInfo['already_paid'];
        $payment['amount'] = $remaining > 0 ? (string)$remaining : '';
        if (!$payment['client_id']) $payment['client_id'] = (int)$installmentInfo['invoice_client_id'];
    }
}

$clients = db()->query('SELECT id, name FROM clients ORDER BY name')->fetchAll();
$jobs = db()->query('SELECT id, client_id, title FROM jobs ORDER BY title')->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $payment['client_id'] = (int)($_POST['client_id'] ?? 0);
    $payment['job_id'] = trim($_POST['job_id'] ?? '');
    $payment['amount'] = (string)($_POST['amount'] ?? '0');
    $payment['payment_date'] = trim($_POST['payment_date'] ?? '');
    $payment['date_label'] = trim($_POST['date_label'] ?? '');
    $payment['note'] = trim($_POST['note'] ?? '');
    $payment['invoice_installment_id'] = $installmentId ?: null;

    $amountNumeric = (float)str_replace([',', ' '], '', $payment['amount']);
    $dateValue = $payment['payment_date'] !== '' ? $payment['payment_date'] : null;
    $jobIdValue = $payment['job_id'] !== '' ? (int)$payment['job_id'] : null;

    if ($payment['client_id'] <= 0) $errors[] = 'انتخاب کارفرما الزامی است.';
    if ($amountNumeric <= 0) $errors[] = 'مبلغ پرداخت باید بزرگتر از صفر باشد.';
    if ($dateValue && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) $errors[] = 'فرمت تاریخ نامعتبر است.';

    // اگر کاری انتخاب شده، باید متعلق به همان کارفرما باشد
    if (!$errors && $jobIdValue) {
        $chk = db()->prepare('SELECT client_id FROM jobs WHERE id = ?');
        $chk->execute([$jobIdValue]);
        $jobClientId = (int)$chk->fetchColumn();
        if ($jobClientId !== $payment['client_id']) {
            $errors[] = 'کار انتخاب‌شده متعلق به این کارفرما نیست.';
        }
    }

    if (!$errors) {
        if ($id) {
            $stmt = db()->prepare('UPDATE payments SET client_id=?, job_id=?, amount=?, payment_date=?, date_label=?, note=?, invoice_installment_id=? WHERE id=?');
            $stmt->execute([$payment['client_id'], $jobIdValue, $amountNumeric, $dateValue, $payment['date_label'] ?: null, $payment['note'], $payment['invoice_installment_id'], $id]);
            log_activity('update', 'payment', $id, money_fmt($amountNumeric));
            flash_set('success', 'پرداخت به‌روزرسانی شد.');
        } else {
            $stmt = db()->prepare('INSERT INTO payments (client_id, job_id, amount, payment_date, date_label, note, invoice_installment_id, created_by) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$payment['client_id'], $jobIdValue, $amountNumeric, $dateValue, $payment['date_label'] ?: null, $payment['note'], $payment['invoice_installment_id'], current_user()['id']]);
            $id = (int)db()->lastInsertId();
            log_activity('create', 'payment', $id, money_fmt($amountNumeric));
            flash_set('success', 'پرداخت جدید ثبت شد.');
        }
        redirect('client_view.php?id=' . $payment['client_id']);
    }
}

$pageTitle = $id ? 'ویرایش پرداخت' : 'ثبت پرداخت جدید';
require __DIR__ . '/includes/header.php';
?>

<h4 class="mb-3"><?= e($pageTitle) ?></h4>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger py-2"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if ($installmentInfo): ?>
<div class="alert alert-info py-2" style="max-width:640px">
    این پرداخت به قسط «<?= e($installmentInfo['label']) ?>» از فاکتور <span dir="ltr"><?= e($installmentInfo['invoice_number']) ?></span> تخصیص داده می‌شود.
    مبلغ این قسط: <?= money($installmentInfo['amount']) ?> — پرداخت‌شده تاکنون: <?= money($installmentInfo['already_paid']) ?>
</div>
<?php endif; ?>

<div class="card p-4" style="max-width:640px">
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="invoice_installment_id" value="<?= (int)$installmentId ?>">

    <div class="mb-3">
        <label class="form-label">کارفرما <span class="text-danger">*</span></label>
        <select name="client_id" id="clientSelect" class="form-select" required>
            <option value="">انتخاب کنید...</option>
            <?php foreach ($clients as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (int)$payment['client_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label">تخصیص به کار مشخص (اختیاری)</label>
        <select name="job_id" id="jobSelect" class="form-select">
            <option value="">— بدون تخصیص به کار خاص (پرداخت کلی کارفرما) —</option>
            <?php foreach ($jobs as $j): ?>
                <option value="<?= (int)$j['id'] ?>" data-client="<?= (int)$j['client_id'] ?>" <?= (string)$payment['job_id'] === (string)$j['id'] ? 'selected' : '' ?>><?= e($j['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="form-text">پیش‌فرض همان روش قبلی است (پرداخت کلی به کارفرما)؛ در صورت نیاز می‌توانید این پرداخت را به یک کار خاص هم مرتبط کنید.</div>
    </div>

    <div class="mb-3">
        <label class="form-label">مبلغ (<?= e(currency_label()) ?>) <span class="text-danger">*</span></label>
        <input type="text" inputmode="numeric" name="amount" class="form-control" required value="<?= e((string)$payment['amount']) ?>">
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">تاریخ (اختیاری)</label>
            <div class="jdp-wrap">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                    <input type="text" class="form-control jdp-input" placeholder="مثلا ۱۴۰۵/۰۵/۲۶" readonly
                           value="<?= e(jalali_input_value((string)$payment['payment_date'])) ?>"
                           data-jalali-picker data-hidden-target="payment_date_hidden" data-clearable="1">
                </div>
                <input type="hidden" name="payment_date" id="payment_date_hidden" value="<?= e((string)$payment['payment_date']) ?>">
            </div>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">برچسب تاریخ / توضیح تاریخ (اختیاری)</label>
            <input type="text" name="date_label" class="form-control" placeholder="مثلا: ۳ مرداد ۱۴۰۵" value="<?= e((string)$payment['date_label']) ?>">
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label">توضیح</label>
        <input type="text" name="note" class="form-control" value="<?= e((string)$payment['note']) ?>">
    </div>

    <button type="submit" class="btn btn-primary">ذخیره</button>
    <a href="<?= e(base_url('payments.php')) ?>" class="btn btn-outline-secondary">انصراف</a>
</form>
</div>

<script>
(function () {
    var clientSelect = document.getElementById('clientSelect');
    var jobSelect = document.getElementById('jobSelect');
    var allOptions = Array.prototype.slice.call(jobSelect.options);

    function filterJobs() {
        var clientId = clientSelect.value;
        var currentValue = jobSelect.value;
        var valueStillValid = false;

        jobSelect.innerHTML = '';
        allOptions.forEach(function (opt) {
            var optClient = opt.getAttribute('data-client');
            if (!optClient || optClient === clientId) {
                jobSelect.appendChild(opt);
                if (opt.value === currentValue) valueStillValid = true;
            }
        });
        if (!valueStillValid) jobSelect.value = '';
    }

    clientSelect.addEventListener('change', filterJobs);
    filterJobs();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
