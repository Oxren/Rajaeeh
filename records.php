<?php
/**
 * ============================================================================
 *  سامانه جامع مدیریت هنرستان شهید رجایی
 *  فایل: records.php — ثبت تأخیر، غیبت، موارد انضباطی و تخلف پوششی
 * ----------------------------------------------------------------------------
 *  قابلیت‌ها:
 *    • ثبت تأخیر حضور با تاریخ شمسی و ساعت خودکار (قابل ویرایش)
 *    • ثبت مورد انضباطی همراه با نوع تخلف، توضیحات و مقدار کسر
 *    • ثبت تخلف پوششی (لباس / شلوار / هر دو / سایر)
 *    • ثبت غیبت با انتخاب تاریخ شمسی؛ روز هفته به‌صورت خودکار محاسبه می‌شود
 *    • نمایش موارد ثبت‌شده «امروز» به‌صورت پیش‌فرض + انتخاب هر روز دیگر از تقویم شمسی
 *    • امکان مینیمایز / بستن هر بخش
 *    • جستجوی زنده دانش‌آموز (AJAX) برای انتخاب سریع هنرجو
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
require_login();

if (!has_perm('register_delay') && !has_perm('register_absence')
    && !has_perm('register_discipline') && !has_perm('register_dress')) {
    hs_forbidden('register_delay');
}

$errors  = [];
$tab     = req('tab', 'delay');                    // delay | absence | discipline | dress
/* تب‌ها بر اساس پرمیشن: اگر تب درخواستی مجاز نباشد، به اولین تب مجاز می‌رود */
$tabAllowed = ['delay' => has_perm('register_delay'), 'absence' => has_perm('register_absence'),
               'discipline' => has_perm('register_discipline'), 'dress' => has_perm('register_dress'),
               'bulk' => has_perm('view_students')];
if (!isset($tabAllowed[$tab]) || !$tabAllowed[$tab]) {
    foreach ($tabAllowed as $tKey => $ok) { if ($ok) { $tab = $tKey; break; } }
}
$now     = hs_now();
$todayJ  = jalali_today();
/**
 * اگر موردی در روز تعطیل (با مجوز مدیر) ثبت شد، هشدار اطلاعی نشان بده تا کاربر
 * بداند این مورد در آمار و گزارش‌ها شمرده نخواهد شد.
 */
function offday_flash_note(string $jdate): void
{
    if (hs_is_off_day($jdate)) {
        flash_set('warn', 'تاریخ انتخابی، روز ' . jalali_weekday_name($jdate)
            . ' (تعطیل) است؛ مورد ثبت شد اما در آمار، نمودارها و گزارش‌ها شمرده نمی‌شود.');
    }
}

$workDay = hs_default_work_date();                 // اگر امروز تعطیل باشد: آخرین روز کاری
$allowOff = hs_allow_offday_records();             // اجازه ثبت در روز تعطیل (تنظیم مدیر)
$viewAllR = hs_can_view_all();                     // مشاهده سوابق همه ثبت‌کنندگان

/* ---------------------------------------------------------------------------
 * ۱) API داخلی: جستجوی زنده دانش‌آموز
 * ------------------------------------------------------------------------ */
if (req('action') === 'search_students' && req('ajax') === '1') {
    $q = clean_text(req('q'), 40);
    if (mb_strlen($q, 'UTF-8') < 2) {
        json_out(['ok' => true, 'items' => []]);
    }
    $like = '%' . $q . '%';
    $items = rows(
        'SELECT id, first_name, last_name, grade, field FROM students
         WHERE first_name LIKE ? OR last_name LIKE ? OR ' . sql_full_name() . ' LIKE ?
         ORDER BY last_name ASC, first_name ASC LIMIT 20',
        [$like, $like, $like]
    );
    $out = [];
    foreach ($items as $s) {
        $out[] = [
            'id'    => (int)$s['id'],
            'name'  => $s['first_name'] . ' ' . $s['last_name'],
            'meta'  => 'پایه ' . $s['grade'] . ' — رشته ' . $s['field'],
            'grade' => $s['grade'],
            'field' => $s['field'],
        ];
    }
    json_out(['ok' => true, 'items' => $out]);
}

/* ---------------------------------------------------------------------------
 * ۲) توابع کمکی
 * ------------------------------------------------------------------------ */
/** واکشی و اعتبارسنجی دانش‌آموز انتخاب‌شده */
function get_student_or_fail(int $id, array &$errors): ?array
{
    if ($id <= 0) {
        $errors[] = 'انتخاب دانش‌آموز الزامی است.';
        return null;
    }
    $s = row('SELECT * FROM students WHERE id = ?', [$id]);
    if (!$s) {
        $errors[] = 'دانش‌آموز انتخاب‌شده در سامانه یافت نشد.';
        return null;
    }
    return $s;
}

/** جدول + پرمیشن هر نوع رکورد (برای حذف و نمایش) */
function record_tables(): array
{
    return [
        'delay'      => ['table' => 'delays',           'perm' => 'register_delay',      'label' => 'تأخیر',            'mark' => 'ت'],
        'absence'    => ['table' => 'absences',         'perm' => 'register_absence',    'label' => 'غیبت',             'mark' => 'غ'],
        'discipline' => ['table' => 'disciplines',      'perm' => 'register_discipline', 'label' => 'مورد انضباطی',      'mark' => 'ا'],
        'dress'      => ['table' => 'dress_violations', 'perm' => 'register_dress',      'label' => 'تخلف پوششی',       'mark' => 'پ'],
    ];
}

