<?php
/**
 * ============================================================================
 *  سامانه جامع مدیریت هنرستان شهید رجایی
 *  فایل: db.php
 * ----------------------------------------------------------------------------
 *  وظایف این فایل (Layers):
 *    ۱) تنظیمات پایه و ثابت‌های دامنه (پایه‌ها، رشته‌ها، پرمیشن‌ها)
 *    ۲) بارگذاری فایل پیکربندی (config.php) که توسط install.php ساخته می‌شود
 *    ۳) اتصال به پایگاه داده با PDO (پشتیبانی از MySQL/MariaDB و SQLite)
 *    ۴) لایه امنیتی: CSRF، Sanitization، Session، Rate Limiting، RBAC
 *    ۵) تقویم شمسی (Jalali) + دریافت تاریخ/ساعت از API معتبر با کش
 *    ۶) توابع اعتبارسنجی و ابزارهای کمکی (اعداد فارسی، شماره تماس، ...)
 *    ۷) لایه قالب (Layout) شامل CSS و JavaScript مشترک — کاملاً Inline
 *    ۸) توابع گزارش‌گیری مشترک (میانگین، بازه تاریخ، آمار داشبورد)
 *
 *  نکته معماری: هیچ فایل خارجی CSS یا JS وجود ندارد؛ تمام استایل و اسکریپت
 *  به صورت Inline (داخل تگ <style> و <script>) از همین فایل چاپ می‌شود.
 * ============================================================================
 */

declare(strict_types=1);

/* ---------------------------------------------------------------------------
 * ۱) ثابت‌های پایه
 * ------------------------------------------------------------------------ */

define('HS_APP_NAME', 'سامانه جامع مدیریت هنرستان');
define('HS_SCHOOL_NAME', 'هنرستان شهید رجایی');
define('HS_VERSION', '1.3.3');
define('HS_TIMEZONE', 'Asia/Tehran');
define('HS_CONFIG_FILE', __DIR__ . '/config.php');
define('HS_LOCK_FILE', __DIR__ . '/data/installed.lock');

date_default_timezone_set(HS_TIMEZONE);
mb_internal_encoding('UTF-8');

/* پایه‌های تحصیلی */
define('HS_GRADES', ['دهم', 'یازدهم', 'دوازدهم']);

/**
 * رشته‌های مجاز به تفکیک پایه
 * قانون کاری: رشته «کامپیوتر» تنها در پایه‌های دهم و یازدهم وجود دارد
 *    و در پایه دوازدهم هرگز نباید نمایش داده شود یا پذیرفته شود.
 */
define('HS_FIELDS_BY_GRADE', [
    'دهم'     => ['کامپیوتر', 'الکتروتکنیک', 'مکانیک', 'بهداشتی', 'مستقل', 'سرمایش'],
    'یازدهم'  => ['کامپیوتر', 'الکتروتکنیک', 'مستقل', 'مکانیک', 'بهداشتی', 'سرمایش'],
    'دوازدهم' => ['الکتروتکنیک', 'مکانیک', 'مستقل', 'بهداشتی', 'سرمایش'],
]);

/* انواع موارد انضباطی و تخلف پوششی */
define('HS_DISCIPLINE_TYPES', [
    'بی‌نظمی در کلاس',
    'غیبت غیرموجه',
    'فرار از کلاس',
    'تأخیر مکرر',
    'استفاده از موبایل',
    'درگیری و نزاع',
    'تخریب اموال',
    'بی‌احترامی به کارکنان',
    'تخلف پوششی',
    'سایر موارد',
]);

define('HS_DRESS_TYPES', ['لباس', 'شلوار', 'هر دو', 'سایر']);

/* کارنامه پرمیشن‌ها — تمام قابلیت‌های سایت به‌عنوان پرمیشن قابل تخصیص */
define('HS_PERMISSIONS', [
    'view_students'       => ['label' => 'مشاهده دانش‌آموزان',        'group' => 'دانش‌آموزان'],
    'add_student'         => ['label' => 'افزودن دانش‌آموز',          'group' => 'دانش‌آموزان'],
    'edit_student'        => ['label' => 'ویرایش دانش‌آموز',          'group' => 'دانش‌آموزان'],
    'delete_student'      => ['label' => 'حذف دانش‌آموز',             'group' => 'دانش‌آموزان'],
    'register_delay'      => ['label' => 'ثبت تأخیر',                 'group' => 'ثبت موارد'],
    'register_absence'    => ['label' => 'ثبت غیبت',                  'group' => 'ثبت موارد'],
    'register_discipline' => ['label' => 'ثبت موارد انضباطی',          'group' => 'ثبت موارد'],
    'register_dress'      => ['label' => 'ثبت تخلف پوششی',            'group' => 'ثبت موارد'],
    /* پنل معلم: مشاهدهٔ کلاس‌ها (پایه × رشته) و ثبت سریع موارد برای دانش‌آموزان همان کلاس.
       معلم بدون این پرمیشن به فهرست کامل دانش‌آموزان (صفحهٔ دانش‌آموزان) دسترسی ندارد. */
    'teacher_panel'       => ['label' => 'پنل معلم (کلاس‌ها)',         'group' => 'پنل معلم'],
    'export_pdf'          => ['label' => 'خروجی PDF / چاپ',           'group' => 'گزارش‌ها'],
    'view_all_records'    => ['label' => 'مشاهده سوابق همه کاربران',   'group' => 'گزارش‌ها'],
    'manage_admins'       => ['label' => 'مدیریت ادمین‌ها و پرمیشن‌ها', 'group' => 'مدیریت سیستم'],
]);

/* ساعت‌های زنگ مدرسه (برای پیش‌فرض ساعت تأخیر) */
define('HS_SCHOOL_START', '07:30');

/**
 * روزهای تعطیل هفتگی (پیش‌فرض: پنجشنبه و جمعه)
 * مبنای شمردن: شنبه=۰ ، یک‌شنبه=۱ ، دوشنبه=۲ ، سه‌شنبه=۳ ، چهارشنبه=۴ ، پنجشنبه=۵ ، جمعه=۶
 * این مقدار از تنظیمات سامانه (جدول settings) خوانده می‌شود و مدیر می‌تواند آن را تغییر دهد.
 */
define('HS_OFF_WEEKDAYS_DEFAULT', [5, 6]);

/**
 * زنگ‌های کلاسی پیش‌فرض (ساعت شروع هر زنگ)
 * دانش‌آموز می‌تواند در چند زنگِ یک روز تأخیر داشته باشد؛ هر زنگ یک رکورد جدا است.
 */
define('HS_BELLS_DEFAULT', [
    ['name' => 'زنگ اول',  'time' => '07:45'],
    ['name' => 'زنگ دوم',  'time' => '08:35'],
    ['name' => 'زنگ سوم',  'time' => '09:45'],
    ['name' => 'زنگ چهارم','time' => '10:35'],
]);

/* ---------------------------------------------------------------------------
 * ۲) بارگذاری پیکربندی (config.php توسط install.php ساخته می‌شود)
 * ------------------------------------------------------------------------ */

/**
 * مقادیر پیش‌فرض پیکربندی پایگاه داده
 */
function hs_config_defaults(): array
{
    return [
        'driver'    => 'sqlite',                       // mysql | sqlite
        'host'      => 'localhost',
        'port'      => 3306,
        'database'  => __DIR__ . '/data/honarestan.sqlite',
        'username'  => 'root',
        'password'  => '',
        'charset'   => 'utf8mb4',
        'installed' => false,
    ];
}

/**
 * قرار دادن پیکربندی در حافظه (کاربرد: نصب‌کننده خودکار پس از نوشتن config.php)
 * این تابع از مشکل «کش OPcache و خواندن نسخه قدیمی فایل» جلوگیری می‌کند.
 */
function hs_config_override(array $config): void
{
    $GLOBALS['__HS_CONFIG_OVERRIDE'] = $config;
    hs_config(true);       // پاک کردن کش داخلی
    db(true);              // بستن اتصال قبلی تا با پیکربندی تازه وصل شود
}

/**
 * خواندن پیکربندی پایگاه داده
 * اگر فایل config.php وجود نداشته باشد، مقادیر پیش‌فرض دموی SQLite برگردانده می‌شود
 * تا کاربر بتواند صفحه نصب (install.php) را باز کند.
 */
function hs_config(bool $reload = false): array
{
    static $config = null;
    if ($reload) {
        $config = null;   // بارگذاری مجدد پس از نوشتن config.php توسط نصب‌کننده
    }
    if ($config !== null) {
        return $config;
    }

    /* اگر نصب‌کننده پیکربندی را در حافظه قرار داده باشد، همان مبنا است
       (این کار از خواندن نسخه قدیمی فایل به‌دلیل کش OPcache جلوگیری می‌کند) */
    if (!empty($GLOBALS['__HS_CONFIG_OVERRIDE']) && is_array($GLOBALS['__HS_CONFIG_OVERRIDE'])) {
        $config = array_merge(hs_config_defaults(), $GLOBALS['__HS_CONFIG_OVERRIDE']);
        return $config;
    }

    $defaults = hs_config_defaults();

    if (is_file(HS_CONFIG_FILE)) {
        /** @noinspection PhpIncludeInspection */
        $user = include HS_CONFIG_FILE;
        if (is_array($user)) {
            $config = array_merge($defaults, $user);
            return $config;
        }
    }
    $config = $defaults;
    return $config;
}

/** آیا سامانه نصب شده است؟ */
function hs_is_installed(): bool
{
    $c = hs_config();
    return !empty($c['installed']) && is_file(HS_LOCK_FILE);
}

/* ---------------------------------------------------------------------------
 * ۳) اتصال به پایگاه داده و لایه دسترسی به داده (Data Access Layer)
 * ------------------------------------------------------------------------ */

/**
 * ساخت (و بازگرداندن) اتصال PDO به‌صورت Singleton
 */
function db(bool $reload = false): PDO
{
    static $pdo = null;
    if ($reload) {
        $pdo = null;      // اتصال مجدد با پیکربندی تازه
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = hs_config();
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        if ($c['driver'] === 'sqlite') {
            $path = (string)$c['database'];
            if (!is_dir(dirname($path))) {
                @mkdir(dirname($path), 0777, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, $options);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'],
                (int)$c['port'],
                $c['database'],
                $c['charset'] ?: 'utf8mb4'
            );
            $pdo = new PDO($dsn, (string)$c['username'], (string)$c['password'], $options);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        $msg = 'اتصال به پایگاه داده برقرار نشد. لطفاً ابتدا فایل install.php را اجرا کنید یا پیکربندی config.php را بررسی کنید.';
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
           . '<title>خطای پایگاه داده</title></head><body style="font-family:Tahoma,sans-serif;background:#f4f6fa;padding:40px">'
           . '<div style="max-width:680px;margin:auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:28px">'
           . '<h2 style="color:#b91c1c;margin-top:0">اتصال به پایگاه داده برقرار نشد</h2>'
           . '<p style="line-height:2">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
           . '<pre style="direction:ltr;text-align:left;background:#0f172a;color:#e2e8f0;padding:14px;border-radius:10px;overflow:auto">'
           . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>'
           . '<p><a href="install.php" style="color:#2f6fed;font-weight:bold">رفتن به صفحه نصب</a></p>'
           . '</div></body></html>';
        exit;
    }
    return $pdo;
}

/** اجرای کوئری با Prepared Statement و بازگرداندن Statement */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/* همگام‌سازی خودکار پرمیشن‌ها پس از نخستین اتصال (بدون هزینهٔ اضافه در هر کوئری) */
function hs_bootstrap(): void
{
    static $done = false;
    if ($done || !hs_is_installed()) {
        return;
    }
    $done = true;
    try {
        hs_sync_permissions();          // پرمیشن‌های جدید را به جدول اضافه می‌کند
        hs_ensure_record_columns();      // ستون‌های تازهٔ غیبت (کلاس/درس و زنگ) در نصب‌های قدیمی
        hs_teacher_scope_migrate();      // یک‌بار: معلم از «همهٔ دانش‌آموزان» به «پنل کلاس‌ها» منتقل می‌شود
    } catch (Throwable $e) {
        // نصب ناقص یا جدول تنظیمات موجود نیست → بی‌صدا رد شود
    }
}

/* ---------------------------------------------------------------------------
 * ۵.۵) مهاجرت‌های سازگاری (بدون نیاز به نصب مجدد)
 * ------------------------------------------------------------------------ */

/** افزودن یک ستون به جدول، اگر وجود نداشته باشد (SQLite و MySQL) */
function hs_ensure_column(string $table, string $column, string $definition): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $pdo    = db();
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $c) {
                if (strcasecmp((string)$c['name'], $column) === 0) {
                    return $cache[$key] = true;
                }
            }
            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        } else {
            $exists = (int)one(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            );
            if ($exists > 0) {
                return $cache[$key] = true;
            }
            $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
        }
        return $cache[$key] = true;
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

/** ستون‌های تازهٔ غیبت: کلاس/درس و زنگ (برای پنل معلم) */
function hs_ensure_record_columns(): void
{
    hs_ensure_column('absences', 'lesson', 'VARCHAR(120) NULL');
    hs_ensure_column('absences', 'time',   'VARCHAR(5) NULL');
}

/**
 * جداسازی دسترسی معلم (یک‌بار، هنگام به‌روزرسانی نسخه):
 *   معلم به‌جای «مشاهدهٔ همهٔ دانش‌آموزان»، پرمیشن «پنل معلم (کلاس‌ها)» می‌گیرد
 *   و دسترسی مدیریت دانش‌آموزان (افزودن/ویرایش/حذف) از او گرفته می‌شود.
 *   مدیر کل هر زمان می‌تواند از صفحهٔ «مدیریت ادمین‌ها» این پرمیشن‌ها را برگرداند.
 */
function hs_teacher_scope_migrate(): void
{
    if (hs_setting('teacher_scope_migrated') === '1') {
        return;
    }
    try {
        $teachers = rows("SELECT id FROM admins WHERE role = 'teacher' AND is_super = 0");
        $panelId  = one('SELECT id FROM permissions WHERE name = ?', ['teacher_panel']);
        $strip    = ['view_students', 'add_student', 'edit_student', 'delete_student'];
        foreach ($teachers as $t) {
            $id = (int)$t['id'];
            foreach ($strip as $permName) {
                $pid = one('SELECT id FROM permissions WHERE name = ?', [$permName]);
                if ($pid) {
                    q('DELETE FROM admin_permissions WHERE admin_id = ? AND permission_id = ?', [$id, (int)$pid]);
                }
            }
            if ($panelId) {
                try {
                    db_insert('admin_permissions', ['admin_id' => $id, 'permission_id' => (int)$panelId]);
                } catch (Throwable $e) {
                    // از قبل وجود داشته است
                }
            }
        }
    } catch (Throwable $e) {
        // جدول‌ها آماده نیستند → در اجرای بعدی دوباره تلاش می‌شود
    }
    hs_setting_set('teacher_scope_migrated', '1');
}

/** دریافت همه سطرها */
function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** دریافت یک سطر */
function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

/** دریافت یک مقدار تک (سلول) — استفاده از تابع خودتعریف SQLite/MySQL سازگار است */
function one(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** درج سطر و بازگرداندن شناسه */
function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $ph   = array_map(static fn($c) => ':' . $c, $cols);
    $sql  = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')';
    $params = [];
    foreach ($data as $k => $v) {
        $params[':' . $k] = $v;
    }
    q($sql, $params);
    return (int)db()->lastInsertId();
}

/** به‌روزرسانی سطرها بر اساس شرط ساده (=) */
function db_update(string $table, array $data, array $where): int
{
    $set = [];
    $params = [];
    foreach ($data as $k => $v) {
        $set[] = $k . ' = :s_' . $k;
        $params[':s_' . $k] = $v;
    }
    $cond = [];
    foreach ($where as $k => $v) {
        $cond[] = $k . ' = :w_' . $k;
        $params[':w_' . $k] = $v;
    }
    $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE ' . implode(' AND ', $cond);
    return q($sql, $params)->rowCount();
}

/** حذف سطر(ها) بر اساس شرط ساده (=) */
function db_delete(string $table, array $where): int
{
    $cond = [];
    $params = [];
    foreach ($where as $k => $v) {
        $cond[] = $k . ' = :w_' . $k;
        $params[':w_' . $k] = $v;
    }
    return q('DELETE FROM ' . $table . ' WHERE ' . implode(' AND ', $cond), $params)->rowCount();
}

/**
 * ساخت SQL مستقل از نوع پایگاه داده برای الحاق ستون سال/ماه
 * (در این پروژه تاریخ‌ها به‌صورت رشته شمسی «YYYY/MM/DD» ذخیره می‌شوند)
 */
function db_driver_name(): string
{
    return (string)hs_config()['driver'];
}

/* ---------------------------------------------------------------------------
 * ۴) لایه امنیت: Session، CSRF، Sanitize، RBAC، Rate Limiting
 * ------------------------------------------------------------------------ */

/** راه‌اندازی نشست امن (فقط یک‌بار) */
function hs_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('HONARESTAN_SID');
    session_start();

    /* جلوگیری از Session Fixation */
    if (empty($_SESSION['_init'])) {
        session_regenerate_id(true);
        $_SESSION['_init']   = time();
        $_SESSION['_user_ip'] = hs_client_ip();
    }
}

/** IP کاربر (با احتیاط نسبت به هدرهای پروکسی) */
function hs_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/** پاک‌سازی خروجی (جلوگیری از XSS) */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** پاک‌سازی ورودی متنی */
function clean_text($value, int $maxLen = 255): string
{
    $value = is_scalar($value) ? (string)$value : '';
    $value = str_replace(["\0", "\r"], '', $value);
    $value = trim(preg_replace('/[ \t]+/u', ' ', $value) ?? '');
    $value = strip_tags($value);
    if ($maxLen > 0 && mb_strlen($value, 'UTF-8') > $maxLen) {
        $value = mb_substr($value, 0, $maxLen, 'UTF-8');
    }
    return $value;
}

/** تبدیل ارقام فارسی/عربی به لاتین و حذف جداکننده‌ها */
function fa_to_en_digits(string $value): string
{
    $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($fa, $en, $value);
}

/** نمایش عدد با ارقام فارسی */
function fa_num($value): string
{
    $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    return str_replace($en, $fa, (string)$value);
}

/* ------------------------------- CSRF ----------------------------------- */

/** توکن CSRF جاری نشست */
function csrf_token(): string
{
    hs_session_start();
    if (empty($_SESSION['csrf_token']) || (int)($_SESSION['csrf_time'] ?? 0) < time() - 7200) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_time']  = time();
    }
    return $_SESSION['csrf_token'];
}

/** فیلد مخفی CSRF برای فرم‌ها */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** بررسی صحت توکن (hash_equals برای مقاومت در برابر Timing Attack) */
function csrf_verify(?string $token): bool
{
    hs_session_start();
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals((string)$_SESSION['csrf_token'], (string)$token);
}

/** اجبار به معتبر بودن CSRF؛ در غیر این صورت خطا */
function csrf_require(): void
{
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!csrf_verify(is_string($token) ? $token : null)) {
        hs_json_or_die('توکن امنیتی نامعتبر است. لطفاً صفحه را دوباره بارگذاری کنید.', 419);
    }
}

/* ------------------------------- Auth ----------------------------------- */

/** ادمین جاری (از نشست) */
function current_admin(): ?array
{
    hs_session_start();
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    static $cache = null;
    if ($cache !== null && (int)$cache['id'] === (int)$_SESSION['admin_id']) {
        return $cache;
    }
    $admin = row('SELECT * FROM admins WHERE id = ? AND is_active = 1', [(int)$_SESSION['admin_id']]);
    if (!$admin) {
        hs_logout();
        return null;
    }
    $cache = $admin;
    return $admin;
}

/** خروج از سامانه */
function hs_logout(): void
{
    hs_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}

/** آیا کاربر وارد شده است؟ */
function hs_logged_in(): bool
{
    return current_admin() !== null;
}

