<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');
require_permission('perm_view_prices');
require_permission('perm_manage_invoices');

$clientId = (int)($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
$clients = db()->query('SELECT id, name FROM clients ORDER BY name')->fetchAll();

$errors = [];

$defaultTaxPercent = (float)get_setting('default_tax_percent', '0');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $issueDate = trim($_POST['issue_date'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $selectedJobs = $_POST['job_ids'] ?? [];
    $extraTitles = $_POST['extra_title'] ?? [];
    $extraAmounts = $_POST['extra_amount'] ?? [];
    $isQuote = isset($_POST['is_quote']) ? 1 : 0;
    $discountPercent = (float)str_replace([',', ' '], '', $_POST['discount_percent'] ?? '0');
    $taxPercent = (float)str_replace([',', ' '], '', $_POST['tax_percent'] ?? '0');
    $instLabels = $_POST['inst_label'] ?? [];
    $instPercents = $_POST['inst_percent'] ?? [];
    $instDueDates = $_POST['inst_due_date'] ?? [];

    if ($clientId <= 0) $errors[] = 'انتخاب کارفرما الزامی است.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) $errors[] = 'تاریخ صدور نامعتبر است.';
    if ($discountPercent < 0 || $discountPercent > 100) $errors[] = 'درصد تخفیف باید بین ۰ تا ۱۰۰ باشد.';
    if ($taxPercent < 0 || $taxPercent > 100) $errors[] = 'درصد مالیات باید بین ۰ تا ۱۰۰ باشد.';

    $items = [];

    if ($clientId > 0 && !empty($selectedJobs)) {
        $in = implode(',', array_fill(0, count($selectedJobs), '?'));
        $stmt = db()->prepare("SELECT id, title, cost FROM jobs WHERE client_id = ? AND id IN ($in)");
        $stmt->execute(array_merge([$clientId], array_map('intval', $selectedJobs)));
        foreach ($stmt->fetchAll() as $j) {
            $items[] = ['job_id' => (int)$j['id'], 'title' => $j['title'], 'amount' => (float)$j['cost']];
        }
    }

    foreach ($extraTitles as $i => $title) {
        $title = trim($title);
        $amount = (float)str_replace([',', ' '], '', $extraAmounts[$i] ?? '0');
        if ($title !== '' && $amount > 0) {
            $items[] = ['job_id' => null, 'title' => $title, 'amount' => $amount];
        }
    }

    if (!$items) $errors[] = 'حداقل یک ردیف (کار یا مورد آزاد) باید انتخاب/وارد شود.';

    // ردیف‌های اقساط معتبر
    $installments = [];
    foreach ($instLabels as $i => $label) {
        $label = trim($label);
        $percent = trim((string)($instPercents[$i] ?? ''));
        $due = trim($instDueDates[$i] ?? '');
        if ($label === '' && $percent === '') continue;
        if ($label === '') $errors[] = 'عنوان همه ردیف‌های اقساط باید وارد شود.';
        if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $errors[] = 'تاریخ سررسید قسط نامعتبر است.';
        $installments[] = ['label' => $label ?: 'قسط', 'percent' => $percent !== '' ? $percent : null, 'due_date' => $due ?: null];
    }
    if ($installments) {
        $sumPercent = array_sum(array_map(fn($i) => (float)($i['percent'] ?? 0), $installments));
        if (abs($sumPercent - 100) > 0.5) $errors[] = 'مجموع درصد اقساط باید ۱۰۰ باشد (فعلا ' . fa_digits((string)$sumPercent) . '٪ است).';
    }

    if (!$errors) {
        $subtotal = array_sum(array_column($items, 'amount'));
        $calc = calc_invoice_total($subtotal, $discountPercent, $taxPercent);
        $total = $calc['total'];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO invoices (client_id, issue_date, note, subtotal, is_quote, discount_percent, tax_percent, total, created_by) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$clientId, $issueDate, $note ?: null, $subtotal, $isQuote, $discountPercent, $taxPercent, $total, current_user()['id']]);
            $invoiceId = (int)$pdo->lastInsertId();

            $invoiceNumber = generate_invoice_number($invoiceId, (bool)$isQuote);
            $upd = $pdo->prepare('UPDATE invoices SET invoice_number = ? WHERE id = ?');
            $upd->execute([$invoiceNumber, $invoiceId]);

            $itemStmt = $pdo->prepare('INSERT INTO invoice_items (invoice_id, job_id, title, amount) VALUES (?,?,?,?)');
            foreach ($items as $it) {
                $itemStmt->execute([$invoiceId, $it['job_id'], $it['title'], $it['amount']]);
            }

            if ($installments) {
                $splitted = split_installments($total, $installments);
                $instStmt = $pdo->prepare('INSERT INTO invoice_installments (invoice_id, label, percent, amount, due_date, sort_order) VALUES (?,?,?,?,?,?)');
                foreach ($splitted as $idx => $inst) {
                    $instStmt->execute([$invoiceId, $inst['label'], $inst['percent'], $inst['amount'], $inst['due_date'], $idx]);
                }
            }

            $pdo->commit();
            log_activity('create', $isQuote ? 'quote' : 'invoice', $invoiceId, $invoiceNumber);
            flash_set('success', ($isQuote ? 'پیش‌فاکتور' : 'فاکتور') . ' با موفقیت صادر شد.');
            redirect('invoice_view.php?id=' . $invoiceId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'خطا در ثبت فاکتور. لطفا دوباره تلاش کنید.';
        }
    }
}

