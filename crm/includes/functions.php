<?php
/**
 * توابع کمکی عمومی
 */

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function money(float|int|string $amount): string
{
    $n = (float)$amount;
    return number_format($n, 0, '.', ',');
}

function currency_label(): string
{
    static $label = null;
    if ($label === null) {
        $label = get_setting('currency_label', 'تومان');
    }
    return $label;
}

function money_fmt(float|int|string $amount): string
{
    return money($amount) . ' ' . currency_label();
}

function three_digit_to_words(int $n): string
{
    $hundreds = ['', 'صد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
    $teens = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
    $tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
    $ones = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];
    if ($n === 0) return '';
    $parts = [];
    $h = intdiv($n, 100);
    $rem = $n % 100;
    if ($h > 0) $parts[] = $hundreds[$h];
    if ($rem >= 10 && $rem < 20) {
        $parts[] = $teens[$rem - 10];
    } else {
        $t = intdiv($rem, 10);
        $o = $rem % 10;
        if ($t > 0) $parts[] = $tens[$t];
        if ($o > 0) $parts[] = $ones[$o];
    }
    return implode(' و ', $parts);
}

function number_to_persian_words(int $amount): string
{
    if ($amount === 0) return 'صفر';
    $negative = $amount < 0;
    $amount = abs($amount);
    $scales = ['', 'هزار', 'میلیون', 'میلیارد', 'تریلیون', 'کوآدریلیون'];
    $groups = [];
    while ($amount > 0) {
        $groups[] = $amount % 1000;
        $amount = intdiv($amount, 1000);
    }
    $parts = [];
    for ($i = count($groups) - 1; $i >= 0; $i--) {
        if ($groups[$i] > 0) {
            $words = three_digit_to_words($groups[$i]);
            $parts[] = $scales[$i] ? ($words . ' ' . $scales[$i]) : $words;
        }
    }
    return ($negative ? 'منفی ' : '') . implode(' و ', $parts);
}

function amount_in_words_fmt(float|int|string $amount): string
{
    $n = (int)round((float)$amount);
    return number_to_persian_words($n) . ' ' . currency_label();
}

function base_url(string $path = ''): string
{
    $base = defined('APP_BASE_URL') ? rtrim(APP_BASE_URL, '/') : '';
    if ($base === '') {
        // اگر تنظیم نشده بود، خودکار از روی درخواست فعلی حدس بزن
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    }
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . base_url($path));
    exit;
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get_all(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        die('درخواست نامعتبر است (خطای CSRF). لطفا صفحه را رفرش کرده و دوباره تلاش کنید.');
    }
}

function get_setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $stmt = db()->query('SELECT setting_key, setting_value FROM settings');
        foreach ($stmt->fetchAll() as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $value]);
}

/**
 * محاسبه وضعیت مالی یک کارفرما بر اساس مجموع هزینه کارها (با درصد قابل محاسبه هر وضعیت) منهای پرداختی‌ها
 */
