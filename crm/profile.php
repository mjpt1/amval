<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$errors = [];
$user = current_user();

$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$dbUser = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $fullName = trim($_POST['full_name'] ?? '');
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($fullName === '') $errors[] = 'نام کامل الزامی است.';

    if ($newPassword !== '' || $confirmPassword !== '' || $currentPassword !== '') {
        if (!password_verify($currentPassword, $dbUser['password_hash'])) {
            $errors[] = 'رمز عبور فعلی صحیح نیست.';
        }
        if (strlen($newPassword) < 6) {
            $errors[] = 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'تکرار رمز عبور جدید مطابقت ندارد.';
        }
    }

    if (!$errors) {
        if ($newPassword !== '') {
            $stmt = db()->prepare('UPDATE users SET full_name=?, password_hash=? WHERE id=?');
            $stmt->execute([$fullName, password_hash($newPassword, PASSWORD_BCRYPT), $user['id']]);
        } else {
            $stmt = db()->prepare('UPDATE users SET full_name=? WHERE id=?');
            $stmt->execute([$fullName, $user['id']]);
        }
        $_SESSION['user']['full_name'] = $fullName;
        flash_set('success', 'اطلاعات پروفایل به‌روزرسانی شد.');
        redirect('profile.php');
    }
}

$pageTitle = 'پروفایل من';
require __DIR__ . '/includes/header.php';
?>

<h4 class="mb-3">پروفایل من</h4>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger py-2"><?= e($err) ?></div>
<?php endforeach; ?>

<div class="card p-4" style="max-width:520px">
<form method="post">
    <?= csrf_field() ?>
    <div class="mb-3">
        <label class="form-label">نام کاربری</label>
        <input type="text" class="form-control" value="<?= e($dbUser['username']) ?>" disabled dir="ltr">
    </div>
    <div class="mb-3">
        <label class="form-label">نام کامل</label>
        <input type="text" name="full_name" class="form-control" value="<?= e($dbUser['full_name']) ?>">
    </div>
    <hr>
    <p class="text-muted small">برای تغییر رمز عبور، فیلدهای زیر را پر کنید. در غیر این صورت خالی بگذارید.</p>
    <div class="mb-3">
        <label class="form-label">رمز عبور فعلی</label>
        <input type="password" name="current_password" class="form-control" dir="ltr">
    </div>
    <div class="mb-3">
        <label class="form-label">رمز عبور جدید</label>
        <input type="password" name="new_password" class="form-control" dir="ltr">
    </div>
    <div class="mb-3">
        <label class="form-label">تکرار رمز عبور جدید</label>
        <input type="password" name="confirm_password" class="form-control" dir="ltr">
    </div>
    <button type="submit" class="btn btn-primary">ذخیره تغییرات</button>
</form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
