<?php
/**
 * ============================================================================
 *  پنل معلم — سامانه جامع مدیریت هنرستان شهید رجایی
 *  مسیر فایل: teacher.php
 * ----------------------------------------------------------------------------
 *  این صفحه، پنل اختصاصی معلم است:
 *    ۱) سه دکمهٔ پایه: پایه دهم / پایه یازدهم / پایه دوازدهم
 *    ۲) با کلیک روی پایه، رشته‌های همان پایه نمایش داده می‌شود
 *       (قانون دامنه: رشتهٔ «کامپیوتر» در پایهٔ دوازدهم وجود ندارد)
 *    ۳) با انتخاب رشته، فهرست دانش‌آموزان همان کلاس می‌آید و کنار هر دانش‌آموز
 *       دکمه‌های ثبت وجود دارد:
 *          ثبت تأخیر  (ساعت ورود به کلاس + توضیحات — چند تأخیر در یک روز)
 *          ثبت غیبت   (توضیحات را خودِ سامانه می‌نویسد؛ کلاس/درس + زنگ اختیاری — تاریخ و روز هفته خودکار)
 *          ثبت مورد انضباطی
 *          ثبت تخلف پوششی
 *          و حذف رکورد اشتباه (فقط رکوردهای خودِ ثبت‌کننده؛ مدیر کل همه را می‌تواند حذف کند)
 *
 *  قاعدهٔ دسترسی: معلم به «فهرست کامل دانش‌آموزان» (students.php) دسترسی ندارد؛
 *     فقط کلاس‌ها (پایه × رشته) را می‌بیند و برای هر کلاس، موارد را ثبت می‌کند.
 *     دسترسی این صفحه با پرمیشن «پنل معلم (کلاس‌ها)» کنترل می‌شود و هر یک از
 *     دکمه‌های ثبت، پرمیشن ثبت خودش را لازم دارد.
 *
 *  همهٔ CSS/HTML/JS داخل همین فایل است (قاعدهٔ پروژه: بدون فایل خارجی).
 * ============================================================================
 */

require_once __DIR__ . '/db.php';

require_login();
require_perm('teacher_panel');

/** یادداشت روز تعطیل در پیام‌های فلش (مثل صفحهٔ ثبت موارد) */
if (!function_exists('offday_flash_note')) {
    function offday_flash_note(string $jdate): void
    {
        if (!hs_is_off_day($jdate)) {
            return;
        }
        hs_allow_offday_records()
            ? flash_set('warn', 'این تاریخ (' . jalali_pretty($jdate) . ' — ' . jalali_weekday_name($jdate) . ') روز تعطیل هفتگی است؛ مدیر کل ثبت در روز تعطیل را مجاز کرده است.')
            : flash_set('warn', 'این تاریخ (' . jalali_pretty($jdate) . ' — ' . jalali_weekday_name($jdate) . ') روز تعطیل هفتگی است و در گزارش‌ها شمرده نمی‌شود.');
    }
}

$me       = current_admin();
$todayJ   = jalali_today();                    // امروز شمسی
$todayDow = jalali_weekday_name($todayJ);
$offToday = hs_is_off_day($todayJ);            // امروز تعطیل است؟
$workDay  = hs_default_work_date();            // آخرین روز کاری (اگر امروز تعطیل باشد)
$allowOff = hs_allow_offday_records();         // تنظیم مدیر کل: ثبت در روز تعطیل
$dayRef   = $offToday ? $workDay : $todayJ;    // روز مرجع برای نمایش «سوابق این کلاس»

/* دکمه‌های ثبت بر اساس پرمیشن‌ها */
$permMap = [
    'delay'      => has_perm('register_delay'),
    'absence'    => has_perm('register_absence'),
    'discipline' => has_perm('register_discipline'),
    'dress'      => has_perm('register_dress'),
];
$kinds = [
    'delay'      => ['label' => 'تأخیر',        'icon' => 'clock',    'btn' => 'btn-amber'],
    'absence'    => ['label' => 'غیبت',         'icon' => 'ban',      'btn' => 'btn-red'],
    'discipline' => ['label' => 'مورد انضباطی', 'icon' => 'scales',   'btn' => 'btn-purple'],
    'dress'      => ['label' => 'تخلف پوششی',   'icon' => 'shirt',    'btn' => 'btn-blue'],
];
$canRegister = false;
foreach ($permMap as $p) {
    if ($p) { $canRegister = true; }
}
/* امروز تعطیل است و اجازهٔ ثبت در تعطیل نداریم → دکمه‌ها غیرفعال */
$blockedToday = $offToday && !$allowOff;