$jobs = [];
if ($clientId > 0) {
    $stmt = db()->prepare("
        SELECT j.*, s.label AS status_label, s.color AS status_color
        FROM jobs j JOIN job_statuses s ON s.id = j.status_id
        WHERE j.client_id = ?
        ORDER BY j.created_at DESC
    ");
    $stmt->execute([$clientId]);
    $jobs = $stmt->fetchAll();
}

$pageTitle = 'فاکتور جدید';
require __DIR__ . '/includes/header.php';
?>

<h4 class="mb-3">صدور فاکتور جدید</h4>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger py-2"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="card p-3 mb-3" style="max-width:300px">
<form method="get">
    <label class="form-label">کارفرما</label>
    <select name="client_id" class="form-select" onchange="this.form.submit()">
        <option value="0">انتخاب کنید...</option>
        <?php foreach ($clients as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $clientId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
    </select>
</form>
</div>

<?php if ($clientId > 0): ?>
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">

    <div class="card p-3 mb-3" style="max-width:800px">
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">تاریخ صدور</label>
                <div class="jdp-wrap">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                        <input type="text" class="form-control jdp-input" placeholder="۱۴۰۵/۰۵/۲۶" readonly required
                               value="<?= e(jalali_input_value(date('Y-m-d'))) ?>"
                               data-jalali-picker data-hidden-target="issue_date_hidden">
                    </div>
                    <input type="hidden" name="issue_date" id="issue_date_hidden" value="<?= e(date('Y-m-d')) ?>">
                </div>
            </div>
            <div class="col-md-6 mb-3 d-flex align-items-end">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" name="is_quote" id="isQuoteCheck" value="1">
                    <label class="form-check-label" for="isQuoteCheck">این یک <strong>پیش‌فاکتور</strong> (استعلام قیمت) است، نه فاکتور رسمی</label>
                </div>
            </div>
        </div>
        <div class="mb-0">
            <label class="form-label">توضیح فاکتور (اختیاری)</label>
            <input type="text" name="note" class="form-control">
        </div>
    </div>

    <div class="card p-3 mb-3">
        <h6 class="fw-bold mb-3">انتخاب کارها برای این فاکتور</h6>
        <?php if (!$jobs): ?>
            <div class="text-muted small">کاری برای این کارفرما ثبت نشده است.</div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="table-light"><tr><th></th><th>عنوان کار</th><th>وضعیت</th><th>هزینه</th></tr></thead>
            <tbody>
            <?php foreach ($jobs as $j): ?>
                <tr>
                    <td><input type="checkbox" class="form-check-input calc-input" name="job_ids[]" value="<?= (int)$j['id'] ?>" data-cost="<?= (float)$j['cost'] ?>"></td>
                    <td><?= e($j['title']) ?></td>
                    <td><span class="badge text-bg-<?= e($j['status_color']) ?>"><?= e($j['status_label']) ?></span></td>
                    <td><?= money($j['cost']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="card p-3 mb-3">
        <h6 class="fw-bold mb-3">ردیف‌های آزاد (اختیاری)</h6>
        <div class="text-muted small mb-2">برای مواردی که در لیست کارها نیستند اما می‌خواهید در فاکتور بیایند.</div>
        <div id="extraRows">
            <div class="row g-2 mb-2 extra-row">
                <div class="col-md-7"><input type="text" name="extra_title[]" class="form-control" placeholder="عنوان"></div>
                <div class="col-md-5"><input type="text" inputmode="numeric" name="extra_amount[]" class="form-control calc-input" placeholder="مبلغ"></div>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addExtraRow()"><i class="bi bi-plus"></i> افزودن ردیف</button>
    </div>

    <div class="card p-3 mb-3">
        <h6 class="fw-bold mb-3">تخفیف، مالیات و جمع کل</h6>
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">درصد تخفیف</label>
                <div class="input-group">
                    <input type="text" inputmode="numeric" name="discount_percent" id="discountPercent" class="form-control calc-input" value="0">
                    <span class="input-group-text">٪</span>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">درصد مالیات</label>
                <div class="input-group">
                    <input type="text" inputmode="numeric" name="tax_percent" id="taxPercent" class="form-control calc-input" value="<?= e((string)$defaultTaxPercent) ?>">
                    <span class="input-group-text">٪</span>
                </div>
            </div>
        </div>
        <table class="table table-sm mb-0">
            <tbody>
                <tr><td>جمع اقلام</td><td class="text-end" id="calcSubtotal">۰</td></tr>
                <tr><td>تخفیف</td><td class="text-end" id="calcDiscount">۰</td></tr>
                <tr><td>مالیات</td><td class="text-end" id="calcTax">۰</td></tr>
                <tr class="fw-bold"><td>مبلغ نهایی قابل پرداخت</td><td class="text-end" id="calcTotal">۰</td></tr>
            </tbody>
        </table>
    </div>

    <div class="card p-3 mb-3">
        <h6 class="fw-bold mb-3">اقساط / مراحل پرداخت (اختیاری)</h6>
        <div class="text-muted small mb-2">می‌توانید مبلغ نهایی فاکتور را به چند مرحله (بیعانه، میان‌کار، تسویه نهایی) تقسیم کنید. مجموع درصدها باید ۱۰۰ باشد. اگر خالی بگذارید، کل مبلغ به‌صورت یکجا خواهد بود.</div>
        <div class="mb-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="presetInstallments([['بیعانه',50],['تسویه نهایی',50]])">۵۰٪ / ۵۰٪</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="presetInstallments([['بیعانه',50],['میان‌کار',25],['تسویه نهایی',25]])">۵۰٪ / ۲۵٪ / ۲۵٪</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="presetInstallments([])">پاک کردن (یکجا)</button>
        </div>
        <div id="instRows"></div>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addInstRow('', '', '')"><i class="bi bi-plus"></i> افزودن قسط</button>
        <div class="small mt-2">مجموع درصد اقساط: <span id="instPercentSum">۰</span>٪</div>
    </div>

    <button type="submit" class="btn btn-primary">صدور فاکتور</button>
    <a href="<?= e(base_url('client_view.php?id=' . $clientId)) ?>" class="btn btn-outline-secondary">انصراف</a>
</form>

<script>
function addExtraRow() {
    var wrap = document.getElementById('extraRows');
    var row = document.querySelector('.extra-row').cloneNode(true);
    row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    wrap.appendChild(row);
    bindCalcEvents();
}

var instCounter = 0;
function addInstRow(label, percent, dueDate) {
    instCounter++;
    var wrap = document.getElementById('instRows');
    var row = document.createElement('div');
    row.className = 'row g-2 mb-2 align-items-center inst-row';
    var hiddenId = 'inst_due_hidden_' + instCounter;
    row.innerHTML =
        '<div class="col-md-4"><input type="text" name="inst_label[]" class="form-control" placeholder="عنوان (مثلا بیعانه)" value="' + (label || '').replace(/"/g, '&quot;') + '"></div>' +
        '<div class="col-md-3"><div class="input-group"><input type="text" inputmode="numeric" name="inst_percent[]" class="form-control inst-percent" placeholder="درصد" value="' + (percent || '') + '"><span class="input-group-text">٪</span></div></div>' +
        '<div class="col-md-4"><div class="jdp-wrap"><div class="input-group">' +
            '<span class="input-group-text"><i class="bi bi-calendar3"></i></span>' +
            '<input type="text" class="form-control jdp-input" placeholder="سررسید (اختیاری)" readonly data-jalali-picker data-hidden-target="' + hiddenId + '" data-clearable="1">' +
        '</div><input type="hidden" name="inst_due_date[]" id="' + hiddenId + '" value="' + (dueDate || '') + '"></div></div>' +
        '<div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'.inst-row\').remove(); recalcInstSum();"><i class="bi bi-trash"></i></button></div>';
    wrap.appendChild(row);
    if (window.JalaliDatePicker) window.JalaliDatePicker.bindAll(row);
    row.querySelectorAll('.inst-percent').forEach(function (i) { i.addEventListener('input', recalcInstSum); });
    recalcInstSum();
}

function presetInstallments(list) {
    document.getElementById('instRows').innerHTML = '';
    list.forEach(function (item) { addInstRow(item[0], item[1], ''); });
    recalcInstSum();
}

function recalcInstSum() {
    var sum = 0;
    document.querySelectorAll('.inst-percent').forEach(function (i) { sum += parseFloat(i.value) || 0; });
    document.getElementById('instPercentSum').textContent = toFa(sum);
}

var FA_DIGITS_MAP = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
function toFa(n) {
    return String(Math.round(n * 100) / 100).replace(/[0-9]/g, function (d) { return FA_DIGITS_MAP[d]; });
}
function faMoney(n) {
    n = Math.round(n);
    var s = n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return s.replace(/[0-9]/g, function (d) { return FA_DIGITS_MAP[d]; });
}

function recalcTotal() {
    var subtotal = 0;
    document.querySelectorAll('input[name="job_ids[]"]:checked').forEach(function (cb) {
        subtotal += parseFloat(cb.getAttribute('data-cost')) || 0;
    });
    document.querySelectorAll('input[name="extra_amount[]"]').forEach(function (i) {
        var v = parseFloat((i.value || '').replace(/[^0-9.]/g, ''));
        if (!isNaN(v)) subtotal += v;
    });
    var discountPercent = parseFloat(document.getElementById('discountPercent').value) || 0;
    var taxPercent = parseFloat(document.getElementById('taxPercent').value) || 0;
    var discountAmount = Math.round(subtotal * discountPercent / 100);
    var afterDiscount = subtotal - discountAmount;
    var taxAmount = Math.round(afterDiscount * taxPercent / 100);
    var total = afterDiscount + taxAmount;

    document.getElementById('calcSubtotal').textContent = faMoney(subtotal);
    document.getElementById('calcDiscount').textContent = faMoney(discountAmount);
    document.getElementById('calcTax').textContent = faMoney(taxAmount);
    document.getElementById('calcTotal').textContent = faMoney(total);
}

function bindCalcEvents() {
    document.querySelectorAll('.calc-input').forEach(function (el) {
        el.removeEventListener('input', recalcTotal);
        el.removeEventListener('change', recalcTotal);
        el.addEventListener('input', recalcTotal);
        el.addEventListener('change', recalcTotal);
    });
}
bindCalcEvents();
recalcTotal();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