/** آیا کاربر مدیر کل است؟ */
function is_super(): bool
{
    $a = current_admin();
    return $a !== null && (int)$a['is_super'] === 1;
}

/** لیست پرمیشن‌های ادمین جاری */
function admin_permissions(?int $adminId = null): array
{
    static $cache = [];
    $admin = current_admin();
    if ($admin === null) {
        return [];
    }
    $adminId = $adminId ?? (int)$admin['id'];
    if (isset($cache[$adminId])) {
        return $cache[$adminId];
    }
    $names = array_column(
        rows(
            'SELECT p.name FROM admin_permissions ap
             JOIN permissions p ON p.id = ap.permission_id
             WHERE ap.admin_id = ?',
            [$adminId]
        ),
        'name'
    );
    $cache[$adminId] = $names;
    return $names;
}

/** بررسی داشتن یک پرمیشن مشخص */
function has_perm(string $permission): bool
{
    if (is_super()) {
        return true;   // مدیر کل بدون محدودیت
    }
    $admin = current_admin();
    if ($admin === null) {
        return false;
    }
    if ($permission === 'manage_admins') {
        // مدیریت ادمین‌ها (ویرایش/مشاهده) صرفاً با پرمیشن صریح
        return in_array('manage_admins', admin_permissions(), true);
    }
    return in_array($permission, admin_permissions(), true);
}

/** اجبار ورود؛ در غیر این صورت هدایت به صفحه ورود */
function require_login(): void
{
    if (!hs_logged_in()) {
        hs_session_start();
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: index.php?err=login_required');
        exit;
    }
}

/** اجبار داشتن پرمیشن؛ در غیر این صورت صفحه ۴۰۳ فارسی */
function require_perm(string $permission): void
{
    require_login();
    if (!has_perm($permission)) {
        hs_forbidden($permission);
    }
}

/** صفحه ۴۰۳ با طراحی یکپارچه */
function hs_forbidden(string $permission = ''): void
{
    http_response_code(403);
    $label = HS_PERMISSIONS[$permission]['label'] ?? $permission;
    $back  = e($_SERVER['HTTP_REFERER'] ?? 'index.php');
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>عدم دسترسی</title></head>'
       . '<body style="margin:0;font-family:Tahoma,\'Vazirmatn\',sans-serif;background:#f4f6fa;display:flex;'
       . 'align-items:center;justify-content:center;min-height:100vh">'
       . '<div style="max-width:560px;background:#fff;border-radius:18px;padding:36px;text-align:center;'
       . 'box-shadow:0 18px 50px rgba(15,42,82,.12);border-top:6px solid #dc2626">'
       . '<div style="color:#dc2626">' . hs_icon('lock', 52) . '</div><h2 style="color:#0f2a52;margin:8px 0">دسترسی غیرمجاز</h2>'
       . '<p style="color:#475569;line-height:2">شما پرمیشن لازم برای این عملیات را ندارید:<br>'
       . '<b style="color:#dc2626">' . e($label) . '</b></p>'
       . '<a href="index.php" style="display:inline-block;margin-top:10px;background:#0f2a52;color:#fff;'
       . 'text-decoration:none;padding:12px 22px;border-radius:12px;font-weight:bold">بازگشت به داشبورد</a>'
       . '<p style="margin-top:16px"><a href="' . $back . '" style="color:#2f6fed">بازگشت به صفحه قبل</a></p>'
       . '</div></body></html>';
    exit;
}

/** پاسخ JSON یا درخواست غیر AJAX → صفحه خطای فارسی */
function hs_json_or_die(string $message, int $code = 400): void
{
    http_response_code($code);
    $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
        || (($_POST['ajax'] ?? '') === '1');
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>خطا</title></head>'
       . '<body style="font-family:Tahoma,sans-serif;background:#f4f6fa;display:flex;align-items:center;'
       . 'justify-content:center;min-height:100vh;margin:0">'
       . '<div style="background:#fff;padding:30px 36px;border-radius:16px;box-shadow:0 14px 40px rgba(15,42,82,.12);'
       . 'border-top:6px solid #f59e0b;max-width:520px;text-align:center">'
       . '<div style="color:#d97706">' . hs_icon('alert', 46) . '</div><h3 style="color:#0f2a52">' . e($message) . '</h3>'
       . '<a href="index.php" style="display:inline-block;background:#0f2a52;color:#fff;padding:11px 20px;'
       . 'border-radius:11px;text-decoration:none;font-weight:bold">بازگشت</a></div></body></html>';
    exit;
}

/* ---------------------------- Rate Limiting ------------------------------ */

/**
 * بررسی محدودیت تلاش ورود (۵ تلاش ناموفق در ۱۰ دقیقه)
 * @return array{allowed:bool,remaining:int,wait:int}
 */
function login_rate_check(string $username): array
{
    $ip    = hs_client_ip();
    $since = date('Y-m-d H:i:s', time() - 600);   // پنجره ۱۰ دقیقه‌ای

    /* سیاست دوگانه: محدودیت سخت برای هر نام کاربری و محدودیت کلی برای هر IP
       (تا کاربران پشت NAT مشترک با چند خطای یک کاربر دیگر مسدود نشوند) */
    $byUser = (int)one(
        'SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at >= ? AND username = ?',
        [$since, $username]
    );
    $byIp = (int)one(
        'SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at >= ? AND ip = ?',
        [$since, $ip]
    );

    $maxUser = 5;
    $maxIp   = 20;
    return [
        'allowed'   => ($byUser < $maxUser) && ($byIp < $maxIp),
        'remaining' => max(0, $maxUser - $byUser),
        'wait'      => 600,
    ];
}