/* ---------------------------------------------------------------------------
 * ۱) پردازش فرم‌ها (ثبت موارد) — الگوی POST → Redirect → GET
 * ------------------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_require();

    $action = clean_text(req('action'), 20);
    $kind   = isset($kinds[$action]) ? $action : null;
    $grade  = validate_grade(req('grade'));
    $field  = $grade ? validate_field(req('field'), $grade) : null;
    $sid    = req_int('student_id');
    $jdate  = normalize_jalali((string)req('jdate')) ?? $dayRef;
    $again  = req('again') === '1';

    $loc = 'teacher.php';
    if ($grade) {
        $loc .= '?grade=' . urlencode($grade) . ($field ? '&field=' . urlencode($field) : '');
        $keepDate = normalize_jalali((string)req('vjdate'));
        if ($keepDate !== null && $keepDate !== $dayRef) {
            $loc .= '&jdate=' . urlencode($keepDate);
        }
    }

    $student = $sid ? row('SELECT id, first_name, last_name, grade, field FROM students WHERE id = ?', [$sid]) : null;

    /* ---------- حذف رکورد اشتباه (مثلاً غیبتی که اشتباهی ثبت شده) ---------- */
    if ($action === 'delete_record') {
        $kind = clean_text(req('kind'), 20);
        $rid  = req_int('id');
        $tables = ['delay' => 'delays', 'absence' => 'absences', 'discipline' => 'disciplines', 'dress' => 'dress_violations'];
        $labels = ['delay' => 'تأخیر', 'absence' => 'غیبت', 'discipline' => 'مورد انضباطی', 'dress' => 'تخلف پوششی'];
        $ok = false;
        if (isset($tables[$kind]) && $rid > 0) {
            require_perm($kind === 'delay' ? 'register_delay' : ($kind === 'absence' ? 'register_absence'
                : ($kind === 'discipline' ? 'register_discipline' : 'register_dress')));
            $tbl = $tables[$kind];
            $rec = row("SELECT t.*, s.first_name, s.last_name, s.grade AS s_grade, s.field AS s_field
                        FROM {$tbl} t JOIN students s ON s.id = t.student_id WHERE t.id = ?", [$rid]);
            if (!$rec) {
                flash_set('error', 'رکورد موردنظر یافت نشد (شاید قبلاً حذف شده است).');
            } elseif ($grade && ($rec['s_grade'] !== $grade || ($field && $rec['s_field'] !== $field))) {
                flash_set('error', 'این رکورد به کلاس انتخاب‌شده تعلق ندارد.');
            } elseif ((int)$rec['created_by'] !== (int)$me['id'] && !is_super() && !has_perm('view_all_records')) {
                flash_set('error', 'فقط رکوردهایی را می‌توانید حذف کنید که خودتان ثبت کرده‌اید.');
            } else {
                db_delete($tbl, ['id' => $rid]);
                log_activity('حذف ' . $labels[$kind], $rec['first_name'] . ' ' . $rec['last_name'] . ' — ' . $rec['jdate']);
                flash_set('success', 'رکورد ' . $labels[$kind] . ' «' . $rec['first_name'] . ' ' . $rec['last_name']
                    . '» در تاریخ ' . jalali_pretty($rec['jdate']) . ' با موفقیت حذف شد.');
                $ok = true;
            }
        } else {
            flash_set('error', 'درخواست حذف نامعتبر است.');
        }
        header('Location: ' . $loc);
        exit;
    }

    if (!$kind) {
        flash_set('error', 'درخواست نامعتبر است؛ از دکمه‌های داخل پنل استفاده کنید.');
    } elseif (!$student) {
        flash_set('error', 'دانش‌آموز انتخاب‌شده یافت نشد.');
    } else {
        /* ثبت غیبت از پنل معلم: «توضیحات» را خودِ سامانه می‌نویسد و معلم چیزی تایپ نمی‌کند
           (کلاس/درس و زنگ از فرم گرفته می‌شود و در ستون‌های خودشان ذخیره می‌شوند) */
        $payload = $_POST;
        if ($kind === 'absence') {
            $lessonTxt = clean_text(req('lesson'), 120);
            $payload['description'] = 'غیبت — ثبت توسط ' . $me['full_name']
                . ($lessonTxt !== '' ? ' (' . $lessonTxt . ')' : '');
        }
        $err = null;
        $id  = hs_register_record($kind, (int)$student['id'], $jdate, $payload, $err);
        $nm  = $student['first_name'] . ' ' . $student['last_name'];

        if ($id) {
            $who = '«' . $nm . '» (پایه ' . $student['grade'] . ' — ' . $student['field'] . ')';
            switch ($kind) {
                case 'delay':
                    $t      = fa_to_en_digits(trim((string)req('time')));
                    $t      = strlen($t) === 4 ? '0' . $t : $t;
                    $bellNm = hs_bell_name($t);
                    $cnt    = (int)one('SELECT COUNT(*) FROM delays WHERE student_id = ? AND jdate = ?', [(int)$student['id'], $jdate]);
                    flash_set('success', 'تأخیر با موفقیت ثبت شد — ' . $who . '، ' . jalali_full($jdate)
                        . '، ساعت ' . fa_num($t) . ($bellNm ? ' (' . $bellNm . ')' : '') . '.');
                    if ($cnt > 1) {
                        flash_set('info', 'این دانش‌آموز در این روز ' . fa_num($cnt) . ' تأخیر (در ' . fa_num($cnt)
                            . ' زنگ مختلف) دارد؛ همهٔ آن‌ها ثبت شده‌اند.');
                    }
                    break;
                case 'absence':
                    $extra = hs_absence_extra_label(req('lesson'), req('time'));
                    flash_set('success', 'غیبت با موفقیت ثبت شد — ' . $who . '، ' . jalali_full($jdate)
                        . '. توضیحات به‌صورت خودکار توسط سامانه نوشته شد.' . ($extra !== '' ? ' ' . $extra : ''));
                    break;
                case 'discipline':
                    $tp  = clean_text(req('type'), 60);
                    $ded = max(0, min(20, (int)fa_to_en_digits((string)req('deduction', '0'))));
                    flash_set('success', 'مورد انضباطی با موفقیت ثبت شد — ' . $who . '، نوع: «' . $tp . '»'
                        . ($ded > 0 ? ' (کسر ' . fa_num($ded) . ')' : '') . '.');
                    break;
                default:
                    $tp = clean_text(req('type'), 30);
                    flash_set('success', 'تخلف پوششی با موفقیت ثبت شد — ' . $who . '، نوع: (' . $tp . ').');
            }
            offday_flash_note($jdate);
            /* پنجرهٔ ثبت فقط در صورتی دوباره باز می‌شود که خودِ معلم خواسته باشد
               (دکمهٔ «ثبت مورد بعدی» یا گزینهٔ «باز ماندن پنجره»). در حالت عادی پنجره بسته
               می‌ماند تا پیام موفقیت به‌طور واضح دیده شود و درخواست ناتمام به نظر نرسد. */
            $sep = ($loc === 'teacher.php' ? '?' : '&');
            $loc .= $again
                ? $sep . 'open=' . urlencode($kind) . '&sid=' . (int)$student['id'] . '&again=1'
                : $sep . 'done=' . urlencode($kind) . '&sid=' . (int)$student['id'];
        } else {
            flash_set('error', $err !== null && $err !== '' ? $err : 'ثبت انجام نشد.');
            $loc .= ($loc === 'teacher.php' ? '?' : '&') . 'open=' . urlencode($kind) . '&sid=' . (int)$student['id'];
        }
    }
    header('Location: ' . $loc);
    exit;
}

/* ---------------------------------------------------------------------------
 * ۲) دادهٔ نمایش: پایه ← رشته ← فهرست کلاس
 * ------------------------------------------------------------------------ */
$grade = validate_grade(req('grade'));
$field = $grade ? validate_field(req('field'), $grade) : null;

$gradeCounts = [];
foreach (HS_GRADES as $g) {
    $gradeCounts[$g] = hs_grade_count($g);
}
$fieldCounts = $grade ? hs_class_counts($grade) : [];
$roster      = ($grade && $field) ? hs_class_students($grade, $field) : [];

/* روز نمایش سوابق کلاس (پیش‌فرض: امروز یا آخرین روز کاری) — قابل تغییر با ?jdate= */
$viewDate = normalize_jalali((string)req('jdate')) ?? $dayRef;
$vPrev    = jalali_add_days($viewDate, -1);
$vNext    = jalali_add_days($viewDate, 1);

/* پنجرهٔ بازشو (بعد از ثبت یا خطا) */
$openKind = isset($kinds[(string)req('open')]) ? (string)req('open') : null;
$openSid  = req_int('sid');

/* آیا کاربر حق حذف رکوردهای دیگران را دارد؟ (خودِ رکوردهایش همیشه قابل حذف است) */
$canDeleteAny = is_super() || has_perm('view_all_records');

/* رکوردهای روز مرجع برای همین کلاس — با رعایت دامنهٔ دسترسی ثبت‌کننده */
[$cfD, $cpD] = creator_filter('d');
[$cfA, $cpA] = creator_filter('ab');
[$cfC, $cpC] = creator_filter('dc');
[$cfV, $cpV] = creator_filter('v');
$scopeLabel  = creator_scope_label();

$dayRecs = ['delay' => [], 'absence' => [], 'discipline' => [], 'dress' => []];
if ($grade && $field) {
    $dayRecs['delay'] = rows(
        "SELECT d.*, s.first_name, s.last_name, a.full_name AS by_name
         FROM delays d JOIN students s ON s.id = d.student_id
         LEFT JOIN admins a ON a.id = d.created_by
         WHERE d.jdate = ? AND s.grade = ? AND s.field = ? AND {$cfD}
         ORDER BY d.time DESC, d.id DESC",
        array_merge([$viewDate, $grade, $field], $cpD)
    );
    $dayRecs['absence'] = rows(
        "SELECT ab.*, s.first_name, s.last_name, a.full_name AS by_name
         FROM absences ab JOIN students s ON s.id = ab.student_id
         LEFT JOIN admins a ON a.id = ab.created_by
         WHERE ab.jdate = ? AND s.grade = ? AND s.field = ? AND {$cfA}
         ORDER BY ab.id DESC",
        array_merge([$viewDate, $grade, $field], $cpA)
    );
    $dayRecs['discipline'] = rows(
        "SELECT dc.*, s.first_name, s.last_name, a.full_name AS by_name
         FROM disciplines dc JOIN students s ON s.id = dc.student_id
         LEFT JOIN admins a ON a.id = dc.created_by
         WHERE dc.jdate = ? AND s.grade = ? AND s.field = ? AND {$cfC}
         ORDER BY dc.id DESC",
        array_merge([$viewDate, $grade, $field], $cpC)
    );
    $dayRecs['dress'] = rows(
        "SELECT v.*, s.first_name, s.last_name, a.full_name AS by_name
         FROM dress_violations v JOIN students s ON s.id = v.student_id
         LEFT JOIN admins a ON a.id = v.created_by
         WHERE v.jdate = ? AND s.grade = ? AND s.field = ? AND {$cfV}
         ORDER BY v.id DESC",
        array_merge([$viewDate, $grade, $field], $cpV)
    );
}

/* جمع‌بندی امروز برای هر دانش‌آموز کلاس (برای نشان‌های کنار نام) */
$todayMark = [];
foreach ($dayRecs as $k => $list) {
    foreach ($list as $r) {
        $sid = (int)$r['student_id'];
        $todayMark[$sid][$k] = ($todayMark[$sid][$k] ?? 0) + 1;
    }
}
/* ثبت‌های خودِ من در امروز/روز مرجع (نمایش در سربرگ پنل) */
$myToday = (int)one(
    'SELECT (SELECT COUNT(*) FROM delays WHERE created_by = ? AND jdate = ?)
          + (SELECT COUNT(*) FROM absences WHERE created_by = ? AND jdate = ?)
          + (SELECT COUNT(*) FROM disciplines WHERE created_by = ? AND jdate = ?)
          + (SELECT COUNT(*) FROM dress_violations WHERE created_by = ? AND jdate = ?)',
    [$me['id'], $viewDate, $me['id'], $viewDate, $me['id'], $viewDate, $me['id'], $viewDate]
);
$bells = hs_bells();

