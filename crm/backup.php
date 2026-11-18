<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');

if (isset($_GET['download'])) {
    $dbName = defined('DB_NAME') ? DB_NAME : 'crm';
    $filename = 'backup_' . $dbName . '_' . date('Y-m-d_H-i') . '.sql';

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    log_activity('create', 'backup', null, $filename, 'دانلود پشتیبان دیتابیس');

    stream_sql_backup();
    exit;
}

$pageTitle = 'پشتیبان‌گیری';
require __DIR__ . '/includes/header.php';
?>

<h4 class="mb-3">پشتیبان‌گیری از دیتابیس</h4>

<div class="card p-4" style="max-width:640px">
    <p>با کلیک روی دکمه زیر، یک فایل SQL شامل کامل ساختار و اطلاعات دیتابیس سیستم (کارفرماها، کارها، پرداخت‌ها، فاکتورها و...) ساخته و دانلود می‌شود. این فایل را در جای امنی (مثلا گوگل‌درایو یا هارد شخصی) نگه دارید.</p>
    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-triangle"></i>
        این فایل شامل تمام اطلاعات دیتابیس (از جمله رمزهای عبور رمزنگاری‌شده کاربران) است؛ آن را در جای امن نگه دارید و برای افراد غیرمجاز ارسال نکنید.
    </div>
    <a href="<?= e(base_url('backup.php?download=1')) ?>" class="btn btn-primary"><i class="bi bi-download"></i> دانلود پشتیبان (SQL)</a>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
