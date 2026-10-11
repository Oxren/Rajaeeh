<?php
/**
 * ============================================================================
 *  سامانه جامع مدیریت هنرستان شهید رجایی
 *  فایل: admin.php — مدیریت ادمین‌ها و پرمیشن‌ها (RBAC)
 * ----------------------------------------------------------------------------
 *  قابلیت‌ها:
 *    • افزودن ادمین جدید (مدیر / معاون / معلم) با نام کاربری و رمز عبور
 *    • تخصیص پرمیشن granular برای هر ادمین (چک‌باکس‌های گروه‌بندی‌شده)
 *    • ویرایش، فعال/غیرفعال‌سازی و حذف ادمین
 *    • مشاهده ماتریس کامل پرمیشن‌های سامانه
 *    • محافظت در برابر ارتقای سطح دسترسی (Privilege Escalation)
 *
 *  قوانین دسترسی:
 *    - فقط «مدیر کل» می‌تواند ادمین جدید بسازد یا ادمین را حذف کند.
 *    - ادمین با پرمیشن manage_admins می‌تواند ادمین‌های معمولی را ویرایش کند،
 *      ولی نمی‌تواند پرمیشن manage_admins بدهد یا حساب مدیر کل را تغییر دهد.
 *
 *  تمام CSS و JavaScript این پنل به‌صورت Inline در همین فایل است.
 * ============================================================================
 */

require __DIR__ . '/db.php';

/* شروع نشست امن پیش از هر خروجی (لازم برای ارسال کوکی نشست و توکن CSRF) */
hs_session_start();

if (!hs_is_installed()) {
    header('Location: install.php');
    exit;
}
require_perm('manage_admins');

$me     = current_admin();
$isSup  = is_super();
$errors = [];

/* ---------------------------------------------------------------------------
 * ۱) اعتبارسنجی و آماده‌سازی ورودی پرمیشن‌ها
 * ------------------------------------------------------------------------ */
function collect_permissions(): array
{
    $posted = $_POST['permissions'] ?? [];
    if (!is_array($posted)) {
        return [];
    }
    $valid = [];
    foreach ($posted as $p) {
        $p = clean_text(is_scalar($p) ? (string)$p : '', 60);
        if ($p !== '' && isset(HS_PERMISSIONS[$p])) {
            $valid[] = $p;
        }
    }
    return array_values(array_unique($valid));
}

/** ثبت/بروزرسانی پرمیشن‌های یک ادمین */
function sync_permissions(int $adminId, array $permNames): int
{
    db_delete('admin_permissions', ['admin_id' => $adminId]);
    $n = 0;
    foreach ($permNames as $name) {
        $pid = one('SELECT id FROM permissions WHERE name = ?', [$name]);
        if (!$pid) {
            // در صورت نبود، پرمیشن را می‌سازیم (همگام‌سازی خودکار)
            $meta = HS_PERMISSIONS[$name] ?? null;
            if (!$meta) {
                continue;
            }
            $pid = db_insert('permissions', ['name' => $name, 'label' => $meta['label'], 'pgroup' => $meta['group']]);
        }
        db_insert('admin_permissions', ['admin_id' => $adminId, 'permission_id' => (int)$pid]);
        $n++;
    }
    return $n;
}

