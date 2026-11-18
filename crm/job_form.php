<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$job = ['id' => 0, 'client_id' => (int)($_GET['client_id'] ?? 0), 'title' => '', 'cost' => '', 'associate_cost' => '', 'status_id' => '', 'description' => '', 'due_date' => '', 'assigned_to' => ''];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM jobs WHERE id = ?');
    $stmt->execute([$id]);
    $found = $stmt->fetch();
    if (!$found) {
        flash_set('danger', 'کار یافت نشد.');
        redirect('jobs.php');
    }
    $job = $found;
}

// اگر کاربر «همکار محدود» (بدون دسترسی مشاهده قیمت) باشد، فقط اجازه دارد کار محول‌شده به خودش را باز کند
// و فقط «قیمت خودش» را وارد/ویرایش کند؛ نه قیمت کارفرما را می‌بیند و نه می‌تواند کار جدید بسازد.
$isAssociateView = is_restricted_associate();
if ($isAssociateView) {
    if (!$id || (int)($job['assigned_to'] ?? 0) !== (int)current_user()['id']) {
        flash_set('danger', 'شما به این کار دسترسی ندارید.');
        redirect('jobs.php');
    }
}

$clients = db()->query('SELECT id, name FROM clients ORDER BY name')->fetchAll();
$statuses = db()->query('SELECT * FROM job_statuses ORDER BY sort_order')->fetchAll();
$staffList = db()->query("SELECT id, full_name FROM users WHERE role='staff' AND is_active=1 ORDER BY full_name")->fetchAll();
if (!$job['status_id'] && $statuses) {
    $job['status_id'] = $statuses[0]['id'];
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if ($isAssociateView) {
        // همکار محدود فقط قیمت خودش را برای همین کار وارد می‌کند
        $assocRaw = (string)($_POST['associate_cost'] ?? '');
        $assocNumeric = $assocRaw !== '' ? (float)str_replace([',', ' '], '', $assocRaw) : null;
        if ($assocNumeric !== null && $assocNumeric < 0) $errors[] = 'قیمت نمی‌تواند منفی باشد.';

        if (!$errors) {
            $stmt = db()->prepare('UPDATE jobs SET associate_cost=? WHERE id=?');
            $stmt->execute([$assocNumeric, $id]);
            $job['associate_cost'] = $assocNumeric;
            log_activity('update', 'job', $id, $job['title'], 'ثبت/ویرایش قیمت همکار');
            flash_set('success', 'قیمت شما برای این کار ثبت شد.');
            redirect('job_form.php?id=' . $id);
        }
    } else {
        $job['client_id'] = (int)($_POST['client_id'] ?? 0);
        $job['title'] = trim($_POST['title'] ?? '');
        $job['cost'] = (string)($_POST['cost'] ?? '0');
        $job['status_id'] = (int)($_POST['status_id'] ?? 0);
        $job['description'] = trim($_POST['description'] ?? '');
        $job['due_date'] = trim($_POST['due_date'] ?? '');

        $costNumeric = (float)str_replace([',', ' '], '', $job['cost']);
        $dueDateValue = $job['due_date'] !== '' ? $job['due_date'] : null;

        $assocCostNumeric = null;
        if (is_admin()) {
            $job['assigned_to'] = trim((string)($_POST['assigned_to'] ?? '')) !== '' ? (int)$_POST['assigned_to'] : null;
            $assocRaw = (string)($_POST['associate_cost'] ?? '');
            $assocCostNumeric = $assocRaw !== '' ? (float)str_replace([',', ' '], '', $assocRaw) : null;
            $job['associate_cost'] = $assocCostNumeric;
        } else {
            // کارمند عادی (با دسترسی مشاهده قیمت) نمی‌تواند مسئول/قیمت همکار را تغییر دهد؛ مقدار فعلی حفظ می‌شود
            $job['assigned_to'] = ($job['assigned_to'] !== null && $job['assigned_to'] !== '') ? (int)$job['assigned_to'] : null;
            $assocCostNumeric = ($job['associate_cost'] !== null && $job['associate_cost'] !== '') ? (float)$job['associate_cost'] : null;
        }

        if ($job['client_id'] <= 0) $errors[] = 'انتخاب کارفرما الزامی است.';
        if ($job['title'] === '') $errors[] = 'عنوان کار الزامی است.';
        if ($job['status_id'] <= 0) $errors[] = 'انتخاب وضعیت الزامی است.';
        if ($costNumeric < 0) $errors[] = 'هزینه نمی‌تواند منفی باشد.';
        if ($assocCostNumeric !== null && $assocCostNumeric < 0) $errors[] = 'قیمت همکار نمی‌تواند منفی باشد.';
        if ($dueDateValue && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDateValue)) $errors[] = 'فرمت تاریخ تحویل نامعتبر است.';

        if (!$errors) {
            if ($id) {
                $stmt = db()->prepare('UPDATE jobs SET client_id=?, title=?, cost=?, associate_cost=?, status_id=?, description=?, due_date=?, assigned_to=? WHERE id=?');
                $stmt->execute([$job['client_id'], $job['title'], $costNumeric, $assocCostNumeric, $job['status_id'], $job['description'], $dueDateValue, $job['assigned_to'], $id]);
                log_activity('update', 'job', $id, $job['title']);
                flash_set('success', 'کار به‌روزرسانی شد.');
            } else {
                $stmt = db()->prepare('INSERT INTO jobs (client_id, title, cost, associate_cost, status_id, description, due_date, assigned_to, created_by) VALUES (?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$job['client_id'], $job['title'], $costNumeric, $assocCostNumeric, $job['status_id'], $job['description'], $dueDateValue, $job['assigned_to'], current_user()['id']]);
                $id = (int)db()->lastInsertId();
                log_activity('create', 'job', $id, $job['title']);
                flash_set('success', 'کار جدید ثبت شد.');
            }
            redirect('client_view.php?id=' . $job['client_id']);
        }
    }
}