/* پیشنهادهای کلاس/درس (برای پر کردن سریع) */
$lessonSuggestions = [];
foreach (db()->query('SELECT DISTINCT lesson FROM absences WHERE lesson IS NOT NULL AND lesson <> "" LIMIT 12') as $r) {
    $lessonSuggestions[] = (string)$r['lesson'];
}

/* داده‌ای که به جاوااسکریپت صفحه داده می‌شود */
$boot = [
    'today'    => $todayJ,
    'dayRef'   => $dayRef,
    'viewDate' => $viewDate,
    'isOff'    => $offToday,
    'blocked'  => $blockedToday,
    'allowOff' => $allowOff,
    'grade'    => $grade,
    'field'    => $field,
    'perm'     => $permMap,
    'bells'    => $bells,
    'open'     => $openKind,
    'openSid'  => $openSid,
    'again'    => req('again') === '1',
    'now'      => $nowTime = hs_now()['time'],
    'roster'   => array_map(static fn($s) => [
        'id'    => (int)$s['id'],
        'name'  => $s['first_name'] . ' ' . $s['last_name'],
        'grade' => $s['grade'],
        'field' => $s['field'],
    ], $roster),
];

/* ===== استایل اختصاصی پنل معلم ===== */
$tpCss = <<<'CSS'
/* ===== پنل معلم ===== */
.tp-hero{background:linear-gradient(135deg,var(--navy-bg) 0%,var(--navy-bg-2) 100%);color:#fff;border-radius:var(--radius);
  padding:20px 22px;margin-bottom:16px;box-shadow:var(--shadow);position:relative;overflow:hidden}
.tp-hero:before{content:"";position:absolute;inset-inline-start:-40px;top:-40px;width:180px;height:180px;
  background:radial-gradient(circle,rgba(59,130,246,.45),transparent 70%);border-radius:50%}