/** ثبت تلاش ورود */
function login_attempt_log(string $username, bool $success): void
{
    db_insert('login_attempts', [
        'username'   => mb_substr($username, 0, 100, 'UTF-8'),
        'ip'         => hs_client_ip(),
        'success'    => $success ? 1 : 0,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    /* پاک‌سازی رکوردهای قدیمی‌تر از ۳ روز برای سبک ماندن جدول */
    if (random_int(1, 20) === 7) {
        q('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 259200)]);
    }
}

/** ثبت رخداد در گزارش فعالیت‌ها */
function log_activity(string $action, string $detail = ''): void
{
    $admin = current_admin();
    try {
        db_insert('activity_log', [
            'admin_id'   => $admin['id'] ?? null,
            'action'     => mb_substr($action, 0, 120, 'UTF-8'),
            'detail'     => mb_substr($detail, 0, 500, 'UTF-8'),
            'ip'         => hs_client_ip(),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // ثبت لاگ نباید جریان اصلی برنامه را متوقف کند
    }
}

/* ---------------------------- پیام‌های Flash ----------------------------- */

function flash_set(string $type, string $message): void
{
    hs_session_start();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** @return array<int,array{type:string,message:string}> */
function flash_take(): array
{
    hs_session_start();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : [];
}

/* ---------------------------------------------------------------------------
 * ۵) تقویم شمسی (Jalali) + تاریخ/ساعت با API معتبر
 * ------------------------------------------------------------------------ */

/**
 * تبدیل تاریخ میلادی به شمسی (الگوریتم دقیق ۳۳ ساله)
 * @return array{0:int,1:int,2:int} [سال، ماه، روز]
 */
function gregorian_to_jalali(int $gy, int $gm, int $gd): array
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2   = ($gm > 2) ? ($gy + 1) : $gy;
    $days  = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
           + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy    = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy   += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy   += intdiv($days - 1, 365);
        $days  = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

/**
 * تبدیل تاریخ شمسی به میلادی
 * @return array{0:int,1:int,2:int} [سال، ماه، روز]
 */
function jalali_to_gregorian(int $jy, int $jm, int $jd): array
{
    $jy  += 1595;
    $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4)
          + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy   = 400 * intdiv($days, 146097);
    $days %= 146097;
    if ($days > 36524) {
        $gy  += 100 * intdiv(--$days, 36524);
        $days %= 36524;
        if ($days >= 365) {
            $days++;
        }
    }
    $gy  += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy  += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $leap = (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0);
    $salA = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $gm = 0;
    while ($gm < 13 && $gd > $salA[$gm]) {
        $gd -= $salA[$gm];
        $gm++;
    }
    return [$gy, $gm, $gd];
}

/** تعداد روزهای یک ماه شمسی (با اعتبارسنجی واقعی سال کبیسه) */
function jalali_month_days(int $jy, int $jm): int
{
    if ($jm <= 6) {
        return 31;
    }
    if ($jm <= 11) {
        return 30;
    }
    // ماه اسفند: بررسی کبیسه بودن با تست رفت‌وبرگشت تبدیل
    [$gy, $gm, $gd] = jalali_to_gregorian($jy, 12, 30);
    [$ry, $rm, $rd] = gregorian_to_jalali($gy, $gm, $gd);
    return ($ry === $jy && $rm === 12 && $rd === 30) ? 30 : 29;
}

/** نام ماه‌های شمسی */
function jalali_months(): array
{
    return ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
            'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
}

/** نام روزهای هفته شمسی (شنبه = ۰) */
function jalali_weekdays(): array
{
    return ['شنبه', 'یک‌شنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه'];
}

/** نرمال‌سازی رشته تاریخ شمسی به قالب YYYY/MM/DD (پذیرش ارقام فارسی و جداکننده‌های -) */
function normalize_jalali(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $v = fa_to_en_digits(trim($value));
    $v = str_replace(['-', '.', '\\'], '/', $v);
    if (!preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $v, $m)) {
        return null;
    }
    [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    if ($mo < 1 || $mo > 12 || $d < 1 || $d > jalali_month_days($y, $mo)) {
        return null;
    }
    return sprintf('%04d/%02d/%02d', $y, $mo, $d);
}

/** اعتبارسنجی تاریخ شمسی */
function is_valid_jalali(?string $value): bool
{
    return normalize_jalali($value) !== null;
}

/** تبدیل تاریخ شمسی (YYYY/MM/DD) به میلادی (Y-m-d) */
function jalali_to_gregorian_str(string $jdate): string
{
    $n = normalize_jalali($jdate);
    if ($n === null) {
        return date('Y-m-d');
    }
    [$y, $m, $d] = array_map('intval', explode('/', $n));
    [$gy, $gm, $gd] = jalali_to_gregorian($y, $m, $d);
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/** تبدیل تاریخ میلادی (Y-m-d) به شمسی (YYYY/MM/DD) */
function gregorian_to_jalali_str(string $gdate): string
{
    $ts = strtotime($gdate) ?: time();
    [$jy, $jm, $jd] = gregorian_to_jalali((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

/** بازه میلادی یک تاریخ شمسی برای مرتب‌سازی/فیلتر (شروع و پایان روز) */
function jalali_day_bounds(string $jdate): array
{
    $g = jalali_to_gregorian_str($jdate);
    return [$g . ' 00:00:00', $g . ' 23:59:59'];
}

/** نام روز هفته از تاریخ شمسی */
function jalali_weekday_name(string $jdate): string
{
    $g   = jalali_to_gregorian_str($jdate);
    $dow = (int)date('w', strtotime($g));           // 0=یک‌شنبه ... 6=شنبه
    $map = [6 => 'شنبه', 0 => 'یک‌شنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنج‌شنبه', 5 => 'جمعه'];
    return $map[$dow] ?? '';
}

/** شماره روز هفته شمسی از تاریخ شمسی (شنبه=۰ ... جمعه=۶) */
function jalali_weekday_index(string $jdate): int
{
    $g   = jalali_to_gregorian_str($jdate);
    $dow = (int)date('w', strtotime($g));
    return ($dow + 1) % 7;
}

/** نمایش زیبای تاریخ شمسی: ۱۴۰۵/۰۱/۰۱ */
function jalali_pretty(?string $jdate, bool $faDigits = true): string
{
    $n = normalize_jalali($jdate);
    if ($n === null) {
        return '—';
    }
    return $faDigits ? fa_num($n) : $n;
}

/** نمایش تاریخ + روز هفته: ۱۴۰۵/۰۱/۰۱ (شنبه) */
function jalali_full(?string $jdate): string
{
    $n = normalize_jalali($jdate);
    if ($n === null) {
        return '—';
    }
    return jalali_pretty($n) . ' (' . jalali_weekday_name($n) . ')';
}

/** نمایش ماه شمسی به شکل «فروردین ۱۴۰۵» */
function jalali_month_label(string $jdate): string
{
    $n = normalize_jalali($jdate);
    if ($n === null) {
        return '—';
    }
    [$y, $m] = array_map('intval', explode('/', $n));
    return (jalali_months()[$m - 1] ?? '') . ' ' . fa_num($y);
}

/** امروز بر اساس تاریخ محلی سرور (تهران) — فرمت شمسی */
function jalali_today(): string
{
    [$y, $m, $d] = gregorian_to_jalali((int)date('Y'), (int)date('n'), (int)date('j'));
    return sprintf('%04d/%02d/%02d', $y, $m, $d);
}

/** تاریخ شمسی d روز قبل/بعد از یک تاریخ */
function jalali_add_days(string $jdate, int $days): string
{
    $g = jalali_to_gregorian_str($jdate);
    $ts = strtotime($g . ' +' . $days . ' day');
    return gregorian_to_jalali_str(date('Y-m-d', $ts ?: time()));
}

/** فاصله روزهای بین دو تاریخ شمسی (روز ۱ = از تاریخ شروع) */
function jalali_days_between(string $from, string $to): int
{
    $a = strtotime(jalali_to_gregorian_str($from));
    $b = strtotime(jalali_to_gregorian_str($to));
    if ($a === false || $b === false) {
        return 0;
    }
    return (int)floor(($b - $a) / 86400) + 1;
}

/**
 * فهرست روزهای بین دو تاریخ شمسی
 * @return string[] نمونه: ['1405/01/01','1405/01/02', ...]
 */
function jalali_date_range(string $from, string $to, int $maxDays = 93): array
{
    $from = normalize_jalali($from) ?? jalali_today();
    $to   = normalize_jalali($to) ?? $from;
    if (strcmp($to, $from) < 0) {
        [$from, $to] = [$to, $from];
    }
    $out  = [];
    $cur  = $from;
    $guard = 0;
    while (strcmp($cur, $to) <= 0 && $guard < $maxDays) {
        $out[] = $cur;
        $cur = jalali_add_days($cur, 1);
        $guard++;
    }
    return $out;
}

/** ابتدا و انتهای هفته شمسی جاری (شنبه تا جمعه) به‌صورت میلادی */
function jalali_current_week(): array
{
    $todayJ = jalali_today();
    $idx    = jalali_weekday_index($todayJ);           // شنبه=۰
    $satJ   = jalali_add_days($todayJ, -$idx);
    $friJ   = jalali_add_days($satJ, 6);
    return [
        'from_j' => $satJ,
        'to_j'   => $friJ,
        'from_g' => jalali_to_gregorian_str($satJ),
        'to_g'   => jalali_to_gregorian_str($friJ),
    ];
}

/* --------------------- تاریخ/ساعت از API معتبر (با کش) ------------------- */

/**
 * دریافت تاریخ و ساعت جاری از API معتبر (با کش فایلی ۱۵ دقیقه‌ای و بازگشت خودکار
 * به ساعت سیستم در صورت قطع اینترنت). ساختار بازگشتی:
 * ['jdate'=>'1405/01/01','time'=>'14:30','weekday'=>'شنبه','source'=>'api|local']
 */
function hs_now(bool $forceRefresh = false): array
{
    hs_session_start();
    $now     = time();
    $cacheTtl = 900;                                  // ۱۵ دقیقه
    $cacheFile = __DIR__ . '/data/time_cache.json';

    $local = static function () use ($now): array {
        $j = jalali_today();
        return [
            'jdate'   => $j,
            'time'    => date('H:i', $now),
            'second'  => (int)date('s', $now),
            'weekday' => jalali_weekday_name($j),
            'gdate'   => date('Y-m-d', $now),
            'source'  => 'local',
            'ts'      => $now,
        ];
    };

    /* ۱) کش فایل */
    if (!$forceRefresh && is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        $c   = $raw ? json_decode($raw, true) : null;
        if (is_array($c) && isset($c['ts'], $c['jdate']) && ($now - (int)$c['ts']) < $cacheTtl) {
            $passed = $now - (int)$c['ts'];
            return [
                'jdate'   => (string)$c['jdate'],
                'time'    => date('H:i', strtotime('today ' . $c['time'] . '') + $passed),
                'second'  => (int)date('s', strtotime('today ' . $c['time'] . '') + $passed),
                'weekday' => (string)($c['weekday'] ?? jalali_weekday_name((string)$c['jdate'])),
                'gdate'   => (string)($c['gdate'] ?? jalali_to_gregorian_str((string)$c['jdate'])),
                'source'  => 'api-cache',
                'ts'      => $now,
            ];
        }
    }

    /* ۲) تلاش برای دریافت از API معتبر (در صورت دسترسی شبکه) */
    if (function_exists('curl_init')) {
        $apis = [
            // سرویس زمان شمسی keybit.ir
            ['url' => 'https://api.keybit.ir/time/', 'type' => 'keybit'],
            // سرویس جهانی زمان (تهران)
            ['url' => 'https://worldtimeapi.org/api/timezone/Asia/Tehran', 'type' => 'worldtime'],
        ];
        foreach ($apis as $api) {
            $body = hs_http_get($api['url'], 3);
            if ($body === null) {
                continue;
            }
            $json = json_decode($body, true);
            if (!is_array($json)) {
                continue;
            }
            try {
                if ($api['type'] === 'keybit' && !empty($json['date']['jalali']['year'])) {
                    $jd = $json['date']['jalali'];
                    $jdate = sprintf(
                        '%04d/%02d/%02d',
                        (int)fa_to_en_digits((string)$jd['year']),
                        (int)fa_to_en_digits((string)$jd['month']['number']),
                        (int)fa_to_en_digits((string)$jd['day'])
                    );
                    $time = fa_to_en_digits((string)($json['time']['24']['hour'] ?? date('H'))
                        . ':' . (string)($json['time']['24']['minute'] ?? date('i')));
                    $data = [
                        'jdate'   => $jdate,
                        'time'    => $time,
                        'weekday' => (string)($json['date']['jalali']['weekday'] ?? jalali_weekday_name($jdate)),
                        'gdate'   => $json['date']['gregorian']['iso'] ?? jalali_to_gregorian_str($jdate),
                        'source'  => 'api',
                        'ts'      => $now,
                    ];
                } elseif ($api['type'] === 'worldtime' && !empty($json['datetime'])) {
                    $ts = strtotime((string)$json['datetime']) ?: $now;
                    $localTzTs = $ts + (int)date('Z', $ts) - (int)date('Z', $ts); // زمان تهران همانند سرور محاسبه می‌شود
                    $jdate = gregorian_to_jalali_str(date('Y-m-d', $localTzTs));
                    $data = [
                        'jdate'   => $jdate,
                        'time'    => date('H:i', $localTzTs),
                        'weekday' => jalali_weekday_name($jdate),
                        'gdate'   => date('Y-m-d', $localTzTs),
                        'source'  => 'api',
                        'ts'      => $now,
                    ];
                } else {
                    continue;
                }
                @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));
                @chmod($cacheFile, 0640);
                return $data;
            } catch (Throwable $e) {
                continue;
            }
        }
    }

    /* ۳) بازگشت به ساعت سیستم (همیشه تاریخ صحیح است؛ صحت ملاک است نه منبع) */
    $l = $local();
    @file_put_contents($cacheFile, json_encode(['jdate' => $l['jdate'], 'time' => $l['time'],
        'weekday' => $l['weekday'], 'gdate' => $l['gdate'], 'source' => 'local', 'ts' => $now], JSON_UNESCAPED_UNICODE));
    return $l;
}

/** درخواست GET با cURL و مهلت کوتاه (بدون بلوکه شدن صفحه) */
function hs_http_get(string $url, int $timeout = 3): ?string
{
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'HonarestanSMS/' . HS_VERSION,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            return null;
        }
        return (string)$body;
    } catch (Throwable $e) {
        return null;
    }
}

/* ---------------------------------------------------------------------------
 * ۵-۲) تنظیمات سامانه، روزهای تعطیل و دامنهٔ دسترسی به سوابق
 * ------------------------------------------------------------------------ */

/** خواندن یک تنظیم از جدول settings (با کش درون‌درخواستی) */
function hs_setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (rows('SELECT skey, sval FROM settings') as $r) {
                $cache[(string)$r['skey']] = $r['sval'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return array_key_exists($key, $cache) && $cache[$key] !== null ? (string)$cache[$key] : $default;
}

/** ذخیره یک تنظیم (ایجاد یا به‌روزرسانی) */
function hs_setting_set(string $key, string $value): void
{
    $exists = (int)one('SELECT COUNT(*) FROM settings WHERE skey = ?', [$key]);
    if ($exists) {
        db_update('settings', ['sval' => mb_substr($value, 0, 500, 'UTF-8')], ['skey' => $key]);
    } else {
        db_insert('settings', ['skey' => $key, 'sval' => mb_substr($value, 0, 500, 'UTF-8')]);
    }
}

/**
 * فهرست روزهای تعطیل هفتگی به‌صورت شمارهٔ روز (شنبه=۰ … جمعه=۶)
 * @return int[]
 */
function hs_off_weekdays(): array
{
    $raw = hs_setting('off_weekdays', implode(',', HS_OFF_WEEKDAYS_DEFAULT));
    $out = [];
    foreach (explode(',', (string)$raw) as $p) {
        $p = trim($p);
        if ($p !== '' && ctype_digit($p)) {
            $i = (int)$p;
            if ($i >= 0 && $i <= 6) {
                $out[] = $i;
            }
        }
    }
    sort($out);
    return $out ?: HS_OFF_WEEKDAYS_DEFAULT;
}

/** نام روزهای تعطیل هفتگی (مثال: ['پنجشنبه','جمعه']) */
function hs_off_weekday_names(): array
{
    $names = jalali_weekdays();
    $out = [];
    foreach (hs_off_weekdays() as $i) {
        $out[] = $names[$i] ?? '';
    }
    return array_values(array_filter($out));
}

/** آیا این تاریخ شمسی روز تعطیل هفتگی است؟ */
function hs_is_off_day(?string $jdate): bool
{
    $n = normalize_jalali($jdate);
    if ($n === null) {
        return false;
    }
    return in_array(jalali_weekday_index($n), hs_off_weekdays(), true);
}

/** آیا ثبت سوابق در روزهای تعطیل مجاز است؟ (تنظیم مدیر) */
function hs_allow_offday_records(): bool
{
    return hs_setting('allow_offday_records', '0') === '1';
}

/** پیام فارسی دلیل غیرمجاز بودن ثبت در روز تعطیل */
function hs_offday_message(string $jdate): string
{
    return 'روز ' . jalali_weekday_name($jdate) . ' (' . jalali_pretty($jdate) . ') تعطیل است؛ ثبت مورد در این روز مجاز نیست.'
         . ' اگر مدرسه در این روز دایر است، از بخش «مدیریت ادمین‌ها → تنظیمات روزهای تعطیل» اجازهٔ ثبت را فعال کنید.';
}

/** حذف روزهای تعطیل از فهرست تاریخ‌ها */
function hs_working_days(array $days): array
{
    return array_values(array_filter($days, static fn($d) => !hs_is_off_day($d)));
}

/** آخرین روز کاری (تعطیل نبودن) از تاریخ داده‌شده به عقب */
function hs_last_working_day(?string $jdate = null): string
{
    $d = normalize_jalali($jdate) ?? jalali_today();
    for ($i = 0; $i < 14; $i++) {
        if (!hs_is_off_day($d)) {
            return $d;
        }
        $d = jalali_add_days($d, -1);
    }
    return $d;
}

/** تاریخ پیش‌فرض برای فرم‌ها و نمایش‌ها (اگر امروز تعطیل باشد، آخرین روز کاری) */
function hs_default_work_date(): string
{
    return hs_is_off_day(jalali_today()) ? hs_last_working_day(jalali_today()) : jalali_today();
}

/** روزهای کاری هفتهٔ جاری شمسی (شنبه … جمعه، بدون روزهای تعطیل) */
function hs_working_week_days(): array
{
    $w = jalali_current_week();
    $all = [];
    $cur = $w['from_j'];
    for ($i = 0; $i < 7; $i++) {
        $all[] = $cur;
        $cur = jalali_add_days($cur, 1);
    }
    return hs_working_days($all);
}

/* ---------------------- دامنهٔ دسترسی به سوابق (ثبت‌کننده) ---------------- */

/**
 * آیا کاربر جاری مجاز است سوابق همهٔ ثبت‌کنندگان را ببیند؟
 * مدیر کل و دارندگان پرمیشن «مشاهده سوابق همه کاربران» → بله
 */
function hs_can_view_all(): bool
{
    static $c = null;
    if ($c === null) {
        $c = is_super() || (has_perm('view_all_records') && has_perm('export_pdf'));
    }
    return $c;
}

/**
 * شرط SQL فیلتر ثبت‌کننده بر پایهٔ سطح دسترسی
 * • کاربر معمولی: فقط سوابق خودش
 * • کاربر مجاز: همه، یا بر اساس پارامتر GET «by» (me / شناسه ادمین)
 * @return array{0:string,1:array} [شرط، پارامترها]
 */
function creator_filter(string $alias = ''): array
{
    $p = $alias !== '' ? $alias . '.' : '';
    $admin = current_admin();
    $by = clean_text(req('by'), 12);

    if (hs_can_view_all()) {
        if ($by === 'me' && $admin) {
            return [$p . 'created_by = ?', [(int)$admin['id']]];
        }
        if ($by !== '' && $by !== 'all' && ctype_digit($by)) {
            return [$p . 'created_by = ?', [(int)$by]];
        }
        return ['1=1', []];
    }
    return [$p . 'created_by = ?', [(int)($admin['id'] ?? 0)]];
}

/** برچسب دامنهٔ جاری برای نمایش در رابط کاربری */
function creator_scope_label(): string
{
    $by = clean_text(req('by'), 12);
    if (!hs_can_view_all()) {
        return 'فقط سوابق ثبت‌شده توسط شما';
    }
    if ($by === 'me') {
        return 'فقط سوابق خودم';
    }
    if (ctype_digit($by)) {
        $a = row('SELECT full_name, username FROM admins WHERE id = ?', [(int)$by]);
        return $a ? 'سوابق ثبت‌شده توسط: ' . $a['full_name'] : 'سوابق همهٔ ثبت‌کنندگان';
    }
    return 'سوابق همهٔ ثبت‌کنندگان';
}

/** فهرست کاربران برای فیلتر «ثبت‌کننده» (فقط برای کاربران مجاز) */
function creator_options(): array
{
    if (!hs_can_view_all()) {
        return [];
    }
    return rows('SELECT id, full_name, username, role FROM admins WHERE is_active = 1 ORDER BY is_super DESC, full_name ASC');
}

/** عنوان فارسی نقش‌ها */
function role_label(string $role): string
{
    $m = ['super' => 'مدیر کل', 'manager' => 'مدیر / مسئول', 'deputy' => 'معاون', 'teacher' => 'معلم', 'staff' => 'کارمند'];
    return $m[$role] ?? $role;
}

/**
 * همگام‌سازی پرمیشن‌های جدول با فهرست ثابت‌های کد
 * (اگر پرمیشن تازه‌ای به نسخه‌های بعدی اضافه شود، خودکار در همه نصب‌ها ساخته می‌شود)
 */
function hs_sync_permissions(): void
{
    $sig = md5(implode('|', array_keys(HS_PERMISSIONS)));
    if (hs_setting('perm_sig') === $sig) {
        return;
    }
    foreach (HS_PERMISSIONS as $name => $meta) {
        $ex = row('SELECT id FROM permissions WHERE name = ?', [$name]);
        if ($ex) {
            db_update('permissions', ['label' => $meta['label'], 'pgroup' => $meta['group']], ['id' => $ex['id']]);
        } else {
            try {
                db_insert('permissions', ['name' => $name, 'label' => $meta['label'], 'pgroup' => $meta['group']]);
            } catch (Throwable $e) {
                // در صورت خطای همزمانی، نادیده گرفته می‌شود
            }
        }
    }
    hs_setting_set('perm_sig', $sig);
}

/* ---------------------------------------------------------------------------
 * ۶) اعتبارسنجی داده‌ها
 * ------------------------------------------------------------------------ */

/** اعتبارسنجی نام/نام خانوادگی فارسی */
function validate_name(?string $name): ?string
{
    $name = clean_text($name, 60);
    if ($name === '' || mb_strlen($name, 'UTF-8') < 2) {
        return null;
    }
    // الف) ارقام (فارسی ۰-۹، عربی ٠-٩ و لاتین) در نام مجاز نیست
    if (preg_match('/[\x{06F0}-\x{06F9}\x{0660}-\x{0669}0-9]/u', $name)) {
        return null;
    }
    // ب) فقط حروف فارسی/عربی، فاصله، نیم‌فاصله، نقطه و خط تیره
    if (!preg_match('/^[\x{0600}-\x{06EF}\x{FB50}-\x{FDFF}\x{200C}\s\.\-]+$/u', $name)) {
        return null;
    }
    return $name;
}

/** اعتبارسنجی شماره تماس ۱۱ رقمی ایرانی (09xxxxxxxxx) */
function validate_phone(?string $phone): ?string
{
    $p = fa_to_en_digits((string)$phone);
    $p = preg_replace('/[\s\-\(\)\.]+/', '', $p) ?? '';
    $p = preg_replace('/^(\+98|0098|98)/', '0', $p) ?? $p;
    if ($p === '' || $p[0] !== '0') {
        $p = '0' . ltrim($p, '0');
    }
    return preg_match('/^09\d{9}$/', $p) ? $p : null;
}

/** اعتبارسنجی پایه */
function validate_grade(?string $grade): ?string
{
    $g = clean_text($grade, 20);
    $map = ['10' => 'دهم', '11' => 'یازدهم', '12' => 'دوازدهم',
            'دهم' => 'دهم', 'یازدهم' => 'یازدهم', 'دوازدهم' => 'دوازدهم'];
    return $map[$g] ?? null;
}

/**
 * فهرست زنگ‌های مدرسه (قابل تغییر در جدول settings با کلید bells)
 * قالب ذخیره: «07:45=زنگ اول,08:35=زنگ دوم»
 * @return array<int, array{name:string,time:string}>
 */
function hs_bells(): array
{
    $raw = hs_setting('bells', '');
    if ($raw !== null && trim($raw) !== '') {
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') { continue; }
            $bits = explode('=', $part, 2);
            $t = fa_to_en_digits(trim($bits[0]));
            if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $t)) { continue; }
            if (strlen($t) === 4) { $t = '0' . $t; }
            $out[] = ['name' => isset($bits[1]) && trim($bits[1]) !== '' ? trim($bits[1]) : ('زنگ ' . fa_num(count($out) + 1)), 'time' => $t];
        }
        if ($out) { return $out; }
    }
    return HS_BELLS_DEFAULT;
}

/** نام زنگ بر اساس ساعت (اگر ساعت با زنگی مطابق باشد) */
function hs_bell_name(?string $time): ?string
{
    if ($time === null || $time === '') { return null; }
    $t = mb_substr(fa_to_en_digits($time), 0, 5);
    if (strlen($t) === 4) { $t = '0' . $t; }
    foreach (hs_bells() as $b) {
        if ($b['time'] === $t) { return $b['name']; }
    }
    return null;
}

/** برچسب «زنگ … — ساعت …» برای نمایش در فهرست‌ها */
function hs_bell_label(?string $time): string
{
    $t = $time !== null && $time !== '' ? fa_num(mb_substr($time, 0, 5)) : '—';
    $b = hs_bell_name($time);
    return $b ? ($b . ' — ' . $t) : $t;
}

/**
 * بررسی مجاز بودن یک رشته برای یک پایه (قانون دامنه: کامپیوتر در دوازدهم وجود ندارد)
 */
function hs_field_ok(string $grade, string $field): bool
{
    return in_array($field, HS_FIELDS_BY_GRADE[$grade] ?? [], true);
}

/** فهرست شناسه‌های دانش‌آموز بر اساس فیلتر پایه/رشته (برای عملیات گروهی) */
function hs_student_ids_by(?string $grade = null, ?string $field = null, ?string $q = null): array
{
    $sql = 'SELECT id FROM students';
    $w = [];
    $p = [];
    if ($grade) { $w[] = 'grade = ?'; $p[] = $grade; }
    if ($field) { $w[] = 'field = ?'; $p[] = $field; }
    if ($q !== null && $q !== '') {
        $w[] = '(first_name LIKE ? OR last_name LIKE ? OR (first_name || " " || last_name) LIKE ?)';
        $p[] = '%' . $q . '%'; $p[] = '%' . $q . '%'; $p[] = '%' . $q . '%';
    }
    if ($w) { $sql .= ' WHERE ' . implode(' AND ', $w); }
    $sql .= ' ORDER BY grade, field, last_name, first_name';
    return array_map('intval', array_column(rows($sql, $p), 'id'));
}

/* ---------------------------------------------------------------------------
 * ۶.۵) پنل معلم — کلاس‌ها (پایه × رشته) و ثبت سریع موارد
 * ------------------------------------------------------------------------ */

/** فهرست دانش‌آموزان یک کلاس (پایه × رشته) */
function hs_class_students(string $grade, string $field): array
{
    return rows(
        'SELECT id, first_name, last_name, grade, field
         FROM students WHERE grade = ? AND field = ?
         ORDER BY last_name, first_name',
        [$grade, $field]
    );
}

/** شمار دانش‌آموزان هر رشته در یک پایه: [رشته => تعداد] */
function hs_class_counts(string $grade): array
{
    $out = [];
    foreach (rows('SELECT field, COUNT(*) AS c FROM students WHERE grade = ? GROUP BY field', [$grade]) as $r) {
        $out[(string)$r['field']] = (int)$r['c'];
    }
    return $out;
}

/** شمار دانش‌آموزان یک پایه */
function hs_grade_count(string $grade): int
{
    return (int)one('SELECT COUNT(*) FROM students WHERE grade = ?', [$grade]);
}

/** برچسب ستون‌های اضافی غیبت (کلاس/درس و زنگ) برای نمایش در فهرست‌ها */
function hs_absence_extra_label(?string $lesson, ?string $time): string
{
    $parts = [];
    if ($lesson !== null && trim($lesson) !== '') {
        $parts[] = 'کلاس/درس: ' . $lesson;
    }
    if ($time !== null && trim((string)$time) !== '') {
        $parts[] = 'زنگ: ' . hs_bell_label((string)$time);
    }
    return implode(' — ', $parts);
}

/**
 * ثبت یک مورد (تأخیر/غیبت/انضباطی/پوششی) با همهٔ قواعد سامانه.
 * خروجی: شناسهٔ رکورد ثبت‌شده یا null در صورت خطا (متن خطا در $err).
 *
 * قواعد مهم:
 *   • تأخیر: چند تأخیر در یک روز مجاز است؛ فقط «همان دانش‌آموز + همان روز + همان ساعت/زنگ» رد می‌شود.
 *   • غیبت: برای هر دانش‌آموز در هر روز یک رکورد (دلیل/کلاس‌درس/زنگ روی همان رکورد ثبت می‌شود).
 *   • روز تعطیل: به‌طور پیش‌فرض ثبت مجاز نیست (تنظیم مدیر کل).
 */
function hs_register_record(string $kind, int $studentId, string $jdate, array $d, ?string &$err = null): ?int
{
    $err = null;
    $permMap = [
        'delay'      => 'register_delay',
        'absence'    => 'register_absence',
        'discipline' => 'register_discipline',
        'dress'      => 'register_dress',
    ];
    $labels = ['delay' => 'تأخیر', 'absence' => 'غیبت', 'discipline' => 'مورد انضباطی', 'dress' => 'تخلف پوششی'];
    if (!isset($permMap[$kind])) {
        $err = 'نوع ثبت نامعتبر است.';
        return null;
    }
    if (!has_perm($permMap[$kind])) {
        $err = 'برای ثبت ' . $labels[$kind] . ' دسترسی ندارید (پرمیشن ' . $permMap[$kind] . ').';
        return null;
    }
    $student = row('SELECT * FROM students WHERE id = ?', [$studentId]);
    if (!$student) {
        $err = 'دانش‌آموز انتخاب‌شده یافت نشد.';
        return null;
    }
    $jdate = normalize_jalali($jdate);
    if ($jdate === null) {
        $err = 'تاریخ نامعتبر است؛ از تقویم شمسی انتخاب کنید.';
        return null;
    }
    if (!hs_allow_offday_records() && hs_is_off_day($jdate)) {
        $err = hs_offday_message($jdate);
        return null;
    }
    $me       = (int)(current_admin()['id'] ?? 0);
    $fullName = $student['first_name'] . ' ' . $student['last_name'];
    $base     = [
        'student_id' => (int)$student['id'],
        'jdate'      => $jdate,
        'gdate'      => jalali_to_gregorian_str($jdate),
        'created_by' => $me,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    /* ------------------------------- تأخیر ------------------------------- */
    if ($kind === 'delay') {
        $time = fa_to_en_digits(trim((string)($d['time'] ?? '')));
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time)) {
            $err = 'ساعت ورود به کلاس نامعتبر است (نمونهٔ درست: 07:45).';
            return null;
        }
        $time = strlen($time) === 4 ? '0' . $time : $time;
        $desc = clean_text($d['description'] ?? '', 255);
        $same = (int)one('SELECT COUNT(*) FROM delays WHERE student_id = ? AND jdate = ? AND time = ?',
                         [(int)$student['id'], $jdate, $time]);
        if ($same > 0) {
            $bell = hs_bell_name($time);
            $err  = 'برای این دانش‌آموز در ' . jalali_pretty($jdate) . ' و ساعت ' . fa_num($time)
                  . ($bell ? ' (' . $bell . ')' : '') . ' قبلاً تأخیر ثبت شده است؛ اگر زنگ دیگری است، ساعت/زنگ را عوض کنید.';
            return null;
        }
        $id = db_insert('delays', $base + ['time' => $time, 'description' => $desc !== '' ? $desc : null]);
        log_activity('ثبت تأخیر', $fullName . ' — ' . $jdate . ' ' . $time);
        return $id;
    }

    /* ------------------------------- غیبت ------------------------------- */
    if ($kind === 'absence') {
        $reason = clean_text($d['description'] ?? '', 255);
        if ($reason === '') {
            $err = 'دلیل غیبت را بنویسید (مثال: بیماری — با اطلاع والدین).';
            return null;
        }
        $lesson = clean_text($d['lesson'] ?? '', 120);
        $time   = fa_to_en_digits(trim((string)($d['time'] ?? '')));
        if ($time !== '' && !preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time)) {
            $err = 'زنگ/ساعت کلاس نامعتبر است (نمونهٔ درست: 09:45).';
            return null;
        }
        $time = $time === '' ? null : (strlen($time) === 4 ? '0' . $time : $time);
        if ((int)one('SELECT COUNT(*) FROM absences WHERE student_id = ? AND jdate = ?', [(int)$student['id'], $jdate]) > 0) {
            $err = 'برای این دانش‌آموز در ' . jalali_pretty($jdate) . ' قبلاً غیبت ثبت شده است (هر روز یک غیبت).';
            return null;
        }
        $id = db_insert('absences', $base + [
            'day_of_week' => jalali_weekday_name($jdate),
            'description' => $reason,
            'lesson'      => $lesson !== '' ? $lesson : null,
            'time'        => $time,
        ]);
        log_activity('ثبت غیبت', $fullName . ' — ' . $jdate . ' — ' . $reason);
        return $id;
    }

    /* -------------------------- مورد انضباطی ---------------------------- */
    if ($kind === 'discipline') {
        $type = clean_text($d['type'] ?? '', 60);
        $desc = clean_text($d['description'] ?? '', 500);
        $ded  = max(0, min(20, (int)fa_to_en_digits((string)($d['deduction'] ?? '0'))));
        if (mb_strlen($type, 'UTF-8') < 3) {
            $err = 'نوع مورد انضباطی را انتخاب یا وارد کنید.';
            return null;
        }
        if (mb_strlen($desc, 'UTF-8') < 3) {
            $err = 'توضیحات مورد انضباطی الزامی است (حداقل ۳ کاراکتر).';
            return null;
        }
        $id = db_insert('disciplines', $base + ['type' => $type, 'description' => $desc, 'deduction' => $ded]);
        log_activity('ثبت مورد انضباطی', $fullName . ' — ' . $type . ' — کسر: ' . $ded);
        return $id;
    }

    /* -------------------------- تخلف پوششی ------------------------------ */
    $type = clean_text($d['type'] ?? '', 30);
    $desc = clean_text($d['description'] ?? '', 255);
    if (!in_array($type, HS_DRESS_TYPES, true)) {
        $err = 'نوع تخلف پوششی نامعتبر است (لباس / شلوار / هر دو / سایر).';
        return null;
    }
    $id = db_insert('dress_violations', $base + ['type' => $type, 'description' => $desc !== '' ? $desc : null]);
    log_activity('ثبت تخلف پوششی', $fullName . ' — ' . $type);
    return $id;
}

/**
 * اعتبارسنجی رشته در محدوده پایه انتخاب‌شده
 * رشته «کامپیوتر» در پایه دوازدهم پذیرفته و نمایش داده نمی‌شود.
 */
function validate_field(?string $field, string $grade): ?string
{
    $f = clean_text($field, 40);
    $allowed = HS_FIELDS_BY_GRADE[$grade] ?? [];
    foreach ($allowed as $a) {
        if ($a === $f) {
            return $f;
        }
    }
    /* پذیرش مترادف‌های رایج */
    $aliases = [
        'کامپیوتر'      => ['کامپیوتر', 'رایانه', 'computer', 'it', 'شبکه و نرم‌افزار'],
        'الکتروتکنیک'   => ['الکتروتکنیک', 'برق', 'الکتروتکنیک (برق)'],
        'مکانیک'        => ['مکانیک', 'مکانیک خودرو', 'ساخت و تولید'],
        'بهداشتی'       => ['بهداشتی', 'بهداشت', 'پرستاری'],
        'مستقل'         => ['مستقل', 'هنرجوی مستقل', 'آزاد'],
        'سرمایش'        => ['سرمایش', 'تأسیسات', 'سرمایش و گرمایش', 'تاسیسات'],
    ];
    foreach ($allowed as $a) {
        foreach ($aliases[$a] ?? [$a] as $al) {
            if (mb_strtolower($al) === mb_strtolower($f)) {
                return $a;
            }
        }
    }
    return null;
}

/** رشته‌های مجاز یک پایه */
function fields_for_grade(string $grade): array
{
    return HS_FIELDS_BY_GRADE[$grade] ?? [];
}

/** لیست کامل رشته‌های موجود در سامانه */
function all_fields(): array
{
    $all = [];
    foreach (HS_FIELDS_BY_GRADE as $fields) {
        foreach ($fields as $f) {
            $all[$f] = true;
        }
    }
    return array_keys($all);
}

/* ---------------------------------------------------------------------------
 * ۷) ابزارهای عمومی (Pagination، مرتب‌سازی، تبدیل خروجی)
 * ------------------------------------------------------------------------ */

/** پارامتر امن از GET/POST */
function req(string $key, $default = null)
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_scalar($v) ? (string)$v : $default;
}

