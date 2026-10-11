<?php
/**
 * ============================================================================
 *  سامانه جامع مدیریت هنرستان شهید رجایی
 *  فایل: install.php — نصب‌کننده خودکار (Wizard)
 * ----------------------------------------------------------------------------
 *  مراحل نصب:
 *    گام ۱) بررسی پیش‌نیازهای سرور (نسخه PHP، افزونه‌ها، دسترسی نوشتن)
 *    گام ۲) پیکربندی پایگاه داده (MySQL/MariaDB یا SQLite) + ساخت config.php
 *    گام ۳) ساخت جداول، درج پرمیشن‌ها، ساخت مدیر کل و داده نمونه
 *
 *  تمام CSS و JavaScript این پنل به‌صورت Inline در همین فایل است.
 * ============================================================================
 */

require __DIR__ . '/db.php';

/* شروع نشست امن پیش از هر خروجی (لازم برای ارسال کوکی نشست و توکن CSRF) */
hs_session_start();

@ini_set('display_errors', '0');

$step      = hs_is_installed() ? 'done' : 'welcome';
$messages  = [];   // پیام‌های خطا/موفقیت
$done      = [];   // گزارش نهایی نصب

/* ---------------------------------------------------------------------------
 * بررسی پیش‌نیازها
 * ------------------------------------------------------------------------ */
function requirement_checks(): array
{
    $dirOk  = is_writable(__DIR__);
    $dataOk = is_dir(__DIR__ . '/data') ? is_writable(__DIR__ . '/data') : @mkdir(__DIR__ . '/data', 0777, true);
    $checks = [
        ['label' => 'نسخه PHP (حداقل ۸.۰)', 'ok' => version_compare(PHP_VERSION, '8.0.0', '>='), 'value' => PHP_VERSION, 'critical' => true],
        ['label' => 'افزونه PDO',            'ok' => extension_loaded('pdo'),        'value' => extension_loaded('pdo') ? 'فعال' : 'غیرفعال', 'critical' => true],
        ['label' => 'افزونه mbstring',       'ok' => extension_loaded('mbstring'),   'value' => extension_loaded('mbstring') ? 'فعال' : 'غیرفعال', 'critical' => true],
        ['label' => 'افزونه JSON',           'ok' => extension_loaded('json'),       'value' => extension_loaded('json') ? 'فعال' : 'غیرفعال', 'critical' => true],
        ['label' => 'درایور pdo_mysql',      'ok' => extension_loaded('pdo_mysql'),  'value' => extension_loaded('pdo_mysql') ? 'فعال' : 'غیرفعال', 'critical' => false],
        ['label' => 'درایور pdo_sqlite',     'ok' => extension_loaded('pdo_sqlite'), 'value' => extension_loaded('pdo_sqlite') ? 'فعال' : 'غیرفعال', 'critical' => false],
        ['label' => 'افزونه zip (خواندن Excel)', 'ok' => extension_loaded('zip'),   'value' => extension_loaded('zip') ? 'فعال' : 'غیرفعال', 'critical' => false],
        ['label' => 'افزونه curl (تاریخ API)',   'ok' => extension_loaded('curl'),  'value' => extension_loaded('curl') ? 'فعال' : 'غیرفعال', 'critical' => false],
        ['label' => 'دسترسی نوشتن در پوشه پروژه', 'ok' => (bool)$dirOk,  'value' => $dirOk ? 'قابل نوشتن' : 'قفل است (chmod 755)', 'critical' => true],
        ['label' => 'دسترسی نوشتن در پوشه data',  'ok' => (bool)$dataOk, 'value' => $dataOk ? 'قابل نوشتن' : 'قابل نوشتن نیست', 'critical' => true],
    ];
    return $checks;
}

/* ---------------------------------------------------------------------------
 * ساختار جداول — مستقل از موتور پایگاه داده
 * ------------------------------------------------------------------------ */