/* ---------------------------------------------------------------------------
 * ۲) پردازش عملیات‌ها (POST)
 * ------------------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_require();
    $action = req('action');

    /* ------------------------- افزودن ادمین ------------------------- */
    if ($action === 'add') {
        if (!$isSup) {
            $errors[] = 'فقط مدیر کل می‌تواند ادمین جدید بسازد.';
        } else {
            $fullName = clean_text(req('full_name'), 120);
            $username = trim((string)req('username'));
            $password = (string)req('password');
            $role     = clean_text(req('role'), 20);

            if (mb_strlen($fullName, 'UTF-8') < 3) {
                $errors[] = 'نام و نام خانوادگی باید حداقل ۳ کاراکتر باشد.';
            }
            if (!preg_match('/^[A-Za-z0-9_\.]{3,60}$/', $username)) {
                $errors[] = 'نام کاربری باید ۳ تا ۶۰ کاراکتر و شامل حروف انگلیسی، عدد، نقطه یا زیرخط باشد.';
            } elseif (row('SELECT id FROM admins WHERE username = ?', [$username])) {
                $errors[] = 'این نام کاربری قبلاً استفاده شده است.';
            }
            if (mb_strlen($password) < 6) {
                $errors[] = 'رمز عبور باید حداقل ۶ کاراکتر باشد.';
            }
            if (!array_key_exists($role, ['manager' => 1, 'deputy' => 1, 'teacher' => 1, 'staff' => 1])) {
                $errors[] = 'نقش انتخاب‌شده نامعتبر است.';
            }

            if (!$errors) {
                $id = db_insert('admins', [
                    'full_name'  => $fullName,
                    'username'   => $username,
                    'password'   => password_hash($password, PASSWORD_DEFAULT),
                    'role'       => $role,
                    'is_super'   => 0,
                    'is_active'  => req('is_active') === '1' ? 1 : 0,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $count = sync_permissions($id, collect_permissions());
                log_activity('افزودن ادمین', "کاربر: {$username} — تعداد پرمیشن: {$count}");
                flash_set('success', "ادمین «{$fullName}» با " . fa_num($count) . ' پرمیشن ساخته شد.');
                header('Location: admin.php');
                exit;
            }
        }
    }

    /* ------------------------- ویرایش ادمین ------------------------- */
    if ($action === 'edit') {
        $id    = req_int('id');
        $target = row('SELECT * FROM admins WHERE id = ?', [$id]);
        if (!$target) {
            $errors[] = 'ادمین مورد نظر یافت نشد.';
        } elseif ((int)$target['is_super'] === 1 && !$isSup) {
            $errors[] = 'ویرایش حساب مدیر کل فقط توسط خود مدیر کل امکان‌پذیر است.';
        } else {
            $fullName = clean_text(req('full_name'), 120);
            $password = (string)req('password');
            $role     = clean_text(req('role'), 20);
            $perms    = collect_permissions();

            if (mb_strlen($fullName, 'UTF-8') < 3) {
                $errors[] = 'نام و نام خانوادگی باید حداقل ۳ کاراکتر باشد.';
            }
            if ($password !== '' && mb_strlen($password) < 6) {
                $errors[] = 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد.';
            }
            /* جلوگیری از ارتقای دسترسی توسط ادمین غیر مدیر کل */
            if (!$isSup && in_array('manage_admins', $perms, true) && !in_array('manage_admins', admin_permissions_lookup($id), true)) {
                $errors[] = 'شما مجاز به اعطای پرمیشن «مدیریت ادمین‌ها» نیستید.';
                $perms = array_values(array_diff($perms, ['manage_admins']));
            }
            if (!$isSup && (int)$target['is_super'] === 1) {
                $errors[] = 'تغییر حساب مدیر کل مجاز نیست.';
            }

            if (!$errors) {
                $data = [
                    'full_name' => $fullName,
                    'role'      => array_key_exists($role, ['manager' => 1, 'deputy' => 1, 'teacher' => 1, 'staff' => 1]) ? $role : $target['role'],
                    'is_active' => req('is_active') === '1' ? 1 : 0,
                ];
                if ($password !== '') {
                    $data['password'] = password_hash($password, PASSWORD_DEFAULT);
                }
                db_update('admins', $data, ['id' => $id]);

                if ((int)$target['is_super'] === 1) {
                    /* برای مدیر کل: همه پرمیشن‌ها همیشه فعال است (نمایشی) */
                    db_delete('admin_permissions', ['admin_id' => $id]);
                } else {
                    sync_permissions($id, $perms);
                }
                log_activity('ویرایش ادمین', 'ادمین #' . $id . ' — ' . $fullName);
                flash_set('success', 'تغییرات حساب «' . $fullName . '» ذخیره شد.');
                header('Location: admin.php');
                exit;
            }
        }
    }

    /* ------------------------- فعال/غیرفعال ------------------------- */
    if ($action === 'toggle') {
        $id = req_int('id');
        $target = row('SELECT * FROM admins WHERE id = ?', [$id]);
        if (!$target) {
            $errors[] = 'ادمین یافت نشد.';
        } elseif ((int)$target['is_super'] === 1) {
            $errors[] = 'حساب مدیر کل قابل غیرفعال‌سازی نیست.';
        } elseif (!$isSup && !has_perm('manage_admins')) {
            $errors[] = 'دسترسی کافی ندارید.';
        } elseif ((int)$target['id'] === (int)$me['id']) {
            $errors[] = 'نمی‌توانید حساب خودتان را غیرفعال کنید.';
        } else {
            $new = (int)$target['is_active'] === 1 ? 0 : 1;
            db_update('admins', ['is_active' => $new], ['id' => $id]);
            log_activity('تغییر وضعیت ادمین', ($new ? 'فعال‌سازی ' : 'غیرفعال‌سازی ') . $target['username']);
            flash_set('success', 'وضعیت حساب «' . $target['full_name'] . '» ' . ($new ? 'فعال' : 'غیرفعال') . ' شد.');
            header('Location: admin.php');
            exit;
        }
    }

    /* ------------------------- حذف ادمین ------------------------- */
    if ($action === 'delete') {
        $id = req_int('id');
        $target = row('SELECT * FROM admins WHERE id = ?', [$id]);
        if (!$target) {
            $errors[] = 'ادمین یافت نشد.';
        } elseif (!$isSup) {
            $errors[] = 'حذف ادمین فقط توسط مدیر کل امکان‌پذیر است.';
        } elseif ((int)$target['is_super'] === 1) {
            $errors[] = 'حساب مدیر کل قابل حذف نیست.';
        } elseif ((int)$target['id'] === (int)$me['id']) {
            $errors[] = 'نمی‌توانید حساب خودتان را حذف کنید.';
        } else {
            db_delete('admin_permissions', ['admin_id' => $id]);
            db_delete('admins', ['id' => $id]);
            log_activity('حذف ادمین', 'کاربر: ' . $target['username']);
            flash_set('success', 'ادمین «' . $target['full_name'] . '» حذف شد.');
            header('Location: admin.php');
            exit;
        }
    }

    /* ------------------------- بازنشانی رمز ------------------------- */
    if ($action === 'reset_pass') {
        $id = req_int('id');
        $target = row('SELECT * FROM admins WHERE id = ?', [$id]);
        $newPass = (string)req('new_password');
        if (!$target) {
            $errors[] = 'ادمین یافت نشد.';
        } elseif (!$isSup && !has_perm('manage_admins')) {
            $errors[] = 'دسترسی کافی ندارید.';
        } elseif (mb_strlen($newPass) < 6) {
            $errors[] = 'رمز جدید باید حداقل ۶ کاراکتر باشد.';
        } else {
            db_update('admins', ['password' => password_hash($newPass, PASSWORD_DEFAULT)], ['id' => $id]);
            log_activity('بازنشانی رمز', 'ادمین: ' . $target['username']);
            flash_set('success', 'رمز عبور «' . $target['full_name'] . '» بازنشانی شد.');
            header('Location: admin.php');
            exit;
        }
    }

    /* ------------------------- ذخیره تنظیمات سامانه (فقط مدیر کل) ------------------------- */
    if ($action === 'save_settings') {
        if (!$isSup) {
            $errors[] = 'تغییر تنظیمات سامانه فقط توسط مدیر کل امکان‌پذیر است.';
        } else {
            $off = $_POST['off_days'] ?? [];
            $offDays = [];
            if (is_array($off)) {
                foreach ($off as $d) {
                    $d = fa_to_en_digits(clean_text(is_scalar($d) ? (string)$d : '', 3));
                    if (ctype_digit($d) && (int)$d >= 0 && (int)$d <= 6) {
                        $offDays[] = (int)$d;
                    }
                }
            }
            $offDays = array_values(array_unique($offDays));
            if (!$offDays) {
                $errors[] = 'حداقل یک روز تعطیل هفتگی باید انتخاب شود.';
            } else {
                hs_setting_set('off_weekdays', implode(',', $offDays));
                hs_setting_set('allow_offday_records', req('allow_offday') === '1' ? '1' : '0');
                log_activity('تنظیمات سامانه', 'روزهای تعطیل: ' . implode(',', $offDays)
                    . ' — اجازه ثبت در تعطیل: ' . (req('allow_offday') === '1' ? 'بله' : 'خیر'));
                flash_set('success', 'تنظیمات روزهای تعطیل ذخیره شد: '
                    . implode(' و ', array_map(static fn($i) => jalali_weekdays()[$i], $offDays)));
                header('Location: admin.php#settingsCard');
                exit;
            }
        }
    }

    /* ------------------------- تغییر رمز خودم ------------------------- */
    if ($action === 'my_password') {
        $old = (string)req('old_password');
        $new = (string)req('new_password');
        if (!password_verify($old, (string)$me['password'])) {
            $errors[] = 'رمز عبور فعلی نادرست است.';
        } elseif (mb_strlen($new) < 6) {
            $errors[] = 'رمز جدید باید حداقل ۶ کاراکتر باشد.';
        } else {
            db_update('admins', ['password' => password_hash($new, PASSWORD_DEFAULT)], ['id' => $me['id']]);
            log_activity('تغییر رمز خود', 'کاربر: ' . $me['username']);
            flash_set('success', 'رمز عبور شما با موفقیت تغییر کرد.');
            header('Location: admin.php');
            exit;
        }
    }
}

/* ---------------------------------------------------------------------------
 * ۳) داده‌های نمایش
 * ------------------------------------------------------------------------ */
$f_q      = clean_text(req('q'), 60);
$f_role   = clean_text(req('role'), 20);
$roleOk   = ['super', 'manager', 'deputy', 'teacher', 'staff'];
$whereA   = [];
$paramsA  = [];
if ($f_q !== '') {
    $whereA[] = '(full_name LIKE ? OR username LIKE ?)';
    $paramsA[] = '%' . $f_q . '%';
    $paramsA[] = '%' . $f_q . '%';
}
if ($f_role !== '' && in_array($f_role, $roleOk, true)) {
    $whereA[] = 'role = ?';
    $paramsA[] = $f_role;
}
$adminRows = rows(
    'SELECT * FROM admins ' . ($whereA ? 'WHERE ' . implode(' AND ', $whereA) : '') . ' ORDER BY is_super DESC, id ASC',
    $paramsA
);

/* آمار ثبت هر کاربر در هفته کاری جاری (نمایش تفکیکی برای مدیر) */
$work = hs_working_week_days();
$wf = $work ? jalali_to_gregorian_str($work[0]) : jalali_current_week()['from_g'];
$wt = $work ? jalali_to_gregorian_str($work[count($work) - 1]) : jalali_current_week()['to_g'];
$adminStats = [];
foreach (rows(
    'SELECT a.id,
            (SELECT COUNT(*) FROM delays d WHERE d.created_by = a.id AND d.gdate BETWEEN ? AND ?) AS delays_c,
            (SELECT COUNT(*) FROM absences ab WHERE ab.created_by = a.id AND ab.gdate BETWEEN ? AND ?) AS absences_c,
            (SELECT COUNT(*) FROM disciplines dc WHERE dc.created_by = a.id AND dc.gdate BETWEEN ? AND ?) AS disc_c,
            (SELECT COUNT(*) FROM dress_violations v WHERE v.created_by = a.id AND v.gdate BETWEEN ? AND ?) AS dress_c
     FROM admins a',
    [$wf, $wt, $wf, $wt, $wf, $wt, $wf, $wt]
) as $r) {
    $adminStats[(int)$r['id']] = $r;
}

/* پرمیشن‌های هر ادمین */
$permMap = [];
foreach ($adminRows as $a) {
    $permMap[(int)$a['id']] = admin_permissions_lookup((int)$a['id']);
}

/** واکشی مستقیم پرمیشن‌های یک ادمین (بدون وابستگی به کاربر جاری) */
function admin_permissions_lookup(int $adminId): array
{
    return array_column(
        rows('SELECT p.name FROM admin_permissions ap JOIN permissions p ON p.id = ap.permission_id WHERE ap.admin_id = ?', [$adminId]),
        'name'
    );
}

$roleLabels = ['super' => 'مدیر کل', 'manager' => 'مدیر/مسئول', 'deputy' => 'معاون', 'teacher' => 'معلم', 'staff' => 'کارمند'];
$roleColors = ['super' => 'purple', 'manager' => 'blue', 'deputy' => 'green', 'teacher' => 'amber', 'staff' => 'gray'];

/* گروه‌بندی پرمیشن‌ها برای نمایش */
$grouped = [];
foreach (HS_PERMISSIONS as $name => $meta) {
    $grouped[$meta['group']][$name] = $meta['label'];
}

$editTarget  = null;
$editPerms   = [];
if (req('edit') !== null) {
    $editTarget = row('SELECT * FROM admins WHERE id = ?', [req_int('edit')]);
    if ($editTarget) {
        $editPerms = admin_permissions_lookup((int)$editTarget['id']);
    }
}

/* آمار کلی */
$totalAdmins  = (int)one('SELECT COUNT(*) FROM admins');
$activeAdmins = (int)one('SELECT COUNT(*) FROM admins WHERE is_active = 1');
$superAdmins  = (int)one('SELECT COUNT(*) FROM admins WHERE is_super = 1');
$permUsage = rows(
    'SELECT p.name, p.label, COUNT(ap.admin_id) AS c
     FROM permissions p LEFT JOIN admin_permissions ap ON ap.permission_id = p.id
     GROUP BY p.id, p.name, p.label ORDER BY c DESC, p.id ASC'
);

$adminCss = <<<'CSS'
/* ===== استایل اختصاصی پنل مدیریت ادمین‌ها ===== */
.a-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.a-avatar{width:44px;height:44px;border-radius:13px;display:grid;place-items:center;font-weight:900;font-size:17px;
  background:linear-gradient(135deg,var(--navy-bg),var(--blue));color:#fff;flex:0 0 auto}
.chips{display:flex;gap:5px;flex-wrap:wrap;max-width:560px}
.chip{background:var(--blue-soft);color:#1d4ed8;border:1px solid #cfe0ff;border-radius:20px;padding:3px 10px;font-size:11.4px;font-weight:700}
.chip.man{background:var(--purple-soft);color:#6d28d9;border-color:#e2d3fb}
.chip.more{background:var(--gray-100);color:var(--gray-500);border-color:var(--gray-200)}
.who{display:flex;align-items:center;gap:13px;padding:14px;border:1px solid var(--gray-200);border-radius:14px;
  background:var(--gray-50);margin-bottom:18px}
.who .t{flex:1}
.who b{display:block;color:var(--navy);font-size:14.6px}
html[data-theme="dark"] .who b{color:#e8eefb}
.strength{height:7px;border-radius:6px;background:var(--gray-200);overflow:hidden;margin-top:6px}
.strength>i{display:block;height:100%;width:0;transition:var(--transition);border-radius:6px}
.strength.s1>i{width:25%;background:#dc2626}.strength.s2>i{width:50%;background:#f59e0b}
.strength.s3>i{width:75%;background:#0ea5e9}.strength.s4>i{width:100%;background:#16a34a}
.perm-tools{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
table.matrix td:first-child{font-weight:700;color:var(--navy);direction:ltr;text-align:left;font-family:monospace;font-size:12.3px}
html[data-theme="dark"] table.matrix td:first-child{color:#9ec5ff}
.pill-role{display:inline-flex;align-items:center;gap:6px}
@media(max-width:760px){.chips{max-width:100%}}
CSS;

layout_head('مدیریت ادمین‌ها و پرمیشن‌ها', 'admin', [$adminCss]);
?>

<?php foreach ($errors as $err): ?>
  <div class="alert err"><span><?= hs_icon('ban', 17) ?></span><span><?= e($err) ?></span></div>
<?php endforeach; ?>

<div class="grid stats mb10">
  <div class="stat blue"><div class="icon"><?= hs_icon('users', 22) ?></div><div><div class="v"><?= fa_num($totalAdmins) ?></div><div class="l">کل کاربران سامانه</div></div></div>
  <div class="stat green"><div class="icon"><?= hs_icon('check', 22) ?></div><div><div class="v"><?= fa_num($activeAdmins) ?></div><div class="l">کاربران فعال</div></div></div>
  <div class="stat purple"><div class="icon"><?= hs_icon('star', 22) ?></div><div><div class="v"><?= fa_num($superAdmins) ?></div><div class="l">مدیر کل (دسترسی کامل)</div></div></div>
  <div class="stat amber"><div class="icon"><?= hs_icon('key', 22) ?></div><div><div class="v"><?= fa_num(count(HS_PERMISSIONS)) ?></div><div class="l">پرمیشن قابل تخصیص</div></div></div>
</div>

<?php if (!$isSup): ?>
  <div class="alert warn"><span><?= hs_icon('info', 17) ?></span><span>
    شما به‌عنوان کاربر با پرمیشن «مدیریت ادمین‌ها» وارد شده‌اید؛ امکان <b>ویرایش</b> ادمین‌های معمولی را دارید،
    اما <b>افزودن/حذف ادمین</b> و تغییر حساب مدیر کل فقط توسط مدیر کل انجام می‌شود.
  </span></div>
<?php endif; ?>

<!-- ================= فهرست ادمین‌ها ================= -->
<div class="card">
  <div class="between mb10">
    <h3 class="mb0"><?= hs_icon('shield', 18) ?> فهرست کاربران و پرمیشن‌ها</h3>
    <div class="flex">
      <form method="get" class="flex" style="margin:0">
        <input type="search" name="q" value="<?= e($f_q) ?>" placeholder="جستجوی نام یا نام کاربری…" style="min-width:200px">
        <select name="role" style="min-width:150px">
          <option value="">همه نقش‌ها</option>
          <option value="super"   <?= $f_role === 'super' ? 'selected' : '' ?>>مدیر کل</option>
          <option value="manager" <?= $f_role === 'manager' ? 'selected' : '' ?>>مدیر / مسئول</option>
          <option value="deputy"  <?= $f_role === 'deputy' ? 'selected' : '' ?>>معاون</option>
          <option value="teacher" <?= $f_role === 'teacher' ? 'selected' : '' ?>>معلم</option>
          <option value="staff"   <?= $f_role === 'staff' ? 'selected' : '' ?>>کارمند</option>
        </select>
        <button class="btn btn-sm btn-primary" type="submit"><?= hs_icon('search', 16) ?> اعمال فیلتر</button>
        <?php if ($f_q !== '' || $f_role !== ''): ?><a class="btn btn-sm btn-ghost" href="admin.php">حذف فیلتر</a><?php endif; ?>
      </form>
      <?php if ($isSup): ?>
        <button class="btn btn-sm btn-blue" onclick="openAdminModal()"><?= hs_icon('plus', 16) ?> افزودن ادمین جدید</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="alert info no-print"><span><?= hs_icon('users', 17) ?></span><span>
    سوابق هر کاربر (مدیر، معاون، معلم) در سامانه <b>جداگانه</b> ثبت و شمرده می‌شود. هر کاربر فقط سوابق خودش را می‌بیند؛
    <b>مدیر کل</b> و دارندگان پرمیشن «مشاهده سوابق همه کاربران» می‌توانند همه ثبت‌ها را تفکیک‌شده در
    <a href="index.php#creatorsCard">داشبورد</a> و <a href="export.php?mode=creators&amp;quick=week">گزارش تفکیکی (PDF)</a> ببینند.
  </span></div>

  <div class="who">
    <div class="a-avatar"><?= e(mb_substr((string)$me['full_name'], 0, 1, 'UTF-8')) ?></div>
    <div class="t">
      <b><?= e($me['full_name']) ?> <span class="badge <?= e($roleColors[$me['role']] ?? 'gray') ?>"><?= e($roleLabels[$me['role']] ?? $me['role']) ?></span>
        <?= $isSup ? '<span class="badge purple">دسترسی کامل ' . hs_icon('star', 17) . '</span>' : '' ?></b>
      <span class="small muted">حساب شما: <code><?= e($me['username']) ?></code> — برای تغییر رمز عبور از دکمه روبه‌رو استفاده کنید.</span>
    </div>
    <button class="btn btn-sm btn-ghost" onclick="HS.modal('mypass', true)"><?= hs_icon('lock', 16) ?> تغییر رمز من</button>
  </div>

  <div class="table-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th>#</th>
          <th>نام و نام خانوادگی</th>
          <th>نام کاربری</th>
          <th>نقش</th>
          <th>پرمیشن‌ها</th>
          <th title="تعداد سوابق ثبت‌شده در هفته کاری جاری">ثبت‌های این هفته</th>
          <th>وضعیت</th>
          <th>تاریخ ایجاد</th>
          <th>عملیات</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$adminRows): ?>
        <tr><td colspan="8"><div class="empty"><div class="e-ic"><?= hs_icon('search', 26) ?></div>کاربری با این مشخصات یافت نشد.</div></td></tr>
      <?php endif; ?>
      <?php foreach ($adminRows as $i => $a):
          $perms = $permMap[(int)$a['id']] ?? [];
          $isT   = (int)$a['is_super'] === 1;
      ?>
        <tr>
          <td><?= fa_num($i + 1) ?></td>
          <td>
            <div class="a-row">
              <div class="a-avatar" style="width:36px;height:36px;font-size:14px"><?= e(mb_substr((string)$a['full_name'], 0, 1, 'UTF-8')) ?></div>
              <div>
                <b><?= e($a['full_name']) ?></b>
                <?php if ((int)$a['id'] === (int)$me['id']): ?><span class="badge blue">خودم</span><?php endif; ?>
                <br><span class="small muted">کد کاربری: <?= fa_num((int)$a['id']) ?></span>
              </div>
            </div>
          </td>
          <td><code><?= e($a['username']) ?></code></td>
          <td><span class="badge <?= e($roleColors[$a['role']] ?? 'gray') ?>"><?= e($roleLabels[$a['role']] ?? $a['role']) ?></span></td>
          <td>
            <?php if ($isT): ?>
              <span class="badge purple">همه پرمیشن‌ها (مدیر کل)</span>
            <?php elseif (!$perms): ?>
              <span class="badge gray">بدون پرمیشن</span>
            <?php else: ?>
              <div class="chips">
                <?php foreach (array_slice($perms, 0, 5) as $p): ?>
                  <span class="chip <?= $p === 'manage_admins' ? 'man' : '' ?>"><?= e(HS_PERMISSIONS[$p]['label'] ?? $p) ?></span>
                <?php endforeach; ?>
                <?php if (count($perms) > 5): ?>
                  <span class="chip more">+ <?= fa_num(count($perms) - 5) ?> مورد دیگر</span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </td>
          <td class="nowrap">
            <?php $st = $adminStats[(int)$a['id']] ?? ['delays_c' => 0, 'absences_c' => 0, 'disc_c' => 0, 'dress_c' => 0]; ?>
            <span class="cnt">
              <i class="d" title="تأخیر">ت <?= fa_num((int)$st['delays_c']) ?></i>
              <i class="a" title="غیبت">غ <?= fa_num((int)$st['absences_c']) ?></i>
              <i class="c" title="انضباطی">ا <?= fa_num((int)$st['disc_c']) ?></i>
              <i class="v" title="پوششی">پ <?= fa_num((int)$st['dress_c']) ?></i>
            </span>
            <?php if ($isSup): ?>
              <br><a class="small" href="records.php?by=<?= (int)$a['id'] ?>">مشاهده سوابق ←</a>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($isT): ?>
              <span class="badge green">فعال</span>
            <?php else: ?>
              <span class="badge <?= (int)$a['is_active'] === 1 ? 'green' : 'red' ?>"><?= (int)$a['is_active'] === 1 ? 'فعال' : 'غیرفعال' ?></span>
            <?php endif; ?>
          </td>
          <td class="small nowrap"><?= e($a['created_at'] ? gregorian_to_jalali_str(substr((string)$a['created_at'], 0, 10)) : '—') ?></td>
          <td>
            <div class="flex" style="gap:6px">
              <?php if ($isSup || (!$isT)): ?>
                <a class="btn btn-sm btn-ghost" href="admin.php?edit=<?= (int)$a['id'] ?>#adminForm" title="ویرایش"><?= hs_icon('pencil', 17) ?> ویرایش</a>
              <?php endif; ?>
              <?php if (!$isT && ($isSup || has_perm('manage_admins'))): ?>
                <form method="post" style="display:inline;margin:0">
                  <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <button class="btn btn-sm btn-amber" type="submit" title="تغییر وضعیت"><?= (int)$a['is_active'] === 1 ? hs_icon('ban', 17) . ' غیرفعال' : hs_icon('check', 17) . ' فعال' ?></button>
                </form>
              <?php endif; ?>
              <?php if ($isSup && !$isT && (int)$a['id'] !== (int)$me['id']): ?>
                <form method="post" style="display:inline;margin:0" class="delForm"
                      data-name="<?= e($a['full_name']) ?>">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                  <button class="btn btn-sm btn-red" type="submit" title="حذف"><?= hs_icon('trash', 16) ?></button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ================= فرم افزودن/ویرایش ================= -->
<?php
$formPerms   = $editTarget ? $editPerms : [];
$formIsSuper = $editTarget && (int)$editTarget['is_super'] === 1;
?>
<div class="card" id="adminForm">
  <h3><?= $editTarget ? hs_icon('pencil', 17) . ' ویرایش کاربر: ' . e($editTarget['full_name']) : hs_icon('plus', 17) . ' افزودن ادمین جدید' ?></h3>

  <?php if (!$isSup && !$editTarget): ?>
    <div class="alert warn"><span><?= hs_icon('lock', 17) ?></span><span>افزودن ادمین جدید فقط توسط مدیر کل انجام می‌شود. شما می‌توانید از ستون «عملیات» ادمین‌های موجود را ویرایش کنید.</span></div>
  <?php elseif ($formIsSuper): ?>
    <div class="alert info"><span><?= hs_icon('star', 17) ?></span><span>این حساب «مدیر کل» است و به‌صورت پیش‌فرض همه پرمیشن‌ها را دارد؛ نیازی به انتخاب پرمیشن نیست. برای تغییر رمز، از دکمه بازنشانی رمز در ستون عملیات استفاده کنید.</span></div>
  <?php else: ?>
    <form method="post" id="adminF" autocomplete="off" data-loading="در حال ذخیره اطلاعات کاربر…">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $editTarget ? 'edit' : 'add' ?>">
      <?php if ($editTarget): ?><input type="hidden" name="id" value="<?= (int)$editTarget['id'] ?>"><?php endif; ?>

      <div class="grid g2">
        <div>
          <label class="fl">نام و نام خانوادگی <span class="req">*</span></label>
          <input type="text" name="full_name" id="afName" required minlength="3" maxlength="120"
                 value="<?= e($editTarget['full_name'] ?? '') ?>" placeholder="مثال: مریم کریمی">
          <span class="hint">مثال: «مریم کریمی (معاون آموزشی)»</span>
        </div>
        <div>
          <label class="fl">نام کاربری <span class="req">*</span></label>
          <input type="text" name="username" id="afUser" required maxlength="60" pattern="[A-Za-z0-9_\.]{3,60}"
                 value="<?= e($editTarget['username'] ?? '') ?>" placeholder="مثال: m.karimi" dir="ltr"
                 <?= $editTarget ? 'readonly title="نام کاربری قابل تغییر نیست"' : '' ?>>
          <span class="hint">حروف انگلیسی، عدد، نقطه یا زیرخط (۳ تا ۶۰ کاراکتر)</span>
        </div>
        <div>
          <label class="fl">رمز عبور <?= $editTarget ? '<span class="muted small">(خالی = بدون تغییر)</span>' : '<span class="req">*</span>' ?></label>
          <input type="password" name="password" id="afPass" <?= $editTarget ? '' : 'required' ?> minlength="6" maxlength="100"
                 placeholder="حداقل ۶ کاراکتر">
          <div class="strength" id="afStr"><i></i></div>
          <span class="hint" id="afStrTxt">قدرت رمز: —</span>
        </div>
        <div>
          <label class="fl">نقش کاربر</label>
          <select name="role" id="afRole">
            <?php foreach (['teacher' => 'معلم', 'deputy' => 'معاون', 'manager' => 'مدیر / مسئول انضباط', 'staff' => 'کارمند دفتری'] as $k => $v): ?>
              <option value="<?= e($k) ?>" <?= ($editTarget['role'] ?? 'teacher') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">نقش فقط برای دسته‌بندی نمایشی است؛ دسترسی واقعی از پرمیشن‌ها تعیین می‌شود.</span>
        </div>
      </div>

      <label class="opt mt10" style="margin-top:16px">
        <span class="switch"><input type="checkbox" name="is_active" value="1" id="afActive"
            <?= (!$editTarget || (int)$editTarget['is_active'] === 1) ? 'checked' : '' ?>><span class="slider"></span></span>
        <span><b style="color:var(--navy)">حساب فعال باشد</b><br>
          <small class="hint" style="margin:0">در صورت غیرفعال بودن، کاربر اجازه ورود به سامانه را ندارد.</small></span>
      </label>

      <h3 style="margin-top:26px"><?= hs_icon('key', 18) ?> پرمیشن‌های این کاربر</h3>
      <div class="perm-tools">
        <button type="button" class="btn btn-sm btn-blue" onclick="permAll(true)"><?= hs_icon('check', 15) ?> انتخاب همه</button>
        <button type="button" class="btn btn-sm btn-ghost" onclick="permAll(false)"><?= hs_icon('close', 15) ?> لغو انتخاب همه</button>
        <button type="button" class="btn btn-sm btn-green" onclick="permPreset('deputy')"><?= hs_icon('star', 15) ?> پیش‌فرض معاون</button>
        <button type="button" class="btn btn-sm btn-amber" onclick="permPreset('teacher')"><?= hs_icon('teacher', 16) ?>‍<?= hs_icon('school', 16) ?> پیش‌فرض معلم (پنل کلاس‌ها + تأخیر و غیبت)</button>
        <button type="button" class="btn btn-sm btn-red" onclick="permPreset('discipline')"><?= hs_icon('scales', 15) ?> پیش‌فرض مسئول انضباط</button>
        <button type="button" class="btn btn-sm btn-amber" onclick="permPreset('supervisor')"><?= hs_icon('star', 16) ?> معاون ارشد — دیدن سوابق همه</button>
        <button type="button" class="btn btn-sm btn-navy" onclick="permPreset('manager')"><?= hs_icon('user', 16) ?> مدیر / مسئول</button>
      </div>
      <p class="hint" style="margin-top:6px">
        «دیدن سوابق همه» یعنی کاربر می‌تواند سوابق ثبت‌شده توسط معلمان و معاونان دیگر را نیز ببیند؛ تا وقتی این پرمیشن را نداشته باشد
        هر کاربر فقط سوابق خودش را می‌بیند. برای فعال شدن، این پرمیشن باید همراه با «خروجی PDF / چاپ» باشد.
        <br><?= hs_icon('teacher', 17) ?>‍<?= hs_icon('school', 17) ?> <b>پنل معلم (کلاس‌ها):</b> کاربر با این پرمیشن فقط کلاس‌ها (پایه × رشته) و فهرست دانش‌آموزان همان کلاس را می‌بیند
        و از داخل کلاس، تأخیر/غیبت/انضباطی/پوششی ثبت می‌کند — بدون دسترسی به صفحهٔ «دانش‌آموزان» (افزودن، ویرایش، حذف و فهرست کامل).
      </p>

      <?php foreach ($grouped as $groupName => $items): ?>
        <div class="perm-group-title"><?= e($groupName) ?></div>
        <div class="perm-grid">
          <?php foreach ($items as $pName => $pLabel):
              $disabled = (!$isSup && $pName === 'manage_admins');
              $checked  = in_array($pName, $formPerms, true);
          ?>
            <label class="perm" for="p_<?= e($pName) ?>">
              <input type="checkbox" id="p_<?= e($pName) ?>" name="permissions[]" value="<?= e($pName) ?>"
                     class="permCb" data-perm="<?= e($pName) ?>" <?= $checked ? 'checked' : '' ?> <?= $disabled ? 'disabled' : '' ?>>
              <span>
                <span class="pn"><?= e($pLabel) ?><?= $disabled ? ' ' . hs_icon('lock', 17) : '' ?></span>
                <span class="pc"><?= e($pName) ?></span>
                <?php if ($disabled): ?><span class="hint">فقط مدیر کل می‌تواند این پرمیشن را اعطا کند.</span><?php endif; ?>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>

      <div class="flex mt20">
        <button class="btn btn-primary" type="submit"><?= $editTarget ? hs_icon('save', 17) . ' ذخیره تغییرات' : hs_icon('plus', 17) . ' ساخت ادمین' ?></button>
        <?php if ($editTarget): ?><a class="btn btn-ghost" href="admin.php">انصراف از ویرایش</a><?php endif; ?>
        <span class="hint" id="permCount"><?= fa_num(count($formPerms)) ?> پرمیشن انتخاب شده است.</span>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php if ($isSup): ?>
<!-- ================= تنظیمات روزهای تعطیل (فقط مدیر کل) ================= -->
<div class="card" id="settingsCard">
  <h3><?= hs_icon('info', 18) ?> تنظیمات روزهای تعطیل هفتگی</h3>
  <p class="muted small">
    روزهایی که در این بخش علامت بزنید، <b>در آمار، نمودارها و همه گزارش‌ها شمرده نمی‌شوند</b> و ستون آن‌ها در جدول‌های
    روزبه‌روز گزارش (تأخیر/غیبت/پوششی) ساخته نخواهد شد. پیش‌فرض: <b>پنجشنبه و جمعه</b>.
  </p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_settings">
    <div class="perm-grid">
      <?php foreach (jalali_weekdays() as $idx => $wname): ?>
        <label class="perm" for="wd_<?= $idx ?>">
          <input type="checkbox" id="wd_<?= $idx ?>" name="off_days[]" value="<?= $idx ?>"
                 <?= in_array($idx, hs_off_weekdays(), true) ? 'checked' : '' ?>>
          <span>
            <span class="pn"><?= e($wname) ?></span>
            <span class="pc">weekday index: <?= (int)$idx ?></span>
            <?php if (in_array($idx, HS_OFF_WEEKDAYS_DEFAULT, true)): ?>
              <span class="hint">پیش‌فرض سامانه</span>
            <?php endif; ?>
          </span>
        </label>
      <?php endforeach; ?>
    </div>

    <label class="opt mt20" style="margin-top:16px">
      <span class="switch">
        <input type="checkbox" name="allow_offday" value="1" <?= hs_allow_offday_records() ? 'checked' : '' ?>>
        <span class="slider"></span>
      </span>
      <span>
        <b style="color:var(--navy)">اجازه ثبت موارد در روزهای تعطیل</b><br>
        <small class="hint" style="margin:0">
          اگر مدرسه در برخی پنجشنبه‌ها دایر است، این گزینه را فعال کنید. توجه: این موارد در گزارش‌ها و آمار شمرده <b>نمی‌شوند</b>.
        </small>
      </span>
    </label>

    <div class="flex mt20">
      <button class="btn btn-primary" type="submit"><?= hs_icon('save', 16) ?> ذخیره تنظیمات</button>
      <a class="btn btn-ghost" href="admin.php">بازنشانی</a>
      <span class="hint">تغییرات بلافاصله در همه پنل‌ها، تقویم شمسی و گزارش‌ها اعمال می‌شود.</span>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- ================= ماتریس پرمیشن‌ها ================= -->
<div class="card">
  <h3><?= hs_icon('clipboard', 18) ?> ماتریس کامل پرمیشن‌های سامانه</h3>
  <p class="muted small">تمام قابلیت‌های سامانه به‌صورت پرمیشن مستقل قابل تخصیص هستند. ستون «تعداد کاربران» نشان می‌دهد چند حساب به این پرمیشن دسترسی دارند.</p>
  <div class="table-wrap">
    <table class="tbl matrix" style="min-width:520px">
      <thead><tr><th>کلید پرمیشن</th><th>عنوان فارسی</th><th>گروه</th><th>تعداد کاربران</th><th>وضعیت شما</th></tr></thead>
      <tbody>
        <?php foreach ($permUsage as $p): ?>
          <tr>
            <td><?= e($p['name']) ?></td>
            <td><?= e($p['label']) ?></td>
            <td><span class="badge blue"><?= e(HS_PERMISSIONS[$p['name']]['group'] ?? '—') ?></span></td>
            <td><?= fa_num((int)$p['c']) ?> کاربر<?= $isSup ? ' <span class="badge purple">+ مدیر کل</span>' : '' ?></td>
            <td><?= has_perm((string)$p['name']) ? '<span class="badge green">دارید ' . hs_icon('check', 17) . '</span>' : '<span class="badge gray">ندارید</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ================= مودال افزودن سریع ================= -->
<div class="modal-bg" id="adminModal">
  <div class="modal">
    <header><span style="font-size:20px"><?= hs_icon('plus', 17) ?></span><h3>افزودن سریع ادمین</h3>
      <button class="btn btn-sm btn-ghost" style="margin-inline-start:auto" onclick="HS.modal('adminModal', false)"><?= hs_icon('close', 16) ?></button></header>
    <div class="mbody">
      <p class="muted small">برای تخصیص پرمیشن‌های دقیق، از فرم کامل پایین صفحه استفاده کنید. این پنجره برای افزودن سریع با پرمیشن‌های پیشنهادی است.</p>
      <form method="post" autocomplete="off" data-loading="در حال ساخت کاربر…">
        <?= csrf_field() ?><input type="hidden" name="action" value="add">
        <label class="fl">نام و نام خانوادگی</label>
        <input type="text" name="full_name" required minlength="3" maxlength="120" placeholder="مثال: علی محمدی (معلم ریاضی)">
        <div class="grid g2 mt10">
          <div>
            <label class="fl">نام کاربری</label>
            <input type="text" name="username" required pattern="[A-Za-z0-9_\.]{3,60}" dir="ltr" placeholder="a.mohammadi">
          </div>
          <div>
            <label class="fl">رمز عبور</label>
            <input type="password" name="password" required minlength="6" placeholder="حداقل ۶ کاراکتر">
          </div>
        </div>
        <label class="fl mt10">نقش</label>
        <select name="role">
          <option value="teacher">معلم</option>
          <option value="deputy">معاون</option>
          <option value="manager">مدیر / مسئول انضباط</option>
          <option value="staff">کارمند دفتری</option>
        </select>
        <input type="hidden" name="is_active" value="1">
        <div class="perm-group-title">پرمیشن‌های پیشنهادی</div>
        <div class="perm-grid">
          <?php foreach (['view_students' => 'مشاهده دانش‌آموزان', 'register_delay' => 'ثبت تأخیر', 'register_absence' => 'ثبت غیبت'] as $pn => $pl): ?>
            <label class="perm"><input type="checkbox" name="permissions[]" value="<?= e($pn) ?>" checked>
              <span><span class="pn"><?= e($pl) ?></span><span class="pc"><?= e($pn) ?></span></span></label>
          <?php endforeach; ?>
        </div>
        <div class="flex mt20">
          <button class="btn btn-blue" type="submit"><?= hs_icon('plus', 16) ?> ساخت کاربر</button>
          <button class="btn btn-ghost" type="button" onclick="HS.modal('adminModal', false)"><?= hs_icon('close', 15) ?> انصراف</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ================= مودال تغییر رمز خودم ================= -->
<div class="modal-bg" id="mypass">
  <div class="modal" style="max-width:470px">
    <header><span style="font-size:20px"><?= hs_icon('lock', 17) ?></span><h3>تغییر رمز عبور من</h3>
      <button class="btn btn-sm btn-ghost" style="margin-inline-start:auto" onclick="HS.modal('mypass', false)"><?= hs_icon('close', 16) ?></button></header>
    <div class="mbody">
      <form method="post" autocomplete="off" data-loading="در حال تغییر رمز…">
        <?= csrf_field() ?><input type="hidden" name="action" value="my_password">
        <label class="fl">رمز عبور فعلی</label>
        <input type="password" name="old_password" required>
        <label class="fl mt10">رمز عبور جدید</label>
        <input type="password" name="new_password" id="mpNew" required minlength="6">
        <div class="strength" id="mpStr"><i></i></div>
        <label class="fl mt10">تکرار رمز جدید</label>
        <input type="password" id="mpRepeat" required>
        <div class="flex mt20">
          <button class="btn btn-primary" type="submit"><?= hs_icon('save', 16) ?> ذخیره رمز جدید</button>
          <button class="btn btn-ghost" type="button" onclick="HS.modal('mypass', false)"><?= hs_icon('close', 15) ?> انصراف</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$adminJs = <<<'JS'
/* ===== اسکریپت پنل مدیریت ادمین‌ها ===== */
function openAdminModal() { HS.modal('adminModal', true); }
function permAll(state) {
  HS.$$('.permCb').forEach(cb => { if (!cb.disabled) cb.checked = state; });
  updPermCount();
}
function permPreset(kind) {
  /* پرمیشن «view_all_records» یعنی دیدن سوابق همه کاربران (تفکیکی).
     فقط همراه با «export_pdf» فعال می‌شود؛ اگر هر دو با هم باشد کاربر همهٔ سوابق را می‌بیند. */
  const presets = {
    deputy: ['view_students','add_student','edit_student','register_delay','register_absence','register_discipline','register_dress','export_pdf'],
    /* معلم: پنل کلاس‌ها + ثبت تأخیر و غیبت (بدون دسترسی به فهرست کامل دانش‌آموزان) */
    teacher: ['teacher_panel','register_delay','register_absence'],
    discipline: ['view_students','register_discipline','register_dress','export_pdf'],
    /* معاون ارشد: ثبت + دیدن سوابق همهٔ کاربران + خروجی PDF */
    supervisor: ['view_students','register_delay','register_absence','register_discipline','register_dress','export_pdf','view_all_records'],
    /* مدیر / مسئول: همه‌چیز جز مدیریت ادمین‌ها */
    manager: ['view_students','add_student','edit_student','delete_student','register_delay','register_absence','register_discipline','register_dress','export_pdf','view_all_records']
  };
  const list = presets[kind] || [];
  HS.$$('.permCb').forEach(cb => {
    if (cb.disabled) return;
    cb.checked = list.indexOf(cb.dataset.perm) > -1;
  });
  updPermCount();
  const names = { deputy: 'معاون', teacher: 'معلم', discipline: 'مسئول انضباط',
                  supervisor: 'معاون ارشد (مشاهدهٔ همهٔ سوابق)', manager: 'مدیر / مسئول' };
  HS.toast('پرمیشن‌های پیش‌فرض «' + (names[kind] || '') + '» اعمال شد.', 'ok');
}
function updPermCount() {
  const n = HS.$$('.permCb:checked').length;
  const el = document.getElementById('permCount');
  if (el) el.textContent = HS.faNum(n) + ' پرمیشن انتخاب شده است.';
}
/* سنجش قدرت رمز */
function strength(v) {
  let s = 0;
  if (v.length >= 6) s++;
  if (v.length >= 10) s++;
  if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++;
  if (/\d/.test(v) && /[^A-Za-z0-9]/.test(v)) s++;
  return Math.min(4, s);
}
document.addEventListener('DOMContentLoaded', function () {
  HS.$$('.permCb').forEach(cb => cb.addEventListener('change', updPermCount));

  const p = document.getElementById('afPass'), st = document.getElementById('afStr'), stx = document.getElementById('afStrTxt');
  if (p) p.addEventListener('input', function () {
    const s = strength(p.value);
    st.className = 'strength' + (s ? ' s' + s : '');
    stx.textContent = 'قدرت رمز: ' + ['—', 'ضعیف', 'متوسط', 'خوب', 'قوی'][s];
  });

  const mp = document.getElementById('mpNew'), mst = document.getElementById('mpStr');
  if (mp) mp.addEventListener('input', () => { const s = strength(mp.value); mst.className = 'strength' + (s ? ' s' + s : ''); });

  /* تأیید حذف ادمین */
  HS.$$('.delForm').forEach(f => f.addEventListener('submit', function (e) {
    if (f.dataset.done) return;
    e.preventDefault();
    HS.confirm('آیا از حذف کاربر «<b>' + f.dataset.name + '</b>» مطمئن هستید؟ این عملیات بازگشت‌پذیر نیست.',
      () => { f.dataset.done = '1'; HS.loading(true, 'در حال حذف کاربر…'); f.submit(); }, { yes: 'بله، حذف کن', title: 'حذف کاربر' });
  }));

  /* بررسی تکرار رمز خودم */
  const r = document.getElementById('mpRepeat');
  if (r) r.form.addEventListener('submit', function (e) {
    if (r.value !== mp.value) {
      e.preventDefault();
      HS.toast('تکرار رمز جدید با رمز جدید یکسان نیست.', 'err');
      r.classList.add('bad');
    }
  });
});
JS;
layout_end([$adminJs]);
