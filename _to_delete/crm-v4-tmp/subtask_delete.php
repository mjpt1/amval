<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('jobs.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT s.*, j.title AS job_title, j.assigned_to FROM job_subtasks s JOIN jobs j ON j.id = s.job_id WHERE s.id = ?');
$stmt->execute([$id]);
$subtask = $stmt->fetch();

if ($subtask && is_restricted_associate() && (int)($subtask['assigned_to'] ?? 0) !== (int)current_user()['id']) {
    flash_set('danger', 'شما به این کار دسترسی ندارید.');
    redirect('jobs.php');
}

if ($subtask) {
    $del = db()->prepare('DELETE FROM job_subtasks WHERE id = ?');
    $del->execute([$id]);
    log_activity('update', 'job', (int)$subtask['job_id'], $subtask['job_title'], 'حذف زیرکار: ' . $subtask['title']);
    flash_set('success', 'زیرکار حذف شد.');
    redirect('job_form.php?id=' . $subtask['job_id']);
}

flash_set('danger', 'زیرکار یافت نشد.');
redirect('jobs.php');
