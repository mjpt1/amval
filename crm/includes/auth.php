<?php
/**
 * احراز هویت و کنترل دسترسی
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (defined('APP_FORCE_HTTPS') && APP_FORCE_HTTPS),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (defined('APP_FORCE_HTTPS') && APP_FORCE_HTTPS && empty($_SERVER['HTTPS'])) {
    $redirectUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
    header('Location: ' . $redirectUrl, true, 301);
    exit;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

/**
 * فقط این نقش‌ها اجازه دسترسی دارند. مثال: require_role('admin') یا require_role('admin','staff')
 */
function require_role(string ...$roles): void
{
    require_login();
    $user = current_user();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        require __DIR__ . '/../403.php';
        exit;
    }
}

function is_admin(): bool
{
    return is_logged_in() && current_user()['role'] === 'admin';
}

function is_staff(): bool
{
    return is_logged_in() && current_user()['role'] === 'staff';
}

function is_client(): bool
{
    return is_logged_in() && current_user()['role'] === 'client';
}

/** مدیر و همکار هر دو دسترسی مدیریتی کامل به کارفرماها/کارها/پرداخت‌ها دارند */
function can_manage(): bool
{
    return is_admin() || is_staff();
}

/**
 * سیستم دسترسی‌های ریز برای کاربران «همکار/کارمند» (role=staff).
 * مدیر همیشه به همه چیز دسترسی دارد. کارفرما هیچ‌کدام از این دسترسی‌ها را ندارد.
 * کلیدهای معتبر: perm_view_prices, perm_manage_clients, perm_manage_payments, perm_manage_invoices
 */
const STAFF_PERMISSIONS = [
    'perm_view_prices'    => 'مشاهده قیمت‌ها و مبالغ مالی کارفرماها',
    'perm_manage_clients' => 'مدیریت کارفرماها (افزودن/ویرایش/حذف)',
    'perm_manage_payments' => 'مدیریت پرداخت‌ها (ثبت/ویرایش)',
    'perm_manage_invoices' => 'مدیریت فاکتورها و پیش‌فاکتورها (صدور/ویرایش)',
];

function user_has_permission(string $key): bool
{
    if (!is_logged_in()) {
        return false;
    }
    if (is_admin()) {
        return true;
    }
    if (!is_staff()) {
        return false;
    }
    $user = current_user();
    // اگر نشست کاربر قدیمی باشد و هنوز کلید جدید را نداشته باشد، پیش‌فرض باز (سازگار با رفتار قبلی) در نظر گرفته می‌شود
    return array_key_exists($key, $user) ? (bool)$user[$key] : true;
}

function can_view_prices(): bool
{
    return user_has_permission('perm_view_prices');
}

function can_manage_clients_perm(): bool
{
    return user_has_permission('perm_manage_clients');
}

function can_manage_payments_perm(): bool
{
    return user_has_permission('perm_manage_payments');
}

function can_manage_invoices_perm(): bool
{
    return user_has_permission('perm_manage_invoices');
}

/** آیا کاربر فعلی یک «همکار محدود» (بدون مشاهده قیمت) است؟ */
function is_restricted_associate(): bool
{
    return is_staff() && !can_view_prices();
}

/**
 * سیستم دسترسی‌های ریز برای کاربران «کارفرما» (role=client).
 * پیش‌فرض همه این دسترسی‌ها غیرفعال است (کارفرما فقط مشاهده‌گر است)؛ مدیر باید هرکدام را به‌صورت دستی برای هر کارفرما فعال کند.
 * کلیدهای معتبر: perm_client_create_job, perm_client_edit_job, perm_client_suggest_price
 */
const CLIENT_PERMISSIONS = [
    'perm_client_create_job'    => 'ثبت کار جدید',
    'perm_client_edit_job'      => 'ویرایش کارهای خودش (عنوان، توضیحات، موعد تحویل)',
    'perm_client_suggest_price' => 'ثبت/ویرایش قیمت پیشنهادی برای کارها',
];

function client_has_permission(string $key): bool
{
    if (!is_client()) {
        return false;
    }
    $user = current_user();
    // برخلاف دسترسی‌های همکار، پیش‌فرض این دسترسی‌ها برای کارفرما بسته است (باید مدیر آن را فعال کند)
    return array_key_exists($key, $user) ? (bool)$user[$key] : false;
}

function client_can_create_job(): bool
{
    return client_has_permission('perm_client_create_job');
}

function client_can_edit_job(): bool
{
    return client_has_permission('perm_client_edit_job');
}

function client_can_suggest_price(): bool
{
    return client_has_permission('perm_client_suggest_price');
}

/**
 * آیا کاربر فعلی به این کار (برای مشاهده/گفتگو) دسترسی دارد؟
 * مدیر و کارمند کامل: به همه کارها دسترسی دارند.
 * همکار محدود: فقط کاری که به او محول شده.
 * کارفرما: فقط کارهای خودش.
 */
function user_can_access_job(array $job): bool
{
    if (!is_logged_in()) {
        return false;
    }
    if (is_admin()) {
        return true;
    }
    if (is_staff()) {
        if (is_restricted_associate()) {
            return (int)($job['assigned_to'] ?? 0) === (int)current_user()['id'];
        }
        return true;
    }
    if (is_client()) {
        return (int)($job['client_id'] ?? 0) === (int)(current_user()['client_id'] ?? 0);
    }
    return false;
}

/**
 * فقط کاربرانی که دسترسی مشخص‌شده را دارند اجازه ورود دارند، وگرنه ۴۰۳
 */
function require_permission(string $key): void
{
    require_login();
    if (!user_has_permission($key)) {
        http_response_code(403);
        require __DIR__ . '/../403.php';
        exit;
    }
}

function attempt_login(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    unset($user['password_hash']);
    $_SESSION['user'] = $user;

    $upd = db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
    $upd->execute([$user['id']]);

    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
