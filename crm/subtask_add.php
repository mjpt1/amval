<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('jobs.php');
}
csrf_verify();

$jobId = (int)($_POST['job_id'] ?? 0);
$title = trim($_POST['title'] ?? '');

$stmt = db()->prepare('SELECT * FROM jobs WHERE id = ?');
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

if ($title === '') {
    flash_set('danger', 'عنوان زیرکار الزامی است.');
} else {
    $sortStmt = db()->prepare('SELECT COALESCE(MAX(sort_order),0) FROM job_subtasks WHERE job_id = ?');
    $sortStmt->execute([$jobId]);
    $maxSort = (int)$sortStmt->fetchColumn();

    $ins = db()->prepare('INSERT INTO job_subtasks (job_id, title, sort_order) VALUES (?,?,?)');
    $ins->execute([$jobId, $title, $maxSort + 1]);
    log_activity('update', 'job', $jobId, $job['title'], 'افزودن زیرکار: ' . $title);
    flash_set('success', 'زیرکار اضافه شد.');
}

redirect('job_form.php?id=' . $jobId);