/** پارامتر عددی */
function req_int(string $key, int $default = 0): int
{
    $v = fa_to_en_digits((string)req($key, (string)$default));
    return (int)filter_var($v, FILTER_SANITIZE_NUMBER_INT);
}

/**
 * مرتب‌سازی امن ستون‌ها
 * @param array<string,string> $allowed نام نمایشی => ستون دیتابیس
 * @return array{0:string,1:string} [ستون، جهت]
 */
function safe_sort(?string $col, ?string $dir, array $allowed, string $defaultCol): array
{
    $dbCols = array_values($allowed);
    $col = $col !== null ? trim($col) : '';
    if ($col !== '' && in_array($col, $dbCols, true)) {
        $safeCol = $col;
    } else {
        $safeCol = $defaultCol;
    }
    $dir = strtolower((string)$dir) === 'asc' ? 'asc' : (strtolower((string)$dir) === 'desc' ? 'desc' : 'desc');
    return [$safeCol, $dir];
}

/** ساخت لینک با حفظ پارامترهای فعلی */
function url_with(array $overrides = [], ?string $base = null): string
{
    $base = $base ?? basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $params = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    $qs = http_build_query($params);
    return $base . ($qs !== '' ? '?' . $qs : '');
}

/** خروجی CSV با BOM برای پشتیبانی از فارسی در Excel */
function send_csv(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                      // BOM
    /* پارامتر escape صریحاً خالی داده می‌شود تا در PHP 8.4 هشدار Deprecated ایجاد نشود */
    fputcsv($out, $header, ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, array_map(static fn($v) => (string)$v, $r), ',', '"', '');
    }
    fclose($out);
    exit;
}

/** پاسخ JSON */
function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------------------------------------------------------------------------
 * ۸) توابع آمار و گزارش‌گیری مشترک
 * ------------------------------------------------------------------------ */

/** تعداد کل دانش‌آموزان (با فیلتر اختیاری) */
function count_students(array $filters = []): int
{
    $where = [];
    $params = [];
    if (!empty($filters['grade'])) {
        $where[] = 'grade = ?';
        $params[] = $filters['grade'];
    }
    if (!empty($filters['field'])) {
        $where[] = 'field = ?';
        $params[] = $filters['field'];
    }
    $sql = 'SELECT COUNT(*) FROM students' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    return (int)one($sql, $params);
}

/** آمار کارت‌های داشبورد */
function dashboard_stats(): array
{
    $todayJ = jalali_today();
    $week   = jalali_current_week();

    /* روزهای کاری هفته (پنجشنبه و جمعه شمرده نمی‌شوند) */
    $workWeek = hs_working_week_days();
    $wFrom    = $workWeek ? jalali_to_gregorian_str($workWeek[0]) : $week['from_g'];
    $wTo      = $workWeek ? jalali_to_gregorian_str($workWeek[count($workWeek) - 1]) : $week['to_g'];

    /* اگر امروز تعطیل باشد، آمار «امروز» روی آخرین روز کاری محاسبه می‌شود */
    $statDay  = hs_default_work_date();
    $isOffToday = hs_is_off_day($todayJ);

    /* دامنهٔ دسترسی: کاربر عادی فقط سوابق خودش، کاربر مجاز همه */
    [$fDelays, $pDelays]     = creator_filter('d');
    [$fAbs, $pAbs]           = creator_filter('a');
    [$fDisc, $pDisc]         = creator_filter('dc');
    [$fDress, $pDress]       = creator_filter('v');

    return [
        'students'         => (int)one('SELECT COUNT(*) FROM students'),
        'absences_today'   => (int)one("SELECT COUNT(*) FROM absences a WHERE a.jdate = ? AND {$fAbs}", array_merge([$statDay], $pAbs)),
        'delays_today'     => (int)one("SELECT COUNT(*) FROM delays d WHERE d.jdate = ? AND {$fDelays}", array_merge([$statDay], $pDelays)),
        'discipline_week'  => (int)one(
            "SELECT COUNT(*) FROM disciplines dc WHERE dc.gdate BETWEEN ? AND ? AND {$fDisc}",
            array_merge([$wFrom, $wTo], $pDisc)
        ),
        'dress_today'      => (int)one("SELECT COUNT(*) FROM dress_violations v WHERE v.jdate = ? AND {$fDress}", array_merge([$statDay], $pDress)),
        'students_grades'  => rows('SELECT grade, COUNT(*) AS c FROM students GROUP BY grade'),
        'students_fields'  => rows('SELECT field, COUNT(*) AS c FROM students GROUP BY field ORDER BY c DESC'),
        'week'             => $week,
        'week_working'     => $workWeek,
        'today'            => $todayJ,
        'stat_day'         => $statDay,
        'is_off_today'     => $isOffToday,
        'off_names'        => hs_off_weekday_names(),
        'scope_label'      => creator_scope_label(),
        'view_all'         => hs_can_view_all(),
    ];
}

/**
 * عبارت الحاق نام و نام خانوادگی — سازگار با هر دو موتور پایگاه داده
 * (در MySQL از CONCAT و در SQLite از عملگر || استفاده می‌شود)
 */
function sql_full_name(string $alias = ''): string
{
    $p = $alias !== '' ? $alias . '.' : '';
    if (db_driver_name() === 'mysql') {
        return "CONCAT($p" . 'first_name, \' \', ' . $p . "last_name)";
    }
    return "($p" . 'first_name || \' \' || ' . $p . 'last_name)';
}

/** رشته WHERE + پارامترها برای فیلتر دانش‌آموزان */
function students_where(array $f, array &$params): string
{
    $where = ['1=1'];
    $params = [];
    if (!empty($f['q'])) {
        $where[] = '(first_name LIKE ? OR last_name LIKE ? OR ' . sql_full_name() . ' LIKE ?)';
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $f['q']) . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($f['first_name'])) {
        $where[] = 'first_name LIKE ?';
        $params[] = '%' . $f['first_name'] . '%';
    }
    if (!empty($f['last_name'])) {
        $where[] = 'last_name LIKE ?';
        $params[] = '%' . $f['last_name'] . '%';
    }
    if (!empty($f['grade'])) {
        $where[] = 'grade = ?';
        $params[] = $f['grade'];
    }
    if (!empty($f['field'])) {
        $where[] = 'field = ?';
        $params[] = $f['field'];
    }
    return implode(' AND ', $where);
}

/** رتبه‌بندی هنرجویان پرتخلف (برای داشبورد) */
function top_offenders(int $limit = 5): array
{
    /* فقط روزهای کاری هفته جاری (بدون پنجشنبه و جمعه) و در دامنهٔ دسترسی کاربر */
    $work = hs_working_week_days();
    if (!$work) {
        return [];
    }
    $from = jalali_to_gregorian_str($work[0]);
    $to   = jalali_to_gregorian_str($work[count($work) - 1]);
    [$fD, $pD] = creator_filter('d');
    [$fA, $pA] = creator_filter('a');
    [$fC, $pC] = creator_filter('dc');

    return rows(
        "SELECT s.id, s.first_name, s.last_name, s.grade, s.field,
                (SELECT COUNT(*) FROM delays d WHERE d.student_id = s.id AND d.gdate BETWEEN ? AND ? AND {$fD}) AS delays_c,
                (SELECT COUNT(*) FROM absences a WHERE a.student_id = s.id AND a.gdate BETWEEN ? AND ? AND {$fA}) AS absences_c,
                (SELECT COUNT(*) FROM disciplines dc WHERE dc.student_id = s.id AND dc.gdate BETWEEN ? AND ? AND {$fC}) AS disc_c
         FROM students s
         ORDER BY (delays_c * 1 + absences_c * 3 + disc_c * 2) DESC
         LIMIT " . max(1, $limit),
        array_merge([$from, $to], $pD, [$from, $to], $pA, [$from, $to], $pC)
    );
}

/* ---------------------------------------------------------------------------
 * ۹) لایه قالب (Layout) — CSS و JS کاملاً Inline
 * ------------------------------------------------------------------------ */

