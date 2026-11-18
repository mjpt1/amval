<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('jobs.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT a.*, j.title AS job_title, j.assigned_to FROM job_attachments a JOIN jobs j ON j.id = a.job_id WHERE a.id = ?');
$stmt->execute([$id]);
$att = $stmt->fetch();

if ($att && is_restricted_associate() && (int)($att['assigned_to'] ?? 0) !== (int)current_user()['id']) {
    flash_set('danger', 'شما به این کار دسترسی ندارید.');
    redirect('jobs.php');
}

if ($att) {
    $path = job_attachments_dir((int)$att['job_id']) . '/' . $att['stored_name'];
    if (is_file($path)) {
        @unlink($path);
    }
    $del = db()->prepare('DELETE FROM job_attachments WHERE id = ?');
    $del->execute([$id]);
    log_activity('update', 'job', (int)$att['job_id'], $att['job_title'], 'حذف پیوست: ' . $att['original_name']);
    flash_set('success', 'پیوست حذف شد.');
    redirect('job_form.php?id=' . $att['job_id']);
}

flash_set('danger', 'پیوست یافت نشد.');
redirect('jobs.php');