function client_financials(int $clientId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('
        SELECT j.status_id, s.code, s.label, s.billing_percent, s.color,
               COUNT(*) AS job_count, COALESCE(SUM(j.cost),0) AS total_cost,
               COALESCE(SUM(j.cost * s.billing_percent / 100),0) AS billable_cost
        FROM jobs j
        JOIN job_statuses s ON s.id = j.status_id
        WHERE j.client_id = ?
        GROUP BY j.status_id, s.code, s.label, s.billing_percent, s.color
        ORDER BY s.sort_order
    ');
    $stmt->execute([$clientId]);
    $byStatus = $stmt->fetchAll();

    $totalCost = 0.0;
    $billable = 0.0;
    $jobCount = 0;
    foreach ($byStatus as $row) {
        $totalCost += (float)$row['total_cost'];
        $billable  += (float)$row['billable_cost'];
        $jobCount  += (int)$row['job_count'];
    }

    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE client_id = ?');
    $stmt->execute([$clientId]);
    $paid = (float)$stmt->fetchColumn();

    return [
        'by_status'    => $byStatus,
        'job_count'    => $jobCount,
        'total_cost'   => $totalCost,   // مجموع کل هزینه همه کارها فارغ از وضعیت
        'billable'     => $billable,    // مجموع قابل محاسبه (با احتساب درصد هر وضعیت)
        'paid'         => $paid,        // مجموع پرداختی‌ها
        'remaining'    => $billable - $paid, // مانده حساب
    ];
}

/**
 * جمع‌بندی کلی کل سیستم (همه کارفرماها) - برای داشبورد مدیر/همکار
 */
function overall_financials(): array
{
    $pdo = db();
    $totalCost = (float)$pdo->query('SELECT COALESCE(SUM(cost),0) FROM jobs')->fetchColumn();
    $billable  = (float)$pdo->query('
        SELECT COALESCE(SUM(j.cost * s.billing_percent / 100),0)
        FROM jobs j JOIN job_statuses s ON s.id = j.status_id
    ')->fetchColumn();
    $paid = (float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM payments')->fetchColumn();
    $clientCount = (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    $jobCount = (int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn();

    $byStatus = $pdo->query('
        SELECT s.id, s.code, s.label, s.color, s.billing_percent,
               COUNT(j.id) AS job_count, COALESCE(SUM(j.cost),0) AS total_cost
        FROM job_statuses s
        LEFT JOIN jobs j ON j.status_id = s.id
        GROUP BY s.id, s.code, s.label, s.color, s.billing_percent
        ORDER BY s.sort_order
    ')->fetchAll();

    return [
        'total_cost'   => $totalCost,
        'billable'     => $billable,
        'paid'         => $paid,
        'remaining'    => $billable - $paid,
        'client_count' => $clientCount,
        'job_count'    => $jobCount,
        'by_status'    => $byStatus,
    ];
}

/** برچسب فارسی و رنگ نشان (badge) برای نقش کاربر */
function role_label_fa(?string $role): string
{
    return match ($role) {
        'admin'  => 'مدیر',
        'staff'  => 'همکار',
        'client' => 'کارفرما',
        default  => $role ?? '',
    };
}

function role_badge_color(?string $role): string
{
    return match ($role) {
        'admin'  => 'danger',
        'staff'  => 'primary',
        'client' => 'info',
        default  => 'secondary',
    };
}

function status_badge(array $status): string
{
    return '<span class="badge text-bg-' . e($status['color']) . '">' . e($status['label']) . '</span>';
}

/**
 * تبدیل ارقام انگلیسی به فارسی
 */
function fa_digits(string $s): string
{
    static $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    static $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return str_replace($en, $fa, $s);
}

/**
 * تبدیل ارقام فارسی/عربی به انگلیسی (برای پردازش ورودی کاربر)
 */
function en_digits(string $s): string
{
    static $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    static $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($fa, $en, $s);
}

function jalali_div(int $a, int $b): int
{
    return (int)floor($a / $b);
}

/**
 * تبدیل تاریخ میلادی به جلالی (خورشیدی) — الگوریتم استاندارد و آزمایش‌شده
 * @return array{0:int,1:int,2:int} [سال، ماه، روز]
 */
function gregorian_to_jalali(int $gy, int $gm, int $gd): array
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + jalali_div($gy2 + 3, 4) - jalali_div($gy2 + 99, 100) + jalali_div($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * jalali_div($days, 12053));
    $days %= 12053;
    $jy += 4 * jalali_div($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += jalali_div($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + jalali_div($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + jalali_div($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

/**
 * تبدیل تاریخ جلالی (خورشیدی) به میلادی
 * @return array{0:int,1:int,2:int} [سال، ماه، روز]
 */
function jalali_to_gregorian(int $jy, int $jm, int $jd): array
{
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (jalali_div($jy, 33) * 8) + jalali_div(($jy % 33) + 3, 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * jalali_div($days, 146097);
    $days %= 146097;
    if ($days > 36524) {
        $days--;
        $gy += 100 * jalali_div($days, 36524);
        $days %= 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * jalali_div($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += jalali_div($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $sal_a = [0, 31, ((($gy % 4 == 0) && ($gy % 100 != 0)) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $gm = 1;
    for ($i = 1; $i <= 12; $i++) {
        $v = $sal_a[$i];
        if ($gd <= $v) { $gm = $i; break; }
        $gd -= $v;
    }
    return [$gy, $gm, $gd];
}

function is_leap_jalali_year(int $jy): bool
{
    [$gy, $gm, $gd] = jalali_to_gregorian($jy, 12, 30);
    [$jy2, $jm2, $jd2] = gregorian_to_jalali($gy, $gm, $gd);
    return $jy2 === $jy && $jm2 === 12 && $jd2 === 30;
}

function jalali_month_length(int $jy, int $jm): int
{
    if ($jm <= 6) return 31;
    if ($jm <= 11) return 30;
    return is_leap_jalali_year($jy) ? 30 : 29;
}

const JALALI_MONTH_NAMES = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

function jalali_month_name(int $jm): string
{
    return JALALI_MONTH_NAMES[$jm - 1] ?? '';
}

/**
 * تبدیل تاریخ میلادی ذخیره‌شده در دیتابیس (Y-m-d یا datetime) به رشته جلالی برای نمایش
 */
function format_date_fa(?string $date): string
{
    if (!$date) return '-';
    $ts = strtotime($date);
    if (!$ts) return e($date);
    [$jy, $jm, $jd] = gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    return fa_digits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
}

/**
 * تبدیل تاریخ/ساعت میلادی به رشته جلالی همراه با ساعت (برای نمایش در گفتگوها)
 */
function format_datetime_fa(?string $date): string
{
    if (!$date) return '-';
    $ts = strtotime($date);
    if (!$ts) return e($date);
    [$jy, $jm, $jd] = gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    return fa_digits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd)) . ' - ' . fa_digits(date('H:i', $ts));
}

/**
 * مقدار جلالی برای پرکردن اولیه ورودی‌های انتخاب تاریخ (رشته خالی اگر تاریخی ثبت نشده)
 */
function jalali_input_value(?string $date): string
{
    if (!$date) return '';
    $ts = strtotime($date);
    if (!$ts) return '';
    [$jy, $jm, $jd] = gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    return fa_digits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
}

/**
 * ثبت یک ردیف در لاگ فعالیت کاربران
 */
function log_activity(string $action, string $entityType, ?int $entityId, string $entityLabel, ?string $details = null): void
{
    $userId = is_logged_in() ? (int)current_user()['id'] : null;
    $stmt = db()->prepare('INSERT INTO activity_log (user_id, action, entity_type, entity_id, entity_label, details) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$userId, $action, $entityType, $entityId, mb_substr($entityLabel, 0, 250), $details]);
}

function activity_action_label(string $action): string
{
    return match ($action) {
        'create' => 'ایجاد',
        'update' => 'ویرایش',
        'delete' => 'حذف',
        default  => $action,
    };
}

function activity_entity_label(string $entityType): string
{
    return match ($entityType) {
        'client'  => 'کارفرما',
        'job'     => 'کار',
        'payment' => 'پرداخت',
        'user'    => 'کاربر',
        'invoice' => 'فاکتور',
        'quote'   => 'پیش‌فاکتور',
        'backup'  => 'پشتیبان‌گیری',
        'comment' => 'گفتگوی کار',
        default   => $entityType,
    };
}

/**
 * تولید یا بازیابی توکن لینک عمومی یک کارفرما
 */
function ensure_client_public_token(int $clientId): string
{
    $stmt = db()->prepare('SELECT public_token FROM clients WHERE id = ?');
    $stmt->execute([$clientId]);
    $token = $stmt->fetchColumn();
    if ($token) {
        return $token;
    }
    $token = bin2hex(random_bytes(20));
    $upd = db()->prepare('UPDATE clients SET public_token = ? WHERE id = ?');
    $upd->execute([$token, $clientId]);
    return $token;
}

function revoke_client_public_token(int $clientId): void
{
    $upd = db()->prepare('UPDATE clients SET public_token = NULL WHERE id = ?');
    $upd->execute([$clientId]);
}

/**
 * پوشه ذخیره پیوست‌های یک کار (و ساخت آن در صورت نبود)
 */
function job_attachments_dir(int $jobId): string
{
    $dir = __DIR__ . '/../uploads/job_' . $jobId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

const ATTACHMENT_ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', 'txt'];
const ATTACHMENT_MAX_SIZE = 8 * 1024 * 1024; // 8 مگابایت

/**
 * آپلود امن یک فایل پیوست برای یک کار. در صورت خطا رشته پیام خطا و در صورت موفقیت null برمی‌گرداند.
 */
function upload_job_attachment(int $jobId, array $file, int $uploadedBy): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null; // فایلی انتخاب نشده - خطایی نیست
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'خطا در آپلود فایل.';
    }
    if ($file['size'] > ATTACHMENT_MAX_SIZE) {
        return 'حجم فایل نباید بیشتر از ۸ مگابایت باشد.';
    }
    $originalName = $file['name'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ATTACHMENT_ALLOWED_EXT, true)) {
        return 'فرمت فایل مجاز نیست. فرمت‌های مجاز: ' . implode(', ', ATTACHMENT_ALLOWED_EXT);
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return 'خطای امنیتی در آپلود فایل.';
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
    $dir = job_attachments_dir($jobId);
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $storedName)) {
        return 'ذخیره فایل روی سرور ناموفق بود.';
    }

    $stmt = db()->prepare('INSERT INTO job_attachments (job_id, original_name, stored_name, file_size, uploaded_by) VALUES (?,?,?,?,?)');
    $stmt->execute([$jobId, $originalName, $storedName, $file['size'], $uploadedBy]);

    return null;
}

/**
 * پوشه ذخیره فایل‌های گفتگو (عکس/صوت) یک کار
 */
function job_comments_dir(int $jobId): string
{
    $dir = __DIR__ . '/../uploads/job_' . $jobId . '/comments';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

const COMMENT_IMAGE_ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const COMMENT_IMAGE_MAX_SIZE = 8 * 1024 * 1024; // ۸ مگابایت
const COMMENT_AUDIO_ALLOWED_EXT = ['webm', 'ogg', 'oga', 'mp3', 'wav', 'm4a', 'mp4'];
const COMMENT_AUDIO_MAX_SIZE = 20 * 1024 * 1024; // ۲۰ مگابایت

/**
 * آپلود امن یک فایل گفتگو (عکس یا صوت) برای یک کار.
 * در صورت موفقیت آرایه ['error'=>null,'stored'=>نام فایل ذخیره‌شده] و در صورت خطا ['error'=>پیام,'stored'=>null] برمی‌گرداند.
 */
function upload_job_comment_file(int $jobId, array $file, array $allowedExt, int $maxSize, string $prefix): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['error' => null, 'stored' => null]; // فایلی انتخاب نشده - خطایی نیست
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'خطا در آپلود فایل.', 'stored' => null];
    }
    if ($file['size'] > $maxSize) {
        return ['error' => 'حجم فایل بیش از حد مجاز است.', 'stored' => null];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $allowedExt, true)) {
        // بعضی مرورگرها برای فایل ضبط‌شده صوتی پسوند نمی‌گذارند؛ اگر نوع MIME صوتی/تصویری باشد با پسوند پیش‌فرض ادامه می‌دهیم
        $ext = $allowedExt[0];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['error' => 'خطای امنیتی در آپلود فایل.', 'stored' => null];
    }

    $storedName = $prefix . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
    $dir = job_comments_dir($jobId);
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $storedName)) {
        return ['error' => 'ذخیره فایل روی سرور ناموفق بود.', 'stored' => null];
    }

    return ['error' => null, 'stored' => $storedName];
}

/**
 * لیست گفتگوهای یک کار به ترتیب زمانی، همراه با اطلاعات نویسنده و پاسخ/فوروارد
 */
function get_job_comments(int $jobId): array
{
    $stmt = db()->prepare('
        SELECT c.*, u.full_name AS author_name, u.role AS author_role,
               r.id AS reply_id, r.body AS reply_body, r.image_name AS reply_image_name, r.audio_name AS reply_audio_name,
               ru.full_name AS reply_author_name,
               fj.title AS forward_job_title,
               fc.body AS forward_body, fc.image_name AS forward_image_name, fc.audio_name AS forward_audio_name,
               fu.full_name AS forward_author_name
        FROM job_comments c
        LEFT JOIN users u ON u.id = c.user_id
        LEFT JOIN job_comments r ON r.id = c.reply_to_id
        LEFT JOIN users ru ON ru.id = r.user_id
        LEFT JOIN jobs fj ON fj.id = c.forward_job_id
        LEFT JOIN job_comments fc ON fc.id = c.forward_comment_id
        LEFT JOIN users fu ON fu.id = fc.user_id
        WHERE c.job_id = ?
        ORDER BY c.created_at ASC, c.id ASC
    ');
    $stmt->execute([$jobId]);
    return $stmt->fetchAll();
}

/**
 * خلاصه کوتاه از یک کامنت برای نمایش در پیش‌نمایش پاسخ/فوروارد
 */
function comment_snippet(?string $body, ?string $imageName, ?string $audioName): string
{
    if ($body !== null && trim($body) !== '') {
        $s = mb_substr(trim($body), 0, 80);
        return $s . (mb_strlen(trim($body)) > 80 ? '…' : '');
    }
    if ($imageName) return '📷 تصویر';
    if ($audioName) return '🎤 پیام صوتی';
    return '-';
}

/**
 * لیست کارهایی که کاربر فعلی به آن‌ها دسترسی دارد (برای فوروارد کردن پیام بین کارها)
 */
function get_accessible_jobs_for_user(): array
{
    if (is_admin() || (is_staff() && !is_restricted_associate())) {
        return db()->query('
            SELECT j.id, j.title, c.name AS client_name
            FROM jobs j JOIN clients c ON c.id = j.client_id
            ORDER BY j.created_at DESC
        ')->fetchAll();
    }
    if (is_restricted_associate()) {
        $stmt = db()->prepare('
            SELECT j.id, j.title, c.name AS client_name
            FROM jobs j JOIN clients c ON c.id = j.client_id
            WHERE j.assigned_to = ?
            ORDER BY j.created_at DESC
        ');
        $stmt->execute([current_user()['id']]);
        return $stmt->fetchAll();
    }
    if (is_client()) {
        $stmt = db()->prepare('
            SELECT j.id, j.title, c.name AS client_name
            FROM jobs j JOIN clients c ON c.id = j.client_id
            WHERE j.client_id = ?
            ORDER BY j.created_at DESC
        ');
        $stmt->execute([(int)(current_user()['client_id'] ?? 0)]);
        return $stmt->fetchAll();
    }
    return [];
}

const STAMP_ALLOWED_EXT = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
const STAMP_MAX_SIZE = 2 * 1024 * 1024; // ۲ مگابایت

/**
 * پوشه ذخیره تصویر مهر/امضای شرکت
 */
function company_stamp_dir(): string
{
    $dir = __DIR__ . '/../uploads/branding';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * آپلود تصویر مهر/امضا؛ در صورت موفقیت مسیر نسبی را در تنظیمات ذخیره می‌کند و آن را برمی‌گرداند، در صورت خطا رشته پیام خطا.
 */
function upload_company_stamp(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['error' => null]; // فایلی انتخاب نشده
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'خطا در آپلود فایل.'];
    }
    if ($file['size'] > STAMP_MAX_SIZE) {
        return ['error' => 'حجم فایل نباید بیشتر از ۲ مگابایت باشد.'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, STAMP_ALLOWED_EXT, true)) {
        return ['error' => 'فرمت فایل مجاز نیست. فرمت‌های مجاز: ' . implode(', ', STAMP_ALLOWED_EXT)];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['error' => 'خطای امنیتی در آپلود فایل.'];
    }
    $storedName = 'stamp_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dir = company_stamp_dir();
    // مهر قبلی را پاک کن
    $old = get_setting('company_stamp_path', '');
    if ($old && is_file(__DIR__ . '/../' . $old)) {
        @unlink(__DIR__ . '/../' . $old);
    }
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $storedName)) {
        return ['error' => 'ذخیره فایل روی سرور ناموفق بود.'];
    }
    $relative = 'uploads/branding/' . $storedName;
    set_setting('company_stamp_path', $relative);
    return ['error' => null, 'path' => $relative];
}

/**
 * تولید یک فایل پشتیبان کامل SQL از دیتابیس جاری (ساختار + داده‌ها) بدون نیاز به mysqldump
 * و مستقیم به خروجی جریان می‌دهد تا برای دیتابیس‌های بزرگ هم حافظه کم مصرف شود.
 */
function stream_sql_backup(): void
{
    $pdo = db();
    $dbName = defined('DB_NAME') ? DB_NAME : '';

    echo "-- پشتیبان دیتابیس CRM\n";
    echo "-- تاریخ تهیه: " . date('Y-m-d H:i:s') . "\n";
    echo "-- دیتابیس: " . $dbName . "\n\n";
    echo "SET NAMES utf8mb4;\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $createRow = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch();
        $createSql = $createRow['Create Table'] ?? '';

        echo "-- ----------------------------\n";
        echo "-- ساختار جدول `$table`\n";
        echo "-- ----------------------------\n";
        echo "DROP TABLE IF EXISTS `$table`;\n";
        echo $createSql . ";\n\n";

        $countStmt = $pdo->query('SELECT COUNT(*) FROM `' . $table . '`');
        $rowCount = (int)$countStmt->fetchColumn();
        if ($rowCount === 0) {
            continue;
        }

        echo "-- داده‌های جدول `$table`\n";
        $chunkSize = 500;
        for ($offset = 0; $offset < $rowCount; $offset += $chunkSize) {
            $stmt = $pdo->query('SELECT * FROM `' . $table . '` LIMIT ' . $chunkSize . ' OFFSET ' . $offset);
            $rows = $stmt->fetchAll();
            if (!$rows) break;

            $columns = array_keys($rows[0]);
            $columnList = '`' . implode('`, `', $columns) . '`';
            $valueLines = [];
            foreach ($rows as $row) {
                $vals = array_map(function ($v) use ($pdo) {
                    if ($v === null) return 'NULL';
                    return $pdo->quote((string)$v);
                }, $row);
                $valueLines[] = '(' . implode(', ', $vals) . ')';
            }
            echo "INSERT INTO `$table` ($columnList) VALUES\n" . implode(",\n", $valueLines) . ";\n";
        }
        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
}

function format_file_size(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

/**
 * تولید شماره فاکتور جدید بر اساس تنظیمات (پیشوند) و شماره ردیف
 */
function generate_invoice_number(int $invoiceId, bool $isQuote = false): string
{
    $prefix = get_setting($isQuote ? 'quote_prefix' : 'invoice_prefix', $isQuote ? 'PRE' : 'INV');
    return $prefix . '-' . str_pad((string)$invoiceId, 5, '0', STR_PAD_LEFT);
}

/**
 * محاسبه مبلغ نهایی فاکتور بر اساس جمع اقلام، درصد تخفیف و درصد مالیات
 */
function calc_invoice_total(float $subtotal, float $discountPercent, float $taxPercent): array
{
    $discountAmount = round($subtotal * $discountPercent / 100);
    $afterDiscount = $subtotal - $discountAmount;
    $taxAmount = round($afterDiscount * $taxPercent / 100);
    $total = $afterDiscount + $taxAmount;
    return ['discount_amount' => $discountAmount, 'tax_amount' => $taxAmount, 'total' => $total];
}

/**
 * تقسیم مبلغ فاکتور به اقساط بر اساس درصدهای وارد شده؛ باقیمانده گرد کردن به آخرین قسط اضافه می‌شود
 */
function split_installments(float $total, array $installments): array
{
    $result = [];
    $sumSoFar = 0;
    $count = count($installments);
    foreach ($installments as $i => $inst) {
        $isLast = ($i === $count - 1);
        if ($isLast) {
            $amount = $total - $sumSoFar;
        } else {
            $percent = (float)($inst['percent'] ?? 0);
            $amount = round($total * $percent / 100);
        }
        $sumSoFar += $amount;
        $result[] = [
            'label' => $inst['label'],
            'percent' => $inst['percent'] !== '' && $inst['percent'] !== null ? (float)$inst['percent'] : null,
            'amount' => $amount,
            'due_date' => $inst['due_date'] ?: null,
        ];
    }
    return $result;
}

/**
 * کارهای نزدیک به موعد تحویل یا عقب‌افتاده (برای ویجت داشبورد)
 */
function upcoming_jobs(int $daysAhead = 7, ?int $clientId = null): array
{
    $sql = "
        SELECT j.*, c.name AS client_name, s.label AS status_label, s.color AS status_color
        FROM jobs j
        JOIN clients c ON c.id = j.client_id
        JOIN job_statuses s ON s.id = j.status_id
        WHERE j.due_date IS NOT NULL
          AND s.billing_percent < 100
          AND j.due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
    ";
    $params = [$daysAhead];
    if ($clientId) {
        $sql .= ' AND j.client_id = ?';
        $params[] = $clientId;
    }
    $sql .= ' ORDER BY j.due_date ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
