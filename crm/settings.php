<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_general') {
        set_setting('company_name', trim($_POST['company_name'] ?? ''));
        set_setting('currency_label', trim($_POST['currency_label'] ?? 'تومان'));
        set_setting('company_address', trim($_POST['company_address'] ?? ''));
        set_setting('company_phone', trim($_POST['company_phone'] ?? ''));
        set_setting('invoice_prefix', trim($_POST['invoice_prefix'] ?? 'INV'));
        flash_set('success', 'تنظیمات کلی ذخیره شد.');
        redirect('settings.php');
    }

    if ($action === 'save_stamp') {
        if (!empty($_FILES['company_stamp']['name'])) {
            $result = upload_company_stamp($_FILES['company_stamp']);
            if ($result['error']) {
                flash_set('danger', $result['error']);
            } else {
                flash_set('success', 'تصویر مهر و امضا ذخیره شد.');
            }
        }
        redirect('settings.php');
    }

    if ($action === 'remove_stamp') {
        $old = get_setting('company_stamp_path', '');
        if ($old && is_file(__DIR__ . '/' . $old)) {
            @unlink(__DIR__ . '/' . $old);
        }
        set_setting('company_stamp_path', '');
        flash_set('success', 'تصویر مهر و امضا حذف شد.');
        redirect('settings.php');
    }

    if ($action === 'save_billing') {
        set_setting('quote_prefix', trim($_POST['quote_prefix'] ?? 'PRE'));
        set_setting('default_tax_percent', (string)max(0, min(100, (float)($_POST['default_tax_percent'] ?? 0))));
        flash_set('success', 'تنظیمات مالی ذخیره شد.');
        redirect('settings.php');
    }

    if ($action === 'update_statuses') {
        $ids = $_POST['status_id'] ?? [];
        $labels = $_POST['status_label'] ?? [];
        $percents = $_POST['status_percent'] ?? [];
        $colors = $_POST['status_color'] ?? [];
        $stmt = db()->prepare('UPDATE job_statuses SET label=?, billing_percent=?, color=? WHERE id=?');
        foreach ($ids as $i => $sid) {
            $label = trim($labels[$i] ?? '');
            $percent = (float)($percents[$i] ?? 0);
            $color = $colors[$i] ?? 'secondary';
            if ($label !== '') {
                $stmt->execute([$label, max(0, min(100, $percent)), $color, (int)$sid]);
            }
        }
        flash_set('success', 'وضعیت‌های کار به‌روزرسانی شد.');
        redirect('settings.php');
    }

    if ($action === 'add_status') {
        $label = trim($_POST['new_label'] ?? '');
        $percent = (float)($_POST['new_percent'] ?? 0);
        $color = $_POST['new_color'] ?? 'secondary';
        if ($label !== '') {
            $code = 'custom_' . substr(md5($label . microtime()), 0, 8);
            $maxSort = (int)db()->query('SELECT COALESCE(MAX(sort_order),0) FROM job_statuses')->fetchColumn();
            $stmt = db()->prepare('INSERT INTO job_statuses (code, label, billing_percent, color, sort_order) VALUES (?,?,?,?,?)');
            $stmt->execute([$code, $label, max(0, min(100, $percent)), $color, $maxSort + 1]);
            flash_set('success', 'وضعیت جدید اضافه شد.');
        }
        redirect('settings.php');
    }

    if ($action === 'delete_status') {
        $sid = (int)$_POST['id'];
        $stmt = db()->prepare('SELECT COUNT(*) FROM jobs WHERE status_id = ?');
        $stmt->execute([$sid]);
        if ((int)$stmt->fetchColumn() > 0) {
            flash_set('danger', 'این وضعیت در حال حاضر روی حداقل یک کار استفاده شده و قابل حذف نیست.');
        } else {
            $del = db()->prepare('DELETE FROM job_statuses WHERE id = ?');
            $del->execute([$sid]);
            flash_set('success', 'وضعیت حذف شد.');
        }
        redirect('settings.php');
    }
}

$companyName = get_setting('company_name', '');
$currencyLabel = get_setting('currency_label', 'تومان');
$companyAddress = get_setting('company_address', '');
$companyPhone = get_setting('company_phone', '');
$invoicePrefix = get_setting('invoice_prefix', 'INV');
$quotePrefix = get_setting('quote_prefix', 'PRE');
$defaultTaxPercent = get_setting('default_tax_percent', '0');
$stampPath = get_setting('company_stamp_path', '');
$statuses = db()->query('SELECT * FROM job_statuses ORDER BY sort_order')->fetchAll();
$colorOptions = ['secondary' => 'خاکستری', 'warning' => 'زرد', 'success' => 'سبز', 'danger' => 'قرمز', 'primary' => 'آبی', 'info' => 'آبی روشن', 'dark' => 'مشکی'];

$pageTitle = 'تنظیمات';
require __DIR__ . '/includes/header.php';
?>

<h4 class="mb-4">تنظیمات سیستم</h4>

