<?php
/**
 * تنظیمات اتصال به دیتابیس
 * این فایل را کپی کرده و به نام config.php ذخیره کنید،
 * سپس مقادیر زیر را با اطلاعاتی که در cPanel -> MySQL Databases ساختید جایگزین کنید.
 */

// معمولا روی هاست اشتراکی نام دیتابیس و کاربر به صورت USERNAME_dbname است
define('DB_HOST', 'localhost');
define('DB_NAME', 'cpaneluser_crm');
define('DB_USER', 'cpaneluser_crm');
define('DB_PASS', 'REPLACE_WITH_YOUR_DB_PASSWORD');
define('DB_CHARSET', 'utf8mb4');

// آدرس کامل پوشه‌ای که برنامه در آن قرار دارد (بدون / در انتها)
// مثال: https://example.com/crm
define('APP_BASE_URL', 'https://example.com/crm');

// کلید رمزنگاری امنیتی - یک رشته تصادفی طولانی و منحصربه‌فرد اینجا بگذارید
define('APP_SECRET', 'CHANGE_THIS_TO_A_RANDOM_LONG_STRING');

// نام سیستم (نمایش داده می‌شود در عنوان صفحات)
define('APP_NAME', 'سیستم مدیریت کارها و امور مالی');

// آیا سایت روی HTTPS اجرا می‌شود؟ (توصیه می‌شود true باشد)
define('APP_FORCE_HTTPS', false);
