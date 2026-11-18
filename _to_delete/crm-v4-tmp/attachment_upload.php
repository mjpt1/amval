<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('jobs.php');
}
csrf_verify();

$jobId = (int)($_POST['job_id'] ?? 0);
$stmt = db()->prepare('SELECT j.*, c.name AS client_name FROM jobs j JOIN clients c ON c.id = j.client_id WHERE j.id = ?');
$stmt->execute([$jobId]);
$job = $stmt->fetch();
if (!$job) {
    flash_set('danger', 'کار یافت نشد.');
    redirect('jobs.php');
}
if (is_restricted_associate() && (int)($job['assigned_to'] ?? 0) !== (int)current_user()['id']) {
    flash_set('danger', 'شما به این کار دسترسی ندارید.');
    redirect('jobs.php');
}

if (!empty($_FILES['attachment']['name'])) {
    $error = upload_job_attachment($jobId, $_FILES['attachment'], current_user()['id']);
    if ($error) {
        flash_set('danger', $error);
    } else {
        log_activity('update', 'job', $jobId, $job['title'], 'افزودن پیوست: ' . $_FILES['attachment']['name']);
        flash_set('success', 'فایل با موفقیت پیوست شد.');
    }
} else {
    flash_set('danger', 'فایلی انتخاب نشده بود.');
}

redirect('job_form.php?id=' . $jobId);
