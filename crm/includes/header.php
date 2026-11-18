<?php
/**
 * هدر مشترک صفحات (بعد از require_login فراخوانی شود)
 * متغیر اختیاری $pageTitle قبل از include قابل تنظیم است
 */
$__currentUser = current_user();
$currentPage = basename($_SERVER['PHP_SELF']);
$pageTitle = $pageTitle ?? '';
$appName = defined('APP_NAME') ? APP_NAME : 'سیستم مدیریت کارها';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ? $pageTitle . ' - ' . $appName : $appName) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= e(base_url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-dark bg-primary px-3">
    <div class="container-fluid">
        <button class="btn btn-link text-white d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas">
            <i class="bi bi-list fs-3"></i>
        </button>
        <a class="navbar-brand" href="<?= e(base_url('index.php')) ?>"><i class="bi bi-kanban-fill me-1"></i> <?= e($appName) ?></a>
        <?php if (can_manage() && can_view_prices()): ?>
        <form action="<?= e(base_url('search.php')) ?>" method="get" class="d-none d-md-flex mx-auto no-print" style="max-width:320px;width:100%;">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="text" name="q" class="form-control" placeholder="جست‌وجوی سراسری... (کارفرما، کار، فاکتور، پرداخت)" value="<?= e($_GET['q'] ?? '') ?>">
            </div>
        </form>
        <?php endif; ?>
        <div class="d-flex align-items-center gap-2">
            <?php if (can_manage() && can_view_prices()): ?>
            <a href="<?= e(base_url('search.php')) ?>" class="btn btn-sm btn-outline-light d-md-none"><i class="bi bi-search"></i></a>
            <?php endif; ?>
            <span class="text-white small d-none d-sm-inline"><i class="bi bi-person-circle"></i> <?= e($__currentUser['full_name'] ?? '') ?></span>
            <a href="<?= e(base_url('profile.php')) ?>" class="btn btn-sm btn-outline-light"><i class="bi bi-gear"></i></a>
            <a href="<?= e(base_url('logout.php')) ?>" class="btn btn-sm btn-light">خروج</a>
        </div>
    </div>
</nav>

<div class="container-fluid">
<div class="row">
    <?php
    $navItems = [];
    if (can_manage() && can_view_prices()) {
        $navItems[] = ['dashboard.php', 'bi-speedometer2', 'داشبورد'];
        $navItems[] = ['clients.php', 'bi-people', 'کارفرماها'];
        $navItems[] = ['jobs.php', 'bi-list-task', 'کارها'];
        $navItems[] = ['payments.php', 'bi-cash-coin', 'پرداخت‌ها'];
        $navItems[] = ['invoices.php', 'bi-receipt', 'فاکتورها'];
        $navItems[] = ['reports.php', 'bi-bar-chart', 'گزارش‌ها'];
        $navItems[] = ['activity_log.php', 'bi-clock-history', 'لاگ فعالیت'];
        if (is_admin()) {
            $navItems[] = ['users.php', 'bi-person-badge', 'کاربران'];
            $navItems[] = ['settings.php', 'bi-sliders', 'تنظیمات'];
            $navItems[] = ['backup.php', 'bi-cloud-download', 'پشتیبان‌گیری'];
        }
    } elseif (is_restricted_associate()) {
        $navItems[] = ['dashboard.php', 'bi-speedometer2', 'داشبورد من'];
        $navItems[] = ['jobs.php', 'bi-list-task', 'کارهای من'];
    } else {
        $navItems[] = ['dashboard.php', 'bi-speedometer2', 'داشبورد من'];
        $navItems[] = ['client_view.php', 'bi-list-task', 'کارها و مانده حساب من'];
    }
    ob_start();
    ?>
    <ul class="nav nav-pills flex-column p-3">
        <?php foreach ($navItems as [$href, $icon, $label]): ?>
        <li class="nav-item">
            <a class="nav-link <?= $currentPage === $href ? 'active' : '' ?>" href="<?= e(base_url($href)) ?>">
                <i class="bi <?= e($icon) ?> me-1"></i> <?= e($label) ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php $navHtml = ob_get_clean(); ?>

    <div class="offcanvas offcanvas-start d-lg-none" tabindex="-1" id="sidebarOffcanvas">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title">منو</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body p-0"><?= $navHtml ?></div>
    </div>

    <div class="col-lg-2 d-none d-lg-block app-sidebar px-0"><?= $navHtml ?></div>

    <main class="col-lg-10 py-4">
        <?php foreach (flash_get_all() as $__flashItem): ?>
            <div class="alert alert-<?= e($__flashItem['type']) ?> alert-dismissible fade show" role="alert">
                <?= e($__flashItem['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; unset($__flashItem); ?>
