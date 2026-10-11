<?php
/**
 * ============================================================================
 *  سامانه جامع مدیریت هنرستان شهید رجایی
 *  فایل: export.php — گزارش‌گیری و خروجی PDF / چاپ
 * ----------------------------------------------------------------------------
 *  حالت‌های گزارش:
 *    ) تأخیر  : ردیف | نام و نام خانوادگی | ستون هر روز بازه → علامت «ت»
 *    ) غیبت   : همان ساختار با علامت «غ»
 *    ) انضباط : ردیف | نام و نام خانوادگی | رشته | پایه | مورد ثبت‌شده | توضیحات
 *    ) پوششی  : همان ساختار جدول روزبه‌روز با علامت «پ»
 *
 *  قابلیت‌ها:
 *    • انتخاب بازه تاریخ شمسی با تقویم (یا بازه‌های آماده: امروز/این هفته/این ماه/ماه قبل)
 *    • سربرگ رسمی هنرستان در بالای گزارش (نام مدرسه، پایه، رشته، بازه تاریخ)
 *    • چاپ در اندازه A4 (افقی/عمودی)، اعداد فارسی، RTL کامل
 *    • خروجی CSV از جدول گزارش و دریافت «فایل نمونه» برای ورود گروهی دانش‌آموزان
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
require_perm('export_pdf');

$now    = hs_now();
$todayJ = jalali_today();
$errors = [];

/* ---------------------------------------------------------------------------
 * ۱) تولید «فایل نمونه» ورود گروهی دانش‌آموزان (CSV یا XLSX)
 * ------------------------------------------------------------------------ */
