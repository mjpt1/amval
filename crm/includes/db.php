<?php
/**
 * اتصال به دیتابیس با استفاده از PDO
 */

if (!file_exists(__DIR__ . '/../config.php')) {
    die('فایل تنظیمات یافت نشد. لطفا config.sample.php را کپی کرده و به نام config.php ذخیره و ویرایش کنید.');
}
require_once __DIR__ . '/../config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die('خطا در اتصال به دیتابیس. لطفا تنظیمات config.php را بررسی کنید. (' . htmlspecialchars($e->getMessage()) . ')');
        }
    }
    return $pdo;
}