function schema_sql(string $driver): array
{
    $pk = $driver === 'mysql'
        ? 'INT AUTO_INCREMENT PRIMARY KEY'
        : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $tbl = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    return [
        'admins' => "CREATE TABLE IF NOT EXISTS admins (
            id {$pk},
            full_name VARCHAR(120) NOT NULL,
            username  VARCHAR(60)  NOT NULL UNIQUE,
            password  VARCHAR(255) NOT NULL,
            role      VARCHAR(20)  NOT NULL DEFAULT 'teacher',
            is_super  INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME NULL
        ){$tbl}",

        'permissions' => "CREATE TABLE IF NOT EXISTS permissions (
            id INTEGER PRIMARY KEY " . ($driver === 'mysql' ? 'AUTO_INCREMENT' : '') . ",
            name  VARCHAR(60)  NOT NULL UNIQUE,
            label VARCHAR(120) NOT NULL,
            pgroup VARCHAR(60)  NOT NULL DEFAULT ''
        ){$tbl}",

        'admin_permissions' => "CREATE TABLE IF NOT EXISTS admin_permissions (
            admin_id      INTEGER NOT NULL,
            permission_id INTEGER NOT NULL,
            PRIMARY KEY (admin_id, permission_id),
            FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
            FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
        ){$tbl}",

        'students' => "CREATE TABLE IF NOT EXISTS students (
            id {$pk},
            first_name   VARCHAR(60)  NOT NULL,
            last_name    VARCHAR(60)  NOT NULL,
            grade        VARCHAR(20)  NOT NULL,
            field        VARCHAR(40)  NOT NULL,
            father_phone VARCHAR(15)  NOT NULL,
            mother_phone VARCHAR(15)  NOT NULL,
            created_at   DATETIME NULL
        ){$tbl}",
        'idx_students_grade'   => 'CREATE INDEX IF NOT EXISTS idx_students_grade ON students (grade)',
        'idx_students_field'   => 'CREATE INDEX IF NOT EXISTS idx_students_field ON students (field)',
        'idx_students_lastname' => 'CREATE INDEX IF NOT EXISTS idx_students_lastname ON students (last_name)',

        'delays' => "CREATE TABLE IF NOT EXISTS delays (
            id {$pk},
            student_id  INTEGER NOT NULL,
            jdate       VARCHAR(10) NOT NULL,
            gdate       DATE NOT NULL,
            time        VARCHAR(5)  NOT NULL,
            description VARCHAR(255) NULL,
            created_by  INTEGER NULL,
            created_at  DATETIME NULL,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ){$tbl}",
        'idx_delays_date' => 'CREATE INDEX IF NOT EXISTS idx_delays_jdate ON delays (jdate)',

        'absences' => "CREATE TABLE IF NOT EXISTS absences (
            id {$pk},
            student_id   INTEGER NOT NULL,
            jdate        VARCHAR(10) NOT NULL,
            gdate        DATE NOT NULL,
            day_of_week  VARCHAR(20) NOT NULL,
            description  VARCHAR(255) NULL,
            lesson       VARCHAR(120) NULL,
            time         VARCHAR(5) NULL,
            created_by   INTEGER NULL,
            created_at   DATETIME NULL,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ){$tbl}",
        'idx_absences_date' => 'CREATE INDEX IF NOT EXISTS idx_absences_jdate ON absences (jdate)',

        'disciplines' => "CREATE TABLE IF NOT EXISTS disciplines (
            id {$pk},
            student_id  INTEGER NOT NULL,
            jdate       VARCHAR(10) NOT NULL,
            gdate       DATE NOT NULL,
            type        VARCHAR(60) NOT NULL,
            description VARCHAR(500) NULL,
            deduction   INTEGER NULL DEFAULT 0,
            created_by  INTEGER NULL,
            created_at  DATETIME NULL,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ){$tbl}",
        'idx_disc_date' => 'CREATE INDEX IF NOT EXISTS idx_disciplines_gdate ON disciplines (gdate)',

        'dress_violations' => "CREATE TABLE IF NOT EXISTS dress_violations (
            id {$pk},
            student_id INTEGER NOT NULL,
            jdate      VARCHAR(10) NOT NULL,
            gdate      DATE NOT NULL,
            type       VARCHAR(30) NOT NULL,
            description VARCHAR(255) NULL,
            created_by INTEGER NULL,
            created_at DATETIME NULL,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ){$tbl}",

        'login_attempts' => "CREATE TABLE IF NOT EXISTS login_attempts (
            id {$pk},
            username   VARCHAR(100) NOT NULL,
            ip         VARCHAR(45)  NOT NULL,
            success    INTEGER NOT NULL DEFAULT 0,
            created_at VARCHAR(25)  NOT NULL
        ){$tbl}",

        'activity_log' => "CREATE TABLE IF NOT EXISTS activity_log (
            id {$pk},
            admin_id   INTEGER NULL,
            action     VARCHAR(120) NOT NULL,
            detail     VARCHAR(500) NULL,
            ip         VARCHAR(45)  NULL,
            created_at VARCHAR(25)  NULL
        ){$tbl}",

        'settings' => "CREATE TABLE IF NOT EXISTS settings (
            id {$pk},
            skey VARCHAR(60) NOT NULL UNIQUE,
            sval VARCHAR(500) NULL
        ){$tbl}",
    ];
}

/** ساخت جداول */
function create_tables(string $driver): array
{
    $log = [];
    foreach (schema_sql($driver) as $name => $sql) {
        try {
            db()->exec($sql);
            $log[] = $name;
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            // در MySQL ممکن است CREATE INDEX IF NOT EXISTS پشتیبانی نشود → نادیده گرفته می‌شود
            if (str_contains($msg, 'Duplicate key name') || str_contains($msg, 'already exists')) {
                continue;
            }
            if (str_starts_with($name, 'idx_')) {
                continue;
            }
            throw new RuntimeException("خطا در ساخت «{$name}»: " . $msg);
        }
    }
    return $log;
}

/** درج پرمیشن‌ها */
function seed_permissions(): void
{
    foreach (HS_PERMISSIONS as $name => $meta) {
        $exists = (int)one('SELECT COUNT(*) FROM permissions WHERE name = ?', [$name]);
        if (!$exists) {
            db_insert('permissions', ['name' => $name, 'label' => $meta['label'], 'pgroup' => $meta['group']]);
        } else {
            db_update('permissions', ['label' => $meta['label'], 'pgroup' => $meta['group']], ['name' => $name]);
        }
    }
}

