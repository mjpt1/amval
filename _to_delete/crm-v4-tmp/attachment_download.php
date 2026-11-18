<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('
    SELECT a.*, j.client_id, j.assigned_to
    FROM job_attachments a
    JOIN jobs j ON j.id = a.job_id
    WHERE a.id = ?
');
$stmt->execute([$id]);
$att = $stmt->fetch();

if (!$att) {
    http_response_code(404);
    die('فایل یافت نشد.');
}

if (is_client() && (int)current_user()['client_id'] !== (int)$att['client_id']) {
    http_response_code(403);
    die('شما اجازه دسترسی به این فایل را ندارید.');
}

if (is_restricted_associate() && (int)($att['assigned_to'] ?? 0) !== (int)current_user()['id']) {
    http_response_code(403);
    die('شما اجازه دسترسی به این فایل را ندارید.');
}

$path = job_attachments_dir((int)$att['job_id']) . '/' . $att['stored_name'];
if (!is_file($path)) {
    http_response_code(404);
    die('فایل روی سرور یافت نشد.');
}

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . rawurlencode($att['original_name']) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
