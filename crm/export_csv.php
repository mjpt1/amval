<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');
require_permission('perm_view_prices');

$type = $_GET['type'] ?? '';
$clientId = (int)($_GET['client_id'] ?? 0);
$statusId = (int)($_GET['status_id'] ?? 0);
$q = trim($_GET['q'] ?? '');

$rows = [];
$headers = [];
$filename = 'export.csv';

switch ($type) {
    case 'clients':
        $filename = 'clients.csv';
        $headers = ['نام کارفرما', 'شخص تماس', 'تلفن', 'ایمیل', 'آدرس', 'تعداد کار', 'مانده حساب'];
        $sql = 'SELECT * FROM clients';
        $params = [];
        if ($q !== '') { $sql .= ' WHERE name LIKE ? OR contact_name LIKE ?'; $params = ["%$q%", "%$q%"]; }
        $sql .= ' ORDER BY name';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $c) {
            $f = client_financials((int)$c['id']);
            $rows[] = [$c['name'], $c['contact_name'], $c['phone'], $c['email'], $c['address'] ?? '', $f['job_count'], $f['remaining']];
        }
        break;

    case 'jobs':
        $filename = 'jobs.csv';
        $headers = ['عنوان کار', 'کارفرما', 'هزینه', 'وضعیت', 'موعد تحویل', 'تاریخ ثبت'];
        $sql = '
            SELECT j.*, c.name AS client_name, s.label AS status_label
            FROM jobs j JOIN clients c ON c.id = j.client_id JOIN job_statuses s ON s.id = j.status_id
            WHERE 1=1
        ';
        $params = [];
        if ($clientId) { $sql .= ' AND j.client_id = ?'; $params[] = $clientId; }
        if ($statusId) { $sql .= ' AND j.status_id = ?'; $params[] = $statusId; }
        if ($q !== '') { $sql .= ' AND j.title LIKE ?'; $params[] = "%$q%"; }
        $sql .= ' ORDER BY j.created_at DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $j) {
            $rows[] = [$j['title'], $j['client_name'], $j['cost'], $j['status_label'], $j['due_date'] ? format_date_fa($j['due_date']) : '', format_date_fa($j['created_at'])];
        }
        break;

    case 'payments':
        $filename = 'payments.csv';
        $headers = ['کارفرما', 'کار مرتبط', 'مبلغ', 'تاریخ', 'توضیح'];
        $sql = '
            SELECT p.*, c.name AS client_name, j.title AS job_title
            FROM payments p JOIN clients c ON c.id = p.client_id LEFT JOIN jobs j ON j.id = p.job_id
            WHERE 1=1
        ';
        $params = [];
        if ($clientId) { $sql .= ' AND p.client_id = ?'; $params[] = $clientId; }
        $sql .= ' ORDER BY COALESCE(p.payment_date, p.created_at) DESC, p.id DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $p) {
            $rows[] = [$p['client_name'], $p['job_title'] ?: '', $p['amount'], $p['date_label'] ?: ($p['payment_date'] ? format_date_fa($p['payment_date']) : ''), $p['note'] ?: ''];
        }
        break;

    case 'invoices':
        $filename = 'invoices.csv';
        $headers = ['شماره فاکتور', 'نوع', 'کارفرما', 'تاریخ صدور', 'جمع اقلام', 'تخفیف٪', 'مالیات٪', 'مبلغ نهایی'];
        $sql = 'SELECT i.*, c.name AS client_name FROM invoices i JOIN clients c ON c.id = i.client_id WHERE 1=1';
        $params = [];
        if ($clientId) { $sql .= ' AND i.client_id = ?'; $params[] = $clientId; }
        $sql .= ' ORDER BY i.issue_date DESC, i.id DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $inv) {
            $rows[] = [
                $inv['invoice_number'], $inv['is_quote'] ? 'پیش‌فاکتور' : 'فاکتور', $inv['client_name'],
                format_date_fa($inv['issue_date']), $inv['subtotal'], $inv['discount_percent'], $inv['tax_percent'],
                $inv['total'] > 0 ? $inv['total'] : $inv['subtotal'],
            ];
        }
        break;

    case 'report_monthly':
        $filename = 'monthly_income.csv';
        $headers = ['ماه', 'درآمد'];
        $monthlyRaw = db()->query("
            SELECT DATE_FORMAT(COALESCE(payment_date, created_at), '%Y-%m') AS ym, SUM(amount) AS total
            FROM payments
            WHERE COALESCE(payment_date, created_at) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY ym ORDER BY ym
        ")->fetchAll();
        $monthlyMap = [];
        foreach ($monthlyRaw as $r) { $monthlyMap[$r['ym']] = (float)$r['total']; }
        for ($i = 11; $i >= 0; $i--) {
            $ts = strtotime("-$i months");
            $ym = date('Y-m', $ts);
            [$jy, $jm] = gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), 1);
            $label = jalali_month_name($jm) . ' ' . $jy;
            $rows[] = [$label, $monthlyMap[$ym] ?? 0];
        }
        break;

    case 'report_clients':
        $filename = 'income_by_client.csv';
        $headers = ['کارفرما', 'مجموع پرداختی'];
        $byClient = db()->query('
            SELECT c.name, COALESCE(SUM(p.amount),0) AS total
            FROM clients c LEFT JOIN payments p ON p.client_id = c.id
            GROUP BY c.id, c.name ORDER BY total DESC
        ')->fetchAll();
        foreach ($byClient as $r) { $rows[] = [$r['name'], $r['total']]; }
        break;

    default:
        http_response_code(400);
        die('نوع خروجی نامعتبر است.');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM برای نمایش صحیح فارسی در اکسل
fputcsv($out, $headers);
foreach ($rows as $row) {
    fputcsv($out, $row);
}
fclose($out);