/** ساخت ادمین‌ها */
function seed_admins(string $superUser, string $superPass, string $superName, bool $withDemo): array
{
    $created = [];
    $hash = password_hash($superPass, PASSWORD_DEFAULT);
    $existing = row('SELECT * FROM admins WHERE username = ?', [$superUser]);
    if ($existing) {
        db_update('admins', ['password' => $hash, 'full_name' => $superName, 'is_super' => 1, 'role' => 'super', 'is_active' => 1], ['id' => $existing['id']]);
    } else {
        db_insert('admins', [
            'full_name' => $superName, 'username' => $superUser, 'password' => $hash,
            'role' => 'super', 'is_super' => 1, 'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    $created[] = ['role' => 'مدیر کل (بدون محدودیت)', 'user' => $superUser, 'pass' => $superPass];

    if ($withDemo) {
        $demo = [
            ['full_name' => 'مریم کریمی (معاون آموزشی)', 'username' => 'deputy', 'password' => 'Deputy@1404', 'role' => 'deputy',
             'perms' => ['view_students', 'add_student', 'edit_student', 'register_delay', 'register_absence', 'register_discipline', 'register_dress', 'export_pdf']],
            /* معلم: فقط پنل کلاس‌ها (بدون دسترسی به فهرست کامل دانش‌آموزان) */
            ['full_name' => 'رضا محمدی (معلم)', 'username' => 'teacher', 'password' => 'Teacher@1404', 'role' => 'teacher',
             'perms' => ['teacher_panel', 'register_delay', 'register_absence']],
            ['full_name' => 'سمیه احمدی (مسئول انضباط)', 'username' => 'discipline', 'password' => 'Discipline@1404', 'role' => 'manager',
             'perms' => ['view_students', 'register_discipline', 'register_dress', 'export_pdf']],
            /* ناظر ارشد: نمونهٔ کاربری که سوابق همهٔ معلمان و معاونان را می‌بیند (پرمیشن view_all_records) */
            ['full_name' => 'ناظر ارشد هنرستان', 'username' => 'supervisor', 'password' => 'Supervisor@1404', 'role' => 'deputy',
             'perms' => ['view_students', 'register_delay', 'register_absence', 'register_discipline', 'register_dress', 'export_pdf', 'view_all_records']],
        ];
        foreach ($demo as $d) {
            $ex = row('SELECT * FROM admins WHERE username = ?', [$d['username']]);
            $hash = password_hash($d['password'], PASSWORD_DEFAULT);
            if ($ex) {
                db_update('admins', ['password' => $hash, 'full_name' => $d['full_name'], 'role' => $d['role'], 'is_active' => 1], ['id' => $ex['id']]);
                $id = (int)$ex['id'];
            } else {
                $id = db_insert('admins', [
                    'full_name' => $d['full_name'], 'username' => $d['username'], 'password' => $hash,
                    'role' => $d['role'], 'is_super' => 0, 'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            db_delete('admin_permissions', ['admin_id' => $id]);
            foreach ($d['perms'] as $p) {
                $pid = one('SELECT id FROM permissions WHERE name = ?', [$p]);
                if ($pid) {
                    db_insert('admin_permissions', ['admin_id' => $id, 'permission_id' => (int)$pid]);
                }
            }
            $created[] = ['role' => $d['full_name'], 'user' => $d['username'], 'pass' => $d['password']];
        }
    }
    return $created;
}

/** داده نمونه: دانش‌آموزان + تعدادی غیبت/تاخیر/انضباط در روزهای اخیر */
function seed_demo_data(): array
{
    if ((int)one('SELECT COUNT(*) FROM students') > 0) {
        return ['students' => 0, 'records' => 0];
    }
    $names = [
        ['علی', 'رضایی', 'دهم', 'کامپیوتر'],       ['محمد', 'احمدی', 'دهم', 'الکتروتکنیک'],
        ['حسین', 'مرادی', 'دهم', 'مکانیک'],        ['امیر', 'کریمی', 'دهم', 'بهداشتی'],
        ['رضا', 'موسوی', 'دهم', 'مستقل'],          ['مهدی', 'حسینی', 'دهم', 'سرمایش'],
        ['علی‌رضا', 'نجفی', 'یازدهم', 'کامپیوتر'], ['سعید', 'قاسمی', 'یازدهم', 'الکتروتکنیک'],
        ['یاسین', 'صادقی', 'یازدهم', 'مکانیک'],    ['طاها', 'زمانی', 'یازدهم', 'بهداشتی'],
        ['ابوالفضل', 'شریفی', 'یازدهم', 'سرمایش'], ['پرهام', 'کاظمی', 'یازدهم', 'مستقل'],
        ['سینا', 'اسدی', 'دوازدهم', 'الکتروتکنیک'],['محمدطاها', 'بهرامی', 'دوازدهم', 'مکانیک'],
        ['عرفان', 'توکلی', 'دوازدهم', 'بهداشتی'],  ['کیان', 'رحیمی', 'دوازدهم', 'سرمایش'],
        ['آرش', 'عبدی', 'دوازدهم', 'مستقل'],       ['شایان', 'حیدری', 'دوازدهم', 'الکتروتکنیک'],
    ];
    $adminId = one('SELECT id FROM admins ORDER BY id LIMIT 1');
    $ids = [];
    $phones = 9121000000;
    foreach ($names as $i => $n) {
        $ids[] = db_insert('students', [
            'first_name'   => $n[0],
            'last_name'    => $n[1],
            'grade'        => $n[2],
            'field'        => validate_field($n[3], $n[2]) ?? $n[3],
            'father_phone' => '0' . (++$phones),
            'mother_phone' => '0' . (++$phones + 5000),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    $today = jalali_today();
    $records = 0;
    $dressTypes = HS_DRESS_TYPES;
    $discTypes  = HS_DISCIPLINE_TYPES;

    /* ثبت داده برای چند روز اخیر (شامل امروز)
       برای امروز حتماً چند رکورد ثبت می‌شود تا داشبورد و گزارش‌ها خالی نباشند */
    for ($back = 0; $back <= 6; $back++) {
        $j = jalali_add_days($today, -$back);
        $g = jalali_to_gregorian_str($j);
        /* پنجشنبه و جمعه تعطیل است و هیچ رکورد نمونه‌ای برای آن‌ها ساخته نمی‌شود */
        if (hs_is_off_day($j)) {
            continue;
        }
        $isTodayRun = ($back === 0);
        $dow = jalali_weekday_name($j);   // نام روز هفته (شنبه … جمعه) برای ستون day_of_week
        // غیبت
        foreach ((array)array_rand($ids, $isTodayRun ? 2 : random_int(1, 3)) as $k) {
            db_insert('absences', [
                'student_id' => $ids[$k], 'jdate' => $j, 'gdate' => $g, 'day_of_week' => $dow,
                'description' => null, 'created_by' => $adminId, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $records++;
        }
        // تأخیر
        foreach ((array)array_rand($ids, $isTodayRun ? 4 : random_int(2, 5)) as $k) {
            db_insert('delays', [
                'student_id' => $ids[$k], 'jdate' => $j, 'gdate' => $g,
                'time' => sprintf('%02d:%02d', random_int(7, 9), random_int(0, 59)),
                'description' => 'تأخیر ورود به کلاس', 'created_by' => $adminId, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $records++;
        }
        // موارد انضباطی
        if ($isTodayRun || random_int(0, 1) === 1) {
            $k = array_rand($ids);
            $type = $discTypes[array_rand($discTypes)];
            db_insert('disciplines', [
                'student_id' => $ids[$k], 'jdate' => $j, 'gdate' => $g, 'type' => $type,
                'description' => 'ثبت مورد نمونه در گزارش اولیه سامانه', 'deduction' => random_int(0, 3),
                'created_by' => $adminId, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $records++;
        }
        // تخلف پوششی
        if ($isTodayRun || random_int(0, 2) === 1) {
            $k = array_rand($ids);
            db_insert('dress_violations', [
                'student_id' => $ids[$k], 'jdate' => $j, 'gdate' => $g,
                'type' => $dressTypes[array_rand($dressTypes)], 'description' => null,
                'created_by' => $adminId, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $records++;
        }
    }
    return ['students' => count($ids), 'records' => $records];
}

/** نوشتن فایل پیکربندی */
function write_config(array $cfg): bool
{
    $export = var_export($cfg, true);
    $export = preg_replace('/=>\s*array \(/', '=> [', (string)$export);
    $content = "<?php\n"
        . "/**\n"
        . " * فایل پیکربندی پایگاه داده — به‌صورت خودکار توسط install.php ساخته شده است.\n"
        . " * در صورت تغییر رمز پایگاه داده، این فایل را ویرایش کنید.\n"
        . " */\n"
        . "return " . $export . ";\n";
    $ok = (bool)@file_put_contents(HS_CONFIG_FILE, $content);
    /* بی‌اعتبار کردن کش OPcache تا نسخه تازه فایل خوانده شود */
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(HS_CONFIG_FILE, true);
    }
    return $ok;
}

/** تولید فایل SQL Dump برای MySQL (خروجی مورد انتظار پروژه) */
function write_sql_dump(string $superUser, string $superHash): string
{
    $path = __DIR__ . '/honarestan_mysql.sql';
    $now  = date('Y-m-d H:i:s');
    $sql  = "-- ==========================================================================\n";
    $sql .= "--  هنرستان شهید رجایی — سامانه جامع مدیریت آموزشی\n";
    $sql .= "--  SQL Dump ساختار پایگاه داده (MySQL / MariaDB)\n";
    $sql .= "--  تاریخ تولید: {$now}\n";
    $sql .= "--  کاربرد: می‌توانید این فایل را در phpMyAdmin وارد (Import) کنید.\n";
    $sql .= "--  توجه: پس از درج مدیر کل با رمز هش‌شده، رمز پیش‌فرض «admin123» است.\n";
    $sql .= "-- ==========================================================================\n\n";
    $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    $sql .= "CREATE DATABASE IF NOT EXISTS `honarestan` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nUSE `honarestan`;\n\n";
    foreach (schema_sql('mysql') as $name => $dml) {
        if (str_starts_with($name, 'idx_')) {
            continue;
        }
        $sql .= "DROP TABLE IF EXISTS `{$name}`;\n" . trim($dml) . ";\n\n";
        if ($name === 'permissions') {
            $sql .= "INSERT INTO `permissions` (`name`, `label`, `pgroup`) VALUES\n";
            $vals = [];
            foreach (HS_PERMISSIONS as $pname => $meta) {
                $vals[] = "('" . $pname . "', '" . str_replace("'", "''", $meta['label']) . "', '" . $meta['group'] . "')";
            }
            $sql .= implode(",\n", $vals) . ";\n\n";
        }
        if ($name === 'admins') {
            $sql .= "INSERT INTO `admins` (`full_name`,`username`,`password`,`role`,`is_super`,`is_active`,`created_at`) VALUES\n"
                . "('مدیر هنرستان', '{$superUser}', '{$superHash}', 'super', 1, 1, '{$now}');\n\n";
        }
        if ($name === 'settings') {
            $offCsv = implode(',', HS_OFF_WEEKDAYS_DEFAULT);
            $sql .= "INSERT INTO `settings` (`skey`,`sval`) VALUES "
                 . "('school_name','" . HS_SCHOOL_NAME . "'), "
                 . "('off_weekdays','{$offCsv}'), "
                 . "('allow_offday_records','0');\n\n";
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return (bool)@file_put_contents($path, $sql) ? $path : '';
}

/* ---------------------------------------------------------------------------
 * پردازش فرم‌ها
 * ------------------------------------------------------------------------ */

/* --- مرحله نصب مجدد (فقط توسط مدیر کل وارد شده) --- */
$reinstall = (req('reinstall') === '1');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $messages[] = 'توکن امنیتی نامعتبر است. صفحه را دوباره بارگذاری کنید.';
    } else {
        $action = req('action');

        /* ============ نصب / نصب مجدد ============ */
        if ($action === 'install') {
            if (hs_is_installed() && !(is_super() && $reinstall)) {
                $messages[] = 'سامانه قبلاً نصب شده است. برای نصب مجدد ابتدا با حساب مدیر کل وارد شوید.';
            } elseif ($messages === []) {
                $driver = req('driver', 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
                $schoolName = clean_text(req('school_name', HS_SCHOOL_NAME), 120) ?: HS_SCHOOL_NAME;
                $superUser = clean_text(req('super_user', 'admin'), 60);
                $superPass = (string)req('super_pass');
                $superName = clean_text(req('super_name', 'مدیر هنرستان'), 120);
                $withDemo  = req('demo_data') === '1';

                /* --- اعتبارسنجی ورودی‌ها --- */
                if (!preg_match('/^[A-Za-z0-9_\.]{3,60}$/', $superUser)) {
                    $messages[] = 'نام کاربری مدیر کل باید ۳ تا ۶۰ کاراکتر و شامل حروف انگلیسی، عدد، نقطه یا زیرخط باشد.';
                }
                if (mb_strlen($superPass) < 6) {
                    $messages[] = 'رمز عبور مدیر کل باید حداقل ۶ کاراکتر باشد.';
                }
                if ($withDemo && $driver === 'mysql' && req('force_demo') !== '1') {
                    // فقط هشدار — ادامه می‌دهیم
                }

                $config = [
                    'driver' => $driver,
                    'host' => clean_text(req('db_host', 'localhost'), 120),
                    'port' => max(1, min(65535, req_int('db_port', 3306))),
                    'database' => clean_text(req('db_name', 'honarestan'), 120),
                    'username' => trim((string)req('db_user', 'root')),
                    'password' => (string)req('db_pass', ''),
                    'charset' => 'utf8mb4',
                    'installed' => false,
                ];
                if ($driver === 'sqlite') {
                    $config['database'] = __DIR__ . '/data/honarestan.sqlite';
                }

                /* --- تست اتصال و ساخت پایگاه داده در صورت نیاز --- */
                $connError = null;
                try {
                    if ($driver === 'mysql') {
                        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['host'], $config['port']);
                        $tmp = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                        $tmp->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $config['database']) . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                        $tmp = null;
                    } else {
                        $dir = dirname((string)$config['database']);
                        if (!is_dir($dir)) {
                            @mkdir($dir, 0777, true);
                        }
                    }
                } catch (PDOException $e) {
                    $connError = $e->getMessage();
                }

                if ($connError !== null) {
                    $messages[] = 'اتصال به پایگاه داده MySQL برقرار نشد: ' . $connError;
                } elseif ($messages === []) {
                    /* --- نوشتن config و ساخت جداول --- */
                    try {
                        write_config($config);
                        forcedb_reload($config);          // پیکربندی تازه در حافظه

                        $tables = create_tables($driver);
                        forcedb_reload($config);
                        seed_permissions();
                        $creds = seed_admins($superUser, $superPass, $superName, $withDemo);
                        if ((int)one('SELECT COUNT(*) FROM settings WHERE skey = ?', ['school_name']) === 0) {
                            db_insert('settings', ['skey' => 'school_name', 'sval' => $schoolName]);
                        } else {
                            db_update('settings', ['sval' => $schoolName], ['skey' => 'school_name']);
                        }
                        /* تنظیمات پیش‌فرض: پنجشنبه و جمعه تعطیل + بدون اجازهٔ ثبت در روز تعطیل */
                        foreach ([
                            'off_weekdays'         => implode(',', HS_OFF_WEEKDAYS_DEFAULT),
                            'allow_offday_records' => '0',
                            'perm_sig'             => md5(implode('|', array_keys(HS_PERMISSIONS))),
                        ] as $sk => $sv) {
                            if ((int)one('SELECT COUNT(*) FROM settings WHERE skey = ?', [$sk]) === 0) {
                                db_insert('settings', ['skey' => $sk, 'sval' => $sv]);
                            }
                        }

                        $demoInfo = ['students' => 0, 'records' => 0];
                        if ($withDemo) {
                            $demoInfo = seed_demo_data();
                        }

                        /* علامت نصب + پیکربندی نهایی */
                        $config['installed'] = true;
                        $config['school_name'] = $schoolName;
                        write_config($config);
                        @file_put_contents(HS_LOCK_FILE, date('c'));
                        hs_config(true);

                        $superHash = (string)one('SELECT password FROM admins WHERE username = ?', [$superUser]);
                        $dumpPath = write_sql_dump($superUser, $superHash);

                        $done = [
                            'tables'   => $tables,
                            'creds'    => $creds,
                            'demo'     => $demoInfo,
                            'dump'     => $dumpPath,
                            'school'   => $schoolName,
                            'driver'   => $driver,
                        ];
                        $step = 'finish';
                        log_activity('نصب سامانه', 'نصب موفق روی درایور ' . $driver);
                    } catch (Throwable $e) {
                        $messages[] = 'خطا در فرآیند نصب: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

/**
 * بارگذاری مجدد پیکربندی و اتصال پس از نوشتن config.php
 * (توابع hs_config و db از کش استاتیک استفاده می‌کنند؛ این تابع کش را خالی می‌کند)
 */
function forcedb_reload(array $config = []): void
{
    if ($config !== []) {
        /* پیکربندی تازه در حافظه قرار می‌گیرد و از خواندن نسخه قدیمی فایل جلوگیری می‌شود */
        hs_config_override($config);
        return;
    }
    hs_config(true);
    try {
        db(true);
    } catch (Throwable $e) {
        // اتصال در گام بعدی با config تازه برقرار می‌شود
    }
}

$checks        = requirement_checks();
$criticalOk    = !in_array(false, array_column(array_filter($checks, static fn($c) => $c['critical']), 'ok'), true);
$sqliteOk      = extension_loaded('pdo_sqlite');
$mysqlOk       = extension_loaded('pdo_mysql');
$posting       = $_POST;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>نصب سامانه مدیریت هنرستان | <?= e(HS_SCHOOL_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#0f2a52"/><text x="32" y="44" font-size="34" text-anchor="middle" fill="#fff">ه</text></svg>') ?>">
<style>
@font-face{font-family:"Vazirmatn";font-style:normal;font-weight:400 900;font-display:swap;
 src:url("https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/fonts/webfonts/Vazirmatn%5Bwght%5D.woff2") format("woff2")}
:root{--navy:#0f2a52;--navy2:#123c6b;--navy-bg:#0f2a52;--navy-bg-2:#123c6b;--blue:#2f6fed;--blue-soft:#e8f0ff;--gray-50:#f5f7fb;--gray-100:#eef2f7;
 --gray-200:#e2e8f0;--gray-300:#cbd5e1;--gray-500:#64748b;--gray-700:#334155;--white:#fff;
 --green:#16a34a;--green-soft:#e7f8ee;--amber:#f59e0b;--amber-soft:#fef3c7;--red:#dc2626;--red-soft:#fee2e2;
 --font:"Vazirmatn","Vazir",Tahoma,"Segoe UI",sans-serif;--shadow:0 18px 50px rgba(15,42,82,.12)}
*{box-sizing:border-box}
body{margin:0;font-family:var(--font);background:linear-gradient(160deg,#0f2a52 0%,#173f6f 42%,#f5f7fb 42.1%,#f5f7fb 100%);
 color:var(--gray-700);direction:rtl;line-height:1.9;min-height:100vh;padding:28px 16px 60px}
.wrap{max-width:980px;margin:0 auto}
.hero{text-align:center;color:#fff;margin-bottom:26px}
.hero .logo{width:78px;height:78px;border-radius:22px;margin:0 auto 14px;font-size:38px;display:grid;place-items:center;
 background:linear-gradient(135deg,var(--blue),#22d3ee);box-shadow:0 16px 40px rgba(47,111,237,.45)}
.hero h1{margin:0 0 6px;font-size:26px;color:#fff}
.hero p{margin:0;color:#bcd0ee;font-size:14px}
.card{background:var(--white);border-radius:20px;box-shadow:var(--shadow);padding:26px;margin-bottom:20px}
.steps{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-bottom:22px}
.steps .s{display:flex;align-items:center;gap:9px;background:var(--gray-100);border:1px solid var(--gray-200);
 padding:9px 15px;border-radius:30px;font-weight:700;font-size:13px;color:var(--gray-500)}
.steps .s.on{background:linear-gradient(135deg,var(--navy-bg),var(--navy-bg-2));color:#fff;border-color:var(--navy-bg)}
.steps .s.done{background:var(--green-soft);color:#15803d;border-color:#b7ebc9}
h2.t{margin:0 0 6px;color:var(--navy);font-size:19px}
.sub{color:var(--gray-500);font-size:13px;margin-bottom:18px}
.chk{display:flex;align-items:center;gap:12px;padding:11px 14px;border:1px solid var(--gray-200);border-radius:13px;margin-bottom:9px;background:var(--gray-50)}
.chk .ic{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;font-weight:800;flex:0 0 auto}
.chk.ok .ic{background:var(--green-soft);color:var(--green)}
.chk.no .ic{background:var(--red-soft);color:var(--red)}
.chk .lbl{flex:1;font-weight:700;font-size:13.4px}
.chk .val{font-size:12px;color:var(--gray-500);direction:ltr}
.chk.no{border-color:#fecaca;background:#fff5f5}
.grid{display:grid;gap:15px;grid-template-columns:repeat(auto-fit,minmax(230px,1fr))}
label.fl{display:block;font-weight:700;font-size:13px;margin-bottom:7px}
input[type=text],input[type=password],input[type=number],select{width:100%;padding:11px 13px;border:1.6px solid var(--gray-200);
 border-radius:12px;font-family:inherit;font-size:14px;outline:none;background:#fff;color:var(--gray-700)}
input:focus,select:focus{border-color:var(--blue);box-shadow:0 0 0 4px rgba(47,111,237,.13)}
.hint{font-size:11.7px;color:var(--gray-500);margin-top:5px;display:block}
.btn{display:inline-flex;align-items:center;gap:8px;border:none;cursor:pointer;font-family:inherit;font-weight:800;
 padding:13px 24px;border-radius:13px;font-size:14px;transition:.25s;background:var(--gray-100);color:var(--gray-700)}
.btn:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(15,42,82,.16)}
.btn-primary{background:linear-gradient(135deg,var(--navy-bg),var(--navy-bg-2));color:#fff}
.btn-blue{background:linear-gradient(135deg,var(--blue),#4f8cf7);color:#fff}
.btn-green{background:linear-gradient(135deg,var(--green),#22c55e);color:#fff}
.btn-ghost{background:transparent;border:1.6px solid var(--gray-300)}
.btn:disabled{opacity:.55;cursor:not-allowed;transform:none}
.alert{padding:13px 16px;border-radius:13px;font-size:13.4px;margin-bottom:14px;border:1px solid transparent}
.alert.err{background:var(--red-soft);border-color:#fecaca;color:#991b1b}
.alert.ok{background:var(--green-soft);border-color:#b7ebc9;color:#14532d}
.alert.warn{background:var(--amber-soft);border-color:#fde68a;color:#92400e}
.alert.info{background:var(--blue-soft);border-color:#bfd4fb;color:#1e3a8a}
.radios{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.radio{flex:1;min-width:230px;border:2px solid var(--gray-200);border-radius:15px;padding:14px;cursor:pointer;transition:.25s;background:#fff}
.radio:hover{border-color:var(--blue)}
.radio.on{border-color:var(--blue);background:var(--blue-soft)}
.radio b{display:block;color:var(--navy);margin-bottom:3px}
.radio small{color:var(--gray-500);font-size:12px}
.radio input{margin-inline-end:7px}
table.cred{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px}
table.cred th,table.cred td{border:1px solid var(--gray-200);padding:9px 11px;text-align:right}
table.cred th{background:var(--navy-bg);color:#fff}
code{background:#0f172a;color:#7dd3fc;padding:3px 8px;border-radius:7px;font-size:12.6px;direction:ltr;display:inline-block}
.footer{text-align:center;color:var(--gray-500);font-size:12.5px;margin-top:10px}
.switch{position:relative;display:inline-block;width:50px;height:27px}
.switch input{display:none}
.switch .sl{position:absolute;inset:0;background:var(--gray-300);border-radius:30px;transition:.25s;cursor:pointer}
.switch .sl::before{content:"";position:absolute;width:21px;height:21px;border-radius:50%;background:#fff;top:3px;inset-inline-start:3px;transition:.25s}
.switch input:checked+.sl{background:var(--blue)}
.switch input:checked+.sl::before{transform:translateX(-23px)}
.opt{display:flex;align-items:center;gap:12px;border:1px solid var(--gray-200);border-radius:13px;padding:12px;background:var(--gray-50)}
.flex{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.between{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.mt{margin-top:18px}.hidden{display:none!important}
@media(max-width:640px){.hero h1{font-size:21px}.card{padding:18px}}
</style>
</head>
<body>
<div class="wrap">

  <div class="hero">
    <div class="logo"><?= hs_icon('school', 30) ?></div>
    <h1><?= e(HS_SCHOOL_NAME) ?></h1>
    <p>سامانه جامع مدیریت آموزشی — نصب‌کننده خودکار نسخه <?= fa_num(HS_VERSION) ?></p>
  </div>

  <div class="steps">
    <div class="s <?= $step === 'finish' ? 'done' : 'on' ?>">۱. بررسی پیش‌نیازها</div>
    <div class="s <?= $step === 'finish' ? 'done' : '' ?>">۲. پیکربندی پایگاه داده</div>
    <div class="s <?= $step === 'finish' ? 'done' : '' ?>">۳. پایان نصب</div>
  </div>

  <?php foreach ($messages as $m): ?>
    <div class="alert err"><?= hs_icon('ban', 17) ?> <?= e($m) ?></div>
  <?php endforeach; ?>

<?php if ($step === 'finish'): ?>
  <!-- ==================== پایان نصب ==================== -->
  <div class="card">
    <h2 class="t"><?= hs_icon('check', 17) ?> نصب با موفقیت انجام شد</h2>
    <div class="sub">سامانه روی درایور <code><?= e($done['driver']) ?></code> نصب شد. اطلاعات زیر را در جای امنی ذخیره کنید.</div>

    <div class="alert ok">
      تعداد جداول ساخته‌شده: <b><?= fa_num(count($done['tables'])) ?></b> |
      دانش‌آموزان نمونه: <b><?= fa_num((int)$done['demo']['students']) ?></b> |
      رکوردهای نمونه (تأخیر/غیبت/انضباط/پوشش): <b><?= fa_num((int)$done['demo']['records']) ?></b>
    </div>

    <h3 style="color:var(--navy)"><?= hs_icon('key', 18) ?> حساب‌های کاربری ساخته‌شده</h3>
    <table class="cred">
      <thead><tr><th>نقش</th><th>نام کاربری</th><th>رمز عبور</th></tr></thead>
      <tbody>
      <?php foreach ($done['creds'] as $c): ?>
        <tr><td><?= e($c['role']) ?></td><td><code><?= e($c['user']) ?></code></td><td><code><?= e($c['pass']) ?></code></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="alert warn mt"><?= hs_icon('alert', 17) ?> به دلایل امنیتی، حتماً پس از نخستین ورود، رمز عبور مدیر کل را از پنل مدیریت ادمین‌ها تغییر دهید. همچنین فایل <code>install.php</code> را از سرور حذف یا تغییر نام دهید.</div>

    <?php if (!empty($done['dump'])): ?>
      <div class="alert info"><?= hs_icon('file', 17) ?> فایل SQL Dump نیز ساخته شد: <code><?= e(basename($done['dump'])) ?></code></div>
    <?php endif; ?>

    <div class="flex mt">
      <a class="btn btn-primary" href="index.php">ورود به سامانه ←</a>
      <a class="btn btn-ghost" href="README.md">مطالعه راهنمای نصب</a>
    </div>
  </div>

<?php else: ?>
  <!-- ==================== گام ۱: پیش‌نیازها ==================== -->
  <div class="card">
    <h2 class="t">گام ۱ — بررسی پیش‌نیازهای سرور</h2>
    <div class="sub">موارد قرمز «حیاتی» باید برطرف شوند. موارد غیرحیاتی فقط قابلیت‌های جانبی را محدود می‌کنند.</div>
    <?php foreach ($checks as $c): ?>
      <div class="chk <?= $c['ok'] ? 'ok' : 'no' ?>">
        <span class="ic"><?= $c['ok'] ? hs_icon('check', 17) : hs_icon('close', 17) ?></span>
        <span class="lbl"><?= e($c['label']) ?><?= $c['critical'] ? '' : ' <small class="val">(غیرحیاتی)</small>' ?></span>
        <span class="val"><?= e((string)$c['value']) ?></span>
      </div>
    <?php endforeach; ?>

    <?php if (!$criticalOk): ?>
      <div class="alert err mt">برخی پیش‌نیازهای حیاتی برقرار نیستند. لطفاً موارد بالا را در سرور برطرف کنید.</div>
    <?php else: ?>
      <div class="alert ok mt">تمام پیش‌نیازهای حیاتی برقرار است؛ می‌توانید نصب را ادامه دهید.</div>
    <?php endif; ?>
  </div>

  <!-- ==================== گام ۲: پیکربندی ==================== -->
  <div class="card">
    <h2 class="t">گام ۲ — پیکربندی پایگاه داده و حساب مدیر کل</h2>
    <div class="sub">پس از تأیید فرم، ساختار جداول، پرمیشن‌ها و حساب‌های کاربری به‌صورت خودکار ساخته می‌شود.</div>

    <?php if (hs_is_installed()): ?>
      <div class="alert warn">سامانه قبلاً نصب شده است. اجرای مجدد فرم، پرمیشن‌ها را بازسازی کرده و رمز مدیر کل را بازنشانی می‌کند (داده‌های قبلی حذف نمی‌شوند). برای نصب مجدد، با حساب مدیر کل وارد شوید و سپس گزینه «بازنشانی نصب» را انتخاب کنید.</div>
    <?php endif; ?>

    <form method="post" id="installForm" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="install">
      <input type="hidden" name="driver" id="driverField" value="sqlite">
      <input type="hidden" name="reinstall" value="<?= hs_is_installed() && is_super() ? '1' : '0' ?>">

      <label class="fl">نام مدرسه / هنرستان</label>
      <input type="text" name="school_name" value="<?= e($posting['school_name'] ?? HS_SCHOOL_NAME) ?>" maxlength="120" required>
      <span class="hint">این نام در سربرگ گزارش‌های PDF و صفحه ورود نمایش داده می‌شود.</span>

      <div class="mt"></div>
      <label class="fl">موتور پایگاه داده</label>
      <div class="radios">
        <label class="radio on" id="radioSqlite" onclick="selDriver('sqlite')">
          <b><input type="radio" name="driver_ui" value="sqlite" checked> SQLite (بدون نیاز به تنظیمات)</b>
          <small>مناسب تست سریع و هاست‌های محدود؛ فایل پایگاه داده به‌صورت خودکار در پوشه data ساخته می‌شود.</small>
        </label>
        <label class="radio" id="radioMysql" onclick="selDriver('mysql')">
          <b><input type="radio" name="driver_ui" value="mysql" <?= $mysqlOk ? '' : 'disabled' ?>> MySQL / MariaDB (پیشنهاد برای هاست واقعی)</b>
          <small><?= $mysqlOk ? 'ساختار جداول و SQL Dump تولید می‌شود؛ می‌توانید از phpMyAdmin نیز استفاده کنید.' : 'افزونه pdo_mysql روی سرور فعال نیست.' ?></small>
        </label>
      </div>

      <div id="mysqlBox" class="hidden">
        <div class="grid">
          <div>
            <label class="fl">هاست پایگاه داده</label>
            <input type="text" name="db_host" value="<?= e($posting['db_host'] ?? 'localhost') ?>" placeholder="localhost">
          </div>
          <div>
            <label class="fl">پورت</label>
            <input type="number" name="db_port" value="<?= e($posting['db_port'] ?? '3306') ?>" min="1" max="65535">
          </div>
          <div>
            <label class="fl">نام پایگاه داده</label>
            <input type="text" name="db_name" value="<?= e($posting['db_name'] ?? 'honarestan') ?>" placeholder="honarestan">
            <span class="hint">اگر وجود نداشته باشد، سامانه تلاش می‌کند آن را بسازد.</span>
          </div>
          <div>
            <label class="fl">نام کاربری پایگاه داده</label>
            <input type="text" name="db_user" value="<?= e($posting['db_user'] ?? 'root') ?>">
          </div>
          <div>
            <label class="fl">رمز عبور پایگاه داده</label>
            <input type="password" name="db_pass" value="<?= e($posting['db_pass'] ?? '') ?>" placeholder="در صورت نبود رمز، خالی بگذارید">
          </div>
        </div>
      </div>

      <hr style="margin:24px 0;border:none;border-top:1px dashed var(--gray-300)">

      <h3 style="margin:0 0 12px;color:var(--navy)"><?= hs_icon('user', 18) ?> حساب مدیر کل</h3>
      <div class="grid">
        <div>
          <label class="fl">نام و نام خانوادگی مدیر</label>
          <input type="text" name="super_name" value="<?= e($posting['super_name'] ?? 'مدیر هنرستان') ?>" maxlength="120" required>
        </div>
        <div>
          <label class="fl">نام کاربری</label>
          <input type="text" name="super_user" id="superUser" value="<?= e($posting['super_user'] ?? 'admin') ?>" maxlength="60" required
                 pattern="[A-Za-z0-9_\.]{3,60}">
          <span class="hint">حروف انگلیسی، عدد، نقطه یا زیرخط — حداقل ۳ کاراکتر.</span>
        </div>
        <div>
          <label class="fl">رمز عبور</label>
          <input type="password" name="super_pass" id="superPass" value="admin123" minlength="6" required>
          <span class="hint">حداقل ۶ کاراکتر. پس از ورود، رمز را تغییر دهید.</span>
        </div>
      </div>

      <div class="mt"></div>
      <label class="opt">
        <span class="switch"><input type="checkbox" name="demo_data" value="1" id="demoChk" checked><span class="sl"></span></span>
        <span>
          <b style="color:var(--navy)">درج داده نمونه</b><br>
          <small class="hint" style="margin:0">۱۸ هنرجوی نمونه در پایه‌های دهم/یازدهم/دوازدهم به‌همراه غیبت، تأخیر، موارد انضباطی و پوششی روزهای اخیر — برای تست گزارش‌های PDF.</small>
        </span>
      </label>

      <div class="flex mt" style="justify-content:space-between">
        <div class="flex">
          <button type="submit" class="btn btn-primary" <?= $criticalOk ? '' : 'disabled' ?>
                  data-loading-text="در حال نصب سامانه…"><?= hs_icon('bolt', 17) ?> شروع نصب خودکار</button>
          <a class="btn btn-ghost" href="install.php">بازنشانی فرم</a>
        </div>
        <span class="hint" id="statusHint">آماده نصب</span>
      </div>
    </form>
  </div>

  <!-- ==================== راهنمای کوتاه ==================== -->
  <div class="card">
    <h2 class="t"><?= hs_icon('file', 17) ?> راهنمای سریع نصب روی هاست اشتراکی</h2>
    <ol style="padding-inline-start:20px;margin:10px 0 0;line-height:2.2;font-size:13.6px">
      <li>همه فایل‌های پروژه را در پوشه <code>public_html/honarestan</code> آپلود کنید.</li>
      <li>پوشه <code>data</code> را قابل نوشتن کنید (سطح دسترسی ۷۵۵ یا ۷۷۷).</li>
      <li>در cPanel یک پایگاه داده MySQL و کاربر آن بسازید و اطلاعات را در همین فرم وارد کنید.</li>
      <li>آدرس <code>https://your-domain.com/honarestan/install.php</code> را باز کنید و فرم را تکمیل نمایید.</li>
      <li>پس از پایان نصب، فایل <code>install.php</code> را حذف کنید و وارد سامانه شوید.</li>
    </ol>
  </div>
<?php endif; ?>

  <div class="footer">© <?= fa_num((int)explode('/', jalali_today())[0]) ?> — <?= e(HS_SCHOOL_NAME) ?> | نسخه <?= fa_num(HS_VERSION) ?></div>
</div>

<div id="loader" style="position:fixed;inset:0;background:rgba(240,244,250,.8);backdrop-filter:blur(4px);display:none;
 align-items:center;justify-content:center;flex-direction:column;gap:14px;z-index:9999">
  <div style="width:64px;height:64px;border-radius:50%;border:5px solid #e2e8f0;border-top-color:#2f6fed;animation:sp 0.9s linear infinite"></div>
  <b style="color:#0f2a52" id="loadText">در حال نصب…</b>
</div>
<style>@keyframes sp{to{transform:rotate(360deg)}}</style>

<script>
/* ===== اسکریپت نصب‌کننده ===== */
var HS_INSTALL = (function () {
  "use strict";
  function selDriver(d) {
    document.getElementById('driverField').value = d;
    document.getElementById('radioSqlite').classList.toggle('on', d === 'sqlite');
    document.getElementById('radioMysql').classList.toggle('on', d === 'mysql');
    document.querySelector('input[name=driver_ui][value=sqlite]').checked = (d === 'sqlite');
    document.querySelector('input[name=driver_ui][value=mysql]').checked = (d === 'mysql');
    document.getElementById('mysqlBox').classList.toggle('hidden', d !== 'mysql');
  }
  window.selDriver = selDriver;

  document.addEventListener('DOMContentLoaded', function () {
    var f = document.getElementById('installForm');
    if (!f) return;

    /* اعتبارسنجی زنده نام کاربری و رمز */
    var u = document.getElementById('superUser'), p = document.getElementById('superPass');
    function checkUser() {
      var ok = /^[A-Za-z0-9_.]{3,60}$/.test(u.value);
      u.style.borderColor = ok ? '#16a34a' : '#dc2626';
      return ok;
    }
    function checkPass() {
      var ok = p.value.length >= 6;
      p.style.borderColor = ok ? '#16a34a' : '#dc2626';
      return ok;
    }
    u && u.addEventListener('input', checkUser);
    p && p.addEventListener('input', checkPass);

    f.addEventListener('submit', function (e) {
      var d = document.getElementById('driverField').value;
      var msg = [];
      if (!checkUser()) msg.push('نام کاربری مدیر کل نامعتبر است.');
      if (!checkPass()) msg.push('رمز عبور باید حداقل ۶ کاراکتر باشد.');
      if (d === 'mysql') {
        ['db_host', 'db_name', 'db_user'].forEach(function (n) {
          var el = f.querySelector('[name=' + n + ']');
          if (el && !el.value.trim()) msg.push('فیلد «' + el.previousElementSibling.textContent.trim() + '» الزامی است.');
        });
      }
      if (msg.length) {
        e.preventDefault();
        /* alert() قادر به نمایش آیکون SVG نیست؛ فقط متن نشان داده می‌شود */
        alert('لطفاً موارد زیر را اصلاح کنید:\n\n' + msg.join('\n'));
        return false;
      }
      document.getElementById('loader').style.display = 'flex';
      document.getElementById('loadText').textContent = d === 'mysql'
        ? 'در حال ساخت جداول MySQL و درج داده اولیه…' : 'در حال ساخت فایل پایگاه داده SQLite…';
    });

    /* انتخاب خودکار MySQL اگر SQLite موجود نیست */
    if (!<?= $sqliteOk ? 'true' : 'false' ?> && <?= $mysqlOk ? 'true' : 'false' ?>) selDriver('mysql');
  });
  return { selDriver: selDriver };
})();
</script>
</body>
</html>