.tp-hero h2{margin:0 0 6px;font-size:19px;color:#fff}   /* قاعدهٔ سراسری h2 رنگ سرمه‌ای می‌دهد؛ روی کارت سرمه‌ای باید سفید بماند */
.tp-hero h3,.tp-hero h4{color:#fff}
.tp-hero p{margin:0;color:#c7d7ee;font-size:13px;line-height:2}
.tp-hero .chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.tp-hero .chips span{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.2);border-radius:20px;
  padding:4px 12px;font-size:12px}

/* دکمه‌های پایه */
.tp-grades{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:16px}
@media (max-width:760px){.tp-grades{grid-template-columns:1fr}}
.tp-grade{display:flex;flex-direction:column;align-items:center;gap:6px;padding:22px 14px;border-radius:var(--radius);
  background:var(--white);border:2px solid var(--gray-200);color:var(--navy);text-decoration:none;box-shadow:var(--shadow-sm);
  transition:var(--transition);cursor:pointer}
.tp-grade:hover{transform:translateY(-3px);border-color:var(--blue);box-shadow:var(--shadow)}
.tp-grade.on{border-color:var(--blue);background:linear-gradient(180deg,#eff6ff,#fff)}
.tp-grade .g-ic{display:flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:50%;
  background:linear-gradient(135deg,#e8f0fe,#f4f7ff);color:var(--navy);font-size:22px;font-weight:900}
.tp-grade .g-title{font-size:16px;font-weight:800}
.tp-grade .g-sub{font-size:12px;color:var(--gray-500)}
.tp-grade .g-badge{background:#eef2f7;border:1px solid var(--gray-200);border-radius:20px;padding:2px 12px;font-size:12px;font-weight:700}

/* رشته‌ها */
.tp-fields{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.tp-field{display:inline-flex;align-items:center;gap:8px;background:var(--white);border:1.5px solid var(--gray-200);
  border-radius:14px;padding:9px 14px;font-size:13px;font-weight:700;color:var(--navy);text-decoration:none;transition:var(--transition)}
.tp-field:hover{border-color:var(--blue)}
.tp-field.on{background:var(--navy-bg);color:#fff;border-color:var(--navy-bg)}
.tp-field .n{background:#eef2f7;border-radius:20px;padding:1px 9px;font-size:11.5px;color:var(--navy)}
.tp-field.on .n{background:rgba(255,255,255,.2);color:#fff}
.tp-field.empty{opacity:.62}

/* جدول کلاس */
.tp-class-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.tp-class-head h3{margin:0;font-size:16px}
.tp-search{display:flex;align-items:center;gap:8px;background:var(--gray-50);border:1px solid var(--gray-200);
  border-radius:12px;padding:6px 12px;min-width:230px}
.tp-search input{border:0;background:transparent;outline:0;font-family:inherit;font-size:13px;width:100%}
.tp-search-ic{display:inline-flex;color:var(--gray-500)}
.tp-table{width:100%;border-collapse:collapse;font-size:13.2px}
.tp-table th,.tp-table td{padding:10px 8px;border-bottom:1px solid var(--gray-200);text-align:right;vertical-align:middle}
.tp-table th{background:var(--gray-50);color:var(--navy);font-size:12.4px;white-space:nowrap}
.tp-table tbody tr:hover{background:#f8fafc}
.tp-table td.idx{width:44px;color:var(--gray-500);text-align:center}
.tp-table td.nm{font-weight:700;color:var(--navy)}
.tp-marks{display:flex;gap:5px;flex-wrap:wrap}
.tp-mk{background:#f1f5f9;border:1px solid var(--gray-200);border-radius:20px;padding:2px 9px;font-size:11.4px;color:var(--gray-600)}
.tp-mk.on{background:#fef3c7;border-color:#fcd34d;color:#92400e;font-weight:700}
.tp-mk.ab{background:#fee2e2;border-color:#fca5a5;color:#991b1b;font-weight:700}
.tp-mk.dc{background:#ede9fe;border-color:#c4b5fd;color:#5b21b6;font-weight:700}
.tp-mk.dr{background:#dbeafe;border-color:#93c5fd;color:#1e40af;font-weight:700}
.tp-actions{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-start}
.tp-act{display:inline-flex;align-items:center;gap:5px;border:1.5px solid var(--gray-200);background:var(--white);
  color:var(--navy);border-radius:11px;padding:6px 11px;font-size:12.4px;font-weight:700;cursor:pointer;
  font-family:inherit;transition:var(--transition)}
.tp-act:hover{border-color:var(--blue);background:#eff6ff;transform:translateY(-1px)}
.tp-act[disabled]{opacity:.45;cursor:not-allowed;transform:none}
.tp-act.delay{border-color:#fcd34d;background:#fffbeb}
.tp-act.absence{border-color:#fca5a5;background:#fef2f2}
.tp-act.discipline{border-color:#c4b5fd;background:#f5f3ff}
.tp-act.dress{border-color:#93c5fd;background:#eff6ff}

/* چیپ‌های زنگ (انتخاب سریع زنگ/ساعت) */
.bell-chips{display:flex;gap:7px;flex-wrap:wrap;margin-top:6px}
.bell-chip{background:var(--white);border:1.5px dashed var(--gray-300);border-radius:22px;padding:6px 13px;font-size:12.3px;
  font-family:inherit;color:var(--navy);cursor:pointer;font-weight:700;transition:var(--transition)}
.bell-chip:hover{border-color:var(--blue);color:var(--blue-dark)}
.bell-chip.on{background:var(--navy-bg);border-style:solid;border-color:var(--navy-bg);color:#fff}
.bell-info{margin-top:6px;font-size:12.2px;font-weight:700;color:var(--blue-dark);min-height:18px}
.bell-info.empty{color:var(--gray-400);font-weight:400}

/* یادداشت روز تعطیل زیر فیلد تاریخ */
.offday-note{display:none;margin-top:5px;font-size:12px;color:#b91c1c;font-weight:700}
.offday-note.show{display:block}
input.offday{border-color:#fca5a5;background:#fff1f2}
.tp-note{font-size:12.2px;color:var(--gray-500);line-height:1.95}
.tp-day{border:1px solid var(--gray-200);border-radius:14px;background:var(--white);padding:12px 14px;margin-top:14px}
.tp-daynav{display:flex;align-items:center;gap:9px;flex-wrap:wrap;background:var(--gray-50);border:1px solid var(--gray-200);
  border-radius:14px;padding:10px 14px;margin-top:16px}
.tp-daynav .tp-dn-title{font-weight:800;color:var(--navy);font-size:13.6px;display:inline-flex;align-items:center;gap:6px}
.tp-day h4{margin:0 0 8px;font-size:14px;color:var(--navy)}
.tp-day-list{display:flex;flex-direction:column;gap:7px}
.tp-day-row{display:flex;gap:9px;align-items:flex-start;border-bottom:1px dashed var(--gray-200);padding-bottom:7px;font-size:12.8px}
.tp-day-row:last-child{border-bottom:0;padding-bottom:0}
.tp-day-row .tp-del{margin-inline-start:auto}
.tp-del{display:inline-flex;align-items:center;gap:4px;border:1.4px solid #fecaca;background:#fff5f5;color:#b91c1c;
  border-radius:10px;padding:5px 9px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:var(--transition)}
.tp-del:hover{background:#fee2e2;border-color:#fca5a5}
.tp-tr{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:10px;
  border:1.4px solid #e2e8f0;background:#fff;color:var(--gray-500);cursor:pointer;transition:var(--transition)}
.tp-tr:hover{border-color:#fca5a5;background:#fff5f5;color:#b91c1c}
.tp-day-row b{color:var(--navy)}
.tp-day-row .mt{color:var(--gray-500);font-size:11.8px}
.tp-empty{padding:24px;text-align:center;color:var(--gray-500);font-size:13px}
.auto-note{display:flex;gap:10px;align-items:flex-start;background:#ecfdf5;border:1.4px solid #a7f3d0;
  border-radius:14px;padding:12px 14px;margin-top:12px;font-size:12.8px;line-height:2;color:#065f46}
.auto-note b{color:#047857}
.auto-note .an-ic{display:inline-flex;color:#059669}
.rd-ic{display:inline-flex;color:var(--navy);margin-top:2px}
.tp-del-form{margin:0}
.tp-day-row{align-items:center}
/* گزینهٔ «باز ماندن پنجرهٔ ثبت» در پای فرم‌های مودال */
.tp-again{display:flex;align-items:flex-start;gap:9px;margin:2px 18px 14px;padding:10px 12px;cursor:pointer;
  background:var(--gray-50);border:1px solid var(--gray-200);border-radius:12px;font-size:12.5px;color:var(--gray-700)}
.tp-again input{width:16px;height:16px;accent-color:var(--blue);margin-top:1px;flex:0 0 auto;cursor:pointer}
.tp-again b{color:var(--navy)}
/* نوار «ثبت مورد بعدی برای همین دانش‌آموز» بعد از ثبت موفق */
.tp-next{display:flex;align-items:center;gap:11px;flex-wrap:wrap}
.tp-next .sp{flex:1}
.tp-next b{color:var(--navy)}
CSS;

layout_head('پنل معلم — کلاس‌ها', 'teacher', [$tpCss]);
?>

<!-- ================= سربرگ پنل ================= -->
<div class="tp-hero">
  <h2><?= hs_icon('teacher', 22) ?> پنل معلم — کلاس‌های من</h2>
  <p>
    پایه را انتخاب کنید، سپس رشتهٔ همان پایه را بزنید تا فهرست دانش‌آموزان کلاس باز شود.
    کنار هر دانش‌آموز می‌توانید <b>تأخیر</b>، <b>غیبت</b>، <b>مورد انضباطی</b> و <b>تخلف پوششی</b> ثبت کنید.
  </p>
  <div class="chips">
    <span><?= hs_icon('calendar', 15) ?> امروز: <?= jalali_full($todayJ) ?></span>
    <?php if ($offToday): ?>
      <span><?= hs_icon('info', 15) ?> امروز تعطیل است<?= $allowOff ? ' — ثبت مجاز شده است' : '' ?></span>
    <?php endif; ?>
    <span><?= hs_icon('calendar', 15) ?> روز نمایش سوابق: <?= jalali_pretty($viewDate) ?></span>
    <span><?= hs_icon('clipboard', 15) ?> ثبت‌های خودم در این روز: <?= fa_num($myToday) ?> مورد</span>
    <span><?= hs_icon('eye', 15) ?> <?= e($scopeLabel) ?></span>
  </div>
</div>

<?php
/* پس از ثبت موفق، پنجره بسته می‌ماند و این نوار امکانِ ثبت مورد بعدی برای همان دانش‌آموز را می‌دهد */
$doneKind = isset($kinds[req('done')]) ? (string)req('done') : null;
$doneSid  = req_int('sid');
$doneName = '';
if ($doneKind && $doneSid > 0 && !empty($permMap[$doneKind])) {
    $doneRow  = row('SELECT first_name, last_name FROM students WHERE id = ?', [$doneSid]);
    $doneName = $doneRow ? ($doneRow['first_name'] . ' ' . $doneRow['last_name']) : '';
}
if ($doneKind && $doneName !== ''): ?>
  <div class="card tp-next">
    <span class="mh-ic" style="background:var(--green-soft);color:var(--green)"><?= hs_icon($kinds[$doneKind]['icon'], 20) ?></span>
    <span>
      <b>ثبت <?= e($kinds[$doneKind]['label']) ?> انجام شد.</b><br>
      <small class="hint" style="margin:0">اگر می‌خواهید مورد دیگری برای <b><?= e((string)$doneName) ?></b> ثبت کنید، از دکمهٔ روبه‌رو استفاده کنید؛ در غیر این‌صورت می‌توانید پنجره را بسته رها کنید.</small>
    </span>
    <span class="sp"></span>
    <a class="btn btn-sm <?= e($kinds[$doneKind]['btn']) ?>"
       href="teacher.php?grade=<?= urlencode($grade) ?><?= $field ? '&field=' . urlencode($field) : '' ?>&amp;open=<?= urlencode($doneKind) ?>&amp;sid=<?= (int)$doneSid ?>&amp;again=1">
      <?= hs_icon('plus', 16) ?> ثبت مورد بعدی برای همین دانش‌آموز
    </a>
  </div>
<?php endif; ?>

<?php if (!$canRegister): ?>
  <div class="alert warn"><span><?= hs_icon('info', 18) ?></span><span>
    شما در حال حاضر فقط <b>مشاهدهٔ کلاس‌ها</b> را دارید و پرمیشن ثبت هیچ موردی برایتان فعال نیست.
    از مدیر کل بخواهید پرمیشن‌های «ثبت تأخیر / ثبت غیبت / ثبت مورد انضباطی / ثبت تخلف پوششی» را فعال کند.
  </span></div>
<?php elseif ($blockedToday): ?>
  <div class="alert warn"><span><?= hs_icon('info', 18) ?></span><span>
    امروز (<?= e($todayDow) ?> — <?= jalali_pretty($todayJ) ?>) <b>تعطیل</b> است و ثبت مورد جدید مجاز نیست.
    اگر مدرسه در این روز دایر است، از مدیر کل بخواهید «ثبت در روز تعطیل» را در تنظیمات فعال کند.
    می‌توانید سوابق کلاس‌ها را ببینید و برای روزهای کاری دیگر ثبت کنید (تاریخ فرم را تغییر دهید).
  </span></div>
<?php endif; ?>

<!-- ================= ۱) انتخاب پایه ================= -->
<h3 style="margin:18px 0 10px;font-size:15px">گام ۱ — پایه را انتخاب کنید</h3>
<div class="tp-grades">
  <?php foreach (HS_GRADES as $g): ?>
    <a class="tp-grade <?= $grade === $g ? 'on' : '' ?>" href="teacher.php?grade=<?= urlencode($g) ?>">
      <span class="g-ic"><?= fa_num($g === 'دهم' ? 1 : ($g === 'یازدهم' ? 2 : 3)) ?></span>
      <span class="g-title">پایه <?= e($g) ?></span>
      <span class="g-badge"><?= fa_num($gradeCounts[$g]) ?> دانش‌آموز</span>
      <span class="g-sub"><?= $grade === $g ? 'انتخاب شده — رشته را انتخاب کنید' : 'برای دیدن رشته‌ها کلیک کنید' ?></span>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($grade): ?>
  <!-- ================= ۲) انتخاب رشته ================= -->
  <div class="card">
    <h3>گام ۲ — رشتهٔ کلاس در پایه <?= e($grade) ?> را انتخاب کنید</h3>
    <?php if ($grade === 'دوازدهم'): ?>
      <p class="tp-note"><?= hs_icon('info', 15) ?> در پایهٔ دوازدهم رشتهٔ «کامپیوتر» وجود ندارد؛ بنابراین در فهرست زیر نمایش داده نمی‌شود.</p>
    <?php endif; ?>
    <div class="tp-fields">
      <?php foreach (fields_for_grade($grade) as $f):
          $n = (int)($fieldCounts[$f] ?? 0); ?>
        <a class="tp-field <?= $field === $f ? 'on' : '' ?> <?= $n === 0 ? 'empty' : '' ?>"
           href="teacher.php?grade=<?= urlencode($grade) ?>&field=<?= urlencode($f) ?>">
          <span><?= e($f) ?></span>
          <span class="n"><?= fa_num($n) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php if (array_sum($fieldCounts) === 0): ?>
      <div class="tp-note">در پایهٔ <?= e($grade) ?> هنوز دانش‌آموزی ثبت نشده است.</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($grade && $field): ?>
  <!-- ================= ۳) کلاس و فهرست دانش‌آموزان ================= -->
  <div class="card">
    <div class="tp-class-head">
      <h3>گام ۳ — کلاس: پایه <?= e($grade) ?> — <?= e($field) ?></h3>
      <span class="badge blue"><?= fa_num(count($roster)) ?> دانش‌آموز</span>
      <span class="badge amber">تأخیر این کلاس در <?= jalali_pretty($viewDate) ?>: <?= fa_num(count($dayRecs['delay'])) ?></span>
      <span class="badge red">غیبت: <?= fa_num(count($dayRecs['absence'])) ?></span>
      <span style="flex:1"></span>
      <label class="tp-search" for="tpSearch">
        <span class="tp-search-ic"><?= hs_icon('search', 16) ?></span>
        <input type="text" id="tpSearch" placeholder="جستجو در همین کلاس…" autocomplete="off">
      </label>
      <a class="btn btn-sm btn-ghost" href="records.php?tab=delay"><?= hs_icon('clipboard', 16) ?> صفحهٔ ثبت موارد</a>
    </div>

    <?php if (!$roster): ?>
      <div class="tp-empty">در این کلاس دانش‌آموزی ثبت نشده است.</div>
    <?php else: ?>
      <div style="overflow:auto">
        <table class="tp-table" id="tpTable">
          <thead>
            <tr>
              <th style="text-align:center">#</th>
              <th>نام و نام خانوادگی</th>
              <th>سوابق <?= jalali_pretty($viewDate) ?></th>
              <th><?= $canRegister ? 'ثبت مورد جدید' : 'وضعیت دسترسی' ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($roster as $i => $st):
                $sid   = (int)$st['id'];
                $nm    = $st['first_name'] . ' ' . $st['last_name'];
                $marks = $todayMark[$sid] ?? [];
            ?>
              <tr data-search="<?= e($nm . ' ' . $st['grade'] . ' ' . $st['field']) ?>">
                <td class="idx"><?= fa_num($i + 1) ?></td>
                <td class="nm"><?= e($nm) ?></td>
                <td>
                  <div class="tp-marks">
                    <?php if (($marks['delay'] ?? 0) > 0): ?>
                      <span class="tp-mk on"><?= hs_icon('clock', 13) ?> <?= fa_num($marks['delay']) ?> تأخیر</span>
                    <?php endif; ?>
                    <?php if (($marks['absence'] ?? 0) > 0): ?><span class="tp-mk ab"><?= hs_icon('ban', 13) ?> غایب</span><?php endif; ?>
                    <?php if (($marks['discipline'] ?? 0) > 0): ?><span class="tp-mk dc"><?= hs_icon('scales', 13) ?> انضباطی</span><?php endif; ?>
                    <?php if (($marks['dress'] ?? 0) > 0): ?><span class="tp-mk dr"><?= hs_icon('shirt', 13) ?> پوششی</span><?php endif; ?>
                    <?php if (!$marks): ?><span class="tp-mk">— بی‌سابقه</span><?php endif; ?>
                  </div>
                </td>
                <td>
                  <div class="tp-actions">
                    <?php if ($canRegister): ?>
                      <?php foreach ($kinds as $k => $m): ?>
                        <?php if (!$permMap[$k]) { continue; } ?>
                        <button type="button" class="tp-act <?= e($k) ?>" data-act="<?= e($k) ?>"
                                data-sid="<?= $sid ?>" data-name="<?= e($nm) ?>"
                                <?= $blockedToday ? 'disabled title="امروز تعطیل است"' : 'title="ثبت ' . e($m['label']) . ' برای این دانش‌آموز"' ?>>
                          <?= hs_icon($m['icon'], 15) ?> ثبت <?= e($m['label']) ?>
                        </button>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <span class="tp-mk">فقط مشاهده</span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="tp-note" id="tpShown"></div>
    <?php endif; ?>
  </div>

  <!-- ================= ۴) سوابق روز انتخابی برای همین کلاس ================= -->
  <div class="tp-daynav">
    <span class="tp-dn-title"><?= hs_icon('calendar', 17) ?> سوابق کلاس در روز انتخابی</span>
    <a class="btn btn-sm btn-ghost" href="<?= e('teacher.php?grade=' . urlencode($grade) . '&field=' . urlencode($field) . '&jdate=' . urlencode($vPrev)) ?>"
       title="روز قبل">‹ روز قبل</a>
    <span class="day-badge"><?= jalali_full($viewDate) ?></span>
    <a class="btn btn-sm btn-ghost" href="<?= e('teacher.php?grade=' . urlencode($grade) . '&field=' . urlencode($field) . '&jdate=' . urlencode($vNext)) ?>"
       title="روز بعد">روز بعد ›</a>
    <a class="btn btn-sm btn-blue" href="<?= e('teacher.php?grade=' . urlencode($grade) . '&field=' . urlencode($field)) ?>">امروز / آخرین روز کاری</a>
    <?php if ($viewDate !== $dayRef): ?><span class="badge gray">در حال مشاهدهٔ روزی غیر از امروز</span><?php endif; ?>
  </div>
  <div class="grid g2">
    <div class="tp-day">
      <h4><?= hs_icon('clock', 17) ?> تأخیرهای این کلاس — <?= jalali_full($viewDate) ?> <span class="badge amber"><?= fa_num(count($dayRecs['delay'])) ?></span></h4>
      <div class="tp-day-list">
        <?php if (!$dayRecs['delay']): ?>
          <div class="tp-note">در این روز تأخیری ثبت نشده است.</div>
        <?php endif; ?>
        <?php foreach ($dayRecs['delay'] as $r): ?>
          <div class="tp-day-row">
            <span class="rd-ic"><?= hs_icon('clock', 17) ?></span>
            <span><b><?= e($r['first_name'] . ' ' . $r['last_name']) ?></b><br>
              <span class="mt">زنگ: <?= e(hs_bell_label($r['time'])) ?>
                <?= $r['description'] ? ' — ' . e($r['description']) : '' ?>
                <?= $r['by_name'] ? ' — ثبت: ' . e($r['by_name']) : '' ?></span></span>
            <?php if ((int)$r['created_by'] === (int)$me['id'] || $canDeleteAny): ?>
              <form method="post" class="tp-del-form" data-rdel
                    data-title="حذف تأخیر" data-name="<?= e($r['first_name'] . ' ' . $r['last_name']) ?>"
                    data-info="<?= e(fa_num(mb_substr((string)$r['time'], 0, 5))) ?> — <?= e(jalali_pretty($r['jdate'])) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_record">
                <input type="hidden" name="kind" value="delay">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="grade" value="<?= e($grade) ?>">
                <input type="hidden" name="field" value="<?= e($field) ?>">
                <input type="hidden" name="vjdate" value="<?= e($viewDate) ?>">
                <button type="submit" class="tp-del"><?= hs_icon('trash', 14) ?> حذف</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="tp-day">
      <h4><?= hs_icon('ban', 17) ?> غیبت‌های این کلاس — <?= jalali_full($viewDate) ?> <span class="badge red"><?= fa_num(count($dayRecs['absence'])) ?></span></h4>
      <div class="tp-day-list">
        <?php if (!$dayRecs['absence']): ?>
          <div class="tp-note">در این روز غیبتی ثبت نشده است.</div>
        <?php endif; ?>
        <?php foreach ($dayRecs['absence'] as $r): ?>
          <div class="tp-day-row">
            <span class="rd-ic"><?= hs_icon('ban', 17) ?></span>
            <span><b><?= e($r['first_name'] . ' ' . $r['last_name']) ?></b><br>
              <span class="mt">
                روز: <?= e($r['day_of_week']) ?>
                <?= $r['description'] ? ' — ' . e($r['description']) : '' ?>
                <?= hs_absence_extra_label($r['lesson'] ?? null, $r['time'] ?? null) ? ' — ' . e(hs_absence_extra_label($r['lesson'] ?? null, $r['time'] ?? null)) : '' ?>
                <?= $r['by_name'] ? ' — ثبت: ' . e($r['by_name']) : '' ?>
              </span></span>
            <?php if ((int)$r['created_by'] === (int)$me['id'] || $canDeleteAny): ?>
              <form method="post" class="tp-del-form" data-rdel
                    data-title="حذف غیبت" data-name="<?= e($r['first_name'] . ' ' . $r['last_name']) ?>"
                    data-info="<?= e(jalali_full($r['jdate'])) ?><?= $r['lesson'] ? ' — ' . e($r['lesson']) : '' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_record">
                <input type="hidden" name="kind" value="absence">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="grade" value="<?= e($grade) ?>">
                <input type="hidden" name="field" value="<?= e($field) ?>">
                <input type="hidden" name="vjdate" value="<?= e($viewDate) ?>">
                <button type="submit" class="tp-del"><?= hs_icon('trash', 14) ?> حذف غیبت</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php if ($dayRecs['discipline'] || $dayRecs['dress']): ?>
    <div class="grid g2">
      <?php if ($dayRecs['discipline']): ?>
        <div class="tp-day">
          <h4><?= hs_icon('scales', 17) ?> موارد انضباطی این کلاس <span class="badge purple"><?= fa_num(count($dayRecs['discipline'])) ?></span></h4>
          <div class="tp-day-list">
            <?php foreach ($dayRecs['discipline'] as $r): ?>
              <div class="tp-day-row"><span class="rd-ic"><?= hs_icon('scales', 17) ?></span>
                <span><b><?= e($r['first_name'] . ' ' . $r['last_name']) ?></b><br>
                <span class="mt"><?= e($r['type']) ?><?= (int)$r['deduction'] > 0 ? ' — کسر ' . fa_num((int)$r['deduction']) : '' ?>
                <?= $r['description'] ? ' — ' . e(mb_substr((string)$r['description'], 0, 80, 'UTF-8')) : '' ?></span></span>
                <?php if ((int)$r['created_by'] === (int)$me['id'] || $canDeleteAny): ?>
                  <form method="post" class="tp-del-form" data-rdel
                        data-title="حذف مورد انضباطی" data-name="<?= e($r['first_name'] . ' ' . $r['last_name']) ?>"
                        data-info="<?= e($r['type']) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_record">
                    <input type="hidden" name="kind" value="discipline">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="grade" value="<?= e($grade) ?>">
                    <input type="hidden" name="field" value="<?= e($field) ?>">
                <input type="hidden" name="vjdate" value="<?= e($viewDate) ?>">
                    <button type="submit" class="tp-del"><?= hs_icon('trash', 14) ?> حذف</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
      <?php if ($dayRecs['dress']): ?>
        <div class="tp-day">
          <h4><?= hs_icon('shirt', 17) ?> تخلفات پوششی این کلاس <span class="badge blue"><?= fa_num(count($dayRecs['dress'])) ?></span></h4>
          <div class="tp-day-list">
            <?php foreach ($dayRecs['dress'] as $r): ?>
              <div class="tp-day-row"><span class="rd-ic"><?= hs_icon('shirt', 17) ?></span>
                <span><b><?= e($r['first_name'] . ' ' . $r['last_name']) ?></b><br>
                <span class="mt"><?= e($r['type']) ?><?= $r['description'] ? ' — ' . e($r['description']) : '' ?></span></span>
                <?php if ((int)$r['created_by'] === (int)$me['id'] || $canDeleteAny): ?>
                  <form method="post" class="tp-del-form" data-rdel
                        data-title="حذف تخلف پوششی" data-name="<?= e($r['first_name'] . ' ' . $r['last_name']) ?>"
                        data-info="<?= e($r['type']) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_record">
                    <input type="hidden" name="kind" value="dress">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="grade" value="<?= e($grade) ?>">
                    <input type="hidden" name="field" value="<?= e($field) ?>">
                <input type="hidden" name="vjdate" value="<?= e($viewDate) ?>">
                    <button type="submit" class="tp-del"><?= hs_icon('trash', 14) ?> حذف</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

<?php elseif ($grade): ?>
  <div class="card"><div class="tp-empty">برای دیدن فهرست دانش‌آموزان و ثبت موارد، یکی از رشته‌های بالا را انتخاب کنید.</div></div>
<?php else: ?>
  <div class="card"><div class="tp-empty">برای شروع، یکی از سه پایهٔ بالا را انتخاب کنید.</div></div>
<?php endif; ?>

<!-- ==================================================================
     پنجره‌های ثبت (مودال) — یکی برای هر نوع مورد
=================================================================== -->
<?php if ($grade && $field && $canRegister): ?>

  <!-- تأخیر -->
  <?php if ($permMap['delay']): ?>
  <div class="modal-bg" id="md-delay">
    <div class="modal" style="max-width:560px">
      <header><span class="mh-ic"><?= hs_icon('clock', 22) ?></span>
        <h3>ثبت تأخیر — <span data-mname>دانش‌آموز</span></h3>
        <span style="flex:1"></span>
        <button type="button" class="btn btn-sm btn-ghost" data-close title="بستن"><?= hs_icon('close', 16) ?></button>
      </header>
      <form method="post" data-loading="در حال ثبت تأخیر…" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delay">
        <input type="hidden" name="student_id" data-sid>
        <input type="hidden" name="grade" value="<?= e($grade) ?>">
        <input type="hidden" name="field" value="<?= e($field) ?>">
        <input type="hidden" name="again" value="1">
        <div class="mbody">
          <label class="fl">تاریخ تأخیر (شمسی) <span class="req">*</span></label>
          <input type="text" class="hs-date" name="jdate" value="<?= e($viewDate) ?>" required data-offcheck="1">
          <div class="offday-note"></div>
          <span class="hint">روز هفته: <b data-dowview><?= e(jalali_weekday_name($viewDate)) ?></b> (خودکار)</span>

          <label class="fl mt10">ساعت ورود به کلاس <span class="req">*</span></label>
          <input type="text" name="time" data-time value="<?= e($nowTime) ?>" placeholder="مثال: 07:45" required>
          <div class="bell-chips">
            <?php foreach ($bells as $b): ?>
              <button type="button" class="bell-chip" data-time-set="<?= e($b['time']) ?>">
                <?= hs_icon('bell', 15) ?> <?= e($b['name']) ?> — <?= e(fa_num($b['time'])) ?>
              </button>
            <?php endforeach; ?>
          </div>
          <div class="bell-info empty">با کلیک روی هر زنگ، ساعت همان زنگ در فیلد بالا قرار می‌گیرد.</div>

          <label class="fl mt10">توضیحات تأخیر (اختیاری)</label>
          <input type="text" name="description" maxlength="255" placeholder="مثال: تأخیر ورود به کلاس — دلیل گفته شد">

          <div class="alert info mt10" style="margin-top:12px"><span><?= hs_icon('info', 18) ?></span><span>
            <b>چند تأخیر در یک روز مجاز است.</b> پس از ثبت، همین پنجره برای همین دانش‌آموز باز می‌ماند تا زنگ بعدی را هم ثبت کنید.
            فقط ثبتِ دقیقاً تکراری (همان تاریخ و همان ساعت/زنگ) رد می‌شود.
          </span></div>
        </div>
        <footer>
          <button class="btn btn-amber" type="submit"><?= hs_icon('clock', 17) ?> ثبت تأخیر</button>
          <button type="button" class="btn btn-ghost" data-close><?= hs_icon('close', 15) ?> بستن</button>
        </footer>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- غیبت (کاملاً اتوماتیک: تاریخ، روز هفته و متن توضیحات را سامانه می‌نویسد) -->
  <?php if ($permMap['absence']): ?>
  <div class="modal-bg" id="md-absence">
    <div class="modal" style="max-width:600px">
      <header><span class="mh-ic"><?= hs_icon('ban', 22) ?></span>
        <h3>ثبت غیبت — <span data-mname>دانش‌آموز</span></h3>
        <span style="flex:1"></span>
        <button type="button" class="btn btn-sm btn-ghost" data-close title="بستن"><?= hs_icon('close', 16) ?></button>
      </header>
      <form method="post" data-loading="در حال ثبت غیبت…" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="absence">
        <input type="hidden" name="student_id" data-sid>
        <input type="hidden" name="grade" value="<?= e($grade) ?>">
        <input type="hidden" name="field" value="<?= e($field) ?>">
        <div class="mbody">
          <div class="grid g2">
            <div>
              <label class="fl">تاریخ غیبت (شمسی) <span class="req">*</span></label>
              <input type="text" class="hs-date" name="jdate" value="<?= e($viewDate) ?>" required data-offcheck="1">
              <div class="offday-note"></div>
            </div>
            <div>
              <label class="fl">روز هفته (خودکار)</label>
              <input type="text" data-dowview value="<?= e(jalali_weekday_name($viewDate)) ?>" disabled>
              <span class="hint">غیرقابل ویرایش — از تاریخ شمسی محاسبه می‌شود.</span>
            </div>
          </div>

          <div class="auto-note">
            <span class="an-ic"><?= hs_icon('check', 18) ?></span>
            <span><b>توضیحات غیبت نیازی به نوشتن ندارد.</b><br>
              با زدن دکمهٔ «ثبت غیبت»، خودِ سامانه متن توضیحات را می‌نویسد:
              «غیبت — ثبت توسط <?= e($me['full_name']) ?><?= ' ' ?>(کلاس/درس انتخابی)».</span>
          </div>

          <label class="fl mt10">کلاس / درس (اختیاری)</label>
          <input type="text" name="lesson" maxlength="120" list="tpLessons" placeholder="مثال: ریاضی — کلاس ۱۰۲">
          <datalist id="tpLessons">
            <?php foreach ($lessonSuggestions as $ls): ?><option value="<?= e($ls) ?>"></option><?php endforeach; ?>
          </datalist>

          <label class="fl mt10">زنگ (اختیاری)</label>
          <input type="text" name="time" data-time value="" placeholder="مثال: 07:45">
          <div class="bell-chips">
            <?php foreach ($bells as $b): ?>
              <button type="button" class="bell-chip" data-time-set="<?= e($b['time']) ?>">
                <?= hs_icon('bell', 15) ?> <?= e($b['name']) ?> — <?= e(fa_num($b['time'])) ?>
              </button>
            <?php endforeach; ?>
            <button type="button" class="bell-chip" data-time-set=""><?= hs_icon('close', 14) ?> پاک کردن زنگ</button>
          </div>
          <div class="bell-info empty">اگر غیبت مربوط به زنگ خاصی است، آن را انتخاب کنید (اختیاری).</div>

          <div class="alert info" style="margin-top:12px"><span><?= hs_icon('bolt', 18) ?></span><span>
            ثبت غیبت <b>اتوماتیک</b> است: تاریخ و روز هفته خودکار پر می‌شوند و <b>متن توضیحات را سامانه می‌نویسد</b>؛
            فقط کلاس/درس و زنگ (اختیاری) را انتخاب کنید و یک‌بار «ثبت غیبت» را بزنید. برای هر دانش‌آموز در هر روز یک غیبت ثبت می‌شود.
          </span></div>
        </div>
        <footer>
          <button class="btn btn-red" type="submit"><?= hs_icon('ban', 17) ?> ثبت غیبت</button>
          <button type="button" class="btn btn-ghost" data-close><?= hs_icon('close', 15) ?> بستن</button>
        </footer>
        <label class="tp-again">
          <input type="checkbox" name="again" value="1">
          <span>پنجره بعد از ثبت برای همین دانش‌آموز <b>باز بماند</b> (برای ثبت سریع مورد بعدی)</span>
        </label>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- مورد انضباطی -->
  <?php if ($permMap['discipline']): ?>
  <div class="modal-bg" id="md-discipline">
    <div class="modal" style="max-width:560px">
      <header><span class="mh-ic"><?= hs_icon('scales', 22) ?></span>
        <h3>ثبت مورد انضباطی — <span data-mname>دانش‌آموز</span></h3>
        <span style="flex:1"></span>
        <button type="button" class="btn btn-sm btn-ghost" data-close title="بستن"><?= hs_icon('close', 16) ?></button>
      </header>
      <form method="post" data-loading="در حال ثبت مورد انضباطی…" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="discipline">
        <input type="hidden" name="student_id" data-sid>
        <input type="hidden" name="grade" value="<?= e($grade) ?>">
        <input type="hidden" name="field" value="<?= e($field) ?>">
        <div class="mbody">
          <label class="fl">تاریخ (شمسی) <span class="req">*</span></label>
          <input type="text" class="hs-date" name="jdate" value="<?= e($viewDate) ?>" required data-offcheck="1">
          <div class="offday-note"></div>

          <label class="fl mt10">نوع مورد <span class="req">*</span></label>
          <select name="type" required>
            <?php foreach (HS_DISCIPLINE_TYPES as $t): ?>
              <option value="<?= e($t) ?>"><?= e($t) ?></option>
            <?php endforeach; ?>
          </select>

          <label class="fl mt10">کسر نمره انضباط (۰ تا ۲۰)</label>
          <input type="number" name="deduction" min="0" max="20" step="0.5" value="0">

          <label class="fl mt10">توضیحات <span class="req">*</span></label>
          <input type="text" name="description" maxlength="500" required placeholder="شرح کوتاه مورد — مثلاً: بی‌نظمی در کلاس ریاضی">
        </div>
        <footer>
          <button class="btn btn-purple" type="submit"><?= hs_icon('scales', 17) ?> ثبت مورد</button>
          <button type="button" class="btn btn-ghost" data-close><?= hs_icon('close', 15) ?> بستن</button>
        </footer>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- تخلف پوششی -->
  <?php if ($permMap['dress']): ?>
  <div class="modal-bg" id="md-dress">
    <div class="modal" style="max-width:560px">
      <header><span class="mh-ic"><?= hs_icon('shirt', 22) ?></span>
        <h3>ثبت تخلف پوششی — <span data-mname>دانش‌آموز</span></h3>
        <span style="flex:1"></span>
        <button type="button" class="btn btn-sm btn-ghost" data-close title="بستن"><?= hs_icon('close', 16) ?></button>
      </header>
      <form method="post" data-loading="در حال ثبت تخلف پوششی…" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="dress">
        <input type="hidden" name="student_id" data-sid>
        <input type="hidden" name="grade" value="<?= e($grade) ?>">
        <input type="hidden" name="field" value="<?= e($field) ?>">
        <div class="mbody">
          <label class="fl">تاریخ (شمسی) <span class="req">*</span></label>
          <input type="text" class="hs-date" name="jdate" value="<?= e($viewDate) ?>" required data-offcheck="1">
          <div class="offday-note"></div>

          <label class="fl mt10">نوع تخلف <span class="req">*</span></label>
          <select name="type" required>
            <?php foreach (HS_DRESS_TYPES as $t): ?>
              <option value="<?= e($t) ?>"><?= e($t) ?></option>
            <?php endforeach; ?>
          </select>

          <label class="fl mt10">توضیحات (اختیاری)</label>
          <input type="text" name="description" maxlength="255" placeholder="مثال: بدون لباس فرم مدرسه">
        </div>
        <footer>
          <button class="btn btn-blue" type="submit"><?= hs_icon('shirt', 17) ?> ثبت تخلف</button>
          <button type="button" class="btn btn-ghost" data-close><?= hs_icon('close', 15) ?> بستن</button>
        </footer>
      </form>
    </div>
  </div>
  <?php endif; ?>

<?php endif; ?>

<script>
window.HS_TP = <?= json_encode($boot, JSON_UNESCAPED_UNICODE) ?>;
</script>

<?php
/* ===== جاوااسکریپت اختصاصی پنل معلم (بدون PHP داخل nowdoc) ===== */
$tpJs = <<<'JS'
(function () {
  'use strict';
  const T = window.HS_TP || {};

  /* ---------- ابزارها ---------- */
  function el(sel, root) { return (root || document).querySelector(sel); }
  function els(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

  /* نمایش نام روز هفته و هشدار تعطیلی برای همهٔ فیلدهای تاریخ */
  function refreshOffNotes() {
    els('[data-offcheck]').forEach(function (inp) {
      const wrap = inp.parentNode;
      const note = el('.offday-note', wrap);
      const off  = window.HS && HS.isOffDay ? HS.isOffDay(inp.value) : false;
      inp.classList.toggle('offday', off);
      if (note) {
        note.textContent = off
          ? 'این روز (' + (HS.jWeekday ? HS.jWeekday(inp.value) : '') + ') تعطیل است' + (T.allowOff ? ' — ثبت مجاز شده است.' : '؛ ثبت در این روز مجاز نیست.')
          : '';
        note.classList.toggle('show', !!off);
      }
      const form = inp.closest('form');
      const dow  = form ? el('[data-dowview]', form) : null;
      if (dow && HS.jWeekday) { dow.value = HS.jWeekday(inp.value); }
    });
  }

  /* فعال/غیرفعال کردن چیپ‌های زنگ بر اساس ساعت فیلد */
  function paintBells(form) {
    const t = el('[data-time]', form);
    const info = el('.bell-info', form);
    if (!t || !info) { return; }
    const val = (t.value || '').trim();
    let hit = null;
    els('.bell-chip', form).forEach(function (c) {
      const on = c.dataset.timeSet === val && val !== '';
      c.classList.toggle('on', on);
      if (on) { hit = c.textContent.trim(); }
    });
    if (val === '') {
      info.textContent = 'اگر غیبت مربوط به زنگ خاصی است، آن را انتخاب کنید (اختیاری).';
      info.classList.add('empty');
    } else {
      const bell = (T.bells || []).filter(function (b) { return b.time === val; })[0];
      info.innerHTML = HS.icon('clock', 14) + ' ساعت ' + (HS.faNum ? HS.faNum(val) : val) + (bell ? ' — ' + bell.name : ' (زنگ دلخواه)');
      info.classList.remove('empty');
    }
  }

  /* ---------- تأیید حذف رکورد (غیبت/تأخیر/…) ---------- */
  els('form[data-rdel]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (f.dataset.done) { return; }
      e.preventDefault();
      const t = f.dataset.title || 'حذف رکورد';
      const nm = f.dataset.name || '';
      const info = f.dataset.info || '';
      HS.confirm('<b>' + t + '</b><br>آیا از حذف رکورد «' + nm + '»' + (info ? ' — ' + info : '') + ' مطمئن هستید؟'
        + '<br><span class="small muted">این کار قابل بازگشت نیست.</span>', function () {
          f.dataset.done = '1';
          HS.loading(true, 'در حال حذف رکورد…');
          f.submit();
        }, { yes: 'بله، حذف کن', title: t });
    });
  });

  /* ---------- باز کردن پنجرهٔ ثبت ---------- */
  function openModal(kind, sid, name) {
    const m = document.getElementById('md-' + kind);
    if (!m) { return; }
    els('[data-sid]', m).forEach(function (i) { i.value = sid || ''; });
    els('[data-mname]', m).forEach(function (s) { s.textContent = name || 'دانش‌آموز'; });
    /* بازنشانی فیلدها برای ثبت بعدی */
    const tf = el('[data-time]', m);
    if (tf) { tf.value = (kind === 'delay') ? (T.now || '') : ''; }
    const ds = el('input[name="description"]', m);
    if (ds) { ds.value = ''; }
    const ls = el('input[name="lesson"]', m);
    if (ls && kind === 'absence') { ls.value = ''; }
    const dt = el('[data-offcheck]', m);
    if (dt) { dt.value = T.viewDate || T.dayRef || T.today; }
    paintBells(m);
    refreshOffNotes();
    HS.modal('md-' + kind, true);
    setTimeout(function () {
      const first = (kind === 'absence')
        ? el('input[name="lesson"]', m) : el('input[name="description"], [data-time]', m);
      if (first && first.focus) { first.focus(); }
    }, 120);
  }
  window.HS_TP_OPEN = openModal;

  /* ---------- اتصال دکمه‌های «ثبت …» هر ردیف ---------- */
  els('.tp-act[data-act]').forEach(function (b) {
    b.addEventListener('click', function () {
      openModal(b.dataset.act, b.dataset.sid, b.dataset.name);
    });
  });

  /* ---------- چیپ‌های زنگ ---------- */
  els('.bell-chip').forEach(function (c) {
    c.addEventListener('click', function () {
      const form = c.closest('form');
      const t = form ? el('[data-time]', form) : null;
      if (!t) { return; }
      t.value = c.dataset.timeSet || '';
      paintBells(form);
    });
  });
  els('form').forEach(function (f) {
    const t = el('[data-time]', f);
    if (t) {
      t.addEventListener('input', function () { paintBells(f); });
      t.addEventListener('change', function () { paintBells(f); });
    }
    const d = el('[data-offcheck]', f);
    if (d) { d.addEventListener('change', refreshOffNotes); }
  });

  /* ---------- بستن پنجره‌ها ---------- */
  els('.modal-bg').forEach(function (m) {
    m.addEventListener('click', function (e) {
      if (e.target === m) { HS.modal(m.id, false); }
    });
    els('[data-close]', m).forEach(function (b) { b.onclick = function () { HS.modal(m.id, false); }; });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { els('.modal-bg.show').forEach(function (m) { HS.modal(m.id, false); }); }
  });

  /* ---------- اعتبارسنجی پیش از ارسال ---------- */
  els('.modal-bg form').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      const sid = el('[data-sid]', f);
      if (sid && !sid.value) {
        e.preventDefault(); HS.toast('ابتدا دانش‌آموز را از فهرست کلاس انتخاب کنید.', 'err');
        return;
      }
      const dateInp = el('[data-offcheck]', f);
      if (dateInp && HS.isOffDay(dateInp.value) && !T.allowOff) {
        e.preventDefault();
        HS.toast('این روز (' + HS.jWeekday(dateInp.value) + ') تعطیل است و ثبت در آن مجاز نیست.', 'err', 'روز تعطیل');
        refreshOffNotes();
        return;
      }
      const t = el('[data-time]', f);
      if (t && t.value) {
        t.value = HS.enNum(t.value.trim());
        if (!/^([01]?\d|2[0-3]):([0-5]\d)$/.test(t.value)) {
          e.preventDefault();
          HS.toast('ساعت باید به شکل 07:45 وارد شود.', 'err'); t.classList.add('bad');
          return;
        }
      }
    });
  });

  /* ---------- جستجو در فهرست کلاس ---------- */
  const q = document.getElementById('tpSearch');
  if (q) {
    const rows = els('#tpTable tbody tr');
    const shown = document.getElementById('tpShown');
    function applyFilter() {
      const s = q.value.trim();
      let n = 0;
      rows.forEach(function (tr) {
        const ok = !s || (tr.dataset.search || '').indexOf(s) > -1;
        tr.style.display = ok ? '' : 'none';
        if (ok) { n++; }
      });
      if (shown) {
        shown.textContent = s === ''
          ? 'تعداد کلاس: ' + HS.faNum(rows.length) + ' دانش‌آموز'
          : 'نمایش ' + HS.faNum(n) + ' از ' + HS.faNum(rows.length) + ' دانش‌آموز برای «' + s + '»';
      }
    }
    q.addEventListener('input', applyFilter);
    applyFilter();
  }

  /* ---------- باز کردن خودکار پنجره پس از ثبت (PRG) ---------- */
  refreshOffNotes();
  if (T.open) {
    const st = (T.roster || []).filter(function (s) { return String(s.id) === String(T.openSid); })[0];
    if (st) {
      setTimeout(function () {
        openModal(T.open, st.id, st.name);
        if (T.again) { HS.toast('ثبت شد؛ می‌توانید مورد بعدی را همین‌جا ثبت کنید.', 'ok', 'ثبت انجام شد'); }
      }, 240);
    }
  }
})();
JS;

layout_foot([$tpJs]);