if (req('tab') === 'sample') {
    $header = ['نام', 'نام خانوادگی', 'پایه', 'رشته', 'تلفن پدر', 'تلفن مادر'];
    $sample = [
        ['علی', 'رضایی', 'دهم', 'کامپیوتر', '09121234567', '09127654321'],
        ['محمد', 'احمدی', 'یازدهم', 'الکتروتکنیک', '09121112233', '09124445566'],
        ['سینا', 'اسدی', 'دوازدهم', 'مکانیک', '09123334455', '09129998877'],
    ];
    if (req('format') === 'xlsx') {
        /* ساخت فایل xlsx ساده (بدون کتابخانه خارجی) با رشته‌های Inline */
        if (!class_exists('ZipArchive')) {
            flash_set('error', 'برای ساخت فایل نمونه xlsx باید افزونه zip روی سرور فعال باشد؛ از فرمت CSV استفاده کنید.');
            header('Location: export.php?tab=sample');
            exit;
        }
        $rows = array_merge([$header], $sample);
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $ri => $row) {
            $sheet .= '<row r="' . ($ri + 1) . '">';
            foreach ($row as $ci => $val) {
                $col = chr(65 + $ci);
                $sheet .= '<c r="' . $col . ($ri + 1) . '" t="inlineStr"><is><t>' . e((string)$val) . '</t></is></c>';
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="دانش‌آموزان" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="students-sample.xlsx"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
    send_csv('students-sample.csv', $header, $sample);
}

/* ---------------------------------------------------------------------------
 * ۲) پارامترهای گزارش
 * ------------------------------------------------------------------------ */
$mode        = in_array(req('mode'), ['delay', 'absence', 'discipline', 'dress', 'creators'], true) ? req('mode') : 'delay';
$viewAll     = hs_can_view_all();
$scopeLabel  = creator_scope_label();
$grade       = validate_grade(req('grade')) ?? '';
$field       = $grade !== '' ? (validate_field(req('field'), $grade) ?? '') : clean_text(req('field'), 40);
$quick       = clean_text(req('quick'), 20);
$onlyFlagged = req('only_flagged') === '1';
$from        = normalize_jalali(req('from'));
$to          = normalize_jalali(req('to'));

if ($quick !== '') {
    $workToday = hs_default_work_date();
    switch ($quick) {
        case 'today':
            $from = $to = $workToday;
            break;
        case 'yesterday':
            $from = $to = hs_last_working_day(jalali_add_days($workToday, -1));
            break;
        case 'week':
            $w = jalali_current_week();
            $from = $w['from_j'];
            $to   = $w['to_j'];
            break;
        case 'month':
            [$jy, $jm] = array_map('intval', explode('/', $todayJ));
            $from = sprintf('%04d/%02d/01', $jy, $jm);
            $to   = sprintf('%04d/%02d/%02d', $jy, $jm, jalali_month_days($jy, $jm));
            break;
        case 'prev_month':
            [$jy, $jm] = array_map('intval', explode('/', $todayJ));
            $jm--;
            if ($jm < 1) { $jm = 12; $jy--; }
            $from = sprintf('%04d/%02d/01', $jy, $jm);
            $to   = sprintf('%04d/%02d/%02d', $jy, $jm, jalali_month_days($jy, $jm));
            break;
        case 'last7':
            $from = jalali_add_days($todayJ, -6);
            $to   = $todayJ;
            break;
        case 'last30':
            $from = jalali_add_days($todayJ, -29);
            $to   = $todayJ;
            break;
    }
}
$from = $from ?? $todayJ;
$to   = $to ?? $from;
if (strcmp($from, $to) > 0) {
    [$from, $to] = [$to, $from];
}
$allDays = jalali_date_range($from, $to, 93);     // حداکثر ۹۳ روز (برای خوانایی جدول A4)
$days    = hs_working_days($allDays);             // پنجشنبه و جمعه (تعطیل) در گزارش شمرده نمی‌شوند
$offSkipped = count($allDays) - count($days);
if ($days === []) {
    $days = [hs_last_working_day($to)];
}

/* ---------------------------------------------------------------------------
 * ۳) واکشی دانش‌آموزان بر اساس فیلتر
 * ------------------------------------------------------------------------ */
$modeMeta = [
    'delay'      => ['title' => 'گزارش تأخیرها',        'mark' => 'ت', 'table' => 'delays',            'label' => 'تأخیر'],
    'absence'    => ['title' => 'گزارش غیبت‌ها',        'mark' => 'غ', 'table' => 'absences',          'label' => 'غیبت'],
    'dress'      => ['title' => 'گزارش تخلفات پوششی',   'mark' => 'پ', 'table' => 'dress_violations',  'label' => 'تخلف پوششی'],
    'discipline' => ['title' => 'گزارش موارد انضباطی',  'mark' => 'ا', 'table' => 'disciplines',       'label' => 'مورد انضباطی'],
    'creators'   => ['title' => 'گزارش تفکیکی ثبت‌کنندگان', 'mark' => '—', 'table' => 'delays',       'label' => 'ثبت‌کننده'],
];
$meta = $modeMeta[$mode];

$stWhere  = ['1=1'];
$stParams = [];
if ($grade !== '') { $stWhere[] = 'grade = ?'; $stParams[] = $grade; }
if ($field !== '') { $stWhere[] = 'field = ?'; $stParams[] = $field; }
/* اگر رشته انتخاب شده باشد ولی پایه مشخص نباشد، بر اساس همان رشته فیلتر می‌کنیم */
$stWhereSql = implode(' AND ', $stWhere);

$gFrom = jalali_to_gregorian_str($days[0]);
$gTo   = jalali_to_gregorian_str($days[count($days) - 1]);

$students = rows(
    'SELECT * FROM students WHERE ' . $stWhereSql . ' ORDER BY last_name ASC, first_name ASC',
    $stParams
);

/* نقشه رکوردها: [student_id][jdate] = true  (برای حالت‌های ستونی) */
$marks = [];
$disciplineRows = [];
[$cfDisc, $cpDisc] = creator_filter('d');          // دامنه ثبت‌کننده (کاربر عادی: فقط خودش)
[$cfMark, $cpMark] = creator_filter('r');           // شرط ثبت‌کننده برای جدول‌های روزبه‌روز

if ($mode === 'discipline') {
    $w = ['d.gdate BETWEEN ? AND ?', $cfDisc];
    $p = array_merge([$gFrom, $gTo], $cpDisc);
    $ids = array_column($students, 'id');
    if ($ids) {
        $w[] = 'd.student_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $p = array_merge($p, $ids);
    } else {
        $w[] = '1=0';
    }
    $disciplineRows = rows(
        'SELECT d.*, s.first_name, s.last_name, s.grade, s.field, a.full_name AS by_name
         FROM disciplines d JOIN students s ON s.id = d.student_id
         LEFT JOIN admins a ON a.id = d.created_by
         WHERE ' . implode(' AND ', $w) . ' ORDER BY d.gdate ASC, s.last_name ASC',
        $p
    );
} elseif ($mode !== 'creators') {
    $t = $meta['table'];
    $recs = rows(
        "SELECT r.student_id, r.jdate FROM {$t} r WHERE r.gdate BETWEEN ? AND ? AND {$cfMark}",
        array_merge([$gFrom, $gTo], $cpMark)
    );
    foreach ($recs as $r) {
        $marks[(int)$r['student_id']][(string)$r['jdate']] = true;
    }
    if ($onlyFlagged) {
        $students = array_values(array_filter($students, static fn($s) => isset($marks[(int)$s['id']])));
    }
}

/* آمار تفکیکی ثبت‌کنندگان (حالت «گزارش تفکیکی ثبت‌کنندگان») */
$creators = [];
if ($mode === 'creators') {
    $wf = $days[0];
    $wt = $days[count($days) - 1];
    $wfG = jalali_to_gregorian_str($wf);
    $wtG = jalali_to_gregorian_str($wt);
    /* دامنه: کاربر عادی فقط ردیف خودش؛ مدیر می‌تواند «فقط خودم» یا یک کاربر خاص را انتخاب کند */
    if (!hs_can_view_all()) {
        $cfC  = 'a.id = ?';
        $cpC  = [(int)(current_admin()['id'] ?? 0)];
    } else {
        $byC = clean_text(req('by'), 12);
        if ($byC === 'me' && current_admin()) {
            $cfC = 'a.id = ?'; $cpC = [(int)current_admin()['id']];
        } elseif ($byC !== '' && $byC !== 'all' && ctype_digit($byC)) {
            $cfC = 'a.id = ?'; $cpC = [(int)$byC];
        } else {
            $cfC = '1=1'; $cpC = [];
        }
    }
    $creators = rows(
        "SELECT a.id, a.full_name, a.username, a.role, a.is_super,
                (SELECT COUNT(*) FROM delays d WHERE d.created_by = a.id AND d.gdate BETWEEN ? AND ?) AS delays_c,
                (SELECT COUNT(*) FROM absences ab WHERE ab.created_by = a.id AND ab.gdate BETWEEN ? AND ?) AS absences_c,
                (SELECT COUNT(*) FROM disciplines dc WHERE dc.created_by = a.id AND dc.gdate BETWEEN ? AND ?) AS disc_c,
                (SELECT COUNT(*) FROM dress_violations v WHERE v.created_by = a.id AND v.gdate BETWEEN ? AND ?) AS dress_c
         FROM admins a
         WHERE a.is_active = 1 AND {$cfC}
         ORDER BY (delays_c + absences_c + disc_c + dress_c) DESC, a.full_name ASC",
        array_merge([$wfG, $wtG, $wfG, $wtG, $wfG, $wtG, $wfG, $wtG], $cpC)
    );
    /* اگر دامنه «همه» بود و ادمین غیرفعال هم داشت، فقط فعال‌ها نمایش داده می‌شوند (کافی است) */
}

/* ---------------------------------------------------------------------------
 * ۴) خروجی CSV از جدول گزارش (همان ساختار جدول چاپی)
 * ------------------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && req('action') === 'export_report_csv') {
    csrf_require();
    $csvHeader = [];
    if ($mode === 'creators') {
        $csvHeader = ['ردیف', 'ثبت‌کننده', 'نام کاربری', 'نقش', 'تأخیر', 'غیبت', 'انضباطی', 'پوششی', 'جمع'];
        $out = [];
        foreach ($creators as $i => $c) {
            $out[] = [$i + 1, $c['full_name'], $c['username'], role_label((string)$c['role']),
                      (int)$c['delays_c'], (int)$c['absences_c'], (int)$c['disc_c'], (int)$c['dress_c'],
                      (int)$c['delays_c'] + (int)$c['absences_c'] + (int)$c['disc_c'] + (int)$c['dress_c']];
        }
        send_csv('report-creators-' . str_replace('/', '-', $from) . '.csv', $csvHeader, $out);
    }
    if ($mode === 'discipline') {
        $csvHeader = ['ردیف', 'نام و نام خانوادگی', 'رشته', 'پایه', 'مورد ثبت‌شده', 'توضیحات'];
        $out = [];
        foreach ($disciplineRows as $i => $d) {
            $out[] = [
                $i + 1, $d['first_name'] . ' ' . $d['last_name'], $d['field'], $d['grade'],
                jalali_pretty($d['jdate']) . ' — ' . $d['type'] . ((int)$d['deduction'] > 0 ? ' (کسر ' . $d['deduction'] . ')' : ''),
                (string)$d['description'],
            ];
        }
    } else {
        $csvHeader = array_merge(['ردیف', 'نام و نام خانوادگی'], array_map(static fn($d) => (string)explode('/', $d)[2], $days), ['جمع']);
        $out = [];
        foreach ($students as $i => $s) {
            $row = [$i + 1, $s['first_name'] . ' ' . $s['last_name']];
            $sum = 0;
            foreach ($days as $d) {
                $has = isset($marks[(int)$s['id']][$d]);
                $row[] = $has ? $meta['mark'] : '';
                $sum += $has ? 1 : 0;
            }
            $row[] = $sum;
            $out[] = $row;
        }
    }
    send_csv('report-' . $mode . '-' . str_replace('/', '-', $from) . '.csv', $csvHeader, $out);
}

/* ---------------------------------------------------------------------------
 * ۵) آماده‌سازی داده جدول چاپی
 * ------------------------------------------------------------------------ */
$colTotals = array_fill_keys($days, 0);
$grandTotal = 0;
if ($mode !== 'discipline') {
    foreach ($students as $s) {
        foreach ($days as $d) {
            if (isset($marks[(int)$s['id']][$d])) {
                $colTotals[$d]++;
                $grandTotal++;
            }
        }
    }
}

$periodLabel = $from === $to ? 'تاریخ: ' . jalali_pretty($from) . ' (' . jalali_weekday_name($from) . ')'
    : 'بازه: ' . jalali_pretty($from) . ' الی ' . jalali_pretty($to) . ' (' . fa_num(count($days)) . ' روز)';
$orientation = $mode === 'discipline' ? 'portrait' : (count($days) > 20 ? 'landscape' : 'portrait');

/* ===== استایل برگه گزارش (مشترک بین صفحه گزارش و نمای چاپ) ===== */
$sheetCss = <<<'CSS'
/* ===== برگه رسمی گزارش ===== */
.sheet{background:#fff;color:#111827;border-radius:10px;box-shadow:var(--shadow);padding:22px;margin-top:18px;direction:rtl}
.sheet .letterhead{text-align:center;border-bottom:3px double #0f2a52;padding-bottom:12px;margin-bottom:6px}
.sheet .letterhead .school{font-size:21px;font-weight:900;color:#0f2a52;letter-spacing:.3px}
.sheet .letterhead .sub{font-size:13.4px;color:#334155;margin-top:6px}
.sheet .letterhead .meta{display:flex;justify-content:center;gap:18px;flex-wrap:wrap;margin-top:8px;font-size:13px;color:#0f2a52;font-weight:700}
.sheet .letterhead .meta span{background:#eef2f7;border:1px solid #e2e8f0;border-radius:20px;padding:4px 12px}
.sheet .report-title{text-align:center;font-weight:800;font-size:15.5px;color:#0f2a52;margin:14px 0 10px;
  background:#f1f5f9;border:1px solid #e2e8f0;border-radius:10px;padding:8px}
table.rep{width:100%;border-collapse:collapse;font-size:12.3px;table-layout:auto}
table.rep th,table.rep td{border:1px solid #94a3b8;padding:5px 6px;text-align:center;vertical-align:middle}
table.rep thead th{background:#0f2a52;color:#fff;font-weight:700;font-size:11.8px;white-space:nowrap;padding:6px 4px}
table.rep thead th .wd{display:block;font-weight:400;font-size:9.6px;opacity:.85;margin-top:2px}
table.rep tbody tr:nth-child(even){background:#f8fafc}
table.rep td.name{text-align:right;font-weight:700;color:#0f2a52;white-space:nowrap}
table.rep td.mark{font-weight:900;font-size:14px;color:#b91c1c}
table.rep td.idx{width:42px;color:#475569}
table.rep td.desc{text-align:right;font-size:11.6px;line-height:1.9;color:#334155}
table.rep tfoot td{background:#eef2f7;font-weight:800;color:#0f2a52;border-color:#94a3b8}
table.rep .sum{background:#fef3c7;font-weight:900}
.sheet .sign{margin-top:26px;display:flex;justify-content:space-between;font-size:12.6px;color:#334155}
.sheet .sign div{text-align:center;min-width:180px;border-top:1px dashed #94a3b8;padding-top:6px}
.sheet .pagefoot{margin-top:14px;text-align:center;font-size:11.4px;color:#64748b}
.sheet .no-data{padding:34px;text-align:center;color:#64748b;font-size:14px}
.legend-report{display:flex;gap:14px;flex-wrap:wrap;justify-content:center;margin-top:12px;font-size:12px;color:#334155}
.legend-report b{background:#0f2a52;color:#fff;border-radius:6px;padding:1px 8px;margin-inline-end:5px}
/* --- تنظیمات چاپ برگه (A4) --- */
@media print{
  *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
  .sheet{box-shadow:none!important;padding:0!important;margin:0!important;border-radius:0}
  table.rep{font-size:10.4px}
  table.rep th,table.rep td{padding:3px 3px}
  table.rep thead{display:table-header-group}
  table.rep tr{page-break-inside:avoid}
  .sheet .letterhead .school{font-size:18px}
  .sheet .sign{margin-top:16px}
}
CSS;

/* ===== استایل اختصاصی صفحه گزارش‌گیری (نوار تنظیمات) ===== */
$exportCss = <<<'CSS'
.toolbar{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end}
.quick-row{display:flex;gap:7px;flex-wrap:wrap;margin-top:10px}
.quick-row button{border:1.6px solid var(--gray-200);background:var(--white);border-radius:11px;padding:8px 13px;
  font-family:inherit;font-size:12.6px;font-weight:700;cursor:pointer;color:var(--gray-700);transition:var(--transition)}
.quick-row button:hover{border-color:var(--blue);color:var(--blue);transform:translateY(-2px)}
.quick-row button.on{background:var(--navy-bg);color:#fff;border-color:var(--navy-bg)}
.stat-mini{display:flex;gap:9px;flex-wrap:wrap}
.stat-mini .m{background:var(--white);border:1px solid var(--gray-200);border-radius:12px;padding:9px 14px;font-size:12.8px}
.stat-mini .m b{color:var(--navy);font-size:16px}
html[data-theme="dark"] .stat-mini .m b{color:#e8eefb}
.print-note{background:var(--amber-soft);border:1px solid #fde68a;border-radius:12px;padding:11px 14px;
  font-size:12.6px;color:#92400e;margin-top:12px;line-height:2}
/* --- اندازه و جهت کاغذ A4 (باید خارج از media print باشد) --- */
@page{size:A4 portrait;margin:10mm}
@media print{
  html,body{background:#fff!important}
  .sidebar,.topbar,.no-print,.tabs,.alert,.toasts,#toasts,.fab-menu,.fab-print,.crumb,.back-bar,.burger{display:none!important}
  .main{margin:0!important}
  .content{padding:0!important;max-width:none!important}
  .card{display:none!important}
}
CSS;

/* ---------------------------------------------------------------------------
 * ۶) آماده‌سازی HTML برگه گزارش در بافر + نمای چاپ مستقل (Print View)
 * ------------------------------------------------------------------------ */
ob_start();
?>
<!-- ================= برگه گزارش (قابل چاپ) ================= -->
<div class="sheet" id="reportSheet">
  <!-- سربرگ رسمی هنرستان -->
  <div class="letterhead">
    <div class="school"><?= e(HS_SCHOOL_NAME) ?></div>
    <div class="sub">سامانه جامع مدیریت آموزشی — <?= e($meta['title']) ?></div>
    <div class="meta">
      <span>پایه: <?= $grade !== '' ? e($grade) : 'همه پایه‌ها' ?></span>
      <span>رشته: <?= $field !== '' ? e($field) : 'همه رشته‌ها' ?></span>
      <span><?= e($periodLabel) ?></span>
      <span>ثبت‌کننده: <?= e($scopeLabel) ?></span>
      <span>تاریخ صدور: <?= jalali_pretty($now['jdate']) ?> — ساعت <?= fa_num($now['time']) ?></span>
    </div>
  </div>

  <div class="report-title"><?= e($meta['title']) ?> — تعداد دانش‌آموزان: <?= fa_num(count($students)) ?></div>

  <?php if ($mode === 'creators'): ?>
    <!-- ============ حالت ث: گزارش تفکیکی ثبت‌کنندگان ============ -->
    <?php if (!$creators): ?>
      <div class="no-data"><?= hs_icon('clipboard', 17) ?> در بازه انتخاب‌شده ثبت‌کننده‌ای یافت نشد.</div>
    <?php else: ?>
      <table class="rep">
        <thead>
          <tr>
            <th style="width:46px">ردیف</th>
            <th style="width:190px">ثبت‌کننده</th>
            <th style="width:110px">نام کاربری</th>
            <th style="width:110px">نقش</th>
            <th style="width:80px">تأخیر</th>
            <th style="width:80px">غیبت</th>
            <th style="width:90px">انضباطی</th>
            <th style="width:90px">پوششی</th>
            <th style="width:80px">جمع کل</th>
          </tr>
        </thead>
        <tbody>
        <?php $gSum = ['d' => 0, 'a' => 0, 'c' => 0, 'v' => 0]; ?>
        <?php foreach ($creators as $i => $c):
            $sum = (int)$c['delays_c'] + (int)$c['absences_c'] + (int)$c['disc_c'] + (int)$c['dress_c'];
            $gSum['d'] += (int)$c['delays_c']; $gSum['a'] += (int)$c['absences_c'];
            $gSum['c'] += (int)$c['disc_c'];   $gSum['v'] += (int)$c['dress_c'];
        ?>
          <tr>
            <td class="idx"><?= fa_num($i + 1) ?></td>
            <td class="name"><?= e($c['full_name']) ?><?= (int)$c['is_super'] === 1 ? ' (مدیر کل)' : '' ?></td>
            <td style="direction:ltr"><?= e($c['username']) ?></td>
            <td><?= e(role_label((string)$c['role'])) ?></td>
            <td class="mark" style="color:#b45309"><?= fa_num((int)$c['delays_c']) ?></td>
            <td class="mark"><?= fa_num((int)$c['absences_c']) ?></td>
            <td class="mark"><?= fa_num((int)$c['disc_c']) ?></td>
            <td class="mark" style="color:#1d4ed8"><?= fa_num((int)$c['dress_c']) ?></td>
            <td class="sum"><?= fa_num($sum) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="4">جمع کل ثبت‌های همه کاربران</td>
            <td><?= fa_num($gSum['d']) ?></td>
            <td><?= fa_num($gSum['a']) ?></td>
            <td><?= fa_num($gSum['c']) ?></td>
            <td><?= fa_num($gSum['v']) ?></td>
            <td><?= fa_num($gSum['d'] + $gSum['a'] + $gSum['c'] + $gSum['v']) ?></td>
          </tr>
        </tfoot>
      </table>
      <div class="legend-report">
        <span><b>تفکیک</b> سوابق هر کاربر (معلم، معاون، مسئول انضباط) جداگانه شمرده شده است.</span>
        <span><b>دسترسی</b> مشاهده این گزارش کامل مخصوص مدیر کل و دارندگان پرمیشن «مشاهده سوابق همه کاربران» است.</span>
      </div>
    <?php endif; ?>

  <?php elseif ($mode === 'discipline'): ?>
    <!-- ============ حالت پ: موارد انضباطی ============ -->
    <?php if (!$disciplineRows): ?>
      <div class="no-data"><?= hs_icon('clipboard', 17) ?> در بازه انتخاب‌شده هیچ مورد انضباطی ثبت نشده است.</div>
    <?php else: ?>
      <table class="rep">
        <thead>
          <tr>
            <th style="width:46px">ردیف</th>
            <th style="width:170px">نام و نام خانوادگی</th>
            <th style="width:110px">رشته</th>
            <th style="width:80px">پایه</th>
            <th style="width:210px">مورد ثبت‌شده</th>
            <th>توضیحات</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($disciplineRows as $i => $d): ?>
          <tr>
            <td class="idx"><?= fa_num($i + 1) ?></td>
            <td class="name"><?= e($d['first_name'] . ' ' . $d['last_name']) ?></td>
            <td><?= e($d['field']) ?></td>
            <td><?= e($d['grade']) ?></td>
            <td class="name" style="text-align:center">
              <?= jalali_pretty($d['jdate']) ?><br>
              <span style="font-weight:600"><?= e($d['type']) ?></span>
              <?php if ((int)$d['deduction'] > 0): ?><br><span class="badge red">کسر <?= fa_num((int)$d['deduction']) ?></span><?php endif; ?>
            </td>
            <td class="desc"><?= nl2br(e((string)$d['description'])) ?>
              <?php if (!empty($d['by_name'])): ?><br><span style="color:#94a3b8;font-size:10.8px">ثبت توسط: <?= e($d['by_name']) ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="legend-report">
        <span><b>توضیح</b> این گزارش فقط جهت مشاهده و بایگانی است و شامل علامت‌گذاری روزانه نمی‌شود.</span>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <!-- ============ حالت الف/ب/ت: جدول روزبه‌روز ============ -->
    <?php if (!$students): ?>
      <div class="no-data"><?= hs_icon('clipboard', 17) ?> با فیلترهای انتخابی هیچ دانش‌آموزی یافت نشد.</div>
    <?php else: ?>
      <table class="rep">
        <thead>
          <tr>
            <th rowspan="2" style="width:46px">ردیف</th>
            <th rowspan="2" style="width:185px">نام و نام خانوادگی</th>
            <?php foreach ($days as $d):
                [$dy, $dm, $dd] = array_map('intval', explode('/', $d));
            ?>
              <th style="min-width:30px"><?= fa_num($dd) ?><span class="wd"><?= e(mb_substr(jalali_weekday_name($d), 0, 3, 'UTF-8')) ?></span></th>
            <?php endforeach; ?>
            <th rowspan="2" style="width:52px">جمع</th>
          </tr>
          <tr>
            <?php foreach ($days as $d): ?>
              <th style="font-weight:400;font-size:10px;padding:2px">
                <?= fa_num((int)explode('/', $d)[1]) ?>/<?= fa_num((int)explode('/', $d)[0] % 100) ?>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($students as $i => $s):
            $sum = 0;
        ?>
          <tr>
            <td class="idx"><?= fa_num($i + 1) ?></td>
            <td class="name"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></td>
            <?php foreach ($days as $d):
                $has = isset($marks[(int)$s['id']][$d]);
                if ($has) { $sum++; }
            ?>
              <td class="mark" style="<?= $mode === 'delay' ? 'color:#b45309' : ($mode === 'dress' ? 'color:#1d4ed8' : '#b91c1c') ?>">
                <?= $has ? e($meta['mark']) : '' ?>
              </td>
            <?php endforeach; ?>
            <td class="sum"><?= $sum > 0 ? fa_num($sum) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2">جمع کل روزانه</td>
            <?php foreach ($days as $d): ?>
              <td><?= $colTotals[$d] > 0 ? fa_num($colTotals[$d]) : '—' ?></td>
            <?php endforeach; ?>
            <td><?= fa_num($grandTotal) ?></td>
          </tr>
        </tfoot>
      </table>
      <div class="legend-report">
        <span><b><?= e($meta['mark']) ?></b> <?= e($meta['label']) ?></span>
        <span>ستون «جمع»: تعداد <?= e($meta['label']) ?> هر دانش‌آموز در بازه</span>
        <span>سلول خالی به‌معنی نبود مورد در آن روز است</span>
        <span><?= hs_icon('info', 17) ?> <?= e(implode(' و ', hs_off_weekday_names())) ?> (تعطیل) در جدول شمرده نشده‌اند</span>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="sign">
    <div>مهر و امضای مدیر هنرستان</div>
    <div>امضای معاون / مسئول انضباط</div>
  </div>
  <div class="pagefoot">
    این گزارش توسط سامانه جامع مدیریت <?= e(HS_SCHOOL_NAME) ?> تولید شده است — نسخه <?= fa_num(HS_VERSION) ?>
  </div>
</div>
<?php
$sheetHtml = ob_get_clean();

$isPrintView  = req('print') === '1';
$orientParam  = in_array(req('orient'), ['portrait', 'landscape'], true) ? req('orient') : null;
$orientation  = $orientParam ?? (in_array($mode, ['discipline', 'creators'], true) ? 'portrait' : (count($days) > 14 ? 'landscape' : 'portrait'));
$pdfFileName  = 'گزارش-' . $meta['label'] . '-' . str_replace('/', '-', $from)
              . ($from !== $to ? '_تا_' . str_replace('/', '-', $to) : '');

if ($isPrintView) {
    /* ---- صفحه مستقل چاپ: فقط برگه گزارش، بدون سایدبار و منو ---- */
    $backQuery = $_GET;
    unset($backQuery['print']);
    $backUrl = 'export.php?' . http_build_query($backQuery);
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($pdfFileName) ?> — <?= e(HS_SCHOOL_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#0f2a52"/><text x="32" y="44" font-size="34" text-anchor="middle" fill="#fff">ه</text></svg>') ?>">
<style>
@font-face{font-family:"Vazirmatn";font-style:normal;font-weight:400 900;font-display:swap;
 src:url("https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/fonts/webfonts/Vazirmatn%5Bwght%5D.woff2") format("woff2")}
:root{--navy:#0f2a52;--navy-2:#123c6b;--blue:#2f6fed;--blue-soft:#e8f0ff;--gray-100:#eef2f7;--gray-200:#e2e8f0;
 --gray-300:#cbd5e1;--gray-500:#64748b;--gray-700:#334155;--white:#fff;--green:#16a34a;--green-soft:#e7f8ee;
 --amber:#f59e0b;--amber-soft:#fef3c7;--font:"Vazirmatn","Vazir",Tahoma,"Segoe UI",sans-serif;
 --shadow:0 10px 30px rgba(15,42,82,.08)}
*{box-sizing:border-box}
body{margin:0;background:#eef2f7;font-family:var(--font);direction:rtl;color:var(--gray-700);line-height:1.9;padding:16px}
.pv-bar{max-width:1040px;margin:0 auto 14px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:14px 16px;
 display:flex;gap:10px;align-items:center;flex-wrap:wrap;box-shadow:var(--shadow)}
.pv-bar b{color:var(--navy);font-size:14.5px;margin-inline-end:6px}
.btn{display:inline-flex;align-items:center;gap:8px;border:none;cursor:pointer;font-family:inherit;font-weight:800;
 font-size:13.4px;padding:11px 17px;border-radius:12px;background:var(--gray-100);color:var(--gray-700);transition:.2s;text-decoration:none}
.btn:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(15,42,82,.14)}
.btn-primary{background:linear-gradient(135deg,var(--navy-bg),var(--navy-bg-2));color:#fff}
.btn-blue{background:linear-gradient(135deg,var(--blue),#4f8cf7);color:#fff}
.btn-ghost{background:transparent;border:1.6px solid var(--gray-300)}
.hint{font-size:12.2px;color:var(--gray-500)}
.sheet{max-width:1040px;margin:0 auto}
@page{size:A4 <?= $orientation ?>;margin:10mm}
@media print{
  body{background:#fff!important;padding:0}
  .pv-bar,.no-print{display:none!important}
  .sheet{max-width:none;margin:0}
}
<?= $sheetCss ?>
</style>
</head>
<body>
  <div class="pv-bar no-print">
    <b><?= hs_icon('file', 14) ?> برگه آماده چاپ / ذخیره PDF</b>
    <button class="btn btn-primary" type="button" onclick="window.print()"><?= hs_icon('print', 16) ?> چاپ / ذخیره PDF</button>
    <a class="btn btn-ghost" href="<?= e($backUrl) ?>">↩ بازگشت به گزارش</a>
    <a class="btn btn-ghost" href="index.php"><?= hs_icon('home', 16) ?> منوی اصلی</a>
    <span class="hint">در پنجره چاپ، مقصد را روی «Save as PDF» بگذارید — اندازه: A4 <?= $orientation === 'landscape' ? 'افقی' : 'عمودی' ?></span>
    <span class="hint">پیشنهاد نام فایل: <code><?= e($pdfFileName) ?>.pdf</code></span>
  </div>
<?= $sheetHtml ?>
<script>
/* چاپ خودکار پس از بارگذاری کامل (در تب مستقل مجاز است) */
window.addEventListener('load', function () {
  setTimeout(function () { try { window.print(); } catch (e) {} }, 400);
});
document.addEventListener('keydown', function (e) {
  if (e.ctrlKey && e.key === 'p') { e.preventDefault(); window.print(); }
});
</script>
</body>
</html>
    <?php
    exit;
}

/* هر دو مجموعه استایل: نوار تنظیمات + برگه رسمی گزارش (ساختار چاپی) */
layout_head('گزارش‌گیری و خروجی PDF', 'export', [$exportCss, $sheetCss]);
?>

<!-- ================= نوار تنظیمات گزارش (در چاپ نمایش داده نمی‌شود) ================= -->
<div class="card no-print">
  <h3><?= hs_icon('file', 18) ?> ساخت گزارش و خروجی PDF / چاپ</h3>
  <form method="get" id="exportForm">
    <div class="toolbar">
      <div>
        <label class="fl">نوع گزارش</label>
        <select name="mode" id="exMode">
          <option value="delay"      <?= $mode === 'delay' ? 'selected' : '' ?>>(الف) تأخیر حضور (ت)</option>
          <option value="absence"    <?= $mode === 'absence' ? 'selected' : '' ?>>(ب) غیبت (غ)</option>
          <option value="discipline" <?= $mode === 'discipline' ? 'selected' : '' ?>>(پ) موارد انضباطی (مشاهده‌ای)</option>
          <option value="dress"      <?= $mode === 'dress' ? 'selected' : '' ?>>(ت) تخلف پوششی (پ)</option>
          <option value="creators"   <?= $mode === 'creators' ? 'selected' : '' ?>>(ث) گزارش تفکیکی ثبت‌کنندگان (مدیر، معاون، معلم)</option>
        </select>
      </div>
      <div>
        <label class="fl">پایه</label>
        <select name="grade" id="exGrade">
          <option value="">همه پایه‌ها</option>
          <?php foreach (HS_GRADES as $g): ?>
            <option value="<?= e($g) ?>" <?= $grade === $g ? 'selected' : '' ?>><?= e($g) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="fl">رشته</label>
        <select name="field" id="exField">
          <option value="">همه رشته‌ها</option>
          <?php foreach (all_fields() as $f): ?>
            <option value="<?= e($f) ?>" <?= $field === $f ? 'selected' : '' ?>><?= e($f) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint" id="exFieldHint"></span>
      </div>
      <div>
        <label class="fl">از تاریخ (شمسی)</label>
        <input type="text" class="hs-date" name="from" id="exFrom" value="<?= e($from) ?>">
      </div>
      <div>
        <label class="fl">تا تاریخ (شمسی)</label>
        <input type="text" class="hs-date" name="to" id="exTo" value="<?= e($to) ?>">
      </div>
      <div>
        <label class="fl">فقط دانش‌آموزان دارای رکورد</label>
        <select name="only_flagged">
          <option value="0" <?= $onlyFlagged ? '' : 'selected' ?>>نمایش همه دانش‌آموزان</option>
          <option value="1" <?= $onlyFlagged ? 'selected' : '' ?>>فقط دارای مورد ثبت‌شده</option>
        </select>
      </div>
      <?php if ($viewAll): ?>
      <div>
        <label class="fl">ثبت‌کننده (تفکیک سوابق)</label>
        <select name="by">
          <option value="">همه ثبت‌کنندگان</option>
          <option value="me" <?= req('by') === 'me' ? 'selected' : '' ?>>فقط سوابق خودم</option>
          <?php foreach (creator_options() as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= req('by') === (string)$c['id'] ? 'selected' : '' ?>>
              <?= e($c['full_name']) ?> — <?= e(role_label($c['role'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <span class="hint">سوابق هر کاربر جداست؛ در این فهرست انتخاب کنید.</span>
      </div>
      <?php endif; ?>
      <div>
        <label class="fl">جهت کاغذ (A4)</label>
        <select name="orient">
          <option value="auto" <?= !in_array(req('orient'), ['portrait', 'landscape'], true) ? 'selected' : '' ?>>خودکار (پیشنهادی)</option>
          <option value="portrait" <?= req('orient') === 'portrait' ? 'selected' : '' ?>>عمودی (Portrait)</option>
          <option value="landscape" <?= req('orient') === 'landscape' ? 'selected' : '' ?>>افقی (Landscape)</option>
        </select>
      </div>
      <div class="flex" style="align-items:end">
        <button class="btn btn-primary" type="submit"><?= hs_icon('search', 16) ?> ساخت گزارش</button>
      </div>
    </div>

    <!-- دکمه‌های خروجی PDF / چاپ -->
    <div class="flex mt10" style="gap:9px">
      <button class="btn btn-blue" type="button" id="btnPrint"><?= hs_icon('print', 16) ?> خروجی PDF / چاپ</button>
      <a class="btn btn-ghost" id="printTabLink" href="#" target="_blank" rel="noopener"><?= hs_icon('link', 16) ?> باز کردن برگه چاپ در تب جدید</a>
      <a class="btn btn-ghost" href="index.php"><?= hs_icon('home', 16) ?> بازگشت به منوی اصلی</a>
    </div>
    <div class="print-note">
      <b>راهنمای گرفتن PDF:</b> ۱) روی «خروجی PDF / چاپ» بزنید ۲) در پنجره چاپ، مقصد (Destination) را روی
      <b>Save as PDF</b> بگذارید ۳) ذخیره کنید. اگر پنجره چاپ باز نشد (مثلاً داخل پیش‌نمایش iframe یا با مسدودشدن پاپ‌آپ)،
      از دکمه «باز کردن برگه چاپ در تب جدید» استفاده کنید؛ در آن صفحه چاپ به‌صورت خودکار آغاز می‌شود.
    </div>
    <input type="hidden" name="quick" id="exQuick" value="<?= e($quick) ?>">
    <div class="quick-row">
      <button type="button" data-q="today"      class="<?= $quick === 'today' ? 'on' : '' ?>">امروز</button>
      <button type="button" data-q="yesterday"  class="<?= $quick === 'yesterday' ? 'on' : '' ?>">دیروز</button>
      <button type="button" data-q="week"       class="<?= $quick === 'week' ? 'on' : '' ?>">هفته جاری (شنبه تا جمعه)</button>
      <button type="button" data-q="month"      class="<?= $quick === 'month' ? 'on' : '' ?>">ماه جاری</button>
      <button type="button" data-q="prev_month" class="<?= $quick === 'prev_month' ? 'on' : '' ?>">ماه قبل</button>
      <button type="button" data-q="last7"      class="<?= $quick === 'last7' ? 'on' : '' ?>">۷ روز اخیر</button>
      <button type="button" data-q="last30"     class="<?= $quick === 'last30' ? 'on' : '' ?>">۳۰ روز اخیر</button>
    </div>
  </form>

  <div class="between mt10">
    <div class="stat-mini">
      <div class="m">دانش‌آموزان گزارش: <b><?= fa_num(count($students)) ?></b></div>
      <div class="m">روزهای کاری بازه: <b><?= fa_num(count($days)) ?></b></div>
      <?php if ($offSkipped > 0): ?>
        <div class="m" style="border-color:#fde68a;background:var(--amber-soft)">
          <?= hs_icon('info', 17) ?> روزهای تعطیل حذف‌شده: <b><?= fa_num($offSkipped) ?></b>
          <span class="small">(<?= e(implode(' و ', hs_off_weekday_names())) ?>)</span></div>
      <?php endif; ?>
      <div class="m">دامنه: <b><?= e($scopeLabel) ?></b></div>
      <?php if ($mode !== 'discipline'): ?>
        <div class="m">مجموع <?= e($meta['label']) ?>: <b><?= fa_num($grandTotal) ?></b></div>
      <?php else: ?>
        <div class="m">موارد ثبت‌شده: <b><?= fa_num(count($disciplineRows)) ?></b></div>
      <?php endif; ?>
    </div>
    <div class="flex">
      <form method="post" style="margin:0" class="no-print">
        <?= csrf_field() ?><input type="hidden" name="action" value="export_report_csv">
        <input type="hidden" name="mode" value="<?= e($mode) ?>">
        <input type="hidden" name="grade" value="<?= e($grade) ?>">
        <input type="hidden" name="field" value="<?= e($field) ?>">
        <input type="hidden" name="from" value="<?= e($from) ?>">
        <input type="hidden" name="to" value="<?= e($to) ?>">
        <button class="btn btn-sm btn-green" type="submit"><?= hs_icon('chart', 16) ?> خروجی CSV همین جدول</button>
      </form>
      <a class="btn btn-sm btn-ghost" href="export.php?tab=sample&format=csv"><?= hs_icon('file', 16) ?> فایل نمونه CSV</a>
      <a class="btn btn-sm btn-ghost" href="export.php?tab=sample&format=xlsx"><?= hs_icon('file', 16) ?> فایل نمونه Excel</a>
    </div>
  </div>
</div>

<button class="fab-print no-print" type="button" id="fabPrint" title="خروجی PDF / چاپ"><?= hs_icon('print', 16) ?> خروجی PDF</button>

<?= $sheetHtml ?>  <!-- برگه گزارش (از بافر) -->

<!-- مودال راهنمای چاپ در صورت مسدود شدن پنجره جدید -->
<div class="modal-bg no-print" id="printHelp">
  <div class="modal" style="max-width:560px">
    <header><span style="font-size:20px"><?= hs_icon('print', 17) ?></span><h3>گرفتن خروجی PDF</h3>
      <button class="btn btn-sm btn-ghost" style="margin-inline-start:auto" onclick="HS.modal('printHelp', false)"><?= hs_icon('close', 16) ?></button></header>
    <div class="mbody">
      <div class="alert info"><span><?= hs_icon('info', 17) ?></span><span>
        مرورگر اجازه باز کردن پنجره جدید را نداد (احتمالاً پاپ‌آپ مسدود است یا صفحه داخل iframe پیش‌نمایش اجرا می‌شود).
        یکی از دو راه زیر را انتخاب کنید:
      </span></div>
      <p style="line-height:2.2;margin:0 0 10px">
        <b>راه ۱ — پیشنهادی:</b> لینک زیر را در تب جدید باز کنید؛ برگه چاپ به‌صورت خودکار آماده می‌شود:<br>
        <a class="btn btn-blue btn-sm" id="printHelpOpen" href="#" target="_blank" rel="noopener"><?= hs_icon('link', 16) ?> باز کردن برگه چاپ</a>
      </p>
      <p style="line-height:2.2;margin:0 0 6px"><b>راه ۲ — کپی آدرس:</b></p>
      <div class="flex">
        <input type="text" id="printHelpUrl" readonly dir="ltr" style="flex:1">
        <button class="btn btn-sm btn-ghost" type="button" id="printHelpCopy"><?= hs_icon('clipboard', 16) ?> کپی</button>
      </div>
      <div class="hint mt10">در آن صفحه، دکمه «<?= hs_icon('print', 17) ?> چاپ / ذخیره PDF» یا کلیدهای <b>Ctrl+P</b> را بزنید و مقصد را «Save as PDF» انتخاب کنید.</div>
    </div>
  </div>
</div>

<?php
$exportJs = <<<'JS'
/* ===== اسکریپت پنل گزارش‌گیری ===== */
const FIELDS_BY_GRADE_EX = __FIELDS_BY_GRADE__;
const MODE_LABEL = { delay: 'تأخیر', absence: 'غیبت', discipline: 'موارد انضباطی', dress: 'تخلف پوششی' };

/** آیا صفحه داخل iframe (پیش‌نمایش) اجرا می‌شود؟ */
function isEmbedded() {
  try { return window.self !== window.top; } catch (e) { return true; }
}

/** ساخت آدرس برگه چاپ مستقل با همان فیلترهای فعلی فرم */
function printViewUrl() {
  const q = new URLSearchParams(location.search);
  const f = document.getElementById('exportForm');
  if (f) {
    ['mode', 'grade', 'field', 'from', 'to', 'only_flagged', 'quick', 'orient'].forEach(function (n) {
      const el = f.querySelector('[name=' + n + ']');
      if (!el) return;
      if (n === 'orient' && el.value === 'auto') { q.delete('orient'); return; }
      if (el.value === '') { q.delete(n); return; }
      q.set(n, el.value);
    });
  }
  q.set('print', '1');
  return 'export.php?' + q.toString();
}

/** نمایش راهنمای چاپ در صورت مسدود شدن پنجره جدید */
function showPrintHelp(url) {
  const a = document.getElementById('printHelpOpen');
  const i = document.getElementById('printHelpUrl');
  if (a) a.href = url;
  if (i) i.value = location.origin + location.pathname.replace(/[^/]*$/, '') + url;
  HS.modal('printHelp', true);
}

/** باز کردن برگه چاپ در تب جدید با مدیریت خطا */
function openPrintTab() {
  const url = printViewUrl();
  let w = null;
  try { w = window.open(url, '_blank'); } catch (e) { w = null; }
  if (!w || w.closed || typeof w.closed === 'undefined') {
    showPrintHelp(url);
  } else {
    try { w.focus(); } catch (e) {}
    HS.toast('برگه چاپ در تب جدید باز شد؛ در پنجره چاپ «Save as PDF» را انتخاب کنید.', 'ok', 'خروجی PDF');
  }
}

/** دکمه اصلی: چاپ مستقیم در تب جاری یا هدایت به برگه چاپ */
function printReport() {
  /* تزریق قانون @page مناسب قبل از چاپ (در مرورگرهای مدرن اعمال می‌شود) */
  const landscape = document.getElementById('exMode').value !== 'discipline' && __DAYS_COUNT__ > 14;
  let st = document.getElementById('dynPageRule');
  if (!st) {
    st = document.createElement('style');
    st.id = 'dynPageRule';
    document.head.appendChild(st);
  }
  st.textContent = '@page{size:A4 ' + (landscape ? 'landscape' : 'portrait') + ';margin:10mm}';

  if (!isEmbedded()) {
    HS.toast('پنجره چاپ باز می‌شود؛ مقصد را «Save as PDF» بگذارید.', 'info', 'خروجی PDF');
    setTimeout(() => { try { window.print(); } catch (e) { openPrintTab(); } }, 300);
    return;
  }
  /* داخل iframe: window.print محدود است → برگه چاپ در تب جدید */
  openPrintTab();
}

document.addEventListener('DOMContentLoaded', function () {
  const exGrade = document.getElementById('exGrade');
  if (exGrade) exGrade.addEventListener('change', function () {
    const list = FIELDS_BY_GRADE_EX[exGrade.value];
    const fs = document.getElementById('exField');
    if (!list) { return; }
    const cur = fs.value;
    fs.innerHTML = '<option value="">همه رشته‌ها</option>' + list.map(f => '<option value="' + f + '">' + f + '</option>').join('');
    if (list.indexOf(cur) > -1) fs.value = cur;
    document.getElementById('exFieldHint').innerHTML = 'رشته‌های پایه <b>' + exGrade.value + '</b> نمایش داده می‌شود.';
  });

  /* بازه‌های آماده */
  HS.$$('.quick-row button').forEach(b => b.addEventListener('click', function () {
    document.getElementById('exQuick').value = b.dataset.q;
    HS.loading(true, 'در حال ساخت گزارش…');
    document.getElementById('exportForm').submit();
  }));

  /* ذخیره خودکار گزارش چاپ‌شده */
  window.addEventListener('afterprint', () => document.body.classList.remove('print-landscape'));

  /* راهنما بسته به نوع گزارش */
  const m = document.getElementById('exMode');
  if (m) m.addEventListener('change', function () {
    HS.toast('گزارش «' + MODE_LABEL[m.value] + '» انتخاب شد؛ دکمه «ساخت گزارش» را بزنید.', 'info', 'نوع گزارش');
  });

  /* دکمه‌های چاپ */
  const bp = document.getElementById('btnPrint');
  if (bp) bp.addEventListener('click', printReport);
  const link = document.getElementById('printTabLink');
  function syncLink() { if (link) link.href = printViewUrl(); }
  syncLink();
  const exForm = document.getElementById('exportForm');
  if (exForm) {
    exForm.addEventListener('change', syncLink);
    exForm.addEventListener('input', syncLink);
  }
  const copyBtn = document.getElementById('printHelpCopy');
  if (copyBtn) copyBtn.addEventListener('click', function () {
    const i = document.getElementById('printHelpUrl');
    i.select();
    try {
      navigator.clipboard.writeText(i.value).then(
        () => HS.toast('آدرس کپی شد.', 'ok'),
        () => { document.execCommand('copy'); HS.toast('آدرس کپی شد.', 'ok'); });
    } catch (e) { document.execCommand('copy'); }
  });
  /* دکمه‌های شناور میان‌بر */
  const fabPrint = document.getElementById('fabPrint');
  if (fabPrint) fabPrint.addEventListener('click', printReport);
});
JS;
/* تزریق داده‌های PHP در اسکریپت (جایگزینی Placeholder ها — باگ رفع‌شده) */
$exportJs = str_replace(
    ['__FIELDS_BY_GRADE__', '__DAYS_COUNT__'],
    [json_encode(HS_FIELDS_BY_GRADE, JSON_UNESCAPED_UNICODE), (string)count($days)],
    $exportJs
);
layout_end([$exportJs]);
