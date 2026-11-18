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
    $newState = $subtask['is_done'] ? 0 : 1;
    $upd = db()->prepare('UPDATE job_subtasks SET is_done = ? WHERE id = ?');
    $upd->execute([$newState, $id]);
    log_activity('update', 'job', (int)$subtask['job_id'], $subtask['job_title'], ($newState ? 'انجام‌شده: ' : 'برگشت به انجام‌نشده: ') . $subtask['title']);
    redirect('job_form.php?id=' . $subtask['job_id']);
}

flash_set('danger', 'زیرکار یافت نشد.');
redirect('jobs.php');
