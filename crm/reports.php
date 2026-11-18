<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin', 'staff');
require_permission('perm_view_prices');

$pdo = db();

// درآمد ماهانه ۱۲ ماه اخیر (بر اساس تاریخ پرداخت یا تاریخ ثبت)
$monthlyRaw = $pdo->query("
    SELECT DATE_FORMAT(COALESCE(payment_date, created_at), '%Y-%m') AS ym, SUM(amount) AS total
    FROM payments
    WHERE COALESCE(payment_date, created_at) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY ym
    ORDER BY ym
")->fetchAll();
$monthlyMap = [];
foreach ($monthlyRaw as $r) {
    $monthlyMap[$r['ym']] = (float)$r['total'];
}
$months = [];
$monthLabels = [];
$monthlyValues = [];
for ($i = 11; $i >= 0; $i--) {
    $ts = strtotime("-$i months");
    $ym = date('Y-m', $ts);
    $months[] = $ym;
    [$jy, $jm] = gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), 1);
    $monthLabels[] = jalali_month_name($jm) . ' ' . fa_digits((string)$jy);
    $monthlyValues[] = $monthlyMap[$ym] ?? 0;
}

// درآمد به تفکیک کارفرما (۱۰ کارفرمای برتر بر اساس مجموع پرداختی)
$byClient = $pdo->query("
    SELECT c.name, COALESCE(SUM(p.amount),0) AS total
    FROM clients c
    LEFT JOIN payments p ON p.client_id = c.id
    GROUP BY c.id, c.name
    ORDER BY total DESC
    LIMIT 10
")->fetchAll();

// توزیع وضعیت کارها
$overall = overall_financials();

$pageTitle = 'گزارش‌ها';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">گزارش‌ها و نمودارها</h4>
    <div class="d-flex gap-2 no-print">
        <a href="<?= e(base_url('export_csv.php?type=report_monthly')) ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-spreadsheet"></i> اکسل درآمد ماهانه</a>
        <a href="<?= e(base_url('export_csv.php?type=report_clients')) ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-spreadsheet"></i> اکسل درآمد به تفکیک کارفرما</a>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> چاپ / PDF</button>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3">درآمد ماهانه (۱۲ ماه اخیر)</h6>
            <canvas id="monthlyChart" height="110"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card p-3 h-100">
            <h6 class="fw-bold mb-3">توزیع وضعیت کارها</h6>
            <canvas id="statusChart" height="180"></canvas>
        </div>
    </div>
</div>

<div class="card p-3 mb-4">
    <h6 class="fw-bold mb-3">درآمد به تفکیک کارفرما (۱۰ مورد برتر)</h6>
    <canvas id="clientChart" height="90"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
var monthLabels = <?= json_encode($monthLabels) ?>;
var monthValues = <?= json_encode($monthlyValues) ?>;
var statusLabels = <?= json_encode(array_column($overall['by_status'], 'label')) ?>;
var statusValues = <?= json_encode(array_map('floatval', array_column($overall['by_status'], 'total_cost'))) ?>;
var statusColors = <?= json_encode(array_map(function ($c) {
    $map = ['secondary' => '#6c757d', 'warning' => '#ffc107', 'success' => '#198754', 'danger' => '#dc3545', 'primary' => '#0d6efd', 'info' => '#0dcaf0', 'dark' => '#212529'];
    return $map[$c] ?? '#6c757d';
}, array_column($overall['by_status'], 'color'))) ?>;
var clientLabels = <?= json_encode(array_column($byClient, 'name')) ?>;
var clientValues = <?= json_encode(array_map('floatval', array_column($byClient, 'total'))) ?>;

new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: {
        labels: monthLabels,
        datasets: [{ label: 'درآمد', data: monthValues, backgroundColor: '#0d6efd' }]
    },
    options: { responsive: true, plugins: { legend: { display: false } } }
});

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: statusLabels,
        datasets: [{ data: statusValues, backgroundColor: statusColors }]
    },
    options: { responsive: true }
});

new Chart(document.getElementById('clientChart'), {
    type: 'bar',
    data: {
        labels: clientLabels,
        datasets: [{ label: 'مجموع پرداختی', data: clientValues, backgroundColor: '#198754' }]
    },
    options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } } }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
