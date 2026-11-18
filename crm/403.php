<?php
require_once __DIR__ . '/includes/auth.php';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>دسترسی غیرمجاز</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
<link href="<?= e(base_url('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center" style="height:100vh">
<div class="text-center">
    <h1 class="display-4 text-danger">۴۰۳</h1>
    <p class="fs-5">شما اجازه دسترسی به این بخش را ندارید.</p>
    <a href="<?= e(base_url('index.php')) ?>" class="btn btn-primary">بازگشت به صفحه اصلی</a>
</div>
</body>
</html>