/* ---------------------------------------------------------------------------
 * ۳) پردازش ثبت رکوردها
 * ------------------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_require();
    $action = req('action');

    /* ----------------------------- ثبت تأخیر ----------------------------- */
    if ($action === 'add_delay') {
        require_perm('register_delay');
        $tab = 'delay';
        $student = get_student_or_fail(req_int('student_id'), $errors);
        $jdate = normalize_jalali(req('jdate')) ?? $workDay;
        if (!$allowOff && hs_is_off_day($jdate)) {
            $errors[] = hs_offday_message($jdate);
        }
        $time  = fa_to_en_digits((string)req('time', $now['time']));
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time)) {
            $errors[] = 'ساعت وارد‌شده نامعتبر است (قالب صحیح: 14:30).';
        }
        $desc = clean_text(req('description'), 255);
        if ($student && !$errors) {
            /* دانش‌آموز می‌تواند در چند زنگِ یک روز تأخیر داشته باشد؛
               فقط ثبت دقیقاً تکراری (همان دانش‌آموز، همان روز، همان ساعت/زنگ) رد می‌شود. */
            $timeNorm = strlen($time) === 4 ? '0' . $time : $time;
            $sameCount = (int)one(
                'SELECT COUNT(*) FROM delays WHERE student_id = ? AND jdate = ? AND time = ?',
                [$student['id'], $jdate, $timeNorm]
            );
            if ($sameCount > 0) {
                $bl = hs_bell_name($timeNorm);
                $errors[] = 'برای این دانش‌آموز در تاریخ ' . $jdate . ' و ساعت ' . $timeNorm
                    . ($bl ? ' (' . $bl . ')' : '') . ' قبلاً تأخیر ثبت شده است؛ اگر زنگ دیگری است، ساعت/زنگ را تغییر دهید.';
            } else {
                $id = db_insert('delays', [
                    'student_id' => (int)$student['id'],
                    'jdate'      => $jdate,
                    'gdate'      => jalali_to_gregorian_str($jdate),
                    'time'       => strlen($time) === 4 ? '0' . $time : $time,
                    'description'=> $desc !== '' ? $desc : null,
                    'created_by' => (int)current_admin()['id'],
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                log_activity('ثبت تأخیر', $student['first_name'] . ' ' . $student['last_name'] . ' — ' . $jdate . ' ' . $time);
                $bellTxt = hs_bell_name($timeNorm) ? ' (' . hs_bell_name($timeNorm) . ')' : '';
                $dayCount = (int)one('SELECT COUNT(*) FROM delays WHERE student_id = ? AND jdate = ?', [$student['id'], $jdate]);
                flash_set('success', 'تأخیر با موفقیت ثبت شد — «' . $student['first_name'] . ' ' . $student['last_name'] . '» در تاریخ ' . jalali_pretty($jdate)
                    . '، ساعت ' . fa_num($timeNorm) . $bellTxt . '.');
                if ($dayCount > 1) {
                    flash_set('info', 'این دانش‌آموز در این روز ' . fa_num($dayCount) . ' تأخیر (در ' . fa_num($dayCount)
                        . ' زنگ مختلف) دارد؛ همهٔ آن‌ها در فهرست زیر و در گزارش‌ها ثبت شده‌اند.');
                }
                offday_flash_note($jdate);
                header('Location: records.php?tab=delay&jdate=' . urlencode($jdate));
                exit;
            }
        }
    }

    /* ----------------------------- ثبت غیبت ----------------------------- */
    if ($action === 'add_absence') {
        require_perm('register_absence');
        $tab = 'absence';
        $student = get_student_or_fail(req_int('student_id'), $errors);
        $jdate = normalize_jalali(req('jdate'));
        if ($jdate === null) {
            $errors[] = 'تاریخ غیبت نامعتبر است؛ از تقویم شمسی انتخاب کنید.';
        } elseif (!$allowOff && hs_is_off_day($jdate)) {
            $errors[] = hs_offday_message($jdate);
        }
        $desc = clean_text(req('description'), 255);
        /* کلاس/درس و زنگ (اختیاری) — برای پیگیری در پنل معلم و فهرست‌ها */
        $lesson = clean_text(req('lesson'), 120);
        $abTime = fa_to_en_digits(trim((string)req('time', '')));
        if ($abTime !== '' && !preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $abTime)) {
            $errors[] = 'زنگ/ساعت کلاس نامعتبر است (نمونهٔ درست: 09:45).';
        }
        $abTime = $abTime === '' ? null : (strlen($abTime) === 4 ? '0' . $abTime : $abTime);
        if ($student && $jdate && !$errors) {
            $dow = jalali_weekday_name($jdate);
            if ((int)one('SELECT COUNT(*) FROM absences WHERE student_id = ? AND jdate = ?', [$student['id'], $jdate]) > 0) {
                $errors[] = 'برای این دانش‌آموز در تاریخ ' . $jdate . ' قبلاً غیبت ثبت شده است.';
            } else {
                db_insert('absences', [
                    'student_id'  => (int)$student['id'],
                    'jdate'       => $jdate,
                    'gdate'       => jalali_to_gregorian_str($jdate),
                    'day_of_week' => $dow,
                    'description' => $desc !== '' ? $desc : null,
                    'lesson'      => $lesson !== '' ? $lesson : null,
                    'time'        => $abTime,
                    'created_by'  => (int)current_admin()['id'],
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
                log_activity('ثبت غیبت', $student['first_name'] . ' ' . $student['last_name'] . ' — ' . $jdate . ' (' . $dow . ')');
                flash_set('success', 'غیبت با موفقیت ثبت شد — «' . $student['first_name'] . ' ' . $student['last_name'] . '» در ' . jalali_full($jdate) . '.');
                offday_flash_note($jdate);
                header('Location: records.php?tab=absence&jdate=' . urlencode($jdate));
                exit;
            }
        }
    }

    /* -------------------------- ثبت مورد انضباطی -------------------------- */
    if ($action === 'add_discipline') {
        require_perm('register_discipline');
        $tab = 'discipline';
        $student = get_student_or_fail(req_int('student_id'), $errors);
        $jdate = normalize_jalali(req('jdate')) ?? $workDay;
        if (!$allowOff && hs_is_off_day($jdate)) {
            $errors[] = hs_offday_message($jdate);
        }
        $type  = clean_text(req('type'), 60);
        $desc  = clean_text(req('description'), 500);
        $dedRaw = fa_to_en_digits((string)req('deduction', '0'));
        $deduction = max(0, min(20, (int)$dedRaw));

        if ($type === '' || mb_strlen($type, 'UTF-8') < 3) {
            $errors[] = 'نوع تخلف/مورد انضباطی را انتخاب یا وارد کنید.';
        }
        if ($desc === '' || mb_strlen($desc, 'UTF-8') < 3) {
            $errors[] = 'توضیحات مورد انضباطی الزامی است (حداقل ۳ کاراکتر).';
        }
        if ($student && !$errors) {
            db_insert('disciplines', [
                'student_id'  => (int)$student['id'],
                'jdate'       => $jdate,
                'gdate'       => jalali_to_gregorian_str($jdate),
                'type'        => $type,
                'description' => $desc,
                'deduction'   => $deduction,
                'created_by'  => (int)current_admin()['id'],
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            log_activity('ثبت مورد انضباطی', $student['first_name'] . ' ' . $student['last_name'] . " — {$type} — کسر: {$deduction}");
            flash_set('success', 'مورد انضباطی با موفقیت ثبت شد — «' . $student['first_name'] . ' ' . $student['last_name'] . '»'
                . ($deduction > 0 ? ' (کسر ' . fa_num($deduction) . ')' : '') . '.');
            offday_flash_note($jdate);
            header('Location: records.php?tab=discipline&jdate=' . urlencode($jdate));
            exit;
        }
    }

    /* -------------------------- ثبت تخلف پوششی -------------------------- */
    if ($action === 'add_dress') {
        require_perm('register_dress');
        $tab = 'dress';
        $student = get_student_or_fail(req_int('student_id'), $errors);
        $jdate = normalize_jalali(req('jdate')) ?? $workDay;
        if (!$allowOff && hs_is_off_day($jdate)) {
            $errors[] = hs_offday_message($jdate);
        }
        $type  = clean_text(req('type'), 30);
        $desc  = clean_text(req('description'), 255);

        if (!in_array($type, HS_DRESS_TYPES, true)) {
            $errors[] = 'نوع تخلف پوششی نامعتبر است (لباس / شلوار / هر دو / سایر).';
        }
        if ($student && !$errors) {
            db_insert('dress_violations', [
                'student_id'  => (int)$student['id'],
                'jdate'       => $jdate,
                'gdate'       => jalali_to_gregorian_str($jdate),
                'type'        => $type,
                'description' => $desc !== '' ? $desc : null,
                'created_by'  => (int)current_admin()['id'],
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            log_activity('ثبت تخلف پوششی', $student['first_name'] . ' ' . $student['last_name'] . " — {$type}");
            flash_set('success', 'تخلف پوششی با موفقیت ثبت شد — «' . $student['first_name'] . ' ' . $student['last_name'] . '»، نوع: (' . $type . ').');
                offday_flash_note($jdate);
            header('Location: records.php?tab=dress&jdate=' . urlencode($jdate));
            exit;
        }
    }

    /* ----------------------------- ثبت گروهی ----------------------------- */
    if ($action === 'add_bulk') {
        /* ثبت گروهی برای کاربرانی است که «مشاهده دانش‌آموزان» دارند؛
           معلم‌ها از «پنل معلم» کلاس‌های خود را ثبت می‌کنند */
        if (!has_perm('view_students')) {
            $errors[] = 'ثبت گروهی تنها برای کاربران دارای دسترسی «مشاهده دانش‌آموزان» فعال است؛ '
                      . 'برای ثبت کلاسی از «پنل معلم (کلاس‌ها)» استفاده کنید.';
        }
        $kind = clean_text(req('kind'), 20);
        $kinds = [
            'delay'      => ['perm' => 'register_delay',      'label' => 'تأخیر'],
            'absence'    => ['perm' => 'register_absence',    'label' => 'غیبت'],
            'discipline' => ['perm' => 'register_discipline', 'label' => 'مورد انضباطی'],
            'dress'      => ['perm' => 'register_dress',      'label' => 'تخلف پوششی'],
        ];
        if (!isset($kinds[$kind])) {
            $errors[] = 'نوع ثبت گروهی نامعتبر است.';
        } else {
            require_perm($kinds[$kind]['perm']);
            $jdate = normalize_jalali(req('jdate')) ?? $workDay;

            /* روز تعطیل: به‌طور پیش‌فرض ثبت مجاز نیست (تنظیم مدیر) */
            if (!$allowOff && hs_is_off_day($jdate)) {
                $errors[] = hs_offday_message($jdate);
            }

            /* فهرست دانش‌آموزان انتخاب‌شده: یا مستقیم (ids[]) یا بر اساس فیلتر (select_all) */
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
            if (!$ids && req('select_all') === '1') {
                $ids = hs_student_ids_by(
                    validate_grade(req('f_grade')) ?? null,
                    clean_text(req('f_field'), 40) ?: null,
                    clean_text(req('f_q'), 60) ?: null
                );
            }
            if (!$ids) {
                $errors[] = 'هیچ دانش‌آموزی برای ثبت گروهی انتخاب نشده است.';
            }
            if (count($ids) > 400) {
                $errors[] = 'حداکثر ۴۰۰ دانش‌آموز در هر ثبت گروهی مجاز است (تعداد فعلی: ' . fa_num(count($ids)) . ').';
            }

            /* مقادیر وابسته به نوع */
            $time = '08:00'; $desc = clean_text(req('description'), 255);
            $type = ''; $deduction = null;
            if ($kind === 'delay') {
                $time = fa_to_en_digits((string)req('time', $now['time']));
                if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time)) {
                    $errors[] = 'ساعت وارد‌شده نامعتبر است (قالب صحیح: 14:30).';
                }
                $time = strlen($time) === 4 ? '0' . $time : $time;
            } elseif ($kind === 'discipline') {
                $type = clean_text(req('discipline_type'), 60);
                if (!in_array($type, HS_DISCIPLINE_TYPES, true)) {
                    $errors[] = 'نوع مورد انضباطی را انتخاب کنید.';
                }
                $deduction = req_int('deduction', 0);
                if ($deduction < 0 || $deduction > 20) { $deduction = 0; }
            } elseif ($kind === 'dress') {
                $type = clean_text(req('dress_type'), 60);
                if (!in_array($type, HS_DRESS_TYPES, true)) {
                    $errors[] = 'نوع تخلف پوششی را انتخاب کنید.';
                }
            }

            if (!$errors) {
                $gdate   = jalali_to_gregorian_str($jdate);
                $adminId = (int)current_admin()['id'];
                $inserted = 0; $dup = []; $missing = 0;
                $table = ['delay' => 'delays', 'absence' => 'absences', 'discipline' => 'disciplines', 'dress' => 'dress_violations'][$kind];

                foreach ($ids as $sid) {
                    $st = row('SELECT id, first_name, last_name FROM students WHERE id = ?', [$sid]);
                    if (!$st) { $missing++; continue; }

                    /* جلوگیری از ثبت تکراری همان دانش‌آموز در همان روز و همان نوع */
                    if ($kind === 'delay') {
                        /* چند زنگ در یک روز مجاز است؛ فقط همان ساعت/زنگ تکراری رد می‌شود */
                        $dupQ = 'SELECT COUNT(*) FROM delays WHERE student_id = ? AND jdate = ? AND time = ?';
                        $dupP = [$sid, $jdate, $time];
                    } elseif ($kind === 'absence') {
                        $dupQ = 'SELECT COUNT(*) FROM absences WHERE student_id = ? AND jdate = ?';
                        $dupP = [$sid, $jdate];
                    } elseif ($kind === 'discipline') {
                        $dupQ = 'SELECT COUNT(*) FROM disciplines WHERE student_id = ? AND jdate = ? AND type = ?';
                        $dupP = [$sid, $jdate, $type];
                    } else {
                        $dupQ = 'SELECT COUNT(*) FROM dress_violations WHERE student_id = ? AND jdate = ? AND type = ?';
                        $dupP = [$sid, $jdate, $type];
                    }
                    if ((int)one($dupQ, $dupP) > 0) {
                        $dup[] = $st['first_name'] . ' ' . $st['last_name'];
                        continue;
                    }

                    $payload = [
                        'student_id' => (int)$sid,
                        'jdate'      => $jdate,
                        'gdate'      => $gdate,
                        'description'=> $desc !== '' ? $desc : null,
                        'created_by' => $adminId,
                        'created_at' => date('Y-m-d H:i:s'),
                    ];
                    if ($kind === 'delay') {
                        $payload['time'] = $time;
                    }
                    if ($kind === 'absence') {
                        $payload['day_of_week'] = jalali_weekday_name($jdate);
                    }
                    if ($kind === 'discipline') {
                        $payload['type'] = $type;
                        $payload['deduction'] = $deduction;
                    }
                    if ($kind === 'dress') {
                        $payload['type'] = $type;
                    }
                    db_insert($table, $payload);
                    $inserted++;
                }

                log_activity('ثبت گروهی ' . $kinds[$kind]['label'],
                    'تاریخ ' . $jdate . ' — ثبت‌شده: ' . $inserted . ' | تکراری: ' . count($dup) . ' | یافت‌نشده: ' . $missing);

                if ($inserted > 0) {
                    flash_set('success', $kinds[$kind]['label'] . ' با موفقیت ثبت شد — برای ' . fa_num($inserted)
                        . ' دانش‌آموز در تاریخ ' . jalali_pretty($jdate) . '.');
                    offday_flash_note($jdate);
                }
                if ($dup) {
                    $dupWhy = $kind === 'delay'
                        ? 'برای همین زنگ/ساعت ثبت شده بود'
                        : 'قبلاً در همین روز ثبت شده بود';
                    flash_set('warn', fa_num(count($dup)) . ' مورد رد شد (' . $dupWhy . '): '
                        . implode('، ', array_slice($dup, 0, 8)) . (count($dup) > 8 ? ' و …' : ''));
                }
                if ($missing) {
                    flash_set('err', fa_num($missing) . ' دانش‌آموز یافت نشد (احتمالاً حذف شده است).');
                }
                if ($inserted === 0 && !$dup && !$missing) {
                    flash_set('err', 'هیچ موردی ثبت نشد.');
                }
                $keepIds = $inserted > 0 ? '&ids=' . urlencode(implode(',', array_slice($ids, 0, 200))) : '';
                header('Location: records.php?tab=bulk&kind=' . urlencode($kind) . '&jdate=' . urlencode($jdate) . $keepIds);
                exit;
            }
            if (has_perm('view_students')) { $tab = 'bulk'; }
        }
    }

    /* ----------------------------- حذف رکورد ----------------------------- */
    if ($action === 'delete_record') {
        $kind  = clean_text(req('kind'), 20);
        $id    = req_int('id');
        $map   = record_tables();
        if (!isset($map[$kind])) {
            $errors[] = 'نوع رکورد نامعتبر است.';
        } elseif (!has_perm($map[$kind]['perm'])) {
            $errors[] = 'برای حذف این نوع رکورد دسترسی ندارید.';
        } else {
            $r = row('SELECT * FROM ' . $map[$kind]['table'] . ' WHERE id = ?', [$id]);
            if (!$r) {
                $errors[] = 'رکورد مورد نظر یافت نشد.';
            } else {
                db_delete($map[$kind]['table'], ['id' => $id]);
                log_activity('حذف رکورد ' . $map[$kind]['label'], 'شناسه: ' . $id);
                flash_set('success', $map[$kind]['label'] . ' مورد نظر حذف شد.');
            }
        }
        header('Location: records.php?tab=' . urlencode($kind) . '&jdate=' . urlencode((string)req('jdate', $todayJ)));
        exit;
    }
}

/* ---------------------------------------------------------------------------
 * ۴) واکشی داده برای نمایش
 * ------------------------------------------------------------------------ */
$viewDate = normalize_jalali(req('jdate')) ?? $workDay;
$isToday  = $viewDate === $workDay;

/* شرط دامنه ثبت‌کننده (کاربر عادی: فقط سوابق خودش / مدیر و مجاز: همه یا فیلترشده) */
[$cfDelay, $cpDelay] = creator_filter('d');
[$cfAbs,   $cpAbs]   = creator_filter('ab');
[$cfDisc,  $cpDisc]  = creator_filter('dc');   // در کوئری‌های انضباطی alias همان dc است
[$cfDress, $cpDress] = creator_filter('v');
$scopeLabel = creator_scope_label();

/* آمار روز کاری جاری (برای کارت‌های بالای صفحه) */
$statsToday = [
    'delay'      => (int)one("SELECT COUNT(*) FROM delays d WHERE d.jdate = ? AND {$cfDelay}", array_merge([$workDay], $cpDelay)),
    'absence'    => (int)one("SELECT COUNT(*) FROM absences ab WHERE ab.jdate = ? AND {$cfAbs}", array_merge([$workDay], $cpAbs)),
    'discipline' => (int)one("SELECT COUNT(*) FROM disciplines dc WHERE dc.jdate = ? AND {$cfDisc}", array_merge([$workDay], $cpDisc)),
    'dress'      => (int)one("SELECT COUNT(*) FROM dress_violations v WHERE v.jdate = ? AND {$cfDress}", array_merge([$workDay], $cpDress)),
];

/* فهرست‌های روز انتخابی — فیلترشده بر پایه دامنه دسترسی */
$delays = rows(
    "SELECT d.*, s.first_name, s.last_name, s.grade, s.field, a.full_name AS by_name, a.role AS by_role
     FROM delays d JOIN students s ON s.id = d.student_id
     LEFT JOIN admins a ON a.id = d.created_by
     WHERE d.jdate = ? AND {$cfDelay} ORDER BY d.time DESC",
    array_merge([$viewDate], $cpDelay)
);
$absences = rows(
    "SELECT ab.*, s.first_name, s.last_name, s.grade, s.field, a.full_name AS by_name, a.role AS by_role
     FROM absences ab JOIN students s ON s.id = ab.student_id
     LEFT JOIN admins a ON a.id = ab.created_by
     WHERE ab.jdate = ? AND {$cfAbs} ORDER BY ab.id DESC",
    array_merge([$viewDate], $cpAbs)
);
$disciplines = rows(
    "SELECT dc.*, s.first_name, s.last_name, s.grade, s.field, a.full_name AS by_name, a.role AS by_role
     FROM disciplines dc JOIN students s ON s.id = dc.student_id
     LEFT JOIN admins a ON a.id = dc.created_by
     WHERE dc.jdate = ? AND {$cfDisc} ORDER BY dc.id DESC",
    array_merge([$viewDate], $cpDisc)
);
$dresses = rows(
    "SELECT v.*, s.first_name, s.last_name, s.grade, s.field, a.full_name AS by_name, a.role AS by_role
     FROM dress_violations v JOIN students s ON s.id = v.student_id
     LEFT JOIN admins a ON a.id = v.created_by
     WHERE v.jdate = ? AND {$cfDress} ORDER BY v.id DESC",
    array_merge([$viewDate], $cpDress)
);

/* ---------------------------------------------------------------------------
 *  دادهٔ تب «ثبت گروهی»: فهرست دانش‌آموزان + پیش‌انتخاب‌شده‌ها
 * ------------------------------------------------------------------------ */
$bulkKind = in_array(req('kind'), ['delay', 'absence', 'discipline', 'dress'], true) ? req('kind') : 'delay';
$bulkIds  = [];
$rawIds   = (string)req('ids', '');
if ($rawIds !== '') {
    foreach (explode(',', $rawIds) as $p) {
        $p = fa_to_en_digits(trim($p));
        if (ctype_digit($p)) { $bulkIds[] = (int)$p; }
    }
    $bulkIds = array_values(array_unique($bulkIds));
}
$bulkStudents = rows('SELECT id, first_name, last_name, grade, field FROM students ORDER BY grade, field, last_name, first_name');
$bulkGrades   = array_values(array_unique(array_column($bulkStudents, 'grade')));

/* اگر از فهرست دانش‌آموزان آمده باشد، همان دانش‌آموز پیش‌انتخاب شود */
$preStudent = null;
if (req('student_id') !== null) {
    $preStudent = row('SELECT * FROM students WHERE id = ?', [req_int('student_id')]);
}
/* رکوردهای اخیر برای نمایش سریع در هر تب */
$recentDiscipline = rows(
    "SELECT dc.id, dc.type, dc.jdate, s.first_name, s.last_name, a.full_name AS by_name FROM disciplines dc
     JOIN students s ON s.id = dc.student_id
     LEFT JOIN admins a ON a.id = dc.created_by
     WHERE {$cfDisc} ORDER BY dc.id DESC LIMIT 6",
    $cpDisc
);
$studentTotal = (int)one('SELECT COUNT(*) FROM students');


/* ---------------------------------------------------------------------------
 * ۵) رندر فهرست رکوردهای یک روز (پنل قابل مینیمایز + ناوبری روز)
 * ------------------------------------------------------------------------ */
function render_records_list(string $kind, array $list, string $viewDate, bool $isToday, ?string $workDay = null): void
{
    /* روز کاری مرجع (اگر امروز تعطیل باشد: آخرین روز کاری) */
    $workDay = $workDay ?? hs_default_work_date();
    $meta = [
        'delay'      => ['title' => 'تأخیرهای',       'icon' => 'clock',  'cls' => 'delay',      'empty' => 'در این روز تأخیری ثبت نشده است.',  'color' => 'amber'],
        'absence'    => ['title' => 'غیبت‌های',       'icon' => 'ban',    'cls' => 'absence',    'empty' => 'در این روز غیبتی ثبت نشده است.',    'color' => 'red'],
        'discipline' => ['title' => 'موارد انضباطی',   'icon' => 'scales', 'cls' => 'discipline', 'empty' => 'در این روز مورد انضباطی ثبت نشده است.', 'color' => 'purple'],
        'dress'      => ['title' => 'تخلفات پوششی',   'icon' => 'shirt',  'cls' => 'dress',      'empty' => 'در این روز تخلف پوششی ثبت نشده است.', 'color' => 'blue'],
    ];
    $m = $meta[$kind];
    ?>
    <div class="collapse" id="list-<?= e($kind) ?>">
      <div class="head">
        <?= hs_icon($m['icon'], 17) ?> <?= e($m['title']) ?>
        <span class="badge <?= e($m['color']) ?>"><?= fa_num(count($list)) ?> مورد</span>
        <?= $isToday ? '<span class="badge green">امروز</span>' : '<span class="badge gray">روز انتخابی</span>' ?>
        <span class="chev">▾</span>
      </div>
      <div class="body">
        <form method="get" class="day-nav" data-kind="<?= e($kind) ?>">
          <input type="hidden" name="tab" value="<?= e($kind) ?>">
          <?php if (req('by') !== null): ?><input type="hidden" name="by" value="<?= e(req('by')) ?>"><?php endif; ?>
          <button type="button" class="dn-btn" data-nav="-1" title="روز قبل">›</button>
          <div style="flex:1;min-width:150px">
            <input type="text" class="hs-date dayInput" name="jdate" value="<?= e($viewDate) ?>" placeholder="انتخاب روز از تقویم شمسی">
          </div>
          <button type="button" class="dn-btn" data-nav="1" title="روز بعد">‹</button>
          <button type="submit" class="btn btn-sm btn-primary">نمایش این روز</button>
          <button type="button" class="btn btn-sm btn-ghost" data-today="1"
                  title="اگر امروز تعطیل باشد، آخرین روز کاری نمایش داده می‌شود"><?= hs_is_off_day(jalali_today()) ? hs_icon('calendar', 17) . ' آخرین روز کاری (' . jalali_pretty($workDay) . ')' : 'امروز' ?></button>
        </form>
        <div class="between mt10">
          <span class="day-badge"><?= hs_icon('calendar', 17) ?> <?= jalali_full($viewDate) ?></span>
          <?php if (hs_is_off_day($viewDate)): ?>
            <span class="badge amber"><?= hs_icon('info', 17) ?> روز تعطیل (پنجشنبه/جمعه) — در گزارش‌ها شمرده نمی‌شود</span>
          <?php endif; ?>
          <span class="small muted">آمار روز: <?= fa_num(count($list)) ?> مورد ثبت‌شده — <?= e(creator_scope_label()) ?></span>
        </div>

        <div class="rec-list mt10">
          <?php if (!$list): ?>
            <div class="empty" style="padding:26px"><div class="e-ic"><?= hs_icon('clipboard', 26) ?></div><?= e($m['empty']) ?></div>
          <?php endif; ?>
          <?php foreach ($list as $r):
              $fullName = $r['first_name'] . ' ' . $r['last_name'];
              $info = $fullName . ' — پایه ' . $r['grade'] . ' — ' . $r['field'];
          ?>
            <div class="rec <?= e($m['cls']) ?>">
              <span class="r-av"><?= hs_icon($m['icon'], 18) ?></span>
              <span class="r-main">
                <b><?= e($fullName) ?></b>
                <span class="meta">
                  <?php if ($kind === 'delay'): ?>
                    <?= hs_icon('clock', 17) ?> <?= e(hs_bell_label($r['time'])) ?><?= $r['description'] ? ' — ' . e($r['description']) : '' ?>
                  <?php elseif ($kind === 'absence'): ?>
                    روز هفته: <?= e($r['day_of_week']) ?><?= $r['description'] ? ' — دلیل: ' . e($r['description']) : '' ?><?php
                      $abExtra = hs_absence_extra_label($r['lesson'] ?? null, $r['time'] ?? null);
                      echo $abExtra !== '' ? ' — ' . e($abExtra) : ''; ?>
                  <?php elseif ($kind === 'discipline'): ?>
                    نوع: <?= e($r['type']) ?><?= (int)$r['deduction'] > 0 ? ' — کسر ' . fa_num((int)$r['deduction']) : '' ?>
                    <?= $r['description'] ? '<br>' . hs_icon('clipboard', 17) . ' ' . e(mb_substr((string)$r['description'], 0, 90, 'UTF-8')) : '' ?>
                  <?php else: ?>
                    نوع تخلف: <?= e($r['type']) ?><?= $r['description'] ? ' — ' . e($r['description']) : '' ?>
                  <?php endif; ?>
                  <br><span style="opacity:.85">پایه <?= e($r['grade']) ?> — <?= e($r['field']) ?>
                    <?php
                      /* نام نقش فقط اگر در نام کاربر تکرار نشده باشد نمایش داده می‌شود */
                      $byRole = ($r['by_role'] ?? '') !== '' ? role_label((string)$r['by_role']) : '';
                      $byTxt  = $r['by_name'] ?: 'کاربر حذف‌شده';
                      if ($byRole !== '' && $r['by_name'] && mb_strpos((string)$r['by_name'], $byRole) === false) {
                          $byTxt .= ' (' . $byRole . ')';
                      }
                    ?>
                    | ثبت توسط: <?= e($byTxt) ?></span>
                </span>
              </span>
              <span class="r-side">
                <?= jalali_pretty($r['jdate']) ?>
                <br>
                <?php if (has_perm(['delay' => 'register_delay', 'absence' => 'register_absence', 'discipline' => 'register_discipline', 'dress' => 'register_dress'][$kind])): ?>
                  <form method="post" class="delRec" style="display:inline;margin:0" data-info="<?= e($info) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_record">
                    <input type="hidden" name="kind" value="<?= e($kind) ?>">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="jdate" value="<?= e($viewDate) ?>">
                    <button class="btn btn-sm btn-ghost" type="submit" title="حذف این رکورد"><?= hs_icon('trash', 16) ?></button>
                  </form>
                <?php endif; ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php
}

$recordsCss = <<<'CSS'
/* ===== استایل اختصاصی پنل ثبت موارد ===== */
.rec-grid{display:grid;grid-template-columns:minmax(320px,1fr) minmax(360px,1.15fr);gap:18px}
@media(max-width:1000px){.rec-grid{grid-template-columns:1fr}}
.picker{position:relative}
.picker input{padding-inline-start:38px}
.picker .p-ic{position:absolute;inset-inline-start:12px;top:50%;transform:translateY(-50%);opacity:.6}
.suggest{position:absolute;top:calc(100% + 5px);inset-inline:0;background:var(--white);border:1px solid var(--gray-200);
  border-radius:13px;box-shadow:var(--shadow-lg);max-height:270px;overflow:auto;z-index:600;display:none}
.suggest.show{display:block}
.suggest .sg{padding:10px 13px;cursor:pointer;border-bottom:1px solid var(--gray-200);display:flex;gap:10px;align-items:center;font-size:13.2px}
.suggest .sg:last-child{border-bottom:none}
.suggest .sg:hover,.suggest .sg.on{background:var(--blue-soft)}
.suggest .sg .av{width:31px;height:31px;border-radius:9px;background:var(--navy-bg);color:#fff;display:grid;place-items:center;font-weight:800;font-size:12.6px}
.suggest .sg b{display:block;color:var(--navy);font-size:13.2px}
html[data-theme="dark"] .suggest .sg b{color:#e8eefb}
.suggest .sg small{color:var(--gray-500);font-size:11.5px}
.suggest .empty-s{padding:14px;text-align:center;color:var(--gray-500);font-size:12.6px}
.picked{display:flex;align-items:center;gap:11px;background:var(--green-soft);border:1.5px solid #b7ebc9;border-radius:13px;
  padding:11px 13px;margin-top:9px;font-size:13.2px}
.picked .pk-av{width:38px;height:38px;border-radius:11px;background:var(--green);color:#fff;display:grid;place-items:center;font-weight:900}
.picked b{color:#14532d;display:block}
.picked .x{margin-inline-start:auto;background:none;border:none;cursor:pointer;font-size:15px;color:#14532d}
/* ===== ردیف چیپ‌های ساعت/زنگ =====
   چیدمان شبکه‌ای با عرض یکسان تا چیپ‌ها جابه‌جا و نامرتب نشوند؛
   همه رنگ‌ها از متغیرهای تم می‌آیند تا در حالت شب هم خوانا بمانند. */
.bell-chips,.time-chips{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
  gap:7px;margin-top:7px}
.bell-chips .hint,.time-chips .hint{grid-column:1/-1;margin:0}
.bell-chips button,.time-chips button{display:inline-flex;align-items:center;justify-content:center;gap:6px;
  border:1px solid var(--gray-200);background:var(--white);border-radius:10px;padding:7px 10px;
  font-family:inherit;font-size:12.3px;font-weight:700;cursor:pointer;color:var(--gray-700);
  white-space:nowrap;transition:var(--transition)}
.bell-chips button .hs-i,.time-chips button .hs-i{color:var(--amber);flex:0 0 auto}
.bell-chips button:hover,.time-chips button:hover{border-color:var(--amber);background:var(--amber-soft);color:var(--amber-ink)}
.bell-chips button:hover .hs-i,.time-chips button:hover .hs-i{color:var(--amber-ink)}
/* چیپ انتخاب‌شده: مشخص می‌کند ساعت روی کدام زنگ تنظیم است */
.bell-chips button.on,.time-chips button.on{border-color:var(--amber);background:var(--amber-soft);color:var(--amber-ink);
  box-shadow:inset 0 0 0 1px var(--amber-line)}
.bell-chips button.on .hs-i,.time-chips button.on .hs-i{color:var(--amber-ink)}
/* چیپ «پاک کردن»: خط‌چین و کم‌رنگ تا با زنگ‌ها اشتباه نشود */
.bell-chips button.clear,.time-chips button.clear{background:transparent;border-style:dashed;color:var(--gray-500)}
.bell-chips button.clear .hs-i,.time-chips button.clear .hs-i{color:currentColor}
.bell-chips button.clear:hover,.time-chips button.clear:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.bell-chips button.clear:hover .hs-i,.time-chips button.clear:hover .hs-i{color:var(--red)}
.type-chips{display:flex;gap:7px;flex-wrap:wrap}
.type-chips label{display:inline-flex;align-items:center;gap:7px;border:1.6px solid var(--gray-200);border-radius:12px;
  padding:9px 13px;cursor:pointer;font-size:13px;font-weight:700;transition:var(--transition);background:var(--white)}
.type-chips label:hover{border-color:var(--blue)}
.type-chips input{accent-color:var(--blue)}
.type-chips label:has(input:checked){border-color:var(--blue);background:var(--blue-soft);color:#1d4ed8}
.rec-list{display:grid;gap:9px}
.rec{display:flex;align-items:center;gap:12px;padding:11px 13px;border:1px solid var(--gray-200);border-radius:13px;
  background:var(--white);transition:var(--transition)}
.rec:hover{border-color:var(--blue);box-shadow:var(--shadow)}
.rec .r-av{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;font-weight:800;flex:0 0 auto;color:#fff;font-size:13.5px}
.rec.delay .r-av{background:linear-gradient(135deg,#f59e0b,#fbbf24)}
.rec.absence .r-av{background:linear-gradient(135deg,#dc2626,#f87171)}
.rec.discipline .r-av{background:linear-gradient(135deg,#7c3aed,#a78bfa)}
.rec.dress .r-av{background:linear-gradient(135deg,#2f6fed,#60a5fa)}
.rec .r-main{flex:1;min-width:0}
.rec .r-main b{color:var(--navy);font-size:13.4px}
html[data-theme="dark"] .rec .r-main b{color:#e8eefb}
.rec .r-main .meta{font-size:11.8px;color:var(--gray-500)}
.rec .r-side{text-align:left;white-space:nowrap;font-size:11.8px;color:var(--gray-500)}
.collapse{border:1px solid var(--gray-200);border-radius:var(--radius);background:var(--white);margin-bottom:14px;overflow:hidden}
.collapse .head{display:flex;align-items:center;gap:10px;padding:13px 16px;cursor:pointer;background:var(--gray-50);
  border-bottom:1px solid var(--gray-200);font-weight:800;color:var(--navy);font-size:14px}
html[data-theme="dark"] .collapse .head{color:#e8eefb}
.collapse .head .chev{margin-inline-start:auto;transition:var(--transition);font-size:12px;color:var(--gray-500)}
.collapse .body{padding:14px 16px}
.collapse.min .body{display:none}
.collapse.min .head{border-bottom:none}
.collapse.min .head .chev{transform:rotate(-90deg)}
/* انتخاب گروهی دانش‌آموزان در تب ثبت گروهی */
.bulk-pick-head{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:14px 0 9px}
.so-badge{background:var(--navy-bg);color:#fff;border-radius:999px;padding:7px 15px;font-size:12.6px;font-weight:700}
.so-badge.on{background:var(--green)}
.bulk-pick{max-height:340px;overflow:auto;border:1.5px solid var(--gray-200);border-radius:14px;padding:8px;
  display:grid;grid-template-columns:repeat(auto-fill,minmax(248px,1fr));gap:6px;background:var(--gray-50)}
.bp-item{display:flex;align-items:center;gap:7px;background:var(--white);border:1px solid var(--gray-200);
  border-radius:11px;padding:7px 10px;cursor:pointer;font-size:12.8px;transition:var(--transition)}
.bp-item:hover{border-color:var(--blue);background:var(--blue-soft)}
.bp-item input{width:16px;height:16px;accent-color:var(--blue);cursor:pointer}
.bp-item.on{border-color:var(--blue);background:#eef5ff;box-shadow:0 0 0 2px rgba(37,99,235,.12)}
.bp-name{flex:1;font-weight:600;color:var(--navy)}
.day-nav{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.day-nav .dn-btn{width:38px;height:38px;border-radius:11px;border:1px solid var(--gray-200);background:var(--white);
  cursor:pointer;font-size:16px;color:var(--navy);display:grid;place-items:center}
.day-nav .dn-btn:hover{border-color:var(--blue);color:var(--blue)}
.day-badge{background:var(--navy-bg);color:#fff;border-radius:12px;padding:8px 15px;font-weight:700;font-size:13px}
.kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:14px}
.kpi .k{background:var(--gray-50);border:1px solid var(--gray-200);border-radius:12px;padding:10px 12px;text-align:center}
.kpi .k b{display:block;font-size:20px;color:var(--navy)}
html[data-theme="dark"] .kpi .k b{color:#e8eefb}
.kpi .k span{font-size:11.6px;color:var(--gray-500)}
CSS;

layout_head('ثبت تأخیر، غیبت، انضباط و پوشش', 'records', [$recordsCss]);
?>

<?php foreach ($errors as $err): ?>
  <div class="alert err"><span><?= hs_icon('ban', 17) ?></span><span><?= e($err) ?></span></div>
<?php endforeach; ?>

<!-- ================= کپی‌برداری برای جاوااسکریپت ================= -->
<script>
window.REC = {
  now: <?= json_encode(['jdate' => $now['jdate'], 'time' => $now['time']], JSON_UNESCAPED_UNICODE) ?>,
  today: <?= json_encode($todayJ, JSON_UNESCAPED_UNICODE) ?>,
  csrf: <?= json_encode(csrf_token(), JSON_UNESCAPED_UNICODE) ?>,
  preStudent: <?= json_encode($preStudent ? [
      'id' => (int)$preStudent['id'],
      'name' => $preStudent['first_name'] . ' ' . $preStudent['last_name'],
      'meta' => 'پایه ' . $preStudent['grade'] . ' — رشته ' . $preStudent['field'],
  ] : null, JSON_UNESCAPED_UNICODE) ?>,
  totalStudents: <?= (int)$studentTotal ?>,
  offNames: <?= json_encode(hs_off_weekday_names(), JSON_UNESCAPED_UNICODE) ?>,
  allowOffday: <?= hs_allow_offday_records() ? 'true' : 'false' ?>,
  viewDate: <?= json_encode($viewDate, JSON_UNESCAPED_UNICODE) ?>
};
</script>

<?php if (hs_is_off_day($todayJ)): ?>
  <div class="alert info"><span><?= hs_icon('info', 17) ?></span><span>
    امروز <?= jalali_full($todayJ) ?> روز تعطیل است؛ تاریخ پیش‌فرض فرم‌ها روی آخرین روز کاری
    (<b><?= jalali_full($workDay) ?></b>) تنظیم شده است. روزهای تعطیل در آمار و گزارش‌ها شمرده نمی‌شوند.
  </span></div>
<?php endif; ?>

<!-- نوار دامنه سوابق (ثبت‌کننده) -->
<div class="scope-bar no-print">
  <span class="who-chip <?= $viewAllR ? '' : 'mine' ?>">
    <?= $viewAllR ? hs_icon('eye', 17) . ' دامنه نمایش: ' . e($scopeLabel) : hs_icon('user', 17) . ' دامنه نمایش: فقط سوابق ثبت‌شده توسط شما' ?>
  </span>
  <?php if ($viewAllR): ?>
    <a class="sb-item <?= (req('by') === '' || req('by') === 'all') ? 'on' : '' ?>" href="<?= e(url_with(['by' => null, 'tab' => $tab, 'jdate' => $viewDate])) ?>">همه ثبت‌کنندگان</a>
    <a class="sb-item <?= req('by') === 'me' ? 'on' : '' ?>" href="<?= e(url_with(['by' => 'me', 'tab' => $tab, 'jdate' => $viewDate])) ?>">فقط سوابق خودم</a>
    <?php foreach (creator_options() as $c): ?>
      <a class="sb-item <?= req('by') === (string)$c['id'] ? 'on' : '' ?>"
         href="<?= e(url_with(['by' => (string)$c['id'], 'tab' => $tab, 'jdate' => $viewDate])) ?>">
        <?= e($c['full_name']) ?> <span class="small muted">(<?= e(role_label($c['role'])) ?>)</span>
      </a>
    <?php endforeach; ?>
  <?php else: ?>
    <span class="small muted">شما فقط سوابق ثبت‌شده توسط خودتان را می‌بینید؛ مشاهده سوابق همه کاربران مخصوص مدیر کل است.</span>
  <?php endif; ?>
  <span style="flex:1"></span>
  <a class="btn btn-sm btn-ghost" href="index.php"><?= hs_icon('home', 16) ?> منوی اصلی</a>
</div>

<!-- ================= کارت‌های آماری امروز ================= -->
<div class="grid stats mb10">
  <div class="stat amber"><div class="icon"><?= hs_icon('clock', 22) ?></div><div><div class="v"><?= fa_num($statsToday['delay']) ?></div><div class="l">تأخیرهای <?= jalali_pretty($workDay) ?></div></div></div>
  <div class="stat red"><div class="icon"><?= hs_icon('ban', 22) ?></div><div><div class="v"><?= fa_num($statsToday['absence']) ?></div><div class="l">غیبت‌های <?= jalali_pretty($workDay) ?></div></div></div>
  <div class="stat purple"><div class="icon"><?= hs_icon('scales', 22) ?></div><div><div class="v"><?= fa_num($statsToday['discipline']) ?></div><div class="l">موارد انضباطی <?= jalali_pretty($workDay) ?></div></div></div>
  <div class="stat blue"><div class="icon"><?= hs_icon('shirt', 22) ?></div><div><div class="v"><?= fa_num($statsToday['dress']) ?></div><div class="l">تخلفات پوششی <?= jalali_pretty($workDay) ?></div></div></div>
</div>

<?php if ($studentTotal === 0): ?>
  <div class="alert warn"><span><?= hs_icon('alert', 17) ?></span><span>
    هنوز هیچ دانش‌آموزی در سامانه ثبت نشده است. برای ثبت تأخیر/غیبت ابتدا هنرجویان را وارد کنید.
    <?php if (has_perm('add_student')): ?><a href="students.php?tab=import">ورود گروهی از فایل ←</a><?php endif; ?>
  </span></div>
<?php endif; ?>

<!-- ================= تب‌ها ================= -->
<div class="card">
  <div class="tabs">
    <?php if (has_perm('register_delay')): ?>
      <button class="<?= $tab === 'delay' ? 'active' : '' ?>" onclick="goRecTab('delay')"><?= hs_icon('clock', 17) ?> ثبت تأخیر</button>
    <?php endif; ?>
    <?php if (has_perm('register_absence')): ?>
      <button class="<?= $tab === 'absence' ? 'active' : '' ?>" onclick="goRecTab('absence')"><?= hs_icon('ban', 17) ?> ثبت غیبت</button>
    <?php endif; ?>
    <?php if (has_perm('register_discipline')): ?>
      <button class="<?= $tab === 'discipline' ? 'active' : '' ?>" onclick="goRecTab('discipline')"><?= hs_icon('scales', 17) ?> مورد انضباطی</button>
    <?php endif; ?>
    <?php if (has_perm('register_dress')): ?>
      <button class="<?= $tab === 'dress' ? 'active' : '' ?>" onclick="goRecTab('dress')"><?= hs_icon('shirt', 17) ?> تخلف پوششی</button>
    <?php endif; ?>
    <?php if (has_perm('view_students') && (has_perm('register_delay') || has_perm('register_absence') || has_perm('register_discipline') || has_perm('register_dress'))): ?>
      <button class="<?= $tab === 'bulk' ? 'active' : '' ?>" onclick="goRecTab('bulk')"><?= hs_icon('users', 17) ?> ثبت گروهی</button>
    <?php endif; ?>
    <span class="tag" style="margin-inline-start:auto"><?= hs_icon('clock', 17) ?> الان: <?= jalali_pretty($now['jdate']) ?> — <?= fa_num($now['time']) ?>
      <span class="badge <?= str_starts_with($now['source'], 'api') ? 'green' : 'gray' ?>" style="font-size:10px">
        <?= str_starts_with($now['source'], 'api') ? 'API' : 'محلی' ?></span></span>
  </div>

  <!-- ==================================================================
       تب ۱: ثبت تأخیر
  =================================================================== -->
  <?php if (has_perm('register_delay')): ?>
  <div id="pane-delay" class="tabpane <?= $tab === 'delay' ? '' : 'hidden' ?>">
    <div class="rec-grid">
      <!-- فرم ثبت -->
      <div>
        <div class="collapse" id="formBoxDelay">
          <div class="head"><?= hs_icon('clipboard', 17) ?> ثبت تأخیر جدید <span class="chev">▾</span></div>
          <div class="body">
            <form method="post" id="formDelay" autocomplete="off" data-loading="در حال ثبت تأخیر…">
              <?= csrf_field() ?><input type="hidden" name="action" value="add_delay">
              <input type="hidden" name="student_id" id="delayStudentId" value="">

              <label class="fl">دانش‌آموز <span class="req">*</span></label>
              <div class="picker" data-picker="delay">
                <span class="p-ic"><?= hs_icon('search', 20) ?></span>
                <input type="text" class="pickerInput" placeholder="جستجو بر اساس نام یا نام خانوادگی…" autocomplete="off">
                <div class="suggest"></div>
              </div>
              <div class="pickedWrap"></div>

              <div class="grid g2 mt10">
                <div>
                  <label class="fl">تاریخ (شمسی) <span class="req">*</span></label>
                  <input type="text" class="hs-date" name="jdate" id="delayDate" value="<?= e($viewDate) ?>" required data-offcheck="1">
                  <div class="offday-note" data-offnote="delayDate"></div>
                  <span class="hint">تاریخ پیش‌فرض امروز است؛ روز هفته خودکار محاسبه می‌شود.</span>
                </div>
                <div>
                  <label class="fl">ساعت ورود <span class="req">*</span></label>
                  <input type="text" name="time" id="delayTime" value="<?= e($now['time']) ?>" required dir="ltr" maxlength="5">
                  <div class="time-chips">
                    <span class="hint"><?= hs_icon('bell', 17) ?> زنگ‌ها (دانش‌آموز می‌تواند در چند زنگ یک روز تأخیر داشته باشد):</span>
                    <?php foreach (hs_bells() as $bell): ?>
                      <button type="button" class="bell-chip" data-time="<?= e($bell['time']) ?>"
                              title="ساعت <?= e($bell['time']) ?>"><?= hs_icon('bell', 17) ?> <?= e($bell['name']) ?> — <?= fa_num($bell['time']) ?></button>
                    <?php endforeach; ?>
                    <button type="button" data-time="<?= e($now['time']) ?>"><?= hs_icon('clock', 17) ?> الان (<?= fa_num($now['time']) ?>)</button>
                  </div>
                  <div class="hint" id="delayBellInfo" style="margin-top:6px"></div>
                </div>
              </div>

              <label class="fl mt10">توضیحات (اختیاری)</label>
              <input type="text" name="description" maxlength="255" placeholder="مثال: تأخیر ۱۵ دقیقه — ترافیک">

              <div class="flex mt20">
                <button class="btn btn-amber" type="submit"><?= hs_icon('clock', 16) ?> ثبت تأخیر</button>
                <span class="hint">چند تأخیر در یک روز مجاز است (مثلاً زنگ اول و زنگ سوم)؛ فقط ثبتِ دقیقاً تکراری با همان ساعت/زنگ رد می‌شود.</span>
              </div>
            </form>
          </div>
        </div>
        <div class="alert info"><span><?= hs_icon('info', 17) ?></span><span>تأخیرها در گزارش PDF با حرف «<b>ت</b>» در ستون همان روز مشخص می‌شوند.</span></div>
      </div>

      <!-- فهرست -->
      <div>
        <?php render_records_list('delay', $delays, $viewDate, $isToday, $workDay); ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ==================================================================
       تب ۲: ثبت غیبت
  =================================================================== -->
  <?php if (has_perm('register_absence')): ?>
  <div id="pane-absence" class="tabpane <?= $tab === 'absence' ? '' : 'hidden' ?>">
    <div class="rec-grid">
      <div>
        <div class="collapse" id="formBoxAbs">
          <div class="head"><?= hs_icon('clipboard', 17) ?> ثبت غیبت جدید <span class="chev">▾</span></div>
          <div class="body">
            <form method="post" id="formAbsence" autocomplete="off" data-loading="در حال ثبت غیبت…">
              <?= csrf_field() ?><input type="hidden" name="action" value="add_absence">
              <input type="hidden" name="student_id" id="absStudentId" value="">

              <label class="fl">دانش‌آموز <span class="req">*</span></label>
              <div class="picker" data-picker="absence">
                <span class="p-ic"><?= hs_icon('search', 20) ?></span>
                <input type="text" class="pickerInput" placeholder="جستجو بر اساس نام یا نام خانوادگی…" autocomplete="off">
                <div class="suggest"></div>
              </div>
              <div class="pickedWrap"></div>

              <div class="grid g2 mt10">
                <div>
                  <label class="fl">تاریخ غیبت (شمسی) <span class="req">*</span></label>
                  <input type="text" class="hs-date" name="jdate" id="absDate" value="<?= e($viewDate) ?>" required data-offcheck="1">
                  <div class="offday-note" data-offnote="absDate"></div>
                  <span class="hint">روز هفته به‌طور خودکار از تاریخ محاسبه و ذخیره می‌شود.</span>
                </div>
                <div>
                  <label class="fl">روز هفته (خودکار)</label>
                  <input type="text" id="absDow" value="<?= e(jalali_weekday_name($viewDate)) ?>" disabled>
                  <span class="hint">غیرقابل ویرایش — بر اساس تقویم شمسی محاسبه می‌شود.</span>
                </div>
              </div>

              <div class="grid g2 mt10">
                <div>
                  <label class="fl">دلیل غیبت (اختیاری)</label>
                  <input type="text" name="description" maxlength="255" placeholder="مثال: بیماری — با اطلاع والدین">
                </div>
                <div>
                  <label class="fl">کلاس / درس (اختیاری)</label>
                  <input type="text" name="lesson" maxlength="120" placeholder="مثال: ریاضی — کلاس ۱۰۲">
                </div>
              </div>

              <label class="fl mt10">زنگ (اختیاری)</label>
              <input type="text" name="time" id="absTime" value="" placeholder="مثال: 09:45">
              <div class="bell-chips" id="absBells">
                <?php foreach (hs_bells() as $b): ?>
                  <button type="button" class="bell-chip" data-target="absTime" data-time="<?= e($b['time']) ?>">
                    <?= hs_icon('bell', 17) ?> <?= e($b['name']) ?> — <?= e(fa_num($b['time'])) ?>
                  </button>
                <?php endforeach; ?>
                <button type="button" class="bell-chip clear" data-target="absTime" data-time=""><?= hs_icon('close', 16) ?> پاک کردن</button>
              </div>
              <div class="bell-info empty" id="absBellInfo">اگر غیبت مربوط به زنگ خاصی است، انتخاب کنید (اختیاری).</div>

              <div class="flex mt20">
                <button class="btn btn-red" type="submit"><?= hs_icon('ban', 16) ?> ثبت غیبت</button>
                <span class="hint">غیبت‌ها در گزارش PDF با حرف «<b>غ</b>» علامت‌گذاری می‌شوند.</span>
              </div>
            </form>
          </div>
        </div>
        <div class="alert info"><span><?= hs_icon('info', 17) ?></span><span>می‌توانید با دکمه‌های بالا/پایین روز، غیبت‌های هر روز دیگر را ببینید.</span></div>
      </div>
      <div>
        <?php render_records_list('absence', $absences, $viewDate, $isToday, $workDay); ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ==================================================================
       تب ۳: موارد انضباطی
  =================================================================== -->
  <?php if (has_perm('register_discipline')): ?>
  <div id="pane-discipline" class="tabpane <?= $tab === 'discipline' ? '' : 'hidden' ?>">
    <div class="rec-grid">
      <div>
        <div class="collapse" id="formBoxDisc">
          <div class="head"><?= hs_icon('clipboard', 17) ?> ثبت مورد انضباطی جدید <span class="chev">▾</span></div>
          <div class="body">
            <form method="post" id="formDisc" autocomplete="off" data-loading="در حال ثبت مورد انضباطی…">
              <?= csrf_field() ?><input type="hidden" name="action" value="add_discipline">
              <input type="hidden" name="student_id" id="discStudentId" value="">

              <label class="fl">دانش‌آموز <span class="req">*</span></label>
              <div class="picker" data-picker="discipline">
                <span class="p-ic"><?= hs_icon('search', 20) ?></span>
                <input type="text" class="pickerInput" placeholder="جستجو بر اساس نام یا نام خانوادگی…" autocomplete="off">
                <div class="suggest"></div>
              </div>
              <div class="pickedWrap"></div>

              <div class="grid g2 mt10">
                <div>
                  <label class="fl">تاریخ (شمسی) <span class="req">*</span></label>
                  <input type="text" class="hs-date" name="jdate" id="discJdate" value="<?= e($viewDate) ?>" required data-offcheck="1">
                  <div class="offday-note" data-offnote="discJdate"></div>
                </div>
                <div>
                  <label class="fl">مقدار کسر <span class="hint">(۰ تا ۲۰ — اختیاری)</span></label>
                  <input type="number" name="deduction" min="0" max="20" value="0" dir="ltr">
                </div>
              </div>

              <label class="fl mt10">نوع تخلف <span class="req">*</span></label>
              <select name="type" id="discType" required>
                <option value="">— انتخاب نوع مورد انضباطی —</option>
                <?php foreach (HS_DISCIPLINE_TYPES as $t): ?>
                  <option value="<?= e($t) ?>"><?= e($t) ?></option>
                <?php endforeach; ?>
                <option value="__custom__"><?= hs_icon('plus', 17) ?> سایر (وارد کردن دستی)</option>
              </select>
              <input type="text" id="discTypeCustom" class="hidden mt10" maxlength="60" placeholder="نوع مورد انضباطی را وارد کنید…">

              <label class="fl mt10">توضیحات <span class="req">*</span></label>
              <textarea name="description" rows="3" maxlength="500" required
                        placeholder="شرح دقیق ماجرا، ساعت و محل وقوع، اقدام انجام‌شده…"></textarea>

              <div class="flex mt20">
                <button class="btn" style="background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#fff" type="submit"><?= hs_icon('scales', 16) ?> ثبت مورد انضباطی</button>
              </div>
            </form>
          </div>
        </div>

        <div class="collapse min" id="recentDisc">
          <div class="head"><?= hs_icon('clock', 17) ?> آخرین موارد انضباطی ثبت‌شده <span class="chev">▾</span></div>
          <div class="body">
            <div class="rec-list">
              <?php foreach ($recentDiscipline as $r): ?>
                <div class="rec discipline">
                  <span class="r-av"><?= hs_icon('scales', 18) ?></span>
                  <span class="r-main"><b><?= e($r['first_name'] . ' ' . $r['last_name']) ?></b>
                    <span class="meta"><?= e($r['type']) ?></span></span>
                  <span class="r-side"><?= jalali_pretty($r['jdate']) ?></span>
                </div>
              <?php endforeach; ?>
              <?php if (!$recentDiscipline): ?><div class="empty" style="padding:16px">موردی ثبت نشده است.</div><?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <div>
        <?php render_records_list('discipline', $disciplines, $viewDate, $isToday, $workDay); ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ==================================================================
       تب ۴: تخلف پوششی
  =================================================================== -->
  <?php if (has_perm('register_dress')): ?>
  <div id="pane-dress" class="tabpane <?= $tab === 'dress' ? '' : 'hidden' ?>">
    <div class="rec-grid">
      <div>
        <div class="collapse" id="formBoxDress">
          <div class="head"><?= hs_icon('clipboard', 17) ?> ثبت تخلف پوششی جدید <span class="chev">▾</span></div>
          <div class="body">
            <form method="post" id="formDress" autocomplete="off" data-loading="در حال ثبت تخلف پوششی…">
              <?= csrf_field() ?><input type="hidden" name="action" value="add_dress">
              <input type="hidden" name="student_id" id="dressStudentId" value="">

              <label class="fl">دانش‌آموز <span class="req">*</span></label>
              <div class="picker" data-picker="dress">
                <span class="p-ic"><?= hs_icon('search', 20) ?></span>
                <input type="text" class="pickerInput" placeholder="جستجو بر اساس نام یا نام خانوادگی…" autocomplete="off">
                <div class="suggest"></div>
              </div>
              <div class="pickedWrap"></div>

              <label class="fl mt10">تاریخ (شمسی) <span class="req">*</span></label>
              <input type="text" class="hs-date" name="jdate" id="dressJdate" value="<?= e($viewDate) ?>" required data-offcheck="1">
              <div class="offday-note" data-offnote="dressJdate"></div>

              <label class="fl mt10">نوع تخلف پوششی <span class="req">*</span></label>
              <div class="type-chips">
                <?php foreach (HS_DRESS_TYPES as $i => $t): ?>
                  <label><input type="radio" name="type" value="<?= e($t) ?>" <?= $i === 2 ? 'checked' : '' ?>> <?= e($t) ?></label>
                <?php endforeach; ?>
              </div>
              <span class="hint">اگر تخلف شامل هر دو مورد (لباس و شلوار) بود، گزینه «هر دو» را انتخاب کنید.</span>

              <label class="fl mt10">توضیحات (اختیاری)</label>
              <input type="text" name="description" maxlength="255" placeholder="مثال: مانتو غیرفرم / شلوار جین">

              <div class="flex mt20">
                <button class="btn btn-blue" type="submit"><?= hs_icon('shirt', 16) ?> ثبت تخلف پوششی</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <div>
        <?php render_records_list('dress', $dresses, $viewDate, $isToday, $workDay); ?>
      </div>
    </div>

  </div>
  <?php endif; ?>

  <!-- ==================================================================
       تب ۵: ثبت گروهی برای چند دانش‌آموز
       (فقط کاربرانی که «مشاهده دانش‌آموزان» دارند؛ معلم‌ها از «پنل معلم» کلاس خود را ثبت می‌کنند)
  =================================================================== -->
  <?php if (has_perm('view_students')): ?>
  <div id="pane-bulk" class="tabpane <?= $tab === 'bulk' ? '' : 'hidden' ?>">
    <div class="alert info"><span><?= hs_icon('users', 17) ?></span><span>
      ابتدا <b>نوع مورد</b>، <b>تاریخ</b> و <b>ساعت/نوع</b> را مشخص کنید، سپس دانش‌آموزان را تیک بزنید و «ثبت گروهی» را بزنید.
      مواردی که قبلاً در همان روز ثبت شده‌اند <b>به‌صورت خودکار رد می‌شوند</b> و در پایان گزارش داده می‌شوند.
      برای انتخاب سریع یک کلاس، از فیلتر پایه/رشته و دکمهٔ «انتخاب همهٔ نتایج فیلتر» استفاده کنید.
      <br><?= hs_icon('bell', 17) ?> <b>چند زنگ در یک روز:</b> یک‌بار زنگ اول را ثبت کنید، سپس زنگ را عوض کنید و دوباره «ثبت گروهی» را بزنید؛
      همان گروه انتخاب‌شده باقی می‌ماند و فقط موارد تکراری همان زنگ رد می‌شوند.
    </span></div>

    <form method="post" id="bulkRecForm" data-loading="در حال ثبت گروهی…">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_bulk">

      <div class="grid g4">
        <div>
          <label class="fl">نوع مورد <span class="req">*</span></label>
          <select name="kind" id="bulkKind">
            <?php if (has_perm('register_delay')): ?><option value="delay" <?= $bulkKind === 'delay' ? 'selected' : '' ?>><?= hs_icon('clock', 17) ?> تأخیر</option><?php endif; ?>
            <?php if (has_perm('register_absence')): ?><option value="absence" <?= $bulkKind === 'absence' ? 'selected' : '' ?>><?= hs_icon('ban', 17) ?> غیبت</option><?php endif; ?>
            <?php if (has_perm('register_discipline')): ?><option value="discipline" <?= $bulkKind === 'discipline' ? 'selected' : '' ?>><?= hs_icon('scales', 17) ?> مورد انضباطی</option><?php endif; ?>
            <?php if (has_perm('register_dress')): ?><option value="dress" <?= $bulkKind === 'dress' ? 'selected' : '' ?>><?= hs_icon('shirt', 17) ?> تخلف پوششی</option><?php endif; ?>
          </select>
        </div>
        <div>
          <label class="fl">تاریخ (شمسی) <span class="req">*</span></label>
          <input type="text" class="hs-date" name="jdate" id="bulkJdate" value="<?= e($workDay) ?>" required data-offcheck="1">
          <div class="offday-note" data-offnote="bulkJdate"></div>
        </div>
        <div id="bulkTimeBox">
          <label class="fl">ساعت / زنگ ورود <span class="req">*</span></label>
          <input type="text" name="time" id="bulkTime" value="<?= e($now['time']) ?>" placeholder="08:30">
          <div class="time-chips">
            <?php foreach (hs_bells() as $bell): ?>
              <button type="button" class="bell-chip" data-target="bulkTime"
                      data-time="<?= e($bell['time']) ?>"><?= hs_icon('bell', 17) ?> <?= e($bell['name']) ?> — <?= fa_num($bell['time']) ?></button>
            <?php endforeach; ?>
          </div>
          <span class="hint">برای هر زنگ، یک‌بار «ثبت گروهی» بزنید؛ دانش‌آموزان در بازگشت انتخاب‌شده می‌مانند.</span>
        </div>
        <div id="bulkDiscBox" class="hidden">
          <label class="fl">نوع مورد انضباطی <span class="req">*</span></label>
          <select name="discipline_type" id="bulkTypeDisc" disabled>
            <?php foreach (HS_DISCIPLINE_TYPES as $t): ?><option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div id="bulkDressBox" class="hidden">
          <label class="fl">نوع تخلف پوششی <span class="req">*</span></label>
          <select name="dress_type" id="bulkTypeDress" disabled>
            <?php foreach (HS_DRESS_TYPES as $t): ?><option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div id="bulkDedBox" class="hidden">
          <label class="fl">کسر از نمره انضباط <span class="hint">(۰ تا ۲۰)</span></label>
          <input type="number" name="deduction" id="bulkDeduction" value="0" min="0" max="20">
        </div>
        <div>
          <label class="fl">توضیحات <span class="hint">(اختیاری — برای همه)</span></label>
          <input type="text" name="description" maxlength="120" placeholder="مثال: ورود پس از زنگ اول">
        </div>
      </div>

      <hr style="border:none;border-top:1px solid var(--gray-200);margin:18px 0">

      <div class="grid g3">
        <div>
          <label class="fl">فیلتر پایه (برای انتخاب سریع)</label>
          <select id="bulkFGrade"><option value="">همهٔ پایه‌ها</option>
            <?php foreach (HS_GRADES as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="fl">فیلتر رشته</label>
          <select id="bulkFField"><option value="">همهٔ رشته‌ها</option></select>
        </div>
        <div>
          <label class="fl">جستجوی نام</label>
          <input type="search" id="bulkFQ" placeholder="نام یا نام خانوادگی…">
        </div>
      </div>

      <div class="bulk-pick-head">
        <span class="so-badge" id="bulkCount">۰ دانش‌آموز انتخاب شده</span>
        <button type="button" class="btn btn-sm btn-blue" id="bulkAllVisible"><?= hs_icon('check', 16) ?> انتخاب همهٔ نتایج فیلتر</button>
        <button type="button" class="btn btn-sm btn-ghost" id="bulkNone"><?= hs_icon('close', 16) ?> لغو انتخاب</button>
        <button type="button" class="btn btn-sm btn-ghost" id="bulkInvert"><?= hs_icon('refresh', 16) ?> معکوس</button>
        <span class="hint" style="margin:0" id="bulkVisibleHint"></span>
      </div>

      <!-- لیست دانش‌آموزان برای انتخاب گروهی -->
      <div class="bulk-pick" id="bulkList">
        <?php foreach ($bulkStudents as $bs): ?>
          <label class="bp-item" data-grade="<?= e($bs['grade']) ?>" data-field="<?= e($bs['field']) ?>"
                 data-search="<?= e($bs['first_name'] . ' ' . $bs['last_name']) ?>">
            <input type="checkbox" class="bp-cb" name="ids[]" value="<?= (int)$bs['id'] ?>"
                   <?= in_array((int)$bs['id'], $bulkIds, true) ? 'checked' : '' ?>>
            <span class="bp-name"><?= e($bs['last_name'] . '، ' . $bs['first_name']) ?></span>
            <span class="badge <?= $bs['grade'] === 'دهم' ? 'green' : ($bs['grade'] === 'یازدهم' ? 'amber' : 'purple') ?>" style="font-size:10px"><?= e($bs['grade']) ?></span>
            <span class="badge blue" style="font-size:10px"><?= e($bs['field']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <input type="hidden" name="select_all" id="bulkSelectAll" value="0">
      <div class="flex mt20">
        <button class="btn btn-primary" type="submit" id="bulkSubmit"><?= hs_icon('users', 16) ?> ثبت گروهی برای <span id="bulkSubmitN">۰</span> دانش‌آموز</button>
        <span class="hint" style="margin:0">حداکثر ۴۰۰ دانش‌آموز در هر بار. موارد تکراری همان روز نادیده گرفته می‌شوند.</span>
      </div>
    </form>
  </div>
  <?php endif; ?>

<?php
$recordsJs = <<<'JS'
/* ===== اسکریپت پنل ثبت موارد ===== */
/* نگاشت پایه → رشته‌های مجاز (از db.php تزریق می‌شود) */
const FIELDS_BY_GRADE = window.HS_FIELDS_BY_GRADE || {};
/* ---------- انتخابگر جستجوی دانش‌آموز ---------- */
function initPickers() {
  HS.$$('.picker').forEach(function (p) {
    const kind = p.dataset.picker;
    const input = HS.$('.pickerInput', p);
    const box = HS.$('.suggest', p);
    const wrap = p.parentNode.querySelector('.pickedWrap');
    const hidden = document.getElementById(kind === 'delay' ? 'delayStudentId' : kind === 'absence' ? 'absStudentId' : kind === 'discipline' ? 'discStudentId' : 'dressStudentId');
    let timer = null, idx = -1, items = [];

    function renderList(list) {
      items = list; idx = -1;
      if (!list.length) {
        box.innerHTML = '<div class="empty-s">دانش‌آموزی یافت نشد. حداقل ۲ حرف از نام یا نام خانوادگی را وارد کنید.</div>';
        box.classList.add('show');
        return;
      }
      box.innerHTML = list.map((s, i) =>
        '<div class="sg" data-i="' + i + '"><span class="av">' + s.name.trim().charAt(0) + '</span>' +
        '<span><b>' + s.name + '</b><small>' + s.meta + '</small></span></div>').join('');
      box.classList.add('show');
      HS.$$('.sg', box).forEach(el => el.addEventListener('mousedown', () => pick(items[+el.dataset.i])));
    }
    function pick(s) {
      if (!s) return;
      hidden.value = s.id;
      input.value = s.name;
      box.classList.remove('show');
      wrap.innerHTML = '<div class="picked"><div class="pk-av">' + s.name.trim().charAt(0) + '</div>' +
        '<div><b>' + s.name + '</b><span class="small muted">' + s.meta + ' — شناسه ' + HS.faNum(s.id) + '</span></div>' +
        '<button type="button" class="x" title="حذف انتخاب">' + HS.icon('close', 16) + '</button></div>';
      wrap.querySelector('.x').onclick = () => { hidden.value = ''; input.value = ''; wrap.innerHTML = ''; input.focus(); };
      HS.toast('دانش‌آموز «' + s.name + '» انتخاب شد.', 'ok', 'انتخاب شد');
    }
    input.addEventListener('input', function () {
      hidden.value = '';
      wrap.innerHTML = '';
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length < 2) { box.classList.remove('show'); return; }
      timer = setTimeout(() => {
        fetch('records.php?action=search_students&ajax=1&q=' + encodeURIComponent(q), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(r => r.json()).then(d => renderList(d.items || [])).catch(() => renderList([]));
      }, 250);
    });
    input.addEventListener('keydown', function (e) {
      const nodes = HS.$$('.sg', box);
      if (!nodes.length || !box.classList.contains('show')) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); idx = Math.min(idx + 1, nodes.length - 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); idx = Math.max(idx - 1, 0); }
      else if (e.key === 'Enter') { e.preventDefault(); if (idx > -1) pick(items[idx]); return; }
      else return;
      nodes.forEach((n, i) => n.classList.toggle('on', i === idx));
      nodes[idx].scrollIntoView({ block: 'nearest' });
    });
    input.addEventListener('blur', () => setTimeout(() => box.classList.remove('show'), 180));
  });
}

/* ---------- تب‌ها و پنل‌های قابل مینیمایز ---------- */
function goRecTab(name) {
  HS.$$('.tabpane').forEach(p => p.classList.add('hidden'));
  const el = document.getElementById('pane-' + name);
  if (el) el.classList.remove('hidden');
  const ids = ['delay', 'absence', 'discipline', 'dress', 'bulk'];
  HS.$$('.tabs button').forEach(b => b.classList.remove('active'));
  const i = ids.indexOf(name);
  const btns = HS.$$('.tabs button');
  if (i > -1 && btns[i]) btns[i].classList.add('active');
  const jd = document.querySelector('.dayInput');
  history.replaceState(null, '', '?tab=' + name + '&jdate=' + encodeURIComponent(jd ? jd.value : (window.REC ? window.REC.today : '')));
  try { localStorage.setItem('hs_records_tab', name); } catch (e) {}
}

document.addEventListener('DOMContentLoaded', function () {
  HS.$$('.collapse .head').forEach(h => h.addEventListener('click', () => h.parentNode.classList.toggle('min')));

  const R = window.REC;
  initPickers();

  /* پیش‌انتخاب دانش‌آموز اگر از صفحه فهرست آمده باشد */
  if (R && R.preStudent) {
    ['delay', 'absence', 'discipline', 'dress'].forEach(k => {
      const hid = document.getElementById(k === 'delay' ? 'delayStudentId' : k === 'absence' ? 'absStudentId' : k === 'discipline' ? 'discStudentId' : 'dressStudentId');
      if (!hid) return;
      hid.value = R.preStudent.id;
      const p = document.querySelector('[data-picker=' + k + ']');
      if (!p) return;
      const wrap = p.parentNode.querySelector('.pickedWrap');
      HS.$('.pickerInput', p).value = R.preStudent.name;
      wrap.innerHTML = '<div class="picked"><div class="pk-av">' + R.preStudent.name.trim().charAt(0) + '</div>' +
        '<div><b>' + R.preStudent.name + '</b><span class="small muted">' + R.preStudent.meta + '</span></div>' +
        '<button type="button" class="x">' + HS.icon('close', 16) + '</button></div>';
      wrap.querySelector('.x').onclick = () => { hid.value = ''; HS.$('.pickerInput', p).value = ''; wrap.innerHTML = ''; };
    });
    HS.toast('هنرجوی انتخابی «' + R.preStudent.name + '» در فرم‌های ثبت آماده است.', 'info', 'انتخاب پیش‌فرض');
  }

  /* چیپ‌های ساعت و زنگ (تأخیر / غیبت / ثبت گروهی) */
  HS.$$('.time-chips button, .bell-chip').forEach(b => b.addEventListener('click', function () {
    const target = b.dataset.target || 'delayTime';
    const inp = document.getElementById(target);
    if (!inp) return;
    inp.value = b.dataset.time || '';
    inp.classList.remove('inp-err');
    /* نمایش نام زنگ انتخاب‌شده زیر فیلد */
    const infoId = target === 'delayTime' ? 'delayBellInfo' : (target === 'absTime' ? 'absBellInfo' : null);
    const info = infoId ? document.getElementById(infoId) : null;
    /* چیپ‌ها: فقط یکی در هر ردیف حالت انتخاب‌شده دارد */
    const row = b.parentNode;
    HS.$$('button', row).forEach(function (x) { x.classList.remove('on'); });
    if ((b.dataset.time || '') !== '') { b.classList.add('on'); }
    if (info) {
      /* نکته: آیکون SVG را نمی‌توان در textContent گذاشت؛ باید innerHTML باشد */
      info.innerHTML = (b.dataset.time || '') === ''
        ? 'زنگ پاک شد (اختیاری است).'
        : HS.icon('bell', 16) + ' زنگ انتخاب‌شده: ' + HS.esc((b.textContent || '').trim());
      info.classList.toggle('empty', (b.dataset.time || '') === '');
    }
    if ((b.dataset.time || '') !== '') {
      HS.toast('ساعت روی ' + b.dataset.time + ' تنظیم شد.', 'info');
    }
  }));

  /* نوع انضباطی سفارشی */
  const dt = document.getElementById('discType');
  if (dt) {
    const custom = document.getElementById('discTypeCustom');
    dt.addEventListener('change', function () {
      const isCustom = dt.value === '__custom__';
      custom.classList.toggle('hidden', !isCustom);
      if (isCustom) { custom.focus(); } else { custom.value = ''; }
    });
    const form = document.getElementById('formDisc');
    form.addEventListener('submit', function (e) {
      if (dt.value === '__custom__') {
        if (custom.value.trim().length < 3) {
          e.preventDefault(); HS.toast('نوع مورد انضباطی سفارشی را وارد کنید.', 'err'); custom.classList.add('bad'); return;
        }
        const opt = document.createElement('option');
        opt.value = custom.value.trim(); opt.textContent = custom.value.trim();
        dt.appendChild(opt); dt.value = opt.value;
      }
    });
    /* اعتبارسنجی سمت کلاینت */
    form.addEventListener('submit', function (e) {
      if (!document.getElementById('discStudentId').value) { e.preventDefault(); HS.toast('انتخاب دانش‌آموز الزامی است.', 'err'); }
    });
  }

  /* ================== تب «ثبت گروهی» ================== */
  (function initBulkTab() {
    const form = document.getElementById('bulkRecForm');
    if (!form) return;
    const kindEl = document.getElementById('bulkKind');
    const items  = HS.$$('.bp-item', form);
    const cbs    = HS.$$('.bp-cb', form);
    const listEl = document.getElementById('bulkList');
    const fGrade = document.getElementById('bulkFGrade');
    const fField = document.getElementById('bulkFField');
    const fQ     = document.getElementById('bulkFQ');
    const countEl= document.getElementById('bulkCount');
    const subN   = document.getElementById('bulkSubmitN');

    function fillFields() {
      const g = fGrade ? fGrade.value : '';
      const list = g ? (FIELDS_BY_GRADE[g] || []) : Object.keys(FIELDS_BY_GRADE).reduce((a, k) => a.concat(FIELDS_BY_GRADE[k]), [])
        .filter((v, i, arr) => arr.indexOf(v) === i);
      const old = fField.value;
      fField.innerHTML = '<option value="">همهٔ رشته‌ها</option>' + list.map(f => '<option value="' + f + '">' + f + '</option>').join('');
      if (list.indexOf(old) > -1) fField.value = old;
    }

    function visibleItems() {
      const g = fGrade.value, f = fField.value, q = (fQ.value || '').trim();
      return items.filter(it => {
        const okG = !g || it.dataset.grade === g;
        const okF = !f || it.dataset.field === f;
        const okQ = !q || it.dataset.search.indexOf(q) > -1;
        const vis = okG && okF && okQ;
        it.classList.toggle('hidden', !vis);
        return vis;
      });
    }

    function refresh() {
      const vis = visibleItems();
      const sel = cbs.filter(c => c.checked);
      items.forEach(it => {
        const cb = it.querySelector('.bp-cb');
        it.classList.toggle('on', cb.checked);
      });
      countEl.textContent = HS.faNum(sel.length) + ' دانش‌آموز انتخاب شده';
      countEl.classList.toggle('on', sel.length > 0);
      subN.textContent = HS.faNum(sel.length);
      const hint = document.getElementById('bulkVisibleHint');
      if (hint) hint.textContent = 'نمایش ' + HS.faNum(vis.length) + ' از ' + HS.faNum(items.length) + ' دانش‌آموز';
    }

    function kindUi() {
      if (!kindEl) return;
      const k = kindEl.value;
      document.getElementById('bulkTimeBox').classList.toggle('hidden', k !== 'delay');
      document.getElementById('bulkDiscBox').classList.toggle('hidden', k !== 'discipline');
      document.getElementById('bulkDressBox').classList.toggle('hidden', k !== 'dress');
      document.getElementById('bulkDedBox').classList.toggle('hidden', k !== 'discipline');
      const dt = document.getElementById('bulkTypeDisc'), dr = document.getElementById('bulkTypeDress');
      dt.disabled = k !== 'discipline';
      dr.disabled = k !== 'dress';
      const t = document.getElementById('bulkTime');
      if (t) t.disabled = k !== 'delay';
    }

    fGrade.addEventListener('change', () => { fillFields(); refresh(); });
    fField.addEventListener('change', refresh);
    fQ.addEventListener('input', refresh);
    cbs.forEach(c => c.addEventListener('change', refresh));
    kindEl.addEventListener('change', kindUi);

    document.getElementById('bulkAllVisible').addEventListener('click', () => {
      visibleItems().forEach(it => { it.querySelector('.bp-cb').checked = true; });
      refresh();
      HS.toast('همهٔ دانش‌آموزان فیلترشده انتخاب شدند.', 'ok');
    });
    document.getElementById('bulkNone').addEventListener('click', () => {
      cbs.forEach(c => { c.checked = false; });
      refresh();
    });
    document.getElementById('bulkInvert').addEventListener('click', () => {
      visibleItems().forEach(it => { const c = it.querySelector('.bp-cb'); c.checked = !c.checked; });
      refresh();
    });

    form.addEventListener('submit', function (e) {
      if (this.dataset.confirmed) return;
      const sel = cbs.filter(c => c.checked);
      const jd  = document.getElementById('bulkJdate');
      /* اگر تاریخ تعطیل است، بررسی روز تعطیل خودش پیام می‌دهد و ارسال را سد می‌کند */
      if (jd && HS.isOffDay(jd.value) && !(window.HS_OFF && window.HS_OFF.allow)) return;
      if (!sel.length) {
        e.preventDefault();
        HS.toast('هیچ دانش‌آموزی انتخاب نشده است. با فیلتر پایه/رشته و دکمهٔ «انتخاب همهٔ نتایج فیلتر» سریع‌تر انتخاب کنید.', 'err', 'ثبت گروهی');
        return;
      }
      e.preventDefault();
      const L = { delay: 'تأخیر', absence: 'غیبت', discipline: 'مورد انضباطی', dress: 'تخلف پوششی' };
      const names = sel.slice(0, 5).map(c => (c.closest('.bp-item').dataset.search || '')).join('، ') + (sel.length > 5 ? ' و …' : '');
      HS.confirm('ثبت <b>' + (L[kindEl.value] || '') + '</b> برای <b>' + HS.faNum(sel.length) + '</b> دانش‌آموز در تاریخ <b>' + (jd ? jd.value : '') + '</b>؟'
        + '<br><span class="small">' + names + '</span>', () => {
          this.dataset.confirmed = '1';
          this.submit();
          HS.loading(true, 'در حال ثبت گروهی…');
        }, { yes: 'بله، ثبت کن', title: 'تأیید ثبت گروهی' });
    });

    fillFields();
    kindUi();
    refresh();
  })();

  /* --- بررسی روز تعطیل روی همه فیلدهای تاریخ --- */
  /* یادداشت تعطیلی مربوط به یک فیلد تاریخ را پیدا می‌کند
     (تقویم شمسی فیلد را داخل div می‌پیچد، پس فقط parent کافی نیست) */
  function offNoteFor(input) {
    let n = input.parentNode ? input.parentNode.querySelector('.offday-note') : null;
    if (n) return n;
    const scope = input.closest('form') || document;
    if (input.id) {
      n = scope.querySelector('.offday-note[data-offnote="' + input.id + '"]');
    }
    if (!n && input.parentNode && input.parentNode.parentNode) {
      n = input.parentNode.parentNode.querySelector('.offday-note');
    }
    return n;
  }

  function checkOffDay(input) {
    const val = input.value;
    if (!/^\d{4}\/\d{2}\/\d{2}$/.test(val)) return true;
    const off = HS.isOffDay(val);
    input.classList.toggle('offday', off);
    let n = offNoteFor(input);
    if (n) {
      n.classList.toggle('show', off);
      n.innerHTML = off ? HS.icon('info', 16) + ' روز ' + HS.esc(HS.jWeekday(val)) + ' (' + HS.esc(HS.faNum(val)) + ') تعطیل است؛'
        + ((window.HS_OFF && window.HS_OFF.allow)
            ? ' ثبت در این روز فقط با مجوز مدیر ممکن است — در گزارش‌ها شمرده نخواهد شد.'
            : ' ثبت مورد در این روز مجاز نیست.') : '';
    }
    return !off || (window.HS_OFF && window.HS_OFF.allow);
  }
  HS.checkOffDay = checkOffDay;

  HS.$$('[data-offcheck]').forEach(function (inp) {
    checkOffDay(inp);
    inp.addEventListener('change', function () { checkOffDay(inp); });
    inp.addEventListener('blur', function () { checkOffDay(inp); });
  });

  /* منع ارسال فرم در صورت انتخاب روز تعطیل (سرور نیز بررسی مستقل دارد) */
  HS.$$('form').forEach(function (f) {
    const offInput = f.querySelector('[data-offcheck]');
    if (!offInput) return;
    f.addEventListener('submit', function (e) {
      if (!checkOffDay(offInput)) {
        e.preventDefault();
        HS.toast('روز ' + HS.jWeekday(offInput.value) + ' تعطیل است و ثبت مورد در این روز مجاز نیست. لطفاً یک روز کاری انتخاب کنید.', 'err', 'روز تعطیل');
        offInput.focus();
      }
    });
  });

  /* اعتبارسنجی انتخاب دانش‌آموز در همه فرم‌ها */
  [['formDelay', 'delayStudentId'], ['formAbsence', 'absStudentId'], ['formDress', 'dressStudentId']].forEach(function (pair) {
    const f = document.getElementById(pair[0]);
    if (f) f.addEventListener('submit', function (e) {
      if (!document.getElementById(pair[1]).value) { e.preventDefault(); HS.toast('ابتدا دانش‌آموز را از فهرست جستجو و انتخاب کنید.', 'err'); }
    });
  });

  /* اعتبارسنجی ساعت تأخیر */
  const dtf = document.getElementById('formDelay');
  if (dtf) dtf.addEventListener('submit', function (e) {
    const t = document.getElementById('delayTime');
    t.value = HS.enNum(t.value);
    if (!/^([01]?\d|2[0-3]):([0-5]\d)$/.test(t.value)) {
      e.preventDefault(); t.classList.add('bad'); HS.toast('ساعت باید به شکل 14:30 وارد شود.', 'err');
    } else if (t.value.length === 4) { t.value = '0' + t.value; }
  });

  /* روز هفته خودکار برای غیبت */
  const absDate = document.getElementById('absDate');
  if (absDate) {
    const upd = () => { const d = document.getElementById('absDow'); if (d) d.value = HS.jWeekday(absDate.value); };
    absDate.addEventListener('change', upd); absDate.addEventListener('blur', upd);
    /* مقدار اولیه از سرور */
    upd();
  }

  /* حذف رکورد */
  HS.$$('.delRec').forEach(f => f.addEventListener('submit', function (e) {
    if (f.dataset.done) return;
    e.preventDefault();
    HS.confirm('این رکورد به‌طور کامل حذف شود؟<br><span class="small muted">' + f.dataset.info + '</span>',
      () => { f.dataset.done = '1'; HS.loading(true, 'در حال حذف رکورد…'); f.submit(); }, { yes: 'بله، حذف کن', title: 'حذف رکورد' });
  }));

  /* یادآوری آخرین تب استفاده‌شده (در نبود پارامتر tab در آدرس) */
  (function () {
    const urlTab = new URLSearchParams(location.search).get('tab');
    if (urlTab && document.getElementById('pane-' + urlTab)) return;
    let saved = null;
    try { saved = localStorage.getItem('hs_records_tab'); } catch (e) {}
    if (saved && document.getElementById('pane-' + saved)) goRecTab(saved);
  })();

  /* ناوبری روز در فهرست هر تب — روزهای تعطیل (پنجشنبه/جمعه) رد می‌شوند */
  HS.$$('.day-nav').forEach(function (f) {
    const input = HS.$('.dayInput', f);
    HS.$$('[data-nav]', f).forEach(b => b.addEventListener('click', function () {
      const n = parseInt(b.dataset.nav, 10);
      const cur = /^\d{4}\/\d{2}\/\d{2}$/.test(input.value) ? input.value : HS.workDate();
      input.value = HS.workDayStep(cur, n);      /* جهش به نزدیک‌ترین روز کاری */
      if (!f.checkValidity()) { HS.toast('تاریخ نامعتبر است.', 'err'); return; }
      HS.loading(true, 'در حال بارگذاری رکوردهای روز…');
      f.submit();
    }));
    const t = HS.$('[data-today]', f);
    if (t) t.addEventListener('click', function () {
      input.value = HS.workDate();
      if (!f.checkValidity()) { HS.toast('تاریخ نامعتبر است.', 'err'); return; }
      HS.loading(true, 'در حال بارگذاری رکوردهای روز…');
      f.submit();
    });
    input.addEventListener('change', function () {
      if (!f.checkValidity()) return;
      HS.loading(true, 'در حال بارگذاری…');
      f.submit();
    });
  });
});
JS;
layout_end([$recordsJs]);