<div class="card p-4 mb-4" style="max-width:640px">
    <h6 class="fw-bold mb-3">تنظیمات کلی</h6>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_general">
        <div class="mb-3">
            <label class="form-label">نام سیستم / شرکت</label>
            <input type="text" name="company_name" class="form-control" value="<?= e($companyName) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">واحد پول</label>
            <input type="text" name="currency_label" class="form-control" value="<?= e($currencyLabel) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">آدرس (برای نمایش در سربرگ فاکتور - اختیاری)</label>
            <input type="text" name="company_address" class="form-control" value="<?= e($companyAddress) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">تلفن (برای نمایش در سربرگ فاکتور - اختیاری)</label>
            <input type="text" name="company_phone" class="form-control" dir="ltr" value="<?= e($companyPhone) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">پیشوند شماره فاکتور</label>
            <input type="text" name="invoice_prefix" class="form-control" dir="ltr" value="<?= e($invoicePrefix) ?>">
            <div class="form-text">مثال: با پیشوند INV شماره فاکتور به‌صورت INV-00001 ساخته می‌شود.</div>
        </div>
        <button type="submit" class="btn btn-primary">ذخیره</button>
    </form>
</div>

<div class="card p-4 mb-4" style="max-width:640px">
    <h6 class="fw-bold mb-3">تنظیمات مالی فاکتور</h6>
    <form method="post" class="mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_billing">
        <div class="mb-3">
            <label class="form-label">پیشوند شماره پیش‌فاکتور</label>
            <input type="text" name="quote_prefix" class="form-control" dir="ltr" value="<?= e($quotePrefix) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">درصد مالیات پیش‌فرض فاکتورهای جدید</label>
            <div class="input-group" style="max-width:160px">
                <input type="number" min="0" max="100" step="0.5" name="default_tax_percent" class="form-control" value="<?= e($defaultTaxPercent) ?>">
                <span class="input-group-text">٪</span>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">ذخیره</button>
    </form>

    <hr>
    <h6 class="fw-bold mb-2">مهر و امضای دیجیتال روی فاکتور</h6>
    <p class="text-muted small">تصویر مهر یا امضای شرکت را بارگذاری کنید تا پایین فاکتورهای چاپی نمایش داده شود (فرمت PNG با پس‌زمینه شفاف بهتر است).</p>
    <?php if ($stampPath): ?>
        <div class="mb-3">
            <img src="<?= e(base_url($stampPath)) ?>" alt="مهر و امضا" style="max-height:120px;">
        </div>
        <form method="post" class="mb-3" onsubmit="return confirm('تصویر مهر و امضا حذف شود؟');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="remove_stamp">
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> حذف تصویر</button>
        </form>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="d-flex gap-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_stamp">
        <input type="file" name="company_stamp" class="form-control" accept="image/*" required>
        <button type="submit" class="btn btn-outline-primary text-nowrap">بارگذاری</button>
    </form>
</div>

<div class="card p-4 mb-4">
    <h6 class="fw-bold mb-1">وضعیت‌های کار و درصد قابل محاسبه در مانده حساب</h6>
    <p class="text-muted small">برای هر وضعیت، درصدی که از هزینه کار باید در مبلغ قابل محاسبه/مانده حساب لحاظ شود را تعیین کنید. (مثلا کار «در حال انجام» را می‌توانید ۵۰٪ حساب کنید.)</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_statuses">
        <div class="table-responsive">
        <table class="table align-middle">
            <thead class="table-light">
            <tr><th>عنوان وضعیت</th><th>درصد قابل محاسبه</th><th>رنگ</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($statuses as $s): ?>
                <tr>
                    <td>
                        <input type="hidden" name="status_id[]" value="<?= (int)$s['id'] ?>">
                        <input type="text" name="status_label[]" class="form-control" value="<?= e($s['label']) ?>">
                    </td>
                    <td style="max-width:140px">
                        <div class="input-group">
                            <input type="number" min="0" max="100" step="1" name="status_percent[]" class="form-control" value="<?= (float)$s['billing_percent'] ?>">
                            <span class="input-group-text">٪</span>
                        </div>
                    </td>
                    <td style="max-width:160px">
                        <select name="status_color[]" class="form-select">
                            <?php foreach ($colorOptions as $val => $label): ?>
                                <option value="<?= e($val) ?>" <?= $s['color'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <button type="submit" form="deleteStatusForm<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('حذف این وضعیت؟')"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <button type="submit" class="btn btn-primary">ذخیره تغییرات وضعیت‌ها</button>
    </form>

    <?php foreach ($statuses as $s): ?>
    <form id="deleteStatusForm<?= (int)$s['id'] ?>" method="post" class="d-none">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_status">
        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
    </form>
    <?php endforeach; ?>

    <hr>
    <h6 class="fw-bold mb-2">افزودن وضعیت جدید</h6>
    <form method="post" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_status">
        <div class="col-md-5">
            <label class="form-label small">عنوان</label>
            <input type="text" name="new_label" class="form-control" placeholder="مثلا: در انتظار تایید">
        </div>
        <div class="col-md-3">
            <label class="form-label small">درصد قابل محاسبه</label>
            <input type="number" min="0" max="100" name="new_percent" class="form-control" value="0">
        </div>
        <div class="col-md-3">
            <label class="form-label small">رنگ</label>
            <select name="new_color" class="form-select">
                <?php foreach ($colorOptions as $val => $label): ?>
                    <option value="<?= e($val) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1">
            <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-plus-lg"></i></button>
        </div>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