$attachments = [];
$subtasks = [];
if ($id) {
    $stmt = db()->prepare('
        SELECT a.*, u.full_name AS uploader_name
        FROM job_attachments a
        LEFT JOIN users u ON u.id = a.uploaded_by
        WHERE a.job_id = ?
        ORDER BY a.created_at DESC
    ');
    $stmt->execute([$id]);
    $attachments = $stmt->fetchAll();

    $subStmt = db()->prepare('SELECT * FROM job_subtasks WHERE job_id = ? ORDER BY sort_order, id');
    $subStmt->execute([$id]);
    $subtasks = $subStmt->fetchAll();
}
$subtasksDone = count(array_filter($subtasks, fn($s) => (int)$s['is_done'] === 1));

$pageTitle = $id ? 'ویرایش کار' : 'کار جدید';
require __DIR__ . '/includes/header.php';
?>

<h4 class="mb-3"><?= e($pageTitle) ?></h4>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger py-2"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if ($isAssociateView): ?>
<div class="card p-4 mb-4" style="max-width:640px">
    <h6 class="fw-bold mb-1"><?= e($job['title']) ?></h6>
    <div class="text-muted small mb-3"><?= e($job['description'] ?: '-') ?></div>
    <div class="alert alert-info py-2 small">
        <i class="bi bi-info-circle"></i> شما فقط قیمتی را که خودتان برای این کار در نظر گرفته‌اید وارد می‌کنید. این مبلغ فقط برای شما و مدیر سیستم قابل مشاهده است.
    </div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <div class="mb-3">
            <label class="form-label">قیمت شما برای این کار (<?= e(currency_label()) ?>)</label>
            <input type="text" inputmode="numeric" name="associate_cost" class="form-control" value="<?= e((string)($job['associate_cost'] ?? '')) ?>">
        </div>
        <button type="submit" class="btn btn-primary">ذخیره قیمت</button>
        <a href="<?= e(base_url('jobs.php')) ?>" class="btn btn-outline-secondary">بازگشت</a>
    </form>
</div>
<?php else: ?>
<div class="card p-4 mb-4" style="max-width:640px">
<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$id ?>">

    <div class="mb-3">
        <label class="form-label">کارفرما <span class="text-danger">*</span></label>
        <select name="client_id" class="form-select" required>
            <option value="">انتخاب کنید...</option>
            <?php foreach ($clients as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (int)$job['client_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="mb-3">
        <label class="form-label">عنوان کار <span class="text-danger">*</span></label>
        <input type="text" name="title" class="form-control" required value="<?= e($job['title']) ?>">
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">هزینه (<?= e(currency_label()) ?>) <span class="text-danger">*</span></label>
            <input type="text" inputmode="numeric" name="cost" class="form-control" required value="<?= e((string)$job['cost']) ?>">
            <?php if ($id && ($job['client_proposed_cost'] ?? null) !== null): ?>
                <div class="form-text text-primary"><i class="bi bi-info-circle"></i> قیمت پیشنهادی کارفرما برای این کار: <strong><?= money($job['client_proposed_cost']) ?></strong></div>
            <?php endif; ?>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">وضعیت <span class="text-danger">*</span></label>
            <select name="status_id" class="form-select" required>
                <?php foreach ($statuses as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= (int)$job['status_id'] === (int)$s['id'] ? 'selected' : '' ?>>
                        <?= e($s['label']) ?> (<?= (int)$s['billing_percent'] ?>٪ قابل محاسبه)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if (is_admin()): ?>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">همکار مسئول قیمت‌گذاری (اختیاری)</label>
            <select name="assigned_to" class="form-select">
                <option value="">— بدون همکار مشخص —</option>
                <?php foreach ($staffList as $st): ?>
                    <option value="<?= (int)$st['id'] ?>" <?= (string)($job['assigned_to'] ?? '') === (string)$st['id'] ? 'selected' : '' ?>><?= e($st['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">اگر همکاری انتخاب کنید، او فقط همین کار را می‌بیند و می‌تواند قیمت خودش را (جدا از هزینه بالا) وارد کند. قیمت او را کارفرما نمی‌بیند.</div>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">قیمت همکار (اختیاری، فقط برای مدیر قابل مشاهده)</label>
            <input type="text" inputmode="numeric" name="associate_cost" class="form-control" value="<?= e((string)($job['associate_cost'] ?? '')) ?>">
        </div>
    </div>
    <?php endif; ?>

    <div class="mb-3">
        <label class="form-label">موعد تحویل (اختیاری)</label>
        <div class="jdp-wrap">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                <input type="text" class="form-control jdp-input" placeholder="مثلا ۱۴۰۵/۰۵/۲۶" readonly
                       value="<?= e(jalali_input_value((string)$job['due_date'])) ?>"
                       data-jalali-picker data-hidden-target="due_date_hidden" data-clearable="1">
            </div>
            <input type="hidden" name="due_date" id="due_date_hidden" value="<?= e((string)$job['due_date']) ?>">
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label">توضیحات</label>
        <textarea name="description" class="form-control" rows="3"><?= e($job['description']) ?></textarea>
    </div>

    <button type="submit" class="btn btn-primary">ذخیره</button>
    <a href="<?= e(base_url('jobs.php')) ?>" class="btn btn-outline-secondary">انصراف</a>
</form>
</div>
<?php endif; ?>

<?php if ($id): ?>
<div class="card p-4 mb-4" style="max-width:640px">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold mb-0">زیرکار / چک‌لیست</h6>
        <?php if ($subtasks): ?><span class="badge text-bg-secondary"><?= fa_digits((string)$subtasksDone) ?> از <?= fa_digits((string)count($subtasks)) ?> انجام شد</span><?php endif; ?>
    </div>

    <form method="post" action="<?= e(base_url('subtask_add.php')) ?>" class="mb-3 d-flex gap-2">
        <?= csrf_field() ?>
        <input type="hidden" name="job_id" value="<?= (int)$id ?>">
        <input type="text" name="title" class="form-control" placeholder="عنوان زیرکار جدید..." required>
        <button type="submit" class="btn btn-outline-primary text-nowrap"><i class="bi bi-plus-lg"></i> افزودن</button>
    </form>

    <?php if (!$subtasks): ?>
        <div class="text-muted small">هنوز زیرکاری اضافه نشده است.</div>
    <?php else: ?>
    <ul class="list-group">
        <?php foreach ($subtasks as $s): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center">
            <form method="post" action="<?= e(base_url('subtask_toggle.php')) ?>" class="d-flex align-items-center gap-2 flex-grow-1">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button type="submit" class="btn btn-sm <?= $s['is_done'] ? 'btn-success' : 'btn-outline-secondary' ?>" style="width:32px;">
                    <i class="bi <?= $s['is_done'] ? 'bi-check-lg' : '' ?>"></i>
                </button>
                <span class="<?= $s['is_done'] ? 'text-decoration-line-through text-muted' : '' ?>"><?= e($s['title']) ?></span>
            </form>
            <form method="post" action="<?= e(base_url('subtask_delete.php')) ?>" onsubmit="return confirm('این زیرکار حذف شود؟');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<div class="card p-4" style="max-width:640px">
    <h6 class="fw-bold mb-3">پیوست‌های فایل</h6>

    <form method="post" action="<?= e(base_url('attachment_upload.php')) ?>" enctype="multipart/form-data" class="mb-3 d-flex gap-2">
        <?= csrf_field() ?>
        <input type="hidden" name="job_id" value="<?= (int)$id ?>">
        <input type="file" name="attachment" class="form-control" required>
        <button type="submit" class="btn btn-outline-primary text-nowrap"><i class="bi bi-upload"></i> آپلود</button>
    </form>
    <div class="text-muted small mb-3">فرمت‌های مجاز: jpg, png, gif, webp, pdf, doc(x), xls(x), zip, rar, txt — حداکثر ۸ مگابایت</div>

    <?php if (!$attachments): ?>
        <div class="text-muted small">فایلی پیوست نشده است.</div>
    <?php else: ?>
    <ul class="list-group">
        <?php foreach ($attachments as $a): ?>
        <li class="list-group-item d-flex justify-content-between align-items-center">
            <div>
                <a href="<?= e(base_url('attachment_download.php?id=' . $a['id'])) ?>"><i class="bi bi-paperclip"></i> <?= e($a['original_name']) ?></a>
                <div class="text-muted small"><?= format_file_size((int)$a['file_size']) ?> · <?= e($a['uploader_name'] ?? '') ?> · <?= format_date_fa($a['created_at']) ?></div>
            </div>
            <form method="post" action="<?= e(base_url('attachment_delete.php')) ?>" onsubmit="return confirm('این پیوست حذف شود؟');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<div style="max-width:800px">
    <?php require __DIR__ . '/includes/job_comments_section.php'; ?>
</div>
<?php else: ?>
<div class="text-muted small">برای افزودن پیوست و گفتگو، ابتدا کار را ذخیره کنید.</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