/** CSS پایه سامانه (Design System) */
function theme_css(): string
{
    return <<<'CSS'
/* ===== طراحی پایه (Design Tokens) ===== */
:root{
  --navy:#0f2a52; --navy-2:#123c6b; --blue:#2f6fed; --blue-soft:#e8f0ff;
  /* سطوح سرمه‌ای تیره که همیشه متن سفید روی آن‌ها می‌نشیند (نوار کناری، هدر جدول، دکمهٔ اصلی…)
     این‌ها با تم عوض نمی‌شوند تا متن سفید در حالت شب هم خوانا بماند. */
  --navy-bg:#0f2a52; --navy-bg-2:#123c6b;
  --gray-50:#f5f7fb; --gray-100:#eef2f7; --gray-200:#e2e8f0; --gray-300:#cbd5e1;
  --gray-500:#64748b; --gray-700:#334155; --white:#fff;
  --green:#16a34a; --green-soft:#e7f8ee; --amber:#f59e0b; --amber-soft:#fef3c7;
  --red:#dc2626; --red-soft:#fee2e2; --purple:#7c3aed; --purple-soft:#f3e8ff;
  --on-tint:#0f2a52;         /* متن روی سطوح ملایم رنگی */
  --ink:#0f2a52;             /* سرمه‌ای اصلی قالب */
  --amber-line:#fde68a;      /* خط چیپ‌های زنگ */
  --amber-ink:#92400e;       /* متن چیپ‌های زنگ */
  --shadow:0 10px 30px rgba(15,42,82,.08); --shadow-lg:0 20px 60px rgba(15,42,82,.16);
  --radius:16px; --radius-sm:12px; --sidebar-w:264px; --transition:.25s cubic-bezier(.4,0,.2,1);
  --font:"Vazirmatn","Vazir",Tahoma,"Segoe UI",system-ui,sans-serif;
}
html[data-theme="dark"]{
  --navy:#e8eefb; --navy-2:#0e2748; --blue:#5b8def; --blue-soft:#16233b;
  --navy-bg:#0c1729; --navy-bg-2:#16233b;   /* سطح سرمه‌ای تیره در تم شب */
  --gray-50:#0d1526; --gray-100:#131d31; --gray-200:#1e2b45; --gray-300:#2a3a58;
  --gray-500:#94a3b8; --gray-700:#cbd5e1; --white:#111c30;
  --green-soft:#12291d; --amber-soft:#2b2310; --red-soft:#2c1414; --purple-soft:#221433;
  --shadow:0 10px 30px rgba(0,0,0,.35); --shadow-lg:0 20px 60px rgba(0,0,0,.5);
  /* رنگ متن روی سطوح رنگی (کارت‌های رنگی، هشدار، برچسب) */
  --on-tint:#e8eefb;        /* متن روی سطوح ملایم */
  --ink:#0a1a33;            /* سرمه‌ای اصلی برای سطح‌های روشن */
  --amber-line:#5a440f;     /* خط چیپ‌های زنگ در تم شب */
  --amber-ink:#fde68a;      /* متن چیپ‌های زنگ در تم شب */
}
*{box-sizing:border-box}
body{margin:0;background:var(--gray-50);color:var(--gray-700);font-family:var(--font);
     font-size:14.5px;line-height:1.9;direction:rtl;-webkit-font-smoothing:antialiased}
a{color:var(--blue);text-decoration:none}
h1,h2,h3,h4{margin:0 0 .6rem;color:var(--navy);font-weight:800;line-height:1.6}
html[data-theme="dark"] h1,html[data-theme="dark"] h2,html[data-theme="dark"] h3{color:#e8eefb}
::-webkit-scrollbar{width:9px;height:9px}
::-webkit-scrollbar-thumb{background:var(--gray-300);border-radius:8px}
::-webkit-scrollbar-track{background:transparent}

/* ===== چیدمان کلی ===== */
.app{display:flex;min-height:100vh}
.sidebar{width:var(--sidebar-w);background:linear-gradient(170deg,var(--navy-bg) 0%,var(--navy-bg-2) 100%);
  color:#dbe6f7;position:fixed;inset-block:0;inset-inline-start:0;display:flex;flex-direction:column;
  z-index:60;transition:transform var(--transition);box-shadow:4px 0 24px rgba(6,20,40,.18)}
.sidebar .brand{padding:20px 18px 14px;display:flex;gap:12px;align-items:center;border-bottom:1px solid rgba(255,255,255,.09)}
/* ===== آیکون‌های خطی (SVG درون‌خطی) ===== */
.hs-i{flex:none;vertical-align:-3px;display:inline-block}
.nav a .ic{display:inline-flex;align-items:center;justify-content:center;width:22px;color:inherit}
.nav a .ic .hs-i{opacity:.95}
.btn .hs-i,.tp-act .hs-i,.bell-chip .hs-i,.dent .hs-i{vertical-align:-4px;margin-inline-end:2px}
.mh-ic{display:inline-flex;color:var(--amber);align-items:center}
.hs-logo-ic{color:#fff}
.stat .icon .hs-i{width:24px;height:24px;color:inherit}
.brand .logo .hs-i{color:#fff}
.side-foot .u .hs-i{color:#9db4d6}
.q-ic{display:inline-flex;color:var(--blue-dark)}
.sidebar .brand .logo{width:46px;height:46px;border-radius:13px;background:linear-gradient(135deg,var(--blue),#22d3ee);
  display:grid;place-items:center;font-size:22px;flex:0 0 auto;box-shadow:0 8px 20px rgba(47,111,237,.4)}
.sidebar .brand b{display:block;color:#fff;font-size:15px;line-height:1.6}
.sidebar .brand small{color:#9db4d6;font-size:11.5px}
.nav{padding:14px 12px;overflow-y:auto;flex:1}
.nav .nav-title{font-size:11px;color:#8ea6c9;margin:14px 10px 6px;letter-spacing:.4px}
.nav a{display:flex;align-items:center;gap:11px;padding:11px 13px;border-radius:12px;color:#cfdDF2;
  color:#cfddf2;margin-bottom:5px;font-weight:600;transition:var(--transition)}
.nav a:hover{background:rgba(255,255,255,.08);color:#fff;transform:translateX(-3px)}
.nav a.active{background:linear-gradient(90deg,rgba(47,111,237,.95),rgba(47,111,237,.55));color:#fff;
  box-shadow:0 8px 20px rgba(47,111,237,.35)}
.nav a .ic{width:22px;text-align:center;font-size:16px}
.nav a .lock{margin-inline-start:auto;font-size:12px;opacity:.6}
.sidebar .side-foot{padding:14px;border-top:1px solid rgba(255,255,255,.09);font-size:12.5px;color:#9db4d6}
.sidebar .side-foot .u{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.avatar{width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.13);display:grid;place-items:center;
  font-weight:800;color:#fff;flex:0 0 auto}
.main{flex:1;margin-inline-start:var(--sidebar-w);display:flex;flex-direction:column;min-width:0}
.topbar{position:sticky;top:0;z-index:40;background:color-mix(in srgb,var(--white) 88%,transparent);
  backdrop-filter:blur(12px);border-bottom:1px solid var(--gray-200);padding:12px 22px;display:flex;
  align-items:center;gap:14px;flex-wrap:wrap}
.topbar .page-title{font-weight:800;color:var(--navy);font-size:17px}
html[data-theme="dark"] .topbar .page-title{color:#e8eefb}
.topbar .spacer{flex:1}
.clock{display:flex;align-items:center;gap:8px;background:var(--gray-100);border:1px solid var(--gray-200);
  padding:7px 13px;border-radius:12px;font-weight:700;color:var(--navy);font-size:13px}
html[data-theme="dark"] .clock{color:#dbe6f7}
.content{padding:22px;flex:1;width:100%;max-width:1500px;margin:0 auto}
.burger{display:none;background:var(--gray-100);border:1px solid var(--gray-200);border-radius:11px;
  width:40px;height:40px;font-size:18px;cursor:pointer;color:var(--navy)}
.overlay{position:fixed;inset:0;background:rgba(6,16,34,.5);z-index:55;display:none}
.overlay.show{display:block}

/* ===== کارت و شبکه ===== */
.card{background:var(--white);border:1px solid var(--gray-200);border-radius:var(--radius);box-shadow:var(--shadow);
  padding:20px;margin-bottom:20px}
.card>h3{display:flex;align-items:center;gap:9px;font-size:15.5px;margin-bottom:16px}
.grid{display:grid;gap:16px}
.stats{grid-template-columns:repeat(auto-fit,minmax(215px,1fr))}
.g2{grid-template-columns:repeat(auto-fit,minmax(320px,1fr))}
.g3{grid-template-columns:repeat(auto-fit,minmax(240px,1fr))}
.stat{background:var(--white);border:1px solid var(--gray-200);border-radius:var(--radius);padding:18px;
  display:flex;gap:14px;align-items:center;box-shadow:var(--shadow);transition:var(--transition);position:relative;overflow:hidden}
.stat:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg)}
.stat .icon{width:54px;height:54px;border-radius:15px;display:grid;place-items:center;font-size:24px;flex:0 0 auto}
.stat .v{font-size:26px;font-weight:900;color:var(--navy);line-height:1.3}
html[data-theme="dark"] .stat .v{color:#e8eefb}
/* ===== خوانایی در تم شب: متن روی سطوح رنگی ===== */
html[data-theme="dark"] h4,html[data-theme="dark"] b,html[data-theme="dark"] strong{color:#e8eefb}
html[data-theme="dark"] .card,html[data-theme="dark"] .modal,html[data-theme="dark"] .table-wrap{color:#cbd5e1}
html[data-theme="dark"] .stat .l{color:#a9b8ce}
html[data-theme="dark"] .badge.green{background:#12331f;color:#86efac}
html[data-theme="dark"] .badge.red{background:#3a1515;color:#fca5a5}
html[data-theme="dark"] .badge.amber{background:#3a2c0d;color:#fcd34d}
html[data-theme="dark"] .badge.blue{background:#122a4d;color:#93c5fd}
html[data-theme="dark"] .badge.purple{background:#2b1745;color:#d8b4fe}
html[data-theme="dark"] .badge.gray{background:#1e2b45;color:#cbd5e1}
html[data-theme="dark"] .alert.info{background:#12233c;border-color:#1e3a5f;color:#bfdbfe}
html[data-theme="dark"] .alert.ok,html[data-theme="dark"] .alert.success{background:#12291d;border-color:#1c4a2e;color:#a7f3d0}
html[data-theme="dark"] .alert.warn{background:#33280c;border-color:#5a440f;color:#fde68a}
html[data-theme="dark"] .alert.err,html[data-theme="dark"] .alert.error{background:#331414;border-color:#5c2323;color:#fecaca}
html[data-theme="dark"] .small.muted,html[data-theme="dark"] .muted,html[data-theme="dark"] .hint,
html[data-theme="dark"] .field-hint,html[data-theme="dark"] .mt,html[data-theme="dark"] .tp-note{color:#a9b8ce}
html[data-theme="dark"] .auto-note{background:#12291d;border-color:#1c4a2e;color:#a7f3d0}
html[data-theme="dark"] .auto-note b{color:#6ee7b7}
html[data-theme="dark"] .auto-note .an-ic{color:#34d399}
html[data-theme="dark"] .tp-del{background:#3a1515;border-color:#5c2323;color:#fca5a5}
html[data-theme="dark"] .tp-del:hover{background:#4a1a1a;border-color:#7f2d2d}
html[data-theme="dark"] .tp-tr{background:#16233b;border-color:#2a3a58;color:#cbd5e1}
html[data-theme="dark"] .bell-info{background:#131d31;border-color:#2a3a58;color:#cbd5e1}
html[data-theme="dark"] .bell-chip{background:#16233b;border-color:#2a3a58;color:#dbeafe}
html[data-theme="dark"] .bell-chip.on,html[data-theme="dark"] .bell-chip.active{background:#122a4d;border-color:#2f6fed;color:#bfdbfe}
html[data-theme="dark"] .tp-day-row .mt{color:#a9b8ce}
html[data-theme="dark"] .day-badge{background:#16233b;color:#dbeafe;border-color:#2a3a58}
html[data-theme="dark"] .tp-daynav{background:#0f1a2d;border-color:#1e2b45}
html[data-theme="dark"] .tp-daynav .tp-dn-title{color:#e8eefb}
html[data-theme="dark"] .tp-search{background:#16233b;border-color:#2a3a58;color:#e8eefb}
html[data-theme="dark"] .tp-search input{color:#e8eefb}
html[data-theme="dark"] .tp-search input::placeholder{color:#8fa3bf}
html[data-theme="dark"] .tp-empty,html[data-theme="dark"] .empty{color:#a9b8ce}
html[data-theme="dark"] .tbl thead th{background:#131d31;color:#cbd5e1}
html[data-theme="dark"] .tbl tbody tr:hover td{background:#131d31}
html[data-theme="dark"] .pill,html[data-theme="dark"] .tag{background:#16233b;color:#cbd5e1}
html[data-theme="dark"] .nav a.active{background:linear-gradient(90deg,rgba(47,111,237,.35),rgba(47,111,237,.12));color:#fff}
html[data-theme="dark"] .hint-box,html[data-theme="dark"] .note{background:#131d31;color:#cbd5e1}
html[data-theme="dark"] .quick a{background:#131d31;border-color:#1e2b45;color:#dbeafe}
html[data-theme="dark"] .quick a:hover{background:#16233b;border-color:#2f6fed}
html[data-theme="dark"] .mini{border-color:#1e2b45}
html[data-theme="dark"] .stat{background:var(--white)}
html[data-theme="dark"] .cr-ic,html[data-theme="dark"] .pk-av,html[data-theme="dark"] .r-av{background:#16233b;color:#dbeafe}
html[data-theme="dark"] select,html[data-theme="dark"] input,html[data-theme="dark"] textarea{background:#0f1a2d;color:#e8eefb;border-color:#2a3a58}
html[data-theme="dark"] input::placeholder,html[data-theme="dark"] textarea::placeholder{color:#8fa3bf}
html[data-theme="dark"] .option,html[data-theme="dark"] select option{background:#0f1a2d;color:#e8eefb}
/* ===== تم شب: صفحهٔ ورود، کارت‌های رنگی و عناصر اختصاصی پنل‌ها ===== */
html[data-theme="dark"] .login{background:#111c30}
html[data-theme="dark"] .login .form-side{background:#111c30}
html[data-theme="dark"] .toast{background:#111c30}
html[data-theme="dark"] .inp input.bad{background:#331414}
html[data-theme="dark"] .bp-item.on{background:#122a4d;border-color:#2f6fed}
html[data-theme="dark"] .selop{background:#0f1a2d;border-color:#2f4a7d}
html[data-theme="dark"] .tbl tr.picked{background:#152a4a}
html[data-theme="dark"] .err-list{background:#111c30;border-color:#5c2323}
html[data-theme="dark"] .rank{background:#131d31;border-color:#1e2b45}
html[data-theme="dark"] .rank.top1 .no{background:linear-gradient(135deg,#b45309,#f59e0b);color:#1b1206}
html[data-theme="dark"] .rank.top2 .no{background:linear-gradient(135deg,#475569,#94a3b8);color:#0b1220}
html[data-theme="dark"] .rank.top3 .no{background:linear-gradient(135deg,#78350f,#b45309);color:#fff7ed}
html[data-theme="dark"] .demo{background:#131d31;border-color:#1e2b45}
html[data-theme="dark"] .demo b{color:#e8eefb}
html[data-theme="dark"] .demo code{background:#0f1a2d;color:#e8eefb}
/* پنل معلم */
html[data-theme="dark"] .tp-grade{background:#111c30;border-color:#1e2b45}
html[data-theme="dark"] .tp-grade.on{background:linear-gradient(180deg,#122a4d,#111c30);border-color:#2f6fed}
html[data-theme="dark"] .tp-grade .g-ic{background:linear-gradient(135deg,#16233b,#1e2b45);color:#e8eefb}
html[data-theme="dark"] .tp-grade .g-badge{background:#16233b;border-color:#2a3a58;color:#cbd5e1}
html[data-theme="dark"] .tp-grade .g-sub{color:#a9b8ce}
html[data-theme="dark"] .tp-field{background:#111c30;border-color:#1e2b45;color:#dbeafe}
html[data-theme="dark"] .tp-field .n{background:#16233b;color:#dbeafe}
html[data-theme="dark"] .tp-field.on{background:#2f6fed;border-color:#2f6fed;color:#fff}
html[data-theme="dark"] .tp-field.on .n{background:rgba(255,255,255,.22);color:#fff}
html[data-theme="dark"] .tp-table th{background:#0f1a2d;color:#dbeafe}
html[data-theme="dark"] .tp-table td.nm{color:#e8eefb}
html[data-theme="dark"] .tp-table tbody tr:hover{background:#131d31}
html[data-theme="dark"] .tp-table td,html[data-theme="dark"] .tp-table th{border-color:#1e2b45}
html[data-theme="dark"] .tp-mk{background:#16233b;border-color:#2a3a58;color:#cbd5e1}
html[data-theme="dark"] .tp-mk.on{background:#3a2c0d;border-color:#5a440f;color:#fcd34d}
html[data-theme="dark"] .tp-mk.ab{background:#3a1515;border-color:#5c2323;color:#fca5a5}
html[data-theme="dark"] .tp-mk.dc{background:#2b1745;border-color:#4c2a73;color:#d8b4fe}
html[data-theme="dark"] .tp-mk.dr{background:#122a4d;border-color:#2f4a7d;color:#93c5fd}
html[data-theme="dark"] .tp-act{background:#111c30;border-color:#1e2b45;color:#dbeafe}
html[data-theme="dark"] .tp-act:hover{background:#16233b;border-color:#2f6fed}
html[data-theme="dark"] .tp-act.delay{background:#2b2310;border-color:#5a440f;color:#fcd34d}
html[data-theme="dark"] .tp-act.absence{background:#331414;border-color:#5c2323;color:#fca5a5}
html[data-theme="dark"] .tp-act.discipline{background:#2b1745;border-color:#4c2a73;color:#d8b4fe}
html[data-theme="dark"] .tp-act.dress{background:#122a4d;border-color:#2f4a7d;color:#93c5fd}
html[data-theme="dark"] input.offday{background:#331414;border-color:#7f2d2d}
html[data-theme="dark"] .offday-note{background:#33280c;border-color:#5a440f;color:#fde68a}
html[data-theme="dark"] .tp-day{background:#111c30;border-color:#1e2b45}
html[data-theme="dark"] .tp-day h4{color:#e8eefb}
html[data-theme="dark"] .rd-ic{color:#93c5fd}
html[data-theme="dark"] .mh-ic{color:#fcd34d}
html[data-theme="dark"] .q-ic{color:#93c5fd}
html[data-theme="dark"] .tp-note{color:#a9b8ce}
html[data-theme="dark"] .head{color:#e8eefb}
html[data-theme="dark"] .suggest .sg b,html[data-theme="dark"] .rec .r-main b,
html[data-theme="dark"] .mini .m-main b{color:#e8eefb}
html[data-theme="dark"] .kpi .k b{color:#e8eefb}
html[data-theme="dark"] .wbars .wt,html[data-theme="dark"] .grow .gk{color:#e8eefb}
/* گزارش‌ها (نمای رومیزی؛ برگهٔ چاپ همیشه سفید می‌ماند) */
html[data-theme="dark"] body{background:#0d1526}
html[data-theme="dark"] .pv-bar{background:#111c30;border-color:#1e2b45;color:#cbd5e1}
html[data-theme="dark"] .quick-row button{background:#111c30;border-color:#1e2b45;color:#dbeafe}
html[data-theme="dark"] .quick-row button.on{background:#2f6fed;border-color:#2f6fed;color:#fff}
html[data-theme="dark"] .stat-mini .m{background:#111c30;border-color:#1e2b45}
html[data-theme="dark"] .chk.no{background:#331414;border-color:#5c2323}
html[data-theme="dark"] .tabs button{color:#a9b8ce}
html[data-theme="dark"] .tabs button.active{color:#fff}
html[data-theme="dark"] .day-nav .dn-btn{background:#111c30;border-color:#1e2b45;color:#dbeafe}
html[data-theme="dark"] .who b{color:#e8eefb}
html[data-theme="dark"] table.matrix td:first-child{color:#dbeafe}
html[data-theme="dark"] .perm-row .pn{color:#dbeafe}
html[data-theme="dark"] .g-ic{color:#e8eefb}
/* ===== تم شب: رنگ‌های تیرهٔ درج‌شده در style= خط (که با تم عوض نمی‌شوند) ===== */
html[data-theme="dark"] [style*="color:#0f2a52"],html[data-theme="dark"] [style*="color:#0a1a33"],
html[data-theme="dark"] [style*="color:#111827"],html[data-theme="dark"] [style*="color:#13294b"],
html[data-theme="dark"] [style*="color:#1e293b"],html[data-theme="dark"] [style*="color:#334155"]{color:#e8eefb !important}
html[data-theme="dark"] [style*="color:#b91c1c"],html[data-theme="dark"] [style*="color:#991b1b"]{color:#fca5a5 !important}
html[data-theme="dark"] [style*="color:#b45309"],html[data-theme="dark"] [style*="color:#92400e"]{color:#fcd34d !important}
/* ===== اصلاح تضاد متن‌های آبی/کهربایی روی سطوح ملایم در تم شب =====
   این متن‌ها در حالت روز روی پس‌زمینهٔ روشن خوانا هستند، اما در تم شب روی سطح تیره‌اند. */
html[data-theme="dark"] .who-chip,
html[data-theme="dark"] .chip,
html[data-theme="dark"] .badge.blue,
html[data-theme="dark"] .topbar .btn-back,
html[data-theme="dark"] .type-chips label:has(input:checked),
html[data-theme="dark"] .cnt i.v,
html[data-theme="dark"] .scope-bar .sb-item:not(.on){color:#93c5fd}
html[data-theme="dark"] .who-chip,
html[data-theme="dark"] .chip,
html[data-theme="dark"] .badge.blue,
html[data-theme="dark"] .topbar .btn-back{border-color:#2a4a7a}
html[data-theme="dark"] .stat.amber .icon,
html[data-theme="dark"] .offday-note,
html[data-theme="dark"] .badge.amber,
html[data-theme="dark"] .print-note,
html[data-theme="dark"] .alert.warn{color:#fcd34d}
html[data-theme="dark"] .offday-note,
html[data-theme="dark"] .alert.warn{border-color:#5a440f}
/* دکمه‌های رنگی: در تم شب کمی تیره‌تر تا متن سفیدشان خوانا بماند */
html[data-theme="dark"] .btn-blue{background:#1d4ed8;border-color:#1d4ed8}
html[data-theme="dark"] .btn-blue:hover{background:#1e40af;border-color:#1e40af}
html[data-theme="dark"] .btn-green{background:#15803d;border-color:#15803d}
html[data-theme="dark"] .btn-green:hover{background:#166534;border-color:#166534}
html[data-theme="dark"] .btn-amber{background:#b45309;border-color:#b45309}
html[data-theme="dark"] .btn-amber:hover{background:#92400e;border-color:#92400e}
html[data-theme="dark"] .btn-red{background:#b91c1c;border-color:#b91c1c}
html[data-theme="dark"] .btn-red:hover{background:#991b1b;border-color:#991b1b}
html[data-theme="dark"] .fab-print{background:linear-gradient(135deg,#1d4ed8,#2f6fed)}
html[data-theme="dark"] .cnt i.d{color:#fcd34d}
html[data-theme="dark"] .cnt i.a{color:#fca5a5}
html[data-theme="dark"] .cnt i.c{color:#d8b4fe}
html[data-theme="dark"] [style*="color:#1d4ed8"],html[data-theme="dark"] [style*="color:#1e40af"]{color:#93c5fd !important}
html[data-theme="dark"] [style*="color:#047857"],html[data-theme="dark"] [style*="color:#065f46"]{color:#6ee7b7 !important}
html[data-theme="dark"] [style*="background:#eef2f7"],html[data-theme="dark"] [style*="background:#f8fafc"],
html[data-theme="dark"] [style*="background:#f1f5f9"]{background:#16233b !important}
html[data-theme="dark"] [style*="color:#0f2a52"] a,html[data-theme="dark"] a[style*="color:#0f2a52"]{color:#93c5fd !important}
.stat .l{color:var(--gray-500);font-size:12.8px}
.stat.blue .icon{background:var(--blue-soft);color:var(--blue)}
.stat.green .icon{background:var(--green-soft);color:var(--green)}
.stat.amber .icon{background:var(--amber-soft);color:#b45309}
.stat.red .icon{background:var(--red-soft);color:var(--red)}
.stat.purple .icon{background:var(--purple-soft);color:var(--purple)}

/* ===== دکمه‌ها ===== */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:none;cursor:pointer;
  background:var(--gray-100);color:var(--gray-700);padding:11px 18px;border-radius:12px;font-weight:700;
  font-family:inherit;font-size:13.6px;transition:var(--transition);border:1px solid transparent;white-space:nowrap}
.btn:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(15,42,82,.14)}
.btn:active{transform:translateY(0)}
.btn:disabled{opacity:.55;cursor:not-allowed;transform:none}
.btn-primary{background:linear-gradient(135deg,var(--navy-bg),var(--navy-bg-2));color:#fff}
.btn-blue{background:linear-gradient(135deg,var(--blue),#4f8cf7);color:#fff}
.btn-green{background:linear-gradient(135deg,var(--green),#22c55e);color:#fff}
.btn-red{background:linear-gradient(135deg,var(--red),#f87171);color:#fff}
.btn-amber{background:linear-gradient(135deg,#d97706,var(--amber));color:#fff}
.btn-ghost{background:transparent;border-color:var(--gray-300);color:var(--gray-700)}
.btn-sm{padding:7px 12px;font-size:12.4px;border-radius:10px}
.btn-block{width:100%}
.btn-icon{width:34px;height:34px;padding:0;border-radius:10px}

/* ===== فرم‌ها ===== */
label.fl{display:block;font-weight:700;font-size:13px;margin-bottom:7px;color:var(--gray-700)}
.label-wrap{display:flex;justify-content:space-between;align-items:center;gap:8px}
.req{color:var(--red)}
input[type=text],input[type=password],input[type=number],input[type=tel],input[type=date],input[type=time],
input[type=search],select,textarea{width:100%;padding:11px 13px;border:1.6px solid var(--gray-200);
  border-radius:12px;background:var(--white);color:var(--gray-700);font-family:inherit;font-size:13.8px;
  transition:var(--transition);outline:none}
input:focus,select:focus,textarea:focus{border-color:var(--blue);box-shadow:0 0 0 4px rgba(47,111,237,.13)}
input.bad,select.bad{border-color:var(--red)!important;background:var(--red-soft)}
input.offday,select.offday{border-color:#f59e0b!important;background:#fffbeb}
html[data-theme="dark"] input.offday{background:#2b2310}
.offday-note{display:none;font-size:12px;font-weight:700;color:#b45309;background:var(--amber-soft);
  border:1px solid #fde68a;border-radius:10px;padding:8px 11px;margin-top:7px}
.offday-note.show{display:block}
.scope-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:var(--white);border:1px solid var(--gray-200);
  border-radius:13px;padding:10px 13px;box-shadow:var(--shadow);margin-bottom:16px;font-size:13px}
.scope-bar .sb-item{display:inline-flex;align-items:center;gap:7px;border:1.5px solid var(--gray-200);border-radius:11px;
  padding:7px 12px;font-weight:700;color:var(--gray-700);background:var(--white)}
.scope-bar .sb-item.on{background:var(--navy-bg);border-color:var(--navy-bg);color:#fff}
.scope-bar .sb-item:hover{border-color:var(--blue)}
.who-chip{display:inline-flex;align-items:center;gap:6px;background:var(--blue-soft);color:#1d4ed8;border:1px solid #cfe0ff;
  border-radius:20px;padding:3px 10px;font-size:11.6px;font-weight:700}
.who-chip.mine{background:var(--green-soft);color:#15803d;border-color:#b7ebc9}
input.good{border-color:var(--green)}
.hint{font-size:11.6px;color:var(--gray-500);margin-top:5px;display:block}
.err-msg{font-size:11.8px;color:var(--red);font-weight:700;margin-top:5px;display:none}
.err-msg.show{display:block}
.form-row{margin-bottom:15px}
input[type=file]{width:100%;padding:9px;border:1.6px dashed var(--gray-300);border-radius:12px;background:var(--gray-50);font-size:13px}
.switch{position:relative;display:inline-block;width:48px;height:26px;flex:0 0 auto}
.switch input{display:none}
.switch .slider{position:absolute;inset:0;background:var(--gray-300);border-radius:30px;transition:var(--transition);cursor:pointer}
.switch .slider::before{content:"";position:absolute;width:20px;height:20px;border-radius:50%;background:#fff;
  top:3px;inset-inline-start:3px;transition:var(--transition);box-shadow:0 2px 6px rgba(0,0,0,.2)}
.switch input:checked+.slider{background:var(--blue)}
.switch input:checked+.slider::before{transform:translateX(-22px)}
html[dir=rtl] .switch input:checked+.slider::before{transform:translateX(-22px)}

/* ===== جدول ===== */
.table-wrap{overflow-x:auto;border-radius:var(--radius-sm);border:1px solid var(--gray-200)}
table.tbl{width:100%;border-collapse:collapse;background:var(--white);font-size:13.4px;min-width:560px}
table.tbl thead th{background:linear-gradient(180deg,var(--navy-bg),var(--navy-bg-2));color:#fff;padding:12px 10px;
  text-align:right;font-weight:700;font-size:13px;white-space:nowrap;position:sticky;top:0}
table.tbl tbody td{padding:11px 10px;border-bottom:1px solid var(--gray-200);vertical-align:middle}
table.tbl tbody tr:nth-child(even){background:var(--gray-50)}
table.tbl tbody tr:hover{background:var(--blue-soft)}
table.tbl tbody tr:last-child td{border-bottom:none}
table.tbl th a{color:#fff;display:inline-flex;gap:5px;align-items:center}
table.tbl th .sort-ic{opacity:.55;font-size:11px}
table.tbl th.active-sort .sort-ic{opacity:1;color:#7dd3fc}
.badge{display:inline-block;padding:4px 11px;border-radius:30px;font-size:11.7px;font-weight:800;white-space:nowrap}
.badge.blue{background:var(--blue-soft);color:#1d4ed8}
.badge.green{background:var(--green-soft);color:#15803d}
.badge.amber{background:var(--amber-soft);color:#b45309}
.badge.red{background:var(--red-soft);color:#b91c1c}
.badge.purple{background:var(--purple-soft);color:#6d28d9}
.badge.gray{background:var(--gray-100);color:var(--gray-500)}

/* ===== دکمه منو، بازگشت و ناوبری کمکی ===== */
.burger{display:none;background:var(--gray-100);border:1px solid var(--gray-200);border-radius:11px;
  width:42px;height:42px;font-size:19px;cursor:pointer;color:var(--navy);flex:0 0 auto;transition:var(--transition)}
.burger:hover{background:var(--blue-soft);border-color:var(--blue)}
.fab-menu{display:none;position:fixed;bottom:18px;inset-inline-start:18px;z-index:70;align-items:center;gap:8px;
  background:linear-gradient(135deg,var(--navy-bg),var(--navy-bg-2));color:#fff;border:none;border-radius:30px;
  padding:12px 18px;font-family:inherit;font-weight:800;font-size:13.4px;cursor:pointer;
  box-shadow:0 14px 34px rgba(15,42,82,.32);transition:var(--transition)}
.fab-menu:hover{transform:translateY(-3px)}
.crumb{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:16px;font-size:13px;color:var(--gray-500)}
.crumb a{display:inline-flex;align-items:center;gap:6px;background:var(--white);border:1px solid var(--gray-200);
  border-radius:11px;padding:8px 13px;font-weight:700;color:var(--navy);transition:var(--transition)}
.crumb a:hover{border-color:var(--blue);color:var(--blue);transform:translateY(-2px)}
.crumb .crumb-cur{font-weight:800;color:var(--navy);background:var(--blue-soft);border:1px solid #cfe0ff;
  border-radius:11px;padding:8px 13px}
html[data-theme="dark"] .crumb a{color:#e8eefb}
html[data-theme="dark"] .crumb .crumb-cur{color:#dbeafe}
.back-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:6px;padding-top:16px;
  border-top:1px dashed var(--gray-300)}
.back-bar .grow{flex:1}
.topbar .btn-back{background:var(--blue-soft);border:1px solid #cfe0ff;color:#1d4ed8}
.topbar .btn-back:hover{background:var(--blue);color:#fff}
.fab-print{position:fixed;bottom:18px;inset-inline-end:18px;z-index:70;display:inline-flex;align-items:center;gap:8px;
  background:linear-gradient(135deg,var(--blue),#4f8cf7);color:#fff;border:none;border-radius:30px;padding:12px 18px;
  font-family:inherit;font-weight:800;font-size:13.4px;cursor:pointer;box-shadow:0 14px 34px rgba(47,111,237,.35);
  transition:var(--transition)}
.fab-print:hover{transform:translateY(-3px)}
@media print{.fab-menu,.fab-print,.crumb,.back-bar,.burger{display:none!important}}

/* ===== صفحه‌بندی ===== */
.pager{display:flex;gap:6px;justify-content:center;align-items:center;flex-wrap:wrap;margin-top:18px}
.pager a,.pager span{padding:8px 13px;border-radius:10px;border:1px solid var(--gray-200);background:var(--white);
  font-weight:700;font-size:13px;color:var(--gray-700);min-width:40px;text-align:center}
.pager a:hover{border-color:var(--blue);color:var(--blue)}
.pager .on{background:var(--navy-bg);color:#fff;border-color:var(--navy-bg)}
.pager .off{opacity:.45;cursor:default}

/* ===== توست و مودال ===== */
#toasts{position:fixed;top:16px;inset-inline-start:16px;z-index:9999;display:flex;flex-direction:column;gap:10px;max-width:380px}
.toast{background:var(--white);border-radius:14px;padding:13px 16px;box-shadow:var(--shadow-lg);display:flex;
  gap:11px;align-items:flex-start;border-inline-start:5px solid var(--blue);animation:toastIn .35s ease;font-size:13.4px}
.toast.ok{border-color:var(--green)} .toast.err{border-color:var(--red)} .toast.warn{border-color:var(--amber)}
.toast .t-ic{font-size:19px} .toast b{display:block;color:var(--navy);margin-bottom:2px}
html[data-theme="dark"] .toast b{color:#e8eefb}
.toast .x{margin-inline-start:auto;background:none;border:none;cursor:pointer;color:var(--gray-500);font-size:16px}
@keyframes toastIn{from{opacity:0;transform:translateY(-14px) scale(.97)}to{opacity:1;transform:none}}
.modal-bg{position:fixed;inset:0;background:rgba(8,18,38,.55);backdrop-filter:blur(3px);z-index:9000;display:none;
  align-items:center;justify-content:center;padding:18px}
.modal-bg.show{display:flex;animation:fadeIn .2s ease}
.modal{background:var(--white);border-radius:18px;max-width:640px;width:100%;max-height:92vh;overflow:auto;
  box-shadow:var(--shadow-lg);animation:popIn .3s cubic-bezier(.34,1.4,.64,1)}
.modal header{padding:16px 20px;border-bottom:1px solid var(--gray-200);display:flex;align-items:center;gap:10px}
.modal header h3{margin:0;font-size:16px}
.modal .mbody{padding:20px}
.modal footer{padding:14px 20px;border-top:1px solid var(--gray-200);display:flex;gap:10px;justify-content:flex-start}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes popIn{from{opacity:0;transform:scale(.94)}to{opacity:1;transform:none}}

/* ===== لودر ===== */
#loader{position:fixed;inset:0;background:rgba(240,244,250,.78);backdrop-filter:blur(4px);z-index:9800;
  display:none;align-items:center;justify-content:center;flex-direction:column;gap:16px}
html[data-theme="dark"] #loader{background:rgba(8,14,26,.78)}
#loader.show{display:flex}
.spinner{width:62px;height:62px;border-radius:50%;border:5px solid var(--gray-200);border-top-color:var(--blue);
  animation:spin .85s linear infinite}
#loader span{font-weight:800;color:var(--navy);font-size:14px}
#loader .ld-msg{max-width:460px;text-align:center;color:var(--gray-600);font-size:12.6px;line-height:1.9}
html[data-theme="dark"] #loader .ld-msg{color:#a9bada}
#loader button{margin-top:6px;border:1.5px solid var(--gray-300);background:var(--white);color:var(--navy);
  border-radius:10px;padding:7px 16px;font:inherit;font-weight:700;font-size:12.4px;cursor:pointer}
#loader button:hover{border-color:var(--blue);color:var(--blue)}
html[data-theme="dark"] #loader span{color:#dbe6f7}
@keyframes spin{to{transform:rotate(360deg)}}
.skel{height:14px;border-radius:8px;background:linear-gradient(90deg,var(--gray-200),var(--gray-100),var(--gray-200));
  background-size:200% 100%;animation:shimmer 1.3s infinite}
@keyframes shimmer{to{background-position:-200% 0}}

/* ===== وضعیت خالی و ابزار ===== */
.empty{text-align:center;padding:44px 18px;color:var(--gray-500)}
.empty .e-ic{font-size:52px;margin-bottom:10px;opacity:.7}
.flex{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.between{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.mt0{margin-top:0} .mb0{margin-bottom:0} .mt10{margin-top:10px} .mt20{margin-top:20px} .mb10{margin-bottom:10px}
.muted{color:var(--gray-500)} .small{font-size:12.3px} .bold{font-weight:800} .nowrap{white-space:nowrap}
.center{text-align:center} .hidden{display:none!important}
.alert{padding:13px 16px;border-radius:13px;font-size:13.3px;margin-bottom:16px;border:1px solid transparent;display:flex;gap:10px;align-items:flex-start}
.alert.info{background:var(--blue-soft);border-color:#bfd4fb;color:#1e3a8a}
.alert.ok{background:var(--green-soft);border-color:#b7ebc9;color:#14532d}
.alert.warn{background:var(--amber-soft);border-color:#fde68a;color:#92400e}
.alert.err{background:var(--red-soft);border-color:#fecaca;color:#991b1b}
.collapse-head{cursor:pointer;user-select:none}
.collapse-head .chev{margin-inline-start:auto;transition:var(--transition);font-size:13px;color:var(--gray-500)}
.collapse.collapsed .collapse-body{display:none}
.collapse.collapsed .collapse-head .chev{transform:rotate(-90deg)}
.bar{height:9px;border-radius:6px;background:var(--gray-100);overflow:hidden}
.bar>i{display:block;height:100%;border-radius:6px;background:linear-gradient(90deg,var(--blue),#22d3ee)}
.tabs{display:flex;gap:8px;flex-wrap:wrap;border-bottom:2px solid var(--gray-200);padding-bottom:10px;margin-bottom:18px}
.tabs button{background:var(--gray-100);border:1px solid var(--gray-200);padding:10px 16px;border-radius:12px;
  font-family:inherit;font-weight:700;font-size:13.3px;cursor:pointer;color:var(--gray-700);transition:var(--transition)}
.tabs button:hover{transform:translateY(-2px)}
.tabs button.active{background:linear-gradient(135deg,var(--navy-bg),var(--navy-bg-2));color:#fff;border-color:var(--navy-bg)}
.tag{display:inline-flex;align-items:center;gap:6px;background:var(--gray-100);border:1px solid var(--gray-200);
  padding:5px 11px;border-radius:30px;font-size:12px;font-weight:700;color:var(--gray-500)}
.perm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(255px,1fr));gap:11px}
.perm{display:flex;gap:11px;align-items:flex-start;border:1.6px solid var(--gray-200);border-radius:13px;padding:12px;
  cursor:pointer;transition:var(--transition);background:var(--white)}
.perm:hover{border-color:var(--blue);background:var(--blue-soft)}
.perm input{margin-top:5px;width:17px;height:17px;accent-color:var(--blue);cursor:pointer}
.perm .pn{font-weight:700;font-size:13.2px;color:var(--navy)}
html[data-theme="dark"] .perm .pn{color:#e8eefb}
.perm .pc{font-size:11px;color:var(--gray-500);direction:ltr;text-align:left;font-family:monospace}
.perm:has(input:checked){border-color:var(--blue);background:var(--blue-soft)}
.perm-group-title{font-weight:800;color:var(--navy);font-size:13.4px;margin:16px 0 9px;padding-inline-start:9px;
  border-inline-start:4px solid var(--blue)}
html[data-theme="dark"] .perm-group-title{color:#e8eefb}

/* ===== ریسپانسیو ===== */
@media (max-width:1024px){
  /* سایدبار در حالت موبایل به بیرون صفحه منتقل می‌شود و با دکمه منو باز می‌گردد.
     نکته مهم: قانون .sidebar.open با !important نوشته شده تا از نظر
     Specificity بر قاعده html[dir=rtl] .sidebar غلبه کند (باگ رفع‌شده). */
  .sidebar{transform:translateX(110%)}
  html[dir=rtl] .sidebar{transform:translateX(110%)}
  .sidebar.open{transform:translateX(0)!important}
  .main{margin-inline-start:0}
  .burger{display:grid;place-items:center}
  .content{padding:16px}
  .fab-menu{display:inline-flex}
  .crumb .home-text{display:none}
}
@media (max-width:640px){
  body{font-size:13.6px}
  .stat .v{font-size:22px}
  .topbar{padding:10px 14px;gap:9px}
  .clock{font-size:11.6px;padding:6px 10px}
  .card{padding:15px;border-radius:14px}
  .btn{padding:10px 14px}
}
@media print{
  .sidebar,.topbar,.no-print{display:none!important}
  .main{margin:0}
  body{background:#fff}
}
CSS;
}

/**
 * آیکون خطی SVG (بدون هیچ فایل تصویری خارجی)
 * فهرست آیکون‌ها در فایل icons.php نگه‌داری می‌شود و همهٔ پنل‌ها از همین تابع استفاده می‌کنند.
 */
function hs_icon(string $name, int $size = 18, string $class = ''): string
{
    /* نکته: بارگذاری از تابع جدا انجام می‌شود تا متغیرهای داخلی فایل آیکون‌ها
       روی پارامتر $name این تابع اثر نگذارند (باگ ظریف PHP). */
    $svg = hs_icon_set()[$name] ?? '';
    if ($svg === '') {
        return '';
    }
    return str_replace(
        '<svg ',
        '<svg width="' . $size . '" height="' . $size . '" class="hs-i ' . $class . '" ',
        $svg
    );
}

/** فهرست همهٔ آیکون‌ها (برای تزریق به جاوااسکریپت) */
function hs_icon_set(): array
{
    static $set = null;
    if ($set === null) {
        $set = require __DIR__ . '/icons.php';
    }
    return $set;
}

/** JavaScript پایه سامانه (توست، مودال، سایدبار، تم، لودر، تاریخ‌شمار شمسی) */
function theme_js(): string
{
    $js = <<<'JS'
/* ===== توابع عمومی سامانه (Vanilla JS) ===== */
window.HS = (function () {
  'use strict';
  const $  = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));

  /* ---------- آیکون‌های خطی (SVG درون‌خطی — بدون فایل خارجی) ---------- */
  const ICONS = /*__HS_ICONS__*/;
  function icon(name, size, cls) {
    const raw = ICONS[name];
    if (!raw) { return ''; }
    const px = size || 18;
    return raw.replace('<svg ', '<svg width="' + px + '" height="' + px + '" class="hs-i ' + (cls || '') + '" ');
  }

  /* ---------- امن‌سازی متن برای درج در innerHTML ---------- */
  function esc(v) {
    return String(v === null || v === undefined ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ---------- اعداد و تاریخ شمسی سمت کلاینت (هم‌ارز با توابع PHP) ---------- */
  const FA_D = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
  function faNum(v){ return String(v).replace(/\d/g, d => FA_D[+d]); }
  function enNum(v){ return String(v).replace(/[۰-۹]/g, d => FA_D.indexOf(d)).replace(/[٠-٩]/g, d => ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'].indexOf(d)); }
  const MONTHS = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
  const WD = ['شنبه','یک‌شنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنج‌شنبه','جمعه'];

  function g2j(gy, gm, gd) {
    const gdm = [0,31,59,90,120,151,181,212,243,273,304,334];
    const gy2 = (gm > 2) ? gy + 1 : gy;
    let days = 355666 + (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100)
             + Math.floor((gy2 + 399) / 400) + gd + gdm[gm - 1];
    let jy = -1595 + 33 * Math.floor(days / 12053); days %= 12053;
    jy += 4 * Math.floor(days / 1461); days %= 1461;
    if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
    let jm, jd;
    if (days < 186) { jm = 1 + Math.floor(days / 31); jd = 1 + (days % 31); }
    else { jm = 7 + Math.floor((days - 186) / 30); jd = 1 + ((days - 186) % 30); }
    return [jy, jm, jd];
  }
  function j2g(jy, jm, jd) {
    jy += 1595;
    let days = -355668 + (365 * jy) + Math.floor(jy / 33) * 8 + Math.floor(((jy % 33) + 3) / 4) + jd
             + ((jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
    let gy = 400 * Math.floor(days / 146097); days %= 146097;
    if (days > 36524) { days--; gy += 100 * Math.floor(days / 36524); days %= 36524; if (days >= 365) days++; }
    gy += 4 * Math.floor(days / 1461); days %= 1461;
    if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
    let gd = days + 1;
    const leap = (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0;
    const salA = [0,31, leap ? 29 : 28,31,30,31,30,31,31,30,31,30,31];
    let gm = 0;
    while (gm < 13 && gd > salA[gm]) { gd -= salA[gm]; gm++; }
    return [gy, gm, gd];
  }
  function jDaysInMonth(jy, jm) {
    if (jm <= 6) return 31;
    if (jm <= 11) return 30;
    const g = j2g(jy, 12, 30);
    const back = g2j(g[0], g[1], g[2]);
    return (back[0] === jy && back[1] === 12 && back[2] === 30) ? 30 : 29;
  }
  function jToday() {
    const d = new Date();
    const j = g2j(d.getFullYear(), d.getMonth() + 1, d.getDate());
    return j[0] + '/' + pad(j[1]) + '/' + pad(j[2]);
  }
  function pad(n){ return (n < 10 ? '0' : '') + n; }
  /* آیا روز (شنبه=۰ … جمعه=۶) جزء روزهای تعطیل تنظیم‌شده است؟ */
  function jWeekdayIndex(jdate) {
    const p = jdate.split('/').map(Number);
    const g = j2g(p[0], p[1], p[2]);
    const dow = new Date(g[0], g[1] - 1, g[2]).getDay();   // 0=یک‌شنبه
    return (dow + 1) % 7;                                   // شنبه=0
  }
  function isOffDay(jdate) {
    const off = (window.HS_OFF && window.HS_OFF.days) || [5, 6];
    return off.indexOf(jWeekdayIndex(jdate)) > -1;
  }
  function offDayName(jdate) { return jWeekday(jdate); }

  /* نزدیک‌ترین روز کاری در جهت dir (-1 = قبل، 1 = بعد)؛ روزهای تعطیل رد می‌شوند */
  function workDayStep(from, dir) {
    let d = from && /^\d{4}\/\d{2}\/\d{2}$/.test(from) ? from : jToday();
    const step = dir < 0 ? -1 : 1;
    for (let i = 0; i < 14; i++) {
      d = jAddDays(d, step);
      if (!isOffDay(d)) return d;
    }
    return d;
  }
  /* روز کاری مرجع: امروز اگر روز کاری باشد، وگرنه آخرین روز کاری گذشته */
  function workDate() {
    if (window.HS_OFF && window.HS_OFF.workDate) return window.HS_OFF.workDate;
    let d = jToday();
    for (let i = 0; i < 14 && isOffDay(d); i++) d = jAddDays(d, -1);
    return d;
  }

  function jWeekday(jdate) {
    const p = jdate.split('/').map(Number);
    const g = j2g(p[0], p[1], p[2]);
    const dow = new Date(g[0], g[1] - 1, g[2]).getDay();   // 0=Sunday
    return WD[(dow + 1) % 7];
  }
  function jAddDays(jdate, n) {
    const p = jdate.split('/').map(Number);
    const g = j2g(p[0], p[1], p[2]);
    const d = new Date(g[0], g[1] - 1, g[2]);
    d.setDate(d.getDate() + n);
    const j = g2j(d.getFullYear(), d.getMonth() + 1, d.getDate());
    return j[0] + '/' + pad(j[1]) + '/' + pad(j[2]);
  }

  /* ---------- توست ---------- */
  function toast(message, type, title) {
    type = type || 'ok';
    const icons = { ok: icon('check', 20), err: icon('ban', 20), warn: icon('alert', 20), info: icon('info', 20) };
    const titles = { ok: 'انجام شد', err: 'خطا', warn: 'هشدار', info: 'اطلاع' };
    let box = $('#toasts');
    if (!box) { box = document.createElement('div'); box.id = 'toasts'; document.body.appendChild(box); }
    const el = document.createElement('div');
    el.className = 'toast ' + (type === 'info' ? '' : type);
    el.innerHTML = '<span class="t-ic">' + (icons[type] || icon('check', 20)) + '</span><div><b>' +
      (title || titles[type] || 'انجام شد') + '</b><span>' + message + '</span></div>' +
      '<button class="x" aria-label="بستن">' + icon('close', 15) + '</button>';
    box.appendChild(el);
    const close = () => { el.style.opacity = '0'; el.style.transform = 'translateY(-10px)'; setTimeout(() => el.remove(), 220); };
    $('.x', el).onclick = close;
    setTimeout(close, 5200);
    /* تور نجات: اگر پیام خطا/هشداری روی صفحه آمد و لودر «در حال پردازش» هنوز باز بود،
       لودر بسته می‌شود تا کاربر پشت آن گیر نکند */
    try {
      const l = document.getElementById('loader');
      if (l && l.classList.contains('show') && (type === 'err' || type === 'warn')) hideLoader();
    } catch (e) { }
    return el;
  }

  /* ---------- مودال ---------- */
  function modal(id, open) {
    const m = document.getElementById(id);
    if (!m) return;
    m.classList.toggle('show', open !== false);
    document.body.style.overflow = m.classList.contains('show') ? 'hidden' : '';
  }
  function confirmBox(message, onYes, opts) {
    opts = opts || {};
    const id = 'hs-confirm';
    let m = document.getElementById(id);
    if (!m) {
      m = document.createElement('div');
      m.id = id; m.className = 'modal-bg';
      m.innerHTML = '<div class="modal" style="max-width:460px"><header><span class="mh-ic">' + icon('alert', 24) + '</span>' +
        '<h3 id="hs-cf-title">تأیید عملیات</h3></header><div class="mbody" id="hs-cf-msg"></div>' +
        '<footer><button class="btn btn-red" id="hs-cf-yes">بله، انجام بده</button>' +
        '<button class="btn btn-ghost" id="hs-cf-no">انصراف</button></footer></div>';
      document.body.appendChild(m);
    }
    $('#hs-cf-title').textContent = opts.title || 'تأیید عملیات';
    $('#hs-cf-msg').innerHTML = message;
    $('#hs-cf-yes').textContent = opts.yes || 'بله، انجام بده';
    $('#hs-cf-no').textContent = opts.no || 'انصراف';
    modal(id, true);
    $('#hs-cf-yes').onclick = () => { modal(id, false); onYes && onYes(); };
    $('#hs-cf-no').onclick = () => modal(id, false);
    m.onclick = e => { if (e.target === m) modal(id, false); };
  }

  /* ---------- لودر ---------- */
  /* ---------- لودر ----------
     اگر درخواست طول بکشد پیام راهنما و دکمهٔ بستن نمایش داده می‌شود تا کاربر گیر نکند */
  let ldTimer = null, ldCancel = null;

  function hideLoader() {
    const l = document.getElementById('loader');
    if (!l) return;
    l.classList.remove('show');
    l.classList.remove('wait');
    if (ldTimer) { clearTimeout(ldTimer); ldTimer = null; }
    if (ldCancel) { clearTimeout(ldCancel); ldCancel = null; }
    const b = l.querySelector('button[data-ld-close]');
    if (b) b.remove();
    const m = l.querySelector('.ld-msg');
    if (m) m.remove();
    document.documentElement.style.removeProperty('cursor');
  }

  function loading(show, text, hint) {
    let l = document.getElementById('loader');
    if (!l) {
      l = document.createElement('div'); l.id = 'loader';
      l.innerHTML = '<div class="spinner"></div><span></span>';
      l.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('button[data-ld-close]')) { hideLoader(); return; }
        /* کلیک روی پس‌زمینه: اگر بیش از ۲ ثانیه گذشته باشد، لودر بسته می‌شود */
        if (l.classList.contains('wait')) hideLoader();
      });
      document.body.appendChild(l);
    }
    if (show === false) { hideLoader(); return; }
    $('span', l).textContent = text || 'در حال پردازش…';
    l.classList.add('show');
    /* پاک‌سازی پیام‌های قبلی */
    const oldM = l.querySelector('.ld-msg'); if (oldM) oldM.remove();
    const oldB = l.querySelector('button[data-ld-close]'); if (oldB) oldB.remove();
    if (ldTimer) clearTimeout(ldTimer);
    ldTimer = setTimeout(function () {
      l.classList.add('wait');
      const m = document.createElement('div');
      m.className = 'ld-msg';
      m.innerHTML = hint || 'اگر ادامه دارد، چند لحظه صبر کنید؛ در صورت کند شدن اینترنت یا سرور، می‌توانید این پیام را ببندید.';
      l.appendChild(m);
      const b = document.createElement('button');
      b.type = 'button'; b.setAttribute('data-ld-close', '1');
      b.innerHTML = icon('close', 15) + ' بستن این پیام';
      l.appendChild(b);
      /* اگر تا ۴۵ ثانیه پاسخ نیامد، خودش بسته می‌شود */
      ldCancel = setTimeout(hideLoader, 45000);
    }, 4000);
  }

  /* ---------- سایدبار و تم ---------- */
  let setSidebar = function () {};   /* پیاده‌سازی واقعی در initShell جایگزین می‌شود */

  function initShell() {
    const sb = $('.sidebar'), ov = $('.overlay'), bg = $('.burger');

    /* باز/بسته کردن سایدبار — روی موبایل و تبلت با دکمه منو کار می‌کند */
    setSidebar = function (open) {
      if (!sb) return;
      sb.classList.toggle('open', open);
      if (ov) ov.classList.toggle('show', open);
      if (bg) bg.setAttribute('aria-expanded', open ? 'true' : 'false');
      document.documentElement.style.overflowY = (open && window.innerWidth <= 1024) ? 'hidden' : '';
    }
    window.HS_SET_SIDEBAR = setSidebar;

    if (bg && sb) bg.addEventListener('click', function (e) {
      e.preventDefault();
      setSidebar(!sb.classList.contains('open'));
    });
    if (ov && sb) ov.addEventListener('click', () => setSidebar(false));
    /* بستن با کلید Escape */
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && sb && sb.classList.contains('open')) setSidebar(false); });
    /* بستن خودکار پس از انتخاب هر گزینه منو */
    $$('.nav a', sb || document).forEach(a => a.addEventListener('click', () => {
      if (window.innerWidth <= 1024) setTimeout(() => setSidebar(false), 80);
    }));
    /* بستن در بازگردانی اندازه صفحه به دسکتاپ */
    window.addEventListener('resize', () => { if (window.innerWidth > 1024) setSidebar(false); });
    /* آیکون اولیهٔ دکمهٔ تم بر اساس تم فعلی */
    $$('[data-theme-toggle]').forEach(b => {
      b.innerHTML = icon(document.documentElement.getAttribute('data-theme') === 'dark' ? 'sun' : 'moon', 18);
    });
    $$('[data-theme-toggle]').forEach(b => b.onclick = () => {
      const dark = document.documentElement.getAttribute('data-theme') === 'dark';
      document.documentElement.setAttribute('data-theme', dark ? 'light' : 'dark');
      try { localStorage.setItem('hs_theme', dark ? 'light' : 'dark'); } catch (e) {}
      b.innerHTML = icon(dark ? 'moon' : 'sun', 18);
    });
    /* لودر خودکار برای فرم‌ها
       نکته: اگر هندلر دیگری ارسال فرم را لغو کند (اعتبارسنجی/تأیید/روز تعطیل)، لودر نمایش داده نمی‌شود */
    $$('form[data-loading]').forEach(f => f.addEventListener('submit', function (e) {
      if (f.checkValidity && !f.checkValidity()) return;
      if (e.defaultPrevented) return;
      setTimeout(function () {
        if (e.defaultPrevented) { return; }
        loading(true, f.getAttribute('data-loading-text') || 'در حال پردازش…',
                f.getAttribute('data-loading-hint'));
      }, 0);
    }));
    /* جلوگیری از ارسال دوباره */
    $$('form').forEach(f => f.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;
      $$('button[type=submit]', f).forEach(b => { setTimeout(() => b.disabled = true, 8); });
      setTimeout(() => $$('button[type=submit]', f).forEach(b => b.disabled = false), 6000);
    }));
    /* اگر صفحه از حافظهٔ مرورگر بازگردانده شد، لودر نباید باقی بماند */
    window.addEventListener('pageshow', hideLoader);
    window.addEventListener('beforeunload', function () { /* پاک‌سازی احتیاطی */ });
  }

  /* ---------- انتخابگر تاریخ شمسی ---------- */
  function datePicker(input, opts) {
    if (!input || input._hsPicker) return;
    opts = opts || {};
    input._hsPicker = true;
    input.setAttribute('readonly', 'readonly');
    input.setAttribute('placeholder', 'مثال: 1405/01/01');
    input.setAttribute('autocomplete', 'off');
    input.classList.add('hs-date');

    const wrap = document.createElement('div');
    wrap.style.position = 'relative';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    const pop = document.createElement('div');
    pop.className = 'hs-dp';
    pop.style.cssText = 'position:absolute;z-index:5000;background:var(--white);border:1px solid var(--gray-200);' +
      'border-radius:14px;box-shadow:0 18px 44px rgba(15,42,82,.2);padding:12px;width:290px;top:calc(100% + 6px);' +
      'inset-inline-start:0;display:none';
    wrap.appendChild(pop);

    let cur = input.value && /^\d{4}\/\d{2}\/\d{2}$/.test(input.value) ? input.value : jToday();
    let [cy, cm, cd] = cur.split('/').map(Number);

    function render() {
      const days = jDaysInMonth(cy, cm);
      let html = '<div style="display:flex;align-items:center;gap:6px;margin-bottom:9px">' +
        '<button type="button" class="btn btn-sm btn-ghost" data-nav="-1">‹</button>' +
        '<div style="flex:1;text-align:center;font-weight:800;color:var(--navy)">' + MONTHS[cm - 1] + ' ' + faNum(cy) + '</div>' +
        '<button type="button" class="btn btn-sm btn-ghost" data-nav="1">›</button>' +
        '<button type="button" class="btn btn-sm btn-ghost" data-nav="-12">«</button>' +
        '<button type="button" class="btn btn-sm btn-ghost" data-nav="12">»</button></div>' +
        '<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:3px;text-align:center;font-size:11px;color:var(--gray-500);margin-bottom:5px">' +
        WD.map(w => '<div>' + w.slice(0, 1) + '</div>').join('') + '</div><div style="display:grid;grid-template-columns:repeat(7,1fr);gap:3px" id="hs-dp-days">';
      const firstW = (function () { const g = j2g(cy, cm, 1); return (new Date(g[0], g[1] - 1, g[2]).getDay() + 1) % 7; })();
      for (let i = 0; i < firstW; i++) html += '<span></span>';
      for (let d = 1; d <= days; d++) {
        const val = cy + '/' + pad(cm) + '/' + pad(d);
        const isToday = val === jToday();
        const sel = d === cd && input.value === val;
        const off = isOffDay(val);                       // پنجشنبه / جمعه
        const color = sel ? '#fff' : (off ? '#dc2626' : 'var(--gray-700)');
        html += '<button type="button" data-d="' + d + '"' + (off ? ' data-off="1"' : '') +
          ' title="' + (off ? 'روز تعطیل' : '') + '" style="border:none;cursor:pointer;padding:7px 0;border-radius:9px;font-family:inherit;' +
          'font-size:12.6px;background:' + (sel ? 'var(--blue)' : (isToday ? 'var(--blue-soft)' : 'transparent')) + ';color:' + color +
          ';text-decoration:' + (off && !sel ? 'line-through' : 'none') + ';font-weight:' + (sel || isToday ? '800' : '600') + '">' + faNum(d) + '</button>';
      }
      html += '</div><div style="display:flex;gap:6px;margin-top:10px">' +
        '<button type="button" class="btn btn-sm btn-blue" data-today>امروز</button>' +
        '<button type="button" class="btn btn-sm btn-ghost" data-close>بستن</button></div>';
      pop.innerHTML = html;
      $$('[data-nav]', pop).forEach(b => b.onclick = () => {
        const n = parseInt(b.getAttribute('data-nav'), 10);
        if (Math.abs(n) === 12) { cy += n / 12; } else { cm += n; if (cm > 12) { cm = 1; cy++; } if (cm < 1) { cm = 12; cy--; } }
        render();
      });
      $$('[data-d]', pop).forEach(b => b.onclick = () => {
        cd = parseInt(b.getAttribute('data-d'), 10);
        input.value = cy + '/' + pad(cm) + '/' + pad(cd);
        const off = isOffDay(input.value);
        input.classList.toggle('offday', off);
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.classList.remove('bad'); input.classList.add('good');
        if (off) {
          const allow = window.HS_OFF && window.HS_OFF.allow;
          toast('پنجشنبه و جمعه تعطیل هستند؛ ' + (allow ? 'ثبت در این روز فقط با مجوز مدیر ممکن است.' : 'ثبت مورد در این روز مجاز نیست.'), 'warn', 'روز تعطیل');
        }
        pop.style.display = 'none';
      });
      $('[data-today]', pop).onclick = () => {
        const t = workDate(); input.value = t;   /* روز تعطیل به‌عنوان روز مرجع انتخاب نمی‌شود */
        const p = t.split('/').map(Number); cy = p[0]; cm = p[1]; cd = p[2];
        input.dispatchEvent(new Event('change', { bubbles: true }));
        pop.style.display = 'none'; render();
      };
      $('[data-close]', pop).onclick = () => pop.style.display = 'none';
    }
    input.addEventListener('focus', () => {
      if (input.value && /^\d{4}\/\d{2}\/\d{2}$/.test(input.value)) {
        const p = input.value.split('/').map(Number); cy = p[0]; cm = p[1]; cd = p[2];
      }
      render(); pop.style.display = 'block';
    });
    input.addEventListener('input', () => {
      input.value = enNum(input.value).replace(/[^\d\/]/g, '');
      if (/^\d{4}\/\d{2}\/\d{2}$/.test(input.value)) {
        const p = input.value.split('/').map(Number);
        if (p[2] <= jDaysInMonth(p[0], p[1])) { cy = p[0]; cm = p[1]; cd = p[2]; render(); }
      }
    });
    input.addEventListener('blur', () => setTimeout(() => { if (!wrap.contains(document.activeElement)) pop.style.display = 'none'; }, 180));
  }

  /* ---------- ساعت زنده شمسی ---------- */
  function liveClock(el, jdate, timeStr, source) {
    if (!el) return;
    const base = new Date();
    const parts = (timeStr || '00:00').split(':').map(Number);
    let secs = base.getHours() * 3600 + base.getMinutes() * 60 + base.getSeconds();
    let cur = jdate || jToday();
    function tick() {
      secs++;
      if (secs >= 86400) { secs = 0; cur = jAddDays(cur, 1); }
      const h = Math.floor(secs / 3600), m = Math.floor((secs % 3600) / 60), s = secs % 60;
      el.innerHTML = icon('calendar', 16) + ' ' + faNum(cur) + ' <span style="opacity:.8">' + jWeekday(cur) + '</span> ' +
        icon('clock', 16) + ' ' + faNum(pad(h) + ':' + pad(m) + ':' + pad(s)) +
        (source ? ' <span class="badge ' + (source.indexOf('api') === 0 ? 'green' : 'gray') + '" style="font-size:10px">' +
          (source.indexOf('api') === 0 ? 'API' : 'محلی') + '</span>' : '');
    }
    tick(); setInterval(tick, 1000);
  }

  function initDates(root) { $$('.hs-date', root || document).forEach(i => datePicker(i)); }
  function initSelects(root) { $$('select[data-searchable]', root || document).forEach(s => {
      const w = document.createElement('input');
      w.type = 'text'; w.placeholder = 'جستجو…'; w.className = 'mb10';
      w.style.marginBottom = '6px';
      s.parentNode.insertBefore(w, s);
      const opts = Array.from(s.options).map(o => ({ v: o.value, t: o.textContent, keep: true }));
      w.oninput = () => {
        const q = w.value.trim();
        s.innerHTML = '';
        opts.filter(o => !q || o.t.indexOf(q) > -1).forEach(o => {
          const op = document.createElement('option'); op.value = o.v; op.textContent = o.t; s.appendChild(op);
        });
      };
    }); }

  document.addEventListener('DOMContentLoaded', () => {
    try { const t = localStorage.getItem('hs_theme'); if (t) document.documentElement.setAttribute('data-theme', t); } catch (e) {}
    initShell();
    initDates();
    initSelects();
  });

  return { $: $, $$: $$, icon: icon, ICONS: ICONS, esc: esc,
           toast: toast, modal: modal, confirm: confirmBox, loading: loading, hideLoader: hideLoader, setSidebar: setSidebar,
           isOffDay: isOffDay, jWeekdayIndex: jWeekdayIndex, offDayName: offDayName,
           workDayStep: workDayStep, workDate: workDate,
           datePicker: datePicker, initDates: initDates, initSelects: initSelects, liveClock: liveClock,
           faNum: faNum, enNum: enNum, g2j: g2j, j2g: j2g, jToday: jToday, jAddDays: jAddDays,
           jWeekday: jWeekday, jDaysInMonth: jDaysInMonth, MONTHS: MONTHS, WD: WD };
})();
JS;
    return str_replace('/*__HS_ICONS__*/', json_encode(hs_icon_set(), JSON_UNESCAPED_UNICODE), $js);
}

/** خروجی CSS و JS پایه در تگ استایل/اسکریپت */
function theme_head(array $extraCss = []): void
{
    echo "<style>\n" . theme_css() . "\n";
    foreach ($extraCss as $css) {
        echo "\n/* --- استایل اختصاصی این پنل --- */\n" . $css . "\n";
    }
    echo "</style>\n";
}

/** فوتر: اسکریپت مشترک + بستن قالب */
function layout_foot(array $extraJs = []): void
{
    echo "<script>\n" . theme_js() . "\n</script>\n";
    foreach ($extraJs as $js) {
        echo "<script>\n/* --- اسکریپت اختصاصی این پنل --- */\n" . $js . "\n</script>\n";
    }
    echo "</body></html>";
}

/**
 * سربرگ صفحه (HTML + CSS + نوار بالا) و باز کردن بخش محتوا
 * @param string $title  عنوان صفحه
 * @param string $active کلید منوی فعال (dashboard|students|records|export|admin)
 */
function layout_head(string $title, string $active = '', array $extraCss = []): void
{
    hs_bootstrap();          // همگام‌سازی پرمیشن‌ها در صورت افزوده شدن پرمیشن جدید

    /* نگه‌داشتن عنوان/بخش جاری برای استفاده در layout_end (نوار بازگشت) */
    $GLOBALS['__HS_TITLE']  = $title;
    $GLOBALS['__HS_ACTIVE'] = $active;

    $admin = current_admin();
    $now   = hs_now();
    $name  = $admin['full_name'] ?? '';
    $initial = $name !== '' ? mb_substr($name, 0, 1, 'UTF-8') : '؟';
    $roleLabels = ['super' => 'مدیر کل', 'manager' => 'مدیر', 'deputy' => 'معاون', 'teacher' => 'معلم'];
    $role = $roleLabels[$admin['role'] ?? ''] ?? 'کاربر';
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> | <?= e(HS_SCHOOL_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#0f2a52"/><text x="32" y="44" font-size="34" text-anchor="middle" fill="#fff">ه</text></svg>') ?>">
<!-- فونت وزیرمتن از CDN (اختیاری). در صورت عدم دسترسی، فونت سیستمی جایگزین می‌شود -->
<style>
@font-face{font-family:"Vazirmatn";font-style:normal;font-weight:400 900;font-display:swap;
 src:url("https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/fonts/webfonts/Vazirmatn%5Bwght%5D.woff2") format("woff2")}
</style>
<script>(function(){try{var t=localStorage.getItem('hs_theme');if(t)document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
<?php theme_head($extraCss); ?>
</head>
<body>

<!-- ================= سایدبار ناوبری ================= -->
<aside class="sidebar">
  <div class="brand">
    <div class="logo"><?= hs_icon('school', 26) ?></div>
    <div>
      <b><?= e(HS_SCHOOL_NAME) ?></b>
      <small>سامانه جامع مدیریت آموزشی</small>
    </div>
  </div>
  <nav class="nav">
    <div class="nav-title">منوی اصلی</div>
    <a href="index.php"    class="<?= $active === 'dashboard' ? 'active' : '' ?>"><span class="ic"><?= hs_icon('home', 19) ?></span> داشبورد</a>
    <?php if (has_perm('view_students') || has_perm('add_student')): ?>
      <a href="students.php" class="<?= $active === 'students' ? 'active' : '' ?>"><span class="ic"><?= hs_icon('student', 19) ?></span> دانش‌آموزان</a>
    <?php endif; ?>
    <?php if (has_perm('register_delay') || has_perm('register_absence') || has_perm('register_discipline') || has_perm('register_dress')): ?>
      <a href="records.php"  class="<?= $active === 'records' ? 'active' : '' ?>"><span class="ic"><?= hs_icon('clipboard', 19) ?></span> ثبت موارد</a>
    <?php endif; ?>
    <?php if (has_perm('teacher_panel')): ?>
      <a href="teacher.php"  class="<?= $active === 'teacher' ? 'active' : '' ?>"><span class="ic"><?= hs_icon('teacher', 19) ?></span> پنل معلم (کلاس‌ها)</a>
    <?php endif; ?>
    <?php if (has_perm('export_pdf')): ?>
      <a href="export.php"   class="<?= $active === 'export' ? 'active' : '' ?>"><span class="ic"><?= hs_icon('file', 19) ?></span> گزارش و PDF</a>
    <?php endif; ?>
    <?php if (has_perm('manage_admins')): ?>
      <a href="admin.php"    class="<?= $active === 'admin' ? 'active' : '' ?>"><span class="ic"><?= hs_icon('shield', 19) ?></span> مدیریت ادمین‌ها</a>
    <?php endif; ?>
    <div class="nav-title">سایر</div>
    <a href="index.php?refresh_time=1"><span class="ic"><?= hs_icon('refresh', 19) ?></span> بروزرسانی تاریخ/ساعت</a>
    <a href="logout.php" onclick="return confirm('از سامانه خارج می‌شوید؟')"><span class="ic"><?= hs_icon('logout', 19) ?></span> خروج از حساب</a>
  </nav>
  <div class="side-foot">
    <div class="u">
      <div class="avatar"><?= e($initial) ?></div>
      <div>
        <b style="color:#fff;font-size:13px"><?= e($name) ?></b><br>
        <span><?= e($role) ?><?= is_super() ? ' ' . hs_icon('star', 13) : '' ?></span>
      </div>
    </div>
    <div class="small">نسخه <?= fa_num(HS_VERSION) ?> — <?= e(date('Y/m/d')) ?></div>
  </div>
</aside>
<div class="overlay"></div>

<!-- ================= محتوای اصلی ================= -->
<div class="main">
  <header class="topbar">
    <button class="burger" type="button" id="burgerBtn" aria-label="نمایش منوی اصلی" aria-expanded="false" title="منوی اصلی"><?= hs_icon('menu', 22) ?></button>
    <div class="page-title"><?= e($title) ?></div>
    <div class="spacer"></div>
    <div class="clock" id="hs-clock"></div>
    <?php if ($active !== 'dashboard'): ?>
      <a class="btn btn-sm btn-back" href="index.php" title="بازگشت به داشبورد"><?= hs_icon('home', 16) ?> منوی اصلی</a>
    <?php endif; ?>
    <button class="btn btn-sm btn-ghost" type="button" data-theme-toggle title="تغییر تم شب/روز"></button>
  </header>
  <div class="content">
    <?php if ($active !== 'dashboard'): ?>
      <!-- ناوبری مسیر (Breadcrumb) + دکمه بازگشت به منوی اصلی در همه بخش‌ها -->
      <nav class="crumb" aria-label="مسیر ناوبری">
        <a href="index.php"><?= hs_icon('home', 15) ?> <span class="home-text">منوی اصلی / داشبورد</span></a>
        <span aria-hidden="true">›</span>
        <span class="crumb-cur"><?= e($title) ?></span>
        <span style="flex:1"></span>
        <button class="btn btn-sm btn-ghost" type="button" onclick="if(history.length>1){history.back()}else{location.href='index.php'}"
                title="بازگشت به صفحه قبل"><?= hs_icon('logout', 15) ?> صفحه قبل</button>
      </nav>
    <?php endif; ?>
    <?php
    /* پیام‌های فلش: هم به‌صورت هشدار درون صفحه و هم به‌صورت توست شناور نمایش داده می‌شوند.
       توست بالاتر از پنجره‌های مودال قرار می‌گیرد (z-index بیشتر) تا پیام موفقیت/خطا
       حتی وقتی پنجرهٔ ثبت باز است هم دیده شود. */
    $flashToasts = [];
    foreach (flash_take() as $f):
        $map = ['ok' => 'ok', 'success' => 'ok', 'error' => 'err', 'danger' => 'err', 'warning' => 'warn', 'info' => 'info'];
        $cls = $map[$f['type']] ?? 'info';
        $icon = ['ok' => hs_icon('check', 18), 'err' => hs_icon('ban', 18), 'warn' => hs_icon('alert', 18), 'info' => hs_icon('info', 18)][$cls];
        $flashToasts[] = ['m' => $f['message'], 't' => $cls];
    ?>
      <div class="alert <?= $cls ?>"><span><?= $icon ?></span><span><?= e($f['message']) ?></span></div>
    <?php endforeach; ?>
    <script>
      window.__HS_CLOCK__ = <?= json_encode(['jdate' => $now['jdate'], 'time' => $now['time'], 'source' => $now['source']], JSON_UNESCAPED_UNICODE) ?>;
      /* تنظیمات روزهای تعطیل برای استفاده در تقویم و اعتبارسنجی سمت کلاینت */
      /* نگاشت پایه → رشته‌های مجاز برای استفاده در همهٔ صفحات (قانون: کامپیوتر در دوازدهم نیست) */
      window.HS_FIELDS_BY_GRADE = <?= json_encode(HS_FIELDS_BY_GRADE, JSON_UNESCAPED_UNICODE) ?>;
      window.HS_GRADES = <?= json_encode(HS_GRADES, JSON_UNESCAPED_UNICODE) ?>;
      window.HS_OFF = <?= json_encode([
          'days'     => hs_off_weekdays(),
          'names'    => hs_off_weekday_names(),
          'allow'    => hs_allow_offday_records(),
          'today'    => jalali_today(),
          /* اگر امروز تعطیل باشد، آخرین روز کاری به‌عنوان روز پیش‌فرض برگردانده می‌شود */
          'workDate' => hs_default_work_date(),
          'todayOff' => hs_is_off_day(jalali_today()),
      ], JSON_UNESCAPED_UNICODE) ?>;
      /* نمایش پیام‌های سرور به‌صورت توست (بالاتر از مودال‌ها)
         نکته: این بلوک پیش از تعریف window.HS اجرا می‌شود، پس تا آماده شدن HS صبر می‌کنیم */
      (function () {
        const msg = <?= json_encode($flashToasts, JSON_UNESCAPED_UNICODE) ?>;
        if (!msg.length) { return; }
        function fire() {
          if (!window.HS || !HS.toast) { return; }
          msg.forEach(function (f, i) {
            setTimeout(function () { try { HS.toast(HS.esc(f.m), f.t); } catch (e) { } }, 180 + i * 450);
          });
        }
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fire); }
        else { fire(); }
      })();
    </script>
<?php
}

/** بستن قالب محتوا و افزودن اسکریپت راه‌اندازی ساعت زنده */
function layout_end(array $extraJs = [], bool $noBackBar = false): void
{
    $active = (string)($GLOBALS['__HS_ACTIVE'] ?? '');
    if ($active !== '' && $active !== 'dashboard' && !$noBackBar) {
        /* نوار بازگشت به منوی اصلی در پایان هر بخش */
        echo <<<'HTML'
    <div class="back-bar no-print">
      <a class="btn btn-primary" href="index.php"><?= hs_icon('home', 17) ?> بازگشت به منوی اصلی</a>
      <a class="btn btn-ghost" href="index.php?refresh_time=0#top"><?= hs_icon('refresh', 17) ?> شروع دوباره این بخش</a>
      <div class="grow"></div>
      <button class="btn btn-ghost" type="button" onclick="window.scrollTo({top:0,behavior:'smooth'})"><?= hs_icon('upload', 16) ?> ابتدای صفحه</button>
    </div>
HTML;
    }
    echo <<<'HTML'
    <!-- دکمه شناور «منوی اصلی» برای موبایل/تبلت -->
    <button class="fab-menu" type="button" onclick="document.getElementById('burgerBtn').click();"
            title="نمایش منوی اصلی"><?= hs_icon('menu', 18) ?> منوی اصلی</button>
  </div>
</div>
HTML;
    $boot = <<<'JS'
document.addEventListener('DOMContentLoaded', function () {
  if (window.__HS_CLOCK__) HS.liveClock(document.getElementById('hs-clock'),
      window.__HS_CLOCK__.jdate, window.__HS_CLOCK__.time, window.__HS_CLOCK__.source);
});
JS;
    layout_foot(array_merge([$boot], $extraJs));
}

/* ---------------------------------------------------------------------------
 * ۱۰) توابع خواندن فایل‌های اکسل/متنی برای ورود گروهی دانش‌آموزان
 * ------------------------------------------------------------------------ */

/**
 * خواندن فایل XLSX بدون کتابخانه خارجی (ZipArchive + DOMDocument)
 * @return array<int,array<int,string>> سطرها به‌صورت آرایه ستون‌ها
 */
function read_xlsx_rows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('افزونه zip روی سرور فعال نیست؛ امکان خواندن فایل اکسل وجود ندارد. لطفاً از فایل CSV/متنی استفاده کنید.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('فایل اکسل قابل خواندن نیست. اگر فایل با فرمت قدیمی xls است، آن را با فرمت xlsx یا CSV ذخیره کنید.');
    }

    /* رشته‌های مشترک (sharedStrings) */
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $doc = new DOMDocument();
        @$doc->loadXML($ssXml);
        foreach ($doc->getElementsByTagName('si') as $si) {
            $buf = '';
            foreach ($si->getElementsByTagName('t') as $t) {
                $buf .= $t->nodeValue;
            }
            $shared[] = $buf;
        }
    }

    /* پیدا کردن اولین شیت */
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if ($n && preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) {
                $sheetXml = $zip->getFromName($n);
                break;
            }
        }
    }
    $zip->close();
    if ($sheetXml === false || $sheetXml === null) {
        throw new RuntimeException('ساختار فایل اکسل شناسایی نشد (شیت اول یافت نشد).');
    }

    $doc = new DOMDocument();
    @$doc->loadXML($sheetXml);
    $out = [];
    foreach ($doc->getElementsByTagName('row') as $rowNode) {
        $cells = [];
        $maxIdx = -1;
        foreach ($rowNode->childNodes as $cell) {
            if (!($cell instanceof DOMElement) || $cell->localName !== 'c') {
                continue;
            }
            /* شناسه سلول مثل B7 → شماره ستون */
            $ref  = $cell->getAttribute('r');
            $col  = 0;
            if (preg_match('/^([A-Z]+)/', $ref, $m)) {
                $letters = $m[1];
                for ($i = 0; $i < strlen($letters); $i++) {
                    $col = $col * 26 + (ord($letters[$i]) - 64);
                }
                $col--;
            } else {
                $col = $maxIdx + 1;
            }
            $type = $cell->getAttribute('t');
            $val  = '';
            if ($type === 'inlineStr') {
                foreach ($cell->getElementsByTagName('t') as $t) {
                    $val .= $t->nodeValue;
                }
            } else {
                $vNodes = $cell->getElementsByTagName('v');
                if ($vNodes->length > 0) {
                    $val = $vNodes->item(0)->nodeValue;
                    if ($type === 's') {
                        $val = $shared[(int)$val] ?? '';
                    }
                }
            }
            $cells[$col] = trim((string)$val);
            $maxIdx = max($maxIdx, $col);
        }
        /* پر کردن ستون‌های خالی */
        $line = [];
        for ($i = 0; $i <= $maxIdx; $i++) {
            $line[] = $cells[$i] ?? '';
        }
        if (implode('', $line) !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

/** خواندن فایل متنی/CSV با تشخیص جداکننده و کدگذاری */
function read_text_rows(string $path): array
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('فایل قابل خواندن نیست.');
    }
    /* حذف BOM */
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
    /* تشخیص کدگذاری: در صورت نبود UTF-8 معتبر، از Windows-1256 (فارسی قدیمی) استفاده می‌شود */
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1256');
    }
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $lines = array_values(array_filter(explode("\n", $content), static fn($l) => trim($l) !== ''));
    if (!$lines) {
        return [];
    }
    /* تشخیص جداکننده از بین tab / , / ; / | */
    $candidates = ["\t", ",", ";", "|"];
    $delim = ',';
    $best = 0;
    foreach ($candidates as $c) {
        $count = substr_count($lines[0], $c);
        if ($count > $best) {
            $best = $count;
            $delim = $c;
        }
    }
    $rows = [];
    foreach ($lines as $line) {
        $rows[] = array_map('trim', explode($delim, $line));
    }
    return $rows;
}

/**
 * نرمال‌سازی سطرهای خام فایل به سطرهای استاندارد دانش‌آموز
 * تشخیص خودکار سربرگ (نام، نام خانوادگی، پایه، رشته، تلفن پدر، تلفن مادر)
 * @return array{rows:array<int,array>,errors:array<int,string>}
 */
function parse_students_rows(array $raw): array
{
    $rows   = [];
    $errors = [];
    if (!$raw) {
        return ['rows' => [], 'errors' => ['فایل خالی است یا هیچ سطری خوانده نشد.']];
    }

    /* --- تشخیص خودکار سربرگ ---
       نکته مهم: عنوان‌های بلندتر اول بررسی می‌شوند تا «نام خانوادگی» اشتباهاً
       با کلید «نام» تطبیق داده نشود. هر فیلد نیز فقط یک‌بار نگاشت می‌شود. */
    $headerMap = [];
    $firstRow  = $raw[0];
    $keys = [
        'first_name'  => ['نام', 'اسم', 'نام کوچک', 'first name', 'firstname', 'first', 'name'],
        'last_name'   => ['نام خانوادگی', 'نام‌خانوادگی', 'خانوادگی', 'فامیل', 'فامیلی', 'last name', 'lastname', 'last', 'family', 'surname'],
        'grade'       => ['پایه تحصیلی', 'پایه', 'کلاس', 'grade', 'year'],
        'field'       => ['رشته تحصیلی', 'رشته', 'field', 'major'],
        'father_phone'=> ['تلفن همراه پدر', 'تلفن پدر', 'موبایل پدر', 'شماره پدر', 'شماره همراه پدر', 'father', 'phone1', 'father_phone'],
        'mother_phone'=> ['تلفن همراه مادر', 'تلفن مادر', 'موبایل مادر', 'شماره مادر', 'شماره همراه مادر', 'mother', 'phone2', 'mother_phone'],
    ];
    /* ساخت فهرست (فیلد، عنوان) و مرتب‌سازی نزولی بر اساس طول عنوان */
    $pairs = [];
    foreach ($keys as $field => $aliases) {
        foreach ($aliases as $al) {
            $pairs[] = [$field, $al, mb_strlen($al, 'UTF-8')];
        }
    }
    usort($pairs, static fn(array $a, array $b): int => $b[2] <=> $a[2]);

    $used = [];
    foreach ($firstRow as $i => $cell) {
        $c = clean_text($cell, 40);
        if ($c === '') {
            continue;
        }
        foreach ($pairs as [$field, $alias]) {
            if (isset($used[$field])) {
                continue;
            }
            if (mb_stripos($c, $alias) !== false) {
                $headerMap[$field] = $i;
                $used[$field] = true;
                break;
            }
        }
    }
    /* اگر کمتر از ۴ فیلد شناسایی شد، فرض می‌کنیم سطر اول سربرگ نیست */
    $looksLikeHeader = count($headerMap) >= 4;
    if (!$looksLikeHeader) {
        /* ترتیب پیش‌فرض ستون‌ها: نام | نام خانوادگی | پایه | رشته | تلفن پدر | تلفن مادر */
        $headerMap = ['first_name' => 0, 'last_name' => 1, 'grade' => 2, 'field' => 3, 'father_phone' => 4, 'mother_phone' => 5];
    }
    $startIndex = $looksLikeHeader ? 1 : 0;
    $seen = [];

    for ($i = $startIndex; $i < count($raw); $i++) {
        $r = $raw[$i];
        if (implode('', array_map('strval', $r)) === '') {
            continue;
        }
        $get = static fn(string $k) => isset($headerMap[$k], $r[$headerMap[$k]]) ? clean_text($r[$headerMap[$k]], 80) : '';
        $line = $i + 1;

        $first = validate_name($get('first_name'));
        $last  = validate_name($get('last_name'));
        $grade = validate_grade($get('grade'));

        if ($first === null) {
            $errors[] = "سطر {$line}: نام نامعتبر است («" . $get('first_name') . "»).";
            continue;
        }
        if ($last === null) {
            $errors[] = "سطر {$line}: نام خانوادگی نامعتبر است («" . $get('last_name') . "»).";
            continue;
        }
        if ($grade === null) {
            $errors[] = "سطر {$line}: پایه «" . $get('grade') . "» نامعتبر است (دهم/یازدهم/دوازدهم).";
            continue;
        }
        $field = validate_field($get('field'), $grade);
        if ($field === null) {
            $allowed = implode('، ', fields_for_grade($grade));
            $errors[] = "سطر {$line}: رشته «" . $get('field') . "» برای پایه {$grade} مجاز نیست (رشته‌های مجاز: {$allowed}).";
            continue;
        }
        /* تلفن در ورود گروهی هم اختیاری است: خالی مجاز، ولی اگر پر شده باشد باید معتبر باشد */
        $fpRaw = $get('father_phone');
        $fp = $fpRaw === '' ? '' : validate_phone($fpRaw);
        if ($fp === null) {
            $errors[] = "سطر {$line}: شماره تلفن پدر نامعتبر است («{$fpRaw}») — یا آن را خالی بگذارید.";
            continue;
        }
        $mpRaw = $get('mother_phone');
        $mp = $mpRaw === '' ? '' : validate_phone($mpRaw);
        if ($mp === null) {
            $errors[] = "سطر {$line}: شماره تلفن مادر نامعتبر است («{$mpRaw}») — یا آن را خالی بگذارید.";
            continue;
        }
        $dupKey = $first . '|' . $last . '|' . $grade . '|' . $field;
        if (isset($seen[$dupKey])) {
            $errors[] = "سطر {$line}: «{$first} {$last}» تکراری است و نادیده گرفته شد.";
            continue;
        }
        $seen[$dupKey] = true;
        $rows[] = [
            'first_name'   => $first,
            'last_name'    => $last,
            'grade'        => $grade,
            'field'        => $field,
            'father_phone' => $fp,
            'mother_phone' => $mp,
        ];
    }
    return ['rows' => $rows, 'errors' => $errors];
}

/* ---------------------------- END OF DB.PHP ------------------------------ */
