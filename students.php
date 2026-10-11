<?php
/**
 * ============================================================================
 *  سامانه جامع مدیریت هنرستان شهید رجایی
 *  فایل: students.php — مدیریت دانش‌آموزان
 * ----------------------------------------------------------------------------
 *  قابلیت‌ها:
 *    • افزودن دانش‌آموز از سه طریق: آپلود Excel (xlsx)، فایل متنی/CSV و ورود دستی
 *    • جستجوی پیشرفته (نام، نام خانوادگی، پایه، رشته) + فیلتر ترکیبی + مرتب‌سازی ستون‌ها
 *    • صفحه‌بندی (Pagination) کامل
 *    • اعتبارسنجی سمت کلاینت و سرور (نام فارسی، شماره تماس ۱۱ رقمی، تطابق رشته با پایه)
 *    • جلوگیری از ورود داده تکراری و گزارش خطای تفصیلی فایل آپلودی
 *    • قانون دامنه: رشته «کامپیوتر» فقط برای پایه‌های دهم و یازدهم (Dropdown داینامیک)
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
require_perm('view_students');

$errors   = [];
$importReport = null;      // گزارش آپلود گروهی
$tab      = req('tab', 'list');           // list | add | edit | import
$editId   = req_int('edit');
$editRow  = null;

/* ---------------------------------------------------------------------------
 * ۱) اعتبارسنجی داده یک دانش‌آموز (مشترک بین ورود دستی و گروهی)
 * ------------------------------------------------------------------------ */
function validate_student_payload(array $in): array
{
    $out = [];
    $errs = [];

    $first = validate_name($in['first_name'] ?? null);
    if ($first === null) {
        $errs['first_name'] = 'نام نامعتبر است (حداقل ۲ حرف فارسی، بدون عدد و کاراکتر خاص).';
    } else {
        $out['first_name'] = $first;
    }

    $last = validate_name($in['last_name'] ?? null);
    if ($last === null) {
        $errs['last_name'] = 'نام خانوادگی نامعتبر است (حداقل ۲ حرف فارسی).';
    } else {
        $out['last_name'] = $last;
    }

    $grade = validate_grade($in['grade'] ?? null);
    if ($grade === null) {
        $errs['grade'] = 'پایه را از فهرست (دهم / یازدهم / دوازدهم) انتخاب کنید.';
    } else {
        $out['grade'] = $grade;
    }

    if ($grade !== null) {
        $field = validate_field($in['field'] ?? null, $grade);
        if ($field === null) {
            $allowed = implode('، ', fields_for_grade($grade));
            $errs['field'] = "رشته انتخاب‌شده برای پایه {$grade} مجاز نیست. رشته‌های مجاز: {$allowed}.";
            if ($grade === 'دوازدهم' && (string)($in['field'] ?? '') === 'کامپیوتر') {
                $errs['field'] = 'رشته «کامپیوتر» در پایه دوازدهم وجود ندارد؛ لطفاً رشته دیگری انتخاب کنید.';
            }
        } else {
            $out['field'] = $field;
        }
    }

    /* شمارهٔ تلفن اختیاری است: خالی مجاز است، ولی اگر وارد شد باید ۱۱ رقم و با ۰۹ باشد */
    $fpRaw = clean_text($in['father_phone'] ?? '', 20);
    if ($fpRaw === '') {
        $out['father_phone'] = '';
    } else {
        $fp = validate_phone($fpRaw);
        if ($fp === null) {
            $errs['father_phone'] = 'شماره تلفن پدر باید ۱۱ رقم و با ۰۹ شروع شود (مثال: ۰۹۱۲۳۴۵۶۷۸۹) — یا آن را خالی بگذارید.';
        } else {
            $out['father_phone'] = $fp;
        }
    }

    $mpRaw = clean_text($in['mother_phone'] ?? '', 20);
    if ($mpRaw === '') {
        $out['mother_phone'] = '';
    } else {
        $mp = validate_phone($mpRaw);
        if ($mp === null) {
            $errs['mother_phone'] = 'شماره تلفن مادر باید ۱۱ رقم و با ۰۹ شروع شود — یا آن را خالی بگذارید.';
        } else {
            $out['mother_phone'] = $mp;
        }
    }

    return [$out, $errs];
}

/** بررسی تکراری بودن دانش‌آموز در پایگاه داده */
function student_exists(array $d, ?int $exceptId = null): bool
{
    $sql = 'SELECT COUNT(*) FROM students WHERE first_name = ? AND last_name = ? AND grade = ? AND field = ?';
    $params = [$d['first_name'], $d['last_name'], $d['grade'], $d['field']];
    if ($exceptId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptId;
    }
    return (int)one($sql, $params) > 0;
}

/* ---------------------------------------------------------------------------
 * ۲) پردازش فرم‌ها
 * ------------------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_require();
    $action = req('action');

    /* ------------------------- افزودن دانش‌آموز ------------------------- */
    if ($action === 'add_student') {
        require_perm('add_student');
        [$data, $errs] = validate_student_payload([
            'first_name'   => req('first_name'),
            'last_name'    => req('last_name'),
            'grade'        => req('grade'),
            'field'        => req('field'),
            'father_phone' => req('father_phone'),
            'mother_phone' => req('mother_phone'),
        ]);
        if ($errs) {
            $errors = array_merge($errors, array_values($errs));
            $tab = 'add';
        } elseif (student_exists($data)) {
            $errors[] = 'این دانش‌آموز (نام، نام خانوادگی، پایه و رشته یکسان) قبلاً ثبت شده است.';
            $tab = 'add';
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $id = db_insert('students', $data);
            log_activity('افزودن دانش‌آموز', $data['first_name'] . ' ' . $data['last_name'] . ' — #' . $id);
            flash_set('success', 'دانش‌آموز «' . $data['first_name'] . ' ' . $data['last_name'] . '» با موفقیت ثبت شد.');
            header('Location: students.php');
            exit;
        }
    }

    /* ------------------------- ویرایش دانش‌آموز ------------------------- */
    if ($action === 'edit_student') {
        require_perm('edit_student');
        $id = req_int('id');
        $target = row('SELECT * FROM students WHERE id = ?', [$id]);
        if (!$target) {
            $errors[] = 'دانش‌آموز مورد نظر یافت نشد.';
        } else {
            [$data, $errs] = validate_student_payload([
                'first_name'   => req('first_name'),
                'last_name'    => req('last_name'),
                'grade'        => req('grade'),
                'field'        => req('field'),
                'father_phone' => req('father_phone'),
                'mother_phone' => req('mother_phone'),
            ]);
            if ($errs) {
                $errors = array_merge($errors, array_values($errs));
                $tab = 'edit';
                $editId = $id;
            } elseif (student_exists($data, $id)) {
                $errors[] = 'دانش‌آموز دیگری با همین مشخصات وجود دارد.';
                $tab = 'edit';
                $editId = $id;
            } else {
                db_update('students', $data, ['id' => $id]);
                log_activity('ویرایش دانش‌آموز', $data['first_name'] . ' ' . $data['last_name'] . ' — #' . $id);
                flash_set('success', 'اطلاعات دانش‌آموز ویرایش شد.');
                header('Location: students.php' . (req('back') ? '?' . req('back') : ''));
                exit;
            }
        }
    }

    /* ------------------------- حذف دانش‌آموز ------------------------- */
    if ($action === 'delete_student') {
        require_perm('delete_student');
        $id = req_int('id');
        $target = row('SELECT * FROM students WHERE id = ?', [$id]);
        if (!$target) {
            $errors[] = 'دانش‌آموز یافت نشد.';
        } else {
            /* حذف رکوردهای مرتبط (در صورت پشتیبانی نشدن ON DELETE CASCADE) */
            foreach (['delays', 'absences', 'disciplines', 'dress_violations'] as $t) {
                try { db_delete($t, ['student_id' => $id]); } catch (Throwable $e) {}
            }
            db_delete('students', ['id' => $id]);
            log_activity('حذف دانش‌آموز', $target['first_name'] . ' ' . $target['last_name'] . ' — #' . $id);
            flash_set('success', 'دانش‌آموز «' . $target['first_name'] . ' ' . $target['last_name'] . '» و تمام سوابق او حذف شد.');
        }
        header('Location: students.php');
        exit;
    }

    /* ------------------------- آپلود گروهی (Excel/CSV/TXT) ------------------------- */
    if ($action === 'import_students') {
        require_perm('add_student');
        $tab = 'import';
        $file = $_FILES['import_file'] ?? null;

        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'فایلی انتخاب نشده است.';
        } elseif ((int)$file['error'] !== UPLOAD_ERR_OK) {
            $map = [
                UPLOAD_ERR_INI_SIZE  => 'حجم فایل از حد مجاز سرور بیشتر است.',
                UPLOAD_ERR_FORM_SIZE => 'حجم فایل از حد مجاز فرم بیشتر است.',
                UPLOAD_ERR_PARTIAL   => 'فایل به‌طور کامل آپلود نشد؛ لطفاً دوباره تلاش کنید.',
                UPLOAD_ERR_NO_TMP_DIR=> 'پوشه موقت سرور در دسترس نیست.',
                UPLOAD_ERR_CANT_WRITE=> 'نوشتن فایل روی سرور ممکن نشد.',
            ];
            $errors[] = $map[(int)$file['error']] ?? 'خطای نامشخص در آپلود فایل.';
        } elseif ((int)$file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'حجم فایل نباید بیشتر از ۵ مگابایت باشد.';
        } else {
            $name = (string)$file['name'];
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $allowedExt = ['xlsx', 'xls', 'txt', 'csv', 'tsv'];
            if (!in_array($ext, $allowedExt, true)) {
                $errors[] = 'فرمت فایل پشتیبانی نمی‌شود. فرمت‌های مجاز: xlsx، xls، csv، txt، tsv';
            } else {
                $tmp = (string)$file['tmp_name'];
                $isZip = false;
                $fh = @fopen($tmp, 'rb');
                if ($fh) {
                    $sig = (string)fread($fh, 4);
                    fclose($fh);
                    $isZip = str_starts_with($sig, "PK\x03\x04");
                }
                try {
                    if ($isZip) {
                        $rawRows = read_xlsx_rows($tmp);
                    } elseif (in_array($ext, ['txt', 'csv', 'tsv'], true)) {
                        $rawRows = read_text_rows($tmp);
                    } elseif ($ext === 'xls') {
                        /* فایل‌های xls قدیمی (OLE) بدون کتابخانه قابل خواندن نیستند */
                        throw new RuntimeException('فایل‌های xls قدیمی پشتیبانی نمی‌شوند. لطفاً در Excel گزینه «Save As → xlsx» یا «CSV UTF-8» را انتخاب کنید.');
                    } else {
                        throw new RuntimeException('ساختار فایل قابل شناسایی نیست. فایل را با فرمت xlsx یا CSV ذخیره کنید.');
                    }

                    $parsed = parse_students_rows($rawRows);
                    $rows   = $parsed['rows'];
                    $parseErrors = $parsed['errors'];

                    $inserted = 0;
                    $dupes    = 0;
                    $dupList  = [];
                    foreach ($rows as $r) {
                        if (student_exists($r)) {
                            $dupes++;
                            $dupList[] = $r['first_name'] . ' ' . $r['last_name'] . ' (' . $r['grade'] . ' — ' . $r['field'] . ')';
                            continue;
                        }
                        $r['created_at'] = date('Y-m-d H:i:s');
                        db_insert('students', $r);
                        $inserted++;
                    }
                    $importReport = [
                        'file'      => $name,
                        'totalRows' => count($rawRows),
                        'valid'     => count($rows),
                        'inserted'  => $inserted,
                        'dupes'     => $dupes,
                        'dupList'   => array_slice($dupList, 0, 25),
                        'errors'    => array_slice($parseErrors, 0, 40),
                        'errorCount'=> count($parseErrors),
                    ];
                    if ($inserted > 0) {
                        log_activity('ورود گروهی دانش‌آموزان', "فایل: {$name} — افزوده‌شده: {$inserted} — تکراری: {$dupes}");
                        flash_set('success', fa_num($inserted) . ' دانش‌آموز از فایل «' . $name . '» افزوده شد.');
                    } else {
                        flash_set('warning', 'هیچ دانش‌آموز جدیدی افزوده نشد؛ گزارش کامل را در پایین صفحه ببینید.');
                    }
                } catch (Throwable $ex) {
                    $errors[] = $ex->getMessage();
                }
            }
        }
    }

    /* ------------------------- حذف گروهی بر اساس فیلتر ------------------------- */
    if ($action === 'bulk_delete') {
        require_perm('delete_student');
        $grade = validate_grade(req('grade'));
        $field = $grade ? validate_field(req('field'), $grade) : null;
        if (!$grade) {
            $errors[] = 'برای حذف گروهی، انتخاب پایه الزامی است.';
        } else {
            $sql = 'SELECT id FROM students WHERE grade = ?';
            $params = [$grade];
            if ($field) {
                $sql .= ' AND field = ?';
                $params[] = $field;
            }
            $ids = array_column(rows($sql, $params), 'id');
            foreach ($ids as $sid) {
                foreach (['delays', 'absences', 'disciplines', 'dress_violations'] as $t) {
                    try { db_delete($t, ['student_id' => $sid]); } catch (Throwable $e) {}
                }
                db_delete('students', ['id' => $sid]);
            }
            log_activity('حذف گروهی دانش‌آموزان', 'پایه ' . $grade . ($field ? ' — رشته ' . $field : '') . ' — تعداد: ' . count($ids));
            flash_set('success', fa_num(count($ids)) . ' دانش‌آموز ' . ($field ? 'رشته ' . $field : '') . ' پایه ' . $grade . ' حذف شدند.');
        }
        header('Location: students.php');
        exit;
    }

    /* ------------------------- عملیات گروهی روی موارد انتخاب‌شده ------------------------- */
    if ($action === 'bulk_selected') {
        $op  = clean_text(req('op'), 20);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));

        /* عملیات‌های ثبت مورد → انتقال به صفحهٔ ثبت گروهی */
        if (in_array($op, ['delay', 'absence', 'discipline', 'dress'], true)) {
            require_perm('register_' . $op);
            if (!$ids) {
                flash_set('err', 'هیچ دانش‌آموزی انتخاب نشده بود.');
                header('Location: students.php');
                exit;
            }
            header('Location: records.php?tab=bulk&kind=' . urlencode($op) . '&ids=' . urlencode(implode(',', $ids)));
            exit;
        }

        if ($op === 'delete') {
            require_perm('delete_student');
        } elseif ($op === 'grade' || $op === 'field') {
            require_perm('edit_student');
        } else {
            $errors[] = 'عملیات گروهی انتخاب‌شده نامعتبر است.';
        }

        if ($errors === [] && !$ids) {
            $errors[] = 'برای اجرای عملیات گروهی، حداقل یک دانش‌آموز را تیک بزنید.';
        }

        if ($errors === [] && $op === 'delete') {
            $names = [];
            foreach ($ids as $sid) {
                $t = row('SELECT first_name, last_name FROM students WHERE id = ?', [$sid]);
                if (!$t) { continue; }
                $names[] = $t['first_name'] . ' ' . $t['last_name'];
                foreach (['delays', 'absences', 'disciplines', 'dress_violations'] as $tb) {
                    try { db_delete($tb, ['student_id' => $sid]); } catch (Throwable $e) {}
                }
                db_delete('students', ['id' => $sid]);
            }
            log_activity('حذف گروهی انتخاب‌شده‌ها', 'تعداد: ' . count($names) . ' — ' . mb_substr(implode('، ', $names), 0, 200));
            flash_set('success', fa_num(count($names)) . ' دانش‌آموز به‌همراه همهٔ سوابقشان حذف شد.');
            if ($names) {
                flash_set('info', 'حذف‌شده‌ها: ' . implode('، ', array_slice($names, 0, 12)) . (count($names) > 12 ? ' و …' : ''));
            }
            header('Location: students.php');
            exit;
        }

        if ($errors === [] && ($op === 'grade' || $op === 'field')) {
            $newGrade = validate_grade(req('new_grade'));
            $rawField = clean_text(req('new_field'), 40);
            $changed  = 0;
            $skipped  = [];

            foreach ($ids as $sid) {
                $st = row('SELECT * FROM students WHERE id = ?', [$sid]);
                if (!$st) { continue; }
                $grade = $st['grade'];
                $field = $st['field'];
                $newF  = null;

                if ($op === 'grade') {
                    if ($rawField !== '') {
                        /* رشتهٔ همراه انتخاب شده: باید با پایهٔ جدید سازگار باشد */
                        $newF = validate_field($rawField, $newGrade);
                        if (!$newF) {
                            $skipped[] = $st['first_name'] . ' ' . $st['last_name'] . ' (رشته با پایه هم‌خوان نیست)';
                            continue;
                        }
                    } elseif (!hs_field_ok($newGrade, $field)) {
                        /* قانون دامنه: مثلاً کامپیوتر در دوازدهم وجود ندارد */
                        $skipped[] = $st['first_name'] . ' ' . $st['last_name'] . ' (رشته ' . $field . ' در پایه ' . $newGrade . ' مجاز نیست)';
                        continue;
                    }
                    $grade = $newGrade;
                    if ($newF) { $field = $newF; }
                } else { /* تغییر رشته */
                    $newF = validate_field($rawField, $grade);
                    if (!$newF) {
                        $skipped[] = $st['first_name'] . ' ' . $st['last_name'] . ' (رشته ' . $rawField . ' در پایه ' . $grade . ' مجاز نیست)';
                        continue;
                    }
                    $field = $newF;
                }

                /* جلوگیری از ایجاد رکورد تکراری پس از تغییر */
                if (student_exists([
                    'first_name' => $st['first_name'], 'last_name' => $st['last_name'],
                    'grade' => $grade, 'field' => $field,
                ], (int)$st['id'])) {
                    $skipped[] = $st['first_name'] . ' ' . $st['last_name'] . ' (دانش‌آموز مشابه با این پایه/رشته وجود دارد)';
                    continue;
                }

                db_update('students', ['grade' => $grade, 'field' => $field], ['id' => $sid]);
                $changed++;
                log_activity($op === 'grade' ? 'تغییر پایه گروهی' : 'تغییر رشته گروهی',
                    $st['first_name'] . ' ' . $st['last_name'] . ': ' . $st['grade'] . '/' . $st['field'] . ' → ' . $grade . '/' . $field);
            }

            if ($changed > 0) {
                flash_set('success', fa_num($changed) . ' دانش‌آموز با موفقیت ویرایش شد'
                    . ($op === 'grade' ? ' (پایه → ' . $newGrade . ($rawField !== '' ? ' و رشته → ' . $newF : '') . ')' : ' (رشته → ' . $rawField . ')'));
            }
            if ($skipped) {
                flash_set('err', fa_num(count($skipped)) . ' مورد تغییر نکرد: ' . implode('، ', array_slice($skipped, 0, 6)) . (count($skipped) > 6 ? ' و …' : ''));
            }
            header('Location: students.php');
            exit;
        }
    }

    /* ------------------------- تغییر پایه/رشته گروهی بر اساس فیلتر ------------------------- */
    if ($action === 'bulk_change') {
        require_perm('edit_student');
        $op       = req('op') === 'field' ? 'field' : 'grade';
        $srcGrade = validate_grade(req('grade'));
        $srcField = $srcGrade ? validate_field(req('field'), $srcGrade) : null;
        $newGrade = validate_grade(req('new_grade'));
        $rawField = clean_text(req('new_field'), 40);

        if (!$srcGrade) {
            $errors[] = 'انتخاب پایهٔ مبدأ الزامی است.';
        } elseif ($op === 'grade' && !$newGrade) {
            $errors[] = 'انتخاب پایهٔ مقصد الزامی است.';
        } elseif ($op === 'field' && $rawField === '') {
            $errors[] = 'انتخاب رشتهٔ مقصد الزامی است.';
        } else {
            $changed = 0;
            $skipped = [];
            foreach (hs_student_ids_by($srcGrade, $srcField) as $sid) {
                $st = row('SELECT * FROM students WHERE id = ?', [$sid]);
                if (!$st) { continue; }
                if ($op === 'grade') {
                    $grade = $newGrade;
                    $field = $rawField !== '' ? (validate_field($rawField, $newGrade) ?: null) : $st['field'];
                    if ($field === null && $rawField !== '') {
                        $skipped[] = $st['first_name'] . ' ' . $st['last_name'];
                        continue;
                    }
                    if ($rawField === '' && !hs_field_ok($newGrade, $st['field'])) {
                        $skipped[] = $st['first_name'] . ' ' . $st['last_name'] . ' (رشته ' . $st['field'] . ' در ' . $newGrade . ' مجاز نیست)';
                        continue;
                    }
                    if ($field === null) { $field = $st['field']; }
                } else {
                    $grade = $st['grade'];
                    $field = validate_field($rawField, $grade);
                    if (!$field) {
                        $skipped[] = $st['first_name'] . ' ' . $st['last_name'];
                        continue;
                    }
                }
                if (student_exists(['first_name' => $st['first_name'], 'last_name' => $st['last_name'], 'grade' => $grade, 'field' => $field], (int)$st['id'])) {
                    $skipped[] = $st['first_name'] . ' ' . $st['last_name'] . ' (مشابه تکراری)';
                    continue;
                }
                db_update('students', ['grade' => $grade, 'field' => $field], ['id' => $sid]);
                $changed++;
            }
            log_activity('تغییر گروهی بر اساس فیلتر', $op . ' | مبدأ: ' . $srcGrade . ($srcField ? '/' . $srcField : '') . ' | تعداد: ' . $changed);
            flash_set($changed ? 'success' : 'warn', fa_num($changed) . ' دانش‌آموز ویرایش شد.');
            if ($skipped) {
                flash_set('err', fa_num(count($skipped)) . ' مورد رد شد: ' . implode('، ', array_slice($skipped, 0, 6)) . (count($skipped) > 6 ? ' و …' : ''));
            }
        }
        header('Location: students.php?tab=bulk');
        exit;
    }

    /* ------------------------- خروجی CSV از فهرست ------------------------- */
    if ($action === 'export_csv') {
        require_perm('export_pdf');
        $filters = [
            'q' => clean_text(req('q'), 60), 'first_name' => clean_text(req('fname'), 60),
            'last_name' => clean_text(req('lname'), 60), 'grade' => validate_grade(req('grade')),
            'field' => clean_text(req('field'), 40),
        ];
        $params = '';
        $where  = students_where($filters, $params);
        $list   = rows("SELECT * FROM students WHERE {$where} ORDER BY last_name ASC, first_name ASC", $params ?: []);
        $out = [];
        foreach ($list as $i => $s) {
            $out[] = [$i + 1, $s['first_name'], $s['last_name'], $s['grade'], $s['field'], $s['father_phone'], $s['mother_phone']];
        }
        send_csv('students-' . str_replace('/', '-', jalali_today()) . '.csv',
            ['ردیف', 'نام', 'نام خانوادگی', 'پایه', 'رشته', 'تلفن پدر', 'تلفن مادر'], $out);
    }
}

/* ---------------------------------------------------------------------------
 * ۳) واکشی فهرست با فیلتر/مرتب‌سازی/صفحه‌بندی
 * ------------------------------------------------------------------------ */
$filters = [
    'q'          => clean_text(req('q'), 60),
    'first_name' => clean_text(req('fname'), 60),
    'last_name'  => clean_text(req('lname'), 60),
    'grade'      => validate_grade(req('grade')) ?? '',
    'field'      => clean_text(req('field'), 40),
];
if ($filters['field'] !== '' && $filters['grade'] !== '' && validate_field($filters['field'], $filters['grade']) === null) {
    $filters['field'] = '';   // رشته نامعتبر برای پایه انتخاب‌شده نادیده گرفته می‌شود
}

$sortMap = [
    'first_name'   => 'first_name',
    'last_name'    => 'last_name',
    'grade'        => 'grade',
    'field'        => 'field',
    'father_phone' => 'father_phone',
    'mother_phone' => 'mother_phone',
    'created_at'   => 'created_at',
];
[$sortCol, $sortDir] = safe_sort(req('sort'), req('dir'), $sortMap, 'id');
if ($sortCol === 'id') { $sortDir = req('dir') === 'asc' ? 'asc' : 'desc'; }

$perPage   = in_array(req_int('per_page', 15), [10, 15, 25, 50, 100], true) ? req_int('per_page', 15) : 15;
$page      = max(1, req_int('page', 1));
$wParams   = [];
$whereSql  = students_where($filters, $wParams);
$totalRows = (int)one('SELECT COUNT(*) FROM students WHERE ' . $whereSql, $wParams);
$totalPages= max(1, (int)ceil($totalRows / $perPage));
$page      = min($page, $totalPages);
$offset    = ($page - 1) * $perPage;

$orderSql = $sortCol === 'id'
    ? 'id ' . strtoupper($sortDir)
    : $sortCol . ' ' . strtoupper($sortDir) . ', last_name ASC';

$list = rows(
    'SELECT * FROM students WHERE ' . $whereSql . ' ORDER BY ' . $orderSql . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
    $wParams
);

/* رکوردهای هر دانش‌آموز در فهرست (شمارش سریع) */
$counts = [];
if ($list) {
    $ids = array_column($list, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    foreach ([['delays', 'd'], ['absences', 'a'], ['disciplines', 'c'], ['dress_violations', 'v']] as [$t, $alias]) {
        foreach (rows("SELECT student_id, COUNT(*) AS c FROM {$t} WHERE student_id IN ({$ph}) GROUP BY student_id", $ids) as $r) {
            $counts[(int)$r['student_id']][$alias] = (int)$r['c'];
        }
    }
}

if ($editId > 0) {
    $editRow = row('SELECT * FROM students WHERE id = ?', [$editId]);
    if ($editRow && req('tab') === '') {
        $tab = 'edit';
    }
}

$stats = [
    'total'   => (int)one('SELECT COUNT(*) FROM students'),
    'byGrade' => rows('SELECT grade, COUNT(*) AS c FROM students GROUP BY grade'),
];

$studentsCss = <<<'CSS'
/* ===== استایل اختصاصی پنل دانش‌آموزان ===== */
/* نوار عملیات گروهی روی موارد انتخاب‌شده */
.selop{border:1.6px solid #c7d7f5;background:linear-gradient(180deg,#f5f8ff,#fff)}
.selop-head{display:flex;flex-wrap:wrap;gap:9px;align-items:center}
.so-badge{background:var(--navy-bg);color:#fff;border-radius:999px;padding:7px 15px;font-size:12.6px;font-weight:700}
.so-badge.on{background:var(--green)}
.tbl td input[type=checkbox],.tbl th input[type=checkbox]{width:17px;height:17px;accent-color:var(--blue);cursor:pointer}
.tbl tr.picked{background:#f0f6ff}
.tbl tr.picked td{box-shadow:inset 0 0 0 9999px rgba(37,99,235,.045)}
.filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(178px,1fr));gap:11px;align-items:end}
.filters .fa-end{display:flex;gap:7px}
.inp-err{border-color:var(--red)!important;background:var(--red-soft)}
.field-hint{font-size:11.6px;color:var(--gray-500);margin-top:5px}
.drop{border:2.2px dashed var(--gray-300);border-radius:16px;padding:26px;text-align:center;background:var(--gray-50);
  transition:var(--transition);cursor:pointer}
.drop:hover,.drop.over{border-color:var(--blue);background:var(--blue-soft)}
.drop .d-ic{font-size:42px;margin-bottom:8px}
.drop b{color:var(--navy);display:block;font-size:14.4px}
html[data-theme="dark"] .drop b{color:#e8eefb}
.file-name{margin-top:10px;font-weight:700;color:var(--green);font-size:13px}
.step-list{counter-reset:st;padding:0;margin:10px 0 0;list-style:none}
.step-list li{counter-increment:st;position:relative;padding-inline-start:34px;margin-bottom:9px;font-size:13.2px}
.step-list li::before{content:counter(st);position:absolute;inset-inline-start:0;top:3px;width:24px;height:24px;
  border-radius:8px;background:var(--blue-soft);color:#1d4ed8;display:grid;place-items:center;font-size:12px;font-weight:800}
.report{border:1px solid var(--gray-200);border-radius:14px;padding:16px;background:var(--gray-50);margin-top:16px}
.report .rs{display:flex;gap:11px;flex-wrap:wrap;margin-bottom:12px}
.report .rs .r{flex:1;min-width:118px;background:var(--white);border:1px solid var(--gray-200);border-radius:12px;padding:11px;text-align:center}
.report .rs .r b{display:block;font-size:20px;color:var(--navy)}
html[data-theme="dark"] .report .rs .r b{color:#e8eefb}
.report .rs .r span{font-size:11.6px;color:var(--gray-500)}
.err-list{max-height:260px;overflow:auto;background:var(--white);border:1px solid #fecaca;border-radius:12px;padding:11px 14px;font-size:12.6px;color:#991b1b}
.err-list div{padding:3px 0;border-bottom:1px dashed var(--gray-200)}
.err-list div:last-child{border:none}
.cnt{display:inline-flex;gap:5px;align-items:center;font-size:11.4px;color:var(--gray-500);font-weight:700}
.cnt i{font-style:normal;padding:2px 7px;border-radius:20px;background:var(--gray-100)}
.cnt i.d{background:var(--amber-soft);color:#b45309}
.cnt i.a{background:var(--red-soft);color:#b91c1c}
.cnt i.c{background:var(--purple-soft);color:#6d28d9}
.cnt i.v{background:var(--blue-soft);color:#1d4ed8}
.tabs{position:relative}
CSS;

layout_head('مدیریت دانش‌آموزان', 'students', [$studentsCss]);
?>

<?php foreach ($errors as $err): ?>
  <div class="alert err"><span><?= hs_icon('ban', 17) ?></span><span><?= e($err) ?></span></div>
<?php endforeach; ?>

<div class="grid stats mb10">
  <div class="stat blue"><div class="icon"><?= hs_icon('student', 22) ?></div><div><div class="v"><?= fa_num($stats['total']) ?></div><div class="l">کل دانش‌آموزان ثبت‌شده</div></div></div>
  <?php foreach (HS_GRADES as $g):
      $c = 0;
      foreach ($stats['byGrade'] as $r) { if ($r['grade'] === $g) { $c = (int)$r['c']; } }
      $color = ['دهم' => 'green', 'یازدهم' => 'amber', 'دوازدهم' => 'purple'][$g];
  ?>
    <div class="stat <?= $color ?>"><div class="icon"><?= hs_icon('school', 22) ?></div><div><div class="v"><?= fa_num($c) ?></div><div class="l">پایه <?= e($g) ?></div></div></div>
  <?php endforeach; ?>
</div>

<!-- ================= تب‌ها ================= -->
<div class="card">
  <div class="tabs">
    <button class="<?= $tab === 'list' ? 'active' : '' ?>" onclick="goTab('list')"><?= hs_icon('clipboard', 17) ?> فهرست دانش‌آموزان</button>
    <?php if (has_perm('add_student')): ?>
      <button class="<?= $tab === 'add' ? 'active' : '' ?>" onclick="goTab('add')"><?= hs_icon('pencil', 17) ?> افزودن دستی</button>
      <button class="<?= $tab === 'import' ? 'active' : '' ?>" onclick="goTab('import')"><?= hs_icon('download', 17) ?> آپلود Excel / فایل متنی</button>
    <?php endif; ?>
    <?php if (has_perm('delete_student')): ?>
      <button class="<?= $tab === 'bulk' ? 'active' : '' ?>" onclick="goTab('bulk')"><?= hs_icon('users', 17) ?> عملیات گروهی</button>
    <?php endif; ?>
    <span class="tag" style="margin-inline-start:auto"><?= hs_icon('alert', 17) ?> رشته «کامپیوتر» فقط در پایه‌های دهم و یازدهم</span>
  </div>

  <?php if (!$editRow && $tab === 'edit'): ?>
    <div class="alert warn"><span><?= hs_icon('alert', 17) ?></span><span>دانش‌آموز مورد نظر برای ویرایش یافت نشد.</span></div>
  <?php endif; ?>

  <!-- ================= تب ۱: فهرست ================= -->
  <div id="tab-list" class="tabpane <?= $tab === 'list' ? '' : 'hidden' ?>">
    <form method="get" class="card" style="background:var(--gray-50);box-shadow:none;margin-bottom:16px">
      <input type="hidden" name="tab" value="list">
      <div class="filters">
        <div>
          <label class="fl">جستجوی کلی</label>
          <input type="search" id="fltQ" name="q" value="<?= e($filters['q']) ?>" placeholder="نام یا نام خانوادگی…">
        </div>
        <div>
          <label class="fl">نام</label>
          <input type="text" id="fltFname" name="fname" value="<?= e($filters['first_name']) ?>" placeholder="مثال: علی">
        </div>
        <div>
          <label class="fl">نام خانوادگی</label>
          <input type="text" id="fltLname" name="lname" value="<?= e($filters['last_name']) ?>" placeholder="مثال: رضایی">
        </div>
        <div>
          <label class="fl">پایه</label>
          <select name="grade" id="fltGrade">
            <option value="">همه پایه‌ها</option>
            <?php foreach (HS_GRADES as $g): ?>
              <option value="<?= e($g) ?>" <?= $filters['grade'] === $g ? 'selected' : '' ?>><?= e($g) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="fl">رشته</label>
          <select name="field" id="fltField">
            <option value="">همه رشته‌ها</option>
            <?php foreach (all_fields() as $f): ?>
              <option value="<?= e($f) ?>" <?= $filters['field'] === $f ? 'selected' : '' ?>><?= e($f) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="field-hint" id="fltFieldHint"></span>
        </div>
        <div>
          <label class="fl">تعداد در صفحه</label>
          <select name="per_page">
            <?php foreach ([10, 15, 25, 50, 100] as $pp): ?>
              <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= fa_num($pp) ?> ردیف</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fa-end">
          <button class="btn btn-blue" type="submit" style="flex:1"><?= hs_icon('search', 16) ?> اعمال فیلتر</button>
          <a class="btn btn-ghost" href="students.php">پاک‌سازی</a>
        </div>
      </div>
      <div class="between mt10">
        <span class="muted small">
          نتیجه: <b><?= fa_num($totalRows) ?></b> دانش‌آموز
          <?= $filters['grade'] ? ' — پایه ' . e($filters['grade']) : '' ?>
          <?= $filters['field'] ? ' — رشته ' . e($filters['field']) : '' ?>
        </span>
        <?php if (has_perm('export_pdf')): ?>
          <button class="btn btn-sm btn-green" type="button" id="exportCsvBtn"
                  data-csrf="<?= e(csrf_token()) ?>"><?= hs_icon('chart', 17) ?> خروجی CSV همین فهرست</button>
          <a class="btn btn-sm btn-blue" href="export.php?tab=students<?= $filters['grade'] ? '&grade=' . urlencode($filters['grade']) : '' ?><?= $filters['field'] ? '&field=' . urlencode($filters['field']) : '' ?><?= $filters['q'] !== '' ? '&q=' . urlencode($filters['q']) : '' ?>"><?= hs_icon('file', 17) ?> گزارش PDF همین فهرست</a>
        <?php endif; ?>
      </div>
    </form>

    <!-- ============ نوار عملیات گروهی روی دانش‌آموزان انتخاب‌شده ============ -->
    <form method="post" id="selOpForm" class="card selop no-print" data-loading="در حال اجرای عملیات گروهی…">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="bulk_selected">
      <div class="selop-head">
        <span class="so-badge" id="selCount">۰ دانش‌آموز انتخاب شده</span>
        <span class="hint" style="margin:0">با تیک‌زدن ردیف‌های جدول، عملیات گروهی فعال می‌شود. «انتخاب همه» فقط ردیف‌های همین صفحه را تیک می‌زند.</span>
      </div>
      <div class="grid g3 mt10">
        <div>
          <label class="fl">عملیات گروهی</label>
          <select name="op" id="selOp">
            <option value="">— انتخاب عملیات —</option>
            <option value="delay"      <?= !has_perm('register_delay') ? 'disabled' : '' ?>><?= hs_icon('clock', 17) ?> ثبت تأخیر برای انتخاب‌شده‌ها</option>
            <option value="absence"    <?= !has_perm('register_absence') ? 'disabled' : '' ?>><?= hs_icon('ban', 17) ?> ثبت غیبت برای انتخاب‌شده‌ها</option>
            <option value="discipline" <?= !has_perm('register_discipline') ? 'disabled' : '' ?>><?= hs_icon('scales', 17) ?> ثبت مورد انضباطی برای انتخاب‌شده‌ها</option>
            <option value="dress"      <?= !has_perm('register_dress') ? 'disabled' : '' ?>><?= hs_icon('shirt', 17) ?> ثبت تخلف پوششی برای انتخاب‌شده‌ها</option>
            <option value="grade"      <?= !has_perm('edit_student') ? 'disabled' : '' ?>><?= hs_icon('school', 17) ?> تغییر پایهٔ انتخاب‌شده‌ها</option>
            <option value="field"      <?= !has_perm('edit_student') ? 'disabled' : '' ?>><?= hs_icon('info', 17) ?> تغییر رشتهٔ انتخاب‌شده‌ها</option>
            <option value="delete"     <?= !has_perm('delete_student') ? 'disabled' : '' ?>><?= hs_icon('trash', 17) ?> حذف انتخاب‌شده‌ها</option>
          </select>
        </div>
        <div id="selGradeBox" class="hidden">
          <label class="fl">پایهٔ جدید <span class="req">*</span></label>
          <select name="new_grade" id="selGrade">
            <option value="">— انتخاب پایه —</option>
            <?php foreach (HS_GRADES as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div id="selFieldBox" class="hidden">
          <label class="fl">رشتهٔ جدید <span class="hint" id="selFieldHint">(اختیاری)</span></label>
          <select name="new_field" id="selField"><option value="">— انتخاب رشته —</option></select>
        </div>
        <div style="align-self:end">
          <button class="btn btn-primary btn-block" type="submit" id="selRun" disabled><?= hs_icon('bolt', 16) ?> اجرای عملیات گروهی</button>
        </div>
      </div>
      <div class="hint mt10" id="selNote" style="margin-top:8px">
        برای ثبت تأخیر/غیبت/انضباط/پوشش، پس از اجرا به تب «ثبت گروهی» صفحهٔ ثبت موارد منتقل می‌شوید و تاریخ و جزئیات را آنجا تأیید می‌کنید.
      </div>
    </form>

    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th style="width:44px"><input type="checkbox" id="selAll" title="انتخاب همهٔ ردیف‌های این صفحه"></th>
            <th style="width:56px">ردیف</th>
            <?php
            $th = static function (string $key, string $label) use ($sortCol, $sortDir): void {
                $isActive = $sortCol === $key;
                $next = ($isActive && $sortDir === 'asc') ? 'desc' : 'asc';
                $icon = $isActive ? ($sortDir === 'asc' ? '▲' : '▼') : '⇅';
                echo '<th class="' . ($isActive ? 'active-sort' : '') . '"><a href="' . e(url_with(['sort' => $key, 'dir' => $next, 'page' => 1])) . '">'
                   . e($label) . '<span class="sort-ic">' . $icon . '</span></a></th>';
            };
            $th('first_name', 'نام');
            $th('last_name', 'نام خانوادگی');
            $th('grade', 'پایه');
            $th('field', 'رشته');
            $th('father_phone', 'تلفن پدر');
            $th('mother_phone', 'تلفن مادر');
            ?>
            <th>سوابق</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$list): ?>
          <tr><td colspan="10">
            <div class="empty"><div class="e-ic"><?= hs_icon('search', 26) ?></div>
              دانش‌آموزی با این مشخصات یافت نشد.
              <?php if (has_perm('add_student')): ?>
                <br><a class="btn btn-sm btn-blue mt10" href="students.php?tab=import">افزودن گروهی از فایل Excel</a>
              <?php endif; ?>
            </div>
          </td></tr>
        <?php endif; ?>
        <?php foreach ($list as $i => $s):
            $c = $counts[(int)$s['id']] ?? [];
            $rowNo = $offset + $i + 1;
        ?>
          <tr>
            <td><input type="checkbox" class="rowSel" form="selOpForm" name="ids[]" value="<?= (int)$s['id'] ?>"
                       data-name="<?= e($s['first_name'] . ' ' . $s['last_name']) ?>"
                       data-grade="<?= e($s['grade']) ?>" data-field="<?= e($s['field']) ?>"></td>
            <td><?= fa_num($rowNo) ?></td>
            <td><b><?= e($s['first_name']) ?></b></td>
            <td><b><?= e($s['last_name']) ?></b></td>
            <td><span class="badge <?= $s['grade'] === 'دهم' ? 'green' : ($s['grade'] === 'یازدهم' ? 'amber' : 'purple') ?>"><?= e($s['grade']) ?></span></td>
            <td><span class="badge blue"><?= e($s['field']) ?></span></td>
            <td dir="ltr" class="nowrap"><?= fa_num($s['father_phone']) ?></td>
            <td dir="ltr" class="nowrap"><?= fa_num($s['mother_phone']) ?></td>
            <td class="nowrap">
              <span class="cnt">
                <i class="d" title="تأخیر">ت <?= fa_num($c['d'] ?? 0) ?></i>
                <i class="a" title="غیبت">غ <?= fa_num($c['a'] ?? 0) ?></i>
                <i class="c" title="انضباطی">ا <?= fa_num($c['c'] ?? 0) ?></i>
                <i class="v" title="پوششی">پ <?= fa_num($c['v'] ?? 0) ?></i>
              </span>
            </td>
            <td>
              <div class="flex" style="gap:5px">
                <?php if (has_perm('edit_student')): ?>
                  <a class="btn btn-sm btn-ghost" href="students.php?tab=edit&edit=<?= (int)$s['id'] ?>" title="ویرایش"><?= hs_icon('pencil', 17) ?></a>
                <?php endif; ?>
                <?php if (has_perm('register_delay') || has_perm('register_absence') || has_perm('register_discipline') || has_perm('register_dress')): ?>
                  <a class="btn btn-sm btn-blue" href="records.php?student_id=<?= (int)$s['id'] ?>" title="ثبت مورد جدید"><?= hs_icon('clipboard', 17) ?></a>
                <?php endif; ?>
                <?php if (has_perm('delete_student')): ?>
                  <form method="post" style="display:inline;margin:0" class="delStudent"
                        data-name="<?= e($s['first_name'] . ' ' . $s['last_name']) ?>">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_student"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
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

    <!-- صفحه‌بندی -->
    <?php if ($totalPages > 1): ?>
      <div class="pager">
        <a class="<?= $page <= 1 ? 'off' : '' ?>" href="<?= $page <= 1 ? '#' : e(url_with(['page' => 1])) ?>">« اول</a>
        <a class="<?= $page <= 1 ? 'off' : '' ?>" href="<?= $page <= 1 ? '#' : e(url_with(['page' => $page - 1])) ?>">قبلی</a>
        <?php
        $start = max(1, $page - 3);
        $end   = min($totalPages, $page + 3);
        for ($p = $start; $p <= $end; $p++):
        ?>
          <?php if ($p === $page): ?>
            <span class="on"><?= fa_num($p) ?></span>
          <?php else: ?>
            <a href="<?= e(url_with(['page' => $p])) ?>"><?= fa_num($p) ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <a class="<?= $page >= $totalPages ? 'off' : '' ?>" href="<?= $page >= $totalPages ? '#' : e(url_with(['page' => $page + 1])) ?>">بعدی</a>
        <a class="<?= $page >= $totalPages ? 'off' : '' ?>" href="<?= $page >= $totalPages ? '#' : e(url_with(['page' => $totalPages])) ?>">آخر »</a>
        <span class="small muted" style="margin-inline-start:10px">صفحه <?= fa_num($page) ?> از <?= fa_num($totalPages) ?></span>
      </div>
    <?php endif; ?>
  </div>

  <!-- ================= تب ۲: افزودن / ویرایش دستی ================= -->
  <?php if (has_perm('add_student')): ?>
  <div id="tab-add" class="tabpane <?= $tab === 'add' ? '' : 'hidden' ?>">
    <form method="post" id="studentForm" autocomplete="off" novalidate data-loading="در حال ذخیره دانش‌آموز…">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_student">
      <div class="grid g2">
        <div>
          <label class="fl">نام <span class="req">*</span></label>
          <input type="text" name="first_name" id="aFirst" data-kind="name" required maxlength="60" placeholder="مثال: علی">
          <div class="field-hint">فقط حروف فارسی؛ حداقل ۲ حرف.</div>
        </div>
        <div>
          <label class="fl">نام خانوادگی <span class="req">*</span></label>
          <input type="text" name="last_name" id="aLast" data-kind="name" required maxlength="60" placeholder="مثال: رضایی">
        </div>
        <div>
          <label class="fl">پایه تحصیلی <span class="req">*</span></label>
          <select name="grade" id="aGrade" required>
            <option value="">— انتخاب پایه —</option>
            <?php foreach (HS_GRADES as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?>
          </select>
          <div class="field-hint">با تغییر پایه، فهرست رشته‌های مجاز به‌صورت خودکار بروزرسانی می‌شود.</div>
        </div>
        <div>
          <label class="fl">رشته تحصیلی <span class="req">*</span></label>
          <select name="field" id="aField" required disabled>
            <option value="">— ابتدا پایه را انتخاب کنید —</option>
          </select>
          <div class="field-hint" id="aFieldHint">رشته‌ها بر اساس پایه انتخابی مشخص می‌شوند.</div>
        </div>
        <div>
          <label class="fl">شماره تلفن پدر <span class="opt">(اختیاری)</span></label>
          <input type="tel" name="father_phone" id="aFPhone" data-kind="phone" inputmode="numeric" maxlength="11" dir="ltr"
                 placeholder="09123456789 (اختیاری)">
          <div class="field-hint">اختیاری است؛ اگر وارد شود باید ۱۱ رقم و با ۰۹ شروع شود (ارقام فارسی نیز پذیرفته می‌شود).</div>
        </div>
        <div>
          <label class="fl">شماره تلفن مادر <span class="opt">(اختیاری)</span></label>
          <input type="tel" name="mother_phone" id="aMPhone" data-kind="phone" inputmode="numeric" maxlength="11" dir="ltr"
                 placeholder="09123456789 (اختیاری)">
          <div class="field-hint">اختیاری است؛ اگر وارد شود باید ۱۱ رقم و با ۰۹ شروع شود.</div>
        </div>
      </div>
      <div class="flex mt20">
        <button class="btn btn-primary" type="submit"><?= hs_icon('save', 16) ?> ثبت دانش‌آموز</button>
        <button class="btn btn-ghost" type="reset"><?= hs_icon('refresh', 16) ?> پاک کردن فرم</button>
        <span class="hint">پس از ثبت، پیام موفقیت نمایش داده می‌شود و فهرست بروزرسانی خواهد شد.</span>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <!-- ================= تب ۳: ویرایش ================= -->
  <?php if ($editRow && has_perm('edit_student')): ?>
  <div id="tab-edit" class="tabpane <?= $tab === 'edit' ? '' : 'hidden' ?>">
    <div class="alert info"><span><?= hs_icon('pencil', 17) ?></span><span>در حال ویرایش اطلاعات دانش‌آموز <b><?= e($editRow['first_name'] . ' ' . $editRow['last_name']) ?></b>
      (کد <?= fa_num((int)$editRow['id']) ?>) — ثبت‌شده در <?= e(gregorian_to_jalali_str(substr((string)$editRow['created_at'], 0, 10))) ?></span></div>
    <form method="post" id="editStudentForm" autocomplete="off" data-loading="در حال ذخیره تغییرات…">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="edit_student">
      <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
      <input type="hidden" name="back" value="<?= e(http_build_query(array_filter($_GET, static fn($k) => $k !== 'edit', ARRAY_FILTER_USE_KEY))) ?>">
      <div class="grid g2">
        <div><label class="fl">نام <span class="req">*</span></label>
          <input type="text" name="first_name" data-kind="name" required maxlength="60" value="<?= e($editRow['first_name']) ?>"></div>
        <div><label class="fl">نام خانوادگی <span class="req">*</span></label>
          <input type="text" name="last_name" data-kind="name" required maxlength="60" value="<?= e($editRow['last_name']) ?>"></div>
        <div><label class="fl">پایه <span class="req">*</span></label>
          <select name="grade" id="eGrade" data-current="<?= e($editRow['grade']) ?>" required>
            <?php foreach (HS_GRADES as $g): ?>
              <option value="<?= e($g) ?>" <?= $editRow['grade'] === $g ? 'selected' : '' ?>><?= e($g) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div><label class="fl">رشته <span class="req">*</span></label>
          <select name="field" id="eField" data-current="<?= e($editRow['field']) ?>" required></select>
          <div class="field-hint" id="eFieldHint"></div></div>
        <div><label class="fl">تلفن پدر <span class="opt">(اختیاری)</span></label>
          <input type="tel" name="father_phone" data-kind="phone" maxlength="11" dir="ltr" value="<?= e($editRow['father_phone']) ?>"></div>
        <div><label class="fl">تلفن مادر <span class="opt">(اختیاری)</span></label>
          <input type="tel" name="mother_phone" data-kind="phone" maxlength="11" dir="ltr" value="<?= e($editRow['mother_phone']) ?>"></div>
      </div>
      <div class="flex mt20">
        <button class="btn btn-primary" type="submit"><?= hs_icon('save', 16) ?> ذخیره تغییرات</button>
        <a class="btn btn-ghost" href="students.php">انصراف</a>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <!-- ================= تب ۴: آپلود گروهی ================= -->
  <?php if (has_perm('add_student')): ?>
  <div id="tab-import" class="tabpane <?= $tab === 'import' ? '' : 'hidden' ?>">
    <div class="grid g2">
      <div>
        <form method="post" enctype="multipart/form-data" id="importForm" data-loading="در حال خواندن فایل و ثبت دانش‌آموزان…">
          <?= csrf_field() ?><input type="hidden" name="action" value="import_students">
          <div class="drop" id="drop">
            <div class="d-ic"><?= hs_icon('download', 28) ?></div>
            <b>فایل Excel یا متنی را اینجا رها کنید یا کلیک کنید</b>
            <span class="hint">فرمت‌های مجاز: xlsx — csv — txt — tsv (حداکثر ۵ مگابایت)</span>
            <input type="file" name="import_file" id="importFile" accept=".xlsx,.xls,.csv,.txt,.tsv" class="hidden">
            <div class="file-name" id="fileName"></div>
          </div>
          <div class="flex mt20">
            <button class="btn btn-primary" type="submit"><?= hs_icon('bolt', 16) ?> شروع ورود گروهی</button>
            <a class="btn btn-ghost" href="export.php?tab=sample"><?= hs_icon('file', 16) ?> دریافت فایل نمونه</a>
          </div>
        </form>

        <h3 class="mt20"><?= hs_icon('clipboard', 18) ?> ترتیب ستون‌های فایل</h3>
        <div class="table-wrap">
          <table class="tbl" style="min-width:420px">
            <thead><tr><th>ستون</th><th>عنوان</th><th>نمونه</th></tr></thead>
            <tbody>
              <tr><td>۱</td><td>نام</td><td>علی</td></tr>
              <tr><td>۲</td><td>نام خانوادگی</td><td>رضایی</td></tr>
              <tr><td>۳</td><td>پایه</td><td>یازدهم</td></tr>
              <tr><td>۴</td><td>رشته</td><td>کامپیوتر</td></tr>
              <tr><td>۵</td><td>تلفن پدر <span class="opt">(اختیاری)</span></td><td>09121234567</td></tr>
              <tr><td>۶</td><td>تلفن مادر <span class="opt">(اختیاری)</span></td><td>09127654321</td></tr>
            </tbody>
          </table>
        </div>
        <div class="alert info mt10"><span><?= hs_icon('info', 17) ?></span><span>
          اگر سطر اول فایل شامل عنوان ستون‌ها باشد (نام، نام خانوادگی، پایه، رشته، تلفن پدر، تلفن مادر)،
          سامانه آن را به‌صورت خودکار تشخیص داده و از داده‌ها حذف می‌کند. ترتیب ستون‌ها در این حالت اهمیت ندارد.
        </span></div>
      </div>

      <div>
        <h3><?= hs_icon('clipboard', 18) ?> راهنمای قالب فایل</h3>
        <ol class="step-list">
          <li>در Excel سطر اول را به عنوان‌های «نام | نام خانوادگی | پایه | رشته | تلفن پدر | تلفن مادر» اختصاص دهید (فقط چهار ستون اول الزامی است).</li>
          <li>پایه را دقیقاً «دهم»، «یازدهم» یا «دوازدهم» بنویسید (عدد ۱۰/۱۱/۱۲ هم پذیرفته می‌شود).</li>
          <li>رشته باید با رشته‌های مجاز همان پایه مطابقت داشته باشد؛ «کامپیوتر» در پایه دوازدهم مجاز نیست.</li>
          <li>ستون‌های تلفن <b>اختیاری</b>اند؛ اگر پر شوند باید با فرمت 09xxxxxxxxx باشند (ارقام فارسی هم پذیرفته می‌شود).</li>
          <li>فایل را با فرمت xlsx یا CSV UTF-8 ذخیره کنید (فایل‌های xls قدیمی پشتیبانی نمی‌شوند).</li>
          <li>پس از آپلود، گزارش کامل شامل تعداد افزوده‌شده، تکراری‌ها و خطاها نمایش داده می‌شود.</li>
        </ol>
        <div class="alert warn mt10"><span><?= hs_icon('alert', 17) ?></span><span>داده‌های تکراری (نام+نام خانوادگی+پایه+رشته یکسان) به‌صورت خودکار نادیده گرفته می‌شوند.</span></div>
      </div>
    </div>

    <?php if ($importReport): ?>
      <div class="report" id="importReport">
        <h3 class="mt0"><?= hs_icon('chart', 18) ?> گزارش ورود گروهی — فایل: <?= e($importReport['file']) ?></h3>
        <div class="rs">
          <div class="r"><b><?= fa_num($importReport['totalRows']) ?></b><span>کل سطرهای فایل</span></div>
          <div class="r"><b style="color:var(--green)"><?= fa_num($importReport['inserted']) ?></b><span>افزوده‌شده</span></div>
          <div class="r"><b style="color:var(--amber)"><?= fa_num($importReport['dupes']) ?></b><span>تکراری (نادیده)</span></div>
          <div class="r"><b style="color:var(--red)"><?= fa_num($importReport['errorCount']) ?></b><span>سطر دارای خطا</span></div>
        </div>

        <?php if ($importReport['dupList']): ?>
          <h4 style="margin-bottom:6px;color:#b45309"><?= hs_icon('refresh', 18) ?> نمونه فهرست تکراری‌ها</h4>
          <div class="err-list" style="border-color:#fde68a;color:#92400e">
            <?php foreach ($importReport['dupList'] as $d): ?><div><?= e($d) ?></div><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($importReport['errors']): ?>
          <h4 style="margin:14px 0 6px;color:#b91c1c"><?= hs_icon('ban', 18) ?> خطاهای سطرها (<?= fa_num($importReport['errorCount']) ?> مورد)</h4>
          <div class="err-list">
            <?php foreach ($importReport['errors'] as $er): ?><div><?= e($er) ?></div><?php endforeach; ?>
            <?php if ($importReport['errorCount'] > count($importReport['errors'])): ?>
              <div>… و <?= fa_num($importReport['errorCount'] - count($importReport['errors'])) ?> خطای دیگر.</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ================= تب ۵: حذف گروهی ================= -->
  <?php if (has_perm('delete_student')): ?>
  <div id="tab-bulk" class="tabpane <?= $tab === 'bulk' ? '' : 'hidden' ?>">
    <div class="alert info"><span><?= hs_icon('users', 17) ?></span><span>
      <b>سه راه برای عملیات گروهی:</b>
      ۱) در تب <b>«فهرست دانش‌آموزان»</b> ردیف‌ها را تیک بزنید و از نوار بالای جدول عملیات را اجرا کنید
      (ثبت تأخیر/غیبت/انضباط/پوشش، تغییر پایه، تغییر رشته، حذف).
      ۲) در همین تب، بر اساس <b>پایه و رشته</b> (مثلاً همهٔ دهم کامپیوتر) عملیات را اجرا کنید.
      ۳) در صفحهٔ <b>ثبت موارد ← تب «ثبت گروهی»</b> می‌توانید برای یک گروه، مورد ثبت کنید.
    </span></div>

    <?php if (has_perm('edit_student')): ?>
    <!-- ===== تغییر پایه گروهی ===== -->
    <div class="card">
      <h3><?= hs_icon('school', 18) ?> تغییر پایهٔ گروهی</h3>
      <p class="muted small">پایهٔ همهٔ دانش‌آموزان پایه/رشتهٔ مبدأ به پایهٔ مقصد تغییر می‌کند.</p>
      <form method="post" data-loading="در حال تغییر پایه…">
        <?= csrf_field() ?><input type="hidden" name="action" value="bulk_change"><input type="hidden" name="op" value="grade">
        <div class="grid g4">
          <div>
            <label class="fl">پایهٔ مبدأ <span class="req">*</span></label>
            <select name="grade" id="bgFrom" required>
              <option value="">— انتخاب پایه —</option>
              <?php foreach (HS_GRADES as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="fl">رشتهٔ مبدأ <span class="hint">(اختیاری)</span></label>
            <select name="field" id="bgField"><option value="">همهٔ رشته‌ها</option></select>
          </div>
          <div>
            <label class="fl">پایهٔ مقصد <span class="req">*</span></label>
            <select name="new_grade" id="bgTo" required>
              <option value="">— انتخاب پایه —</option>
              <?php foreach (HS_GRADES as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="fl">رشتهٔ مقصد <span class="hint">(اختیاری)</span></label>
            <select name="new_field" id="bgToField"><option value="">بدون تغییر رشته</option></select>
          </div>
        </div>
        <div class="hint" id="bgHint" style="margin-top:8px">اگر رشتهٔ مقصد را خالی بگذارید، رشتهٔ هر دانش‌آموز ثابت می‌ماند؛ در این حالت دانش‌آموزان رشتهٔ «کامپیوتر» برای پایهٔ دوازدهم رد می‌شوند (کامپیوتر در دوازدهم وجود ندارد).</div>
        <button class="btn btn-primary mt10" type="submit"><?= hs_icon('school', 16) ?> اعمال تغییر پایه</button>
      </form>
    </div>

    <!-- ===== تغییر رشته گروهی ===== -->
    <div class="card">
      <h3><?= hs_icon('info', 18) ?> تغییر رشتهٔ گروهی</h3>
      <p class="muted small">رشتهٔ دانش‌آموزان انتخاب‌شده تغییر می‌کند؛ فقط رشته‌های مجاز هر پایه پذیرفته می‌شوند.</p>
      <form method="post" data-loading="در حال تغییر رشته…">
        <?= csrf_field() ?><input type="hidden" name="action" value="bulk_change"><input type="hidden" name="op" value="field">
        <div class="grid g4">
          <div>
            <label class="fl">پایه <span class="req">*</span></label>
            <select name="grade" id="bfGrade" required>
              <option value="">— انتخاب پایه —</option>
              <?php foreach (HS_GRADES as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="fl">رشتهٔ فعلی <span class="hint">(اختیاری)</span></label>
            <select name="field" id="bfFrom"><option value="">همهٔ رشته‌ها</option></select>
          </div>
          <div>
            <label class="fl">رشتهٔ جدید <span class="req">*</span></label>
            <select name="new_field" id="bfTo" required><option value="">— انتخاب رشته —</option></select>
          </div>
          <div style="align-self:end">
            <button class="btn btn-primary btn-block" type="submit"><?= hs_icon('info', 16) ?> اعمال تغییر رشته</button>
          </div>
        </div>
        <div class="hint" id="bfHint" style="margin-top:8px">رشتهٔ «کامپیوتر» برای پایهٔ دوازدهم در فهرست نمایش داده نمی‌شود.</div>
      </form>
    </div>
    <?php endif; ?>

    <div class="alert err"><span><?= hs_icon('ban', 17) ?></span><span>
      <b>هشدار حذف گروهی:</b> این عملیات تمام دانش‌آموزان پایه/رشته انتخابی و کلیه سوابق تأخیر، غیبت، انضباط و پوشش آن‌ها را حذف می‌کند و بازگشت‌پذیر نیست.
    </span></div>
    <form method="post" id="bulkForm" data-loading="در حال حذف گروهی…">
      <?= csrf_field() ?><input type="hidden" name="action" value="bulk_delete">
      <div class="grid g3">
        <div>
          <label class="fl">پایه <span class="req">*</span></label>
          <select name="grade" id="bulkGrade" required>
            <option value="">— انتخاب پایه —</option>
            <?php foreach (HS_GRADES as $g): ?><option value="<?= e($g) ?>"><?= e($g) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="fl">رشته <span class="hint">(اختیاری — خالی = همه رشته‌های این پایه)</span></label>
          <select name="field" id="bulkField"><option value="">همه رشته‌ها</option></select>
        </div>
        <div style="align-self:end">
          <button class="btn btn-red btn-block" type="submit"><?= hs_icon('trash', 16) ?> حذف گروهی</button>
        </div>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php
$studentsJs = <<<'JS'
/* ===== اسکریپت پنل دانش‌آموزان ===== */
/* قانون دامنه: نگاشت پایه → رشته‌های مجاز (رشته کامپیوتر در دوازدهم نیست) */
const FIELDS_BY_GRADE = __FIELDS_BY_GRADE__;

function fillFields(gradeSel, fieldSel, hintEl, preserve) {
  const grade = gradeSel.value;
  const list = FIELDS_BY_GRADE[grade] || [];
  fieldSel.innerHTML = '';
  if (!list.length) {
    fieldSel.disabled = true;
    fieldSel.innerHTML = '<option value="">— ابتدا پایه را انتخاب کنید —</option>';
    if (hintEl) hintEl.textContent = 'رشته‌ها بر اساس پایه انتخابی مشخص می‌شوند.';
    return;
  }
  fieldSel.disabled = false;
  fieldSel.innerHTML = '<option value="">— انتخاب رشته —</option>' +
    list.map(f => '<option value="' + f + '">' + f + '</option>').join('');
  if (preserve && list.indexOf(preserve) > -1) fieldSel.value = preserve;
  else if (preserve) HS.toast('رشته «' + preserve + '» برای پایه ' + grade + ' مجاز نیست؛ رشته جدید را انتخاب کنید.', 'warn');
  if (hintEl) {
    hintEl.innerHTML = 'رشته‌های مجاز پایه <b>' + grade + '</b>: ' + list.join('، ') +
      (grade === 'دوازدهم' ? ' <span class="badge red">رشته کامپیوتر حذف شده است</span>' : '');
  }
}

/* اعتبارسنجی نام و شماره */
/* نام فارسی: بدون ارقام (حتی ارقام فارسی/عربی) — هم‌راستا با اعتبارسنجی سرور */
const NAME_DIGIT_RE = /[\u06F0-\u06F9\u0660-\u06690-9]/;
const NAME_RE = /^[\u0600-\u06EF\uFB50-\uFDFF\u200C\s.\-]{2,60}$/;
function enDigits(v) {
  return String(v).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
}
function markBad(input, bad, msg) {
  input.classList.toggle('inp-err', bad);
  let s = input.parentNode.querySelector('.js-err');
  if (bad) {
    if (!s) { s = document.createElement('div'); s.className = 'field-hint js-err'; s.style.color = '#dc2626'; s.style.fontWeight = '700'; input.parentNode.appendChild(s); }
    s.textContent = msg;
  } else if (s) s.remove();
  return !bad;
}
function validateNameInput(i) {
  const v = i.value.trim();
  const bad = NAME_DIGIT_RE.test(v) || !NAME_RE.test(v);
  return markBad(i, bad, 'نام باید حداقل ۲ حرف فارسی و بدون عدد باشد.');
}
function validatePhoneInput(i) {
  /* شمارهٔ تلفن اختیاری است: خالی مجاز، ولی اگر وارد شود باید ۱۱ رقم و با ۰۹ باشد */
  const v = enDigits((i.value || '').trim());
  i.value = v;
  if (v === '') { return markBad(i, false, ''); }
  return markBad(i, !/^09\d{9}$/.test(v), 'شماره باید ۱۱ رقم و با ۰۹ شروع شود (یا خالی بماند).');
}
function bindValidation(scope) {
  HS.$$('[data-kind=name]', scope).forEach(i => i.addEventListener('input', () => validateNameInput(i)));
  HS.$$('[data-kind=phone]', scope).forEach(i => i.addEventListener('input', () => validatePhoneInput(i)));
}

function goTab(name) {
  HS.$$('.tabpane').forEach(p => p.classList.add('hidden'));
  const el = document.getElementById('tab-' + name);
  if (el) el.classList.remove('hidden');
  HS.$$('.tabs button').forEach(b => b.classList.remove('active'));
  const idx = ['list', 'add', 'edit', 'import', 'bulk'];
  const btns = HS.$$('.tabs button');
  const i = idx.indexOf(name);
  if (i > -1 && btns[i]) btns[i].classList.add('active');
  window.scrollTo({ top: 0, behavior: 'smooth' });
  history.replaceState(null, '', '?tab=' + name);
  try { localStorage.setItem('hs_students_tab', name); } catch (e) {}
  /* پر کردن فیلدهای پویا */
  const map = { add: ['aGrade', 'aField', 'aFieldHint'], edit: ['eGrade', 'eField', 'eFieldHint'], bulk: ['bulkGrade', 'bulkField', null] };
  const m = map[name];
  if (m && document.getElementById(m[0]) && document.getElementById(m[1])) {
    const gs = document.getElementById(m[0]), fs = document.getElementById(m[1]);
    const cur = gs.dataset.current || fs.dataset.current || '';
    fillFields(gs, fs, m[2] ? document.getElementById(m[2]) : null, cur);
  }
}

document.addEventListener('DOMContentLoaded', function () {
  /* --- فرم افزودن --- */
  const aGrade = document.getElementById('aGrade');
  if (aGrade) {
    aGrade.addEventListener('change', () => fillFields(aGrade, document.getElementById('aField'), document.getElementById('aFieldHint'), ''));
    fillFields(aGrade, document.getElementById('aField'), document.getElementById('aFieldHint'), '');
  }
  const aForm = document.getElementById('studentForm');
  if (aForm) aForm.addEventListener('submit', function (e) {
    const ok1 = validateNameInput(document.getElementById('aFirst'));
    const ok2 = validateNameInput(document.getElementById('aLast'));
    const ok3 = validatePhoneInput(document.getElementById('aFPhone'));
    const ok4 = validatePhoneInput(document.getElementById('aMPhone'));
    const fSel = document.getElementById('aField');
    const ok5 = markBad(fSel, !fSel.value, 'انتخاب رشته الزامی است.');
    const gSel = document.getElementById('aGrade');
    const ok6 = markBad(gSel, !gSel.value, 'انتخاب پایه الزامی است.');
    if (!(ok1 && ok2 && ok3 && ok4 && ok5 && ok6)) {
      e.preventDefault();
      HS.toast('لطفاً خطاهای فرم را برطرف کنید.', 'err');
    }
  });

  /* --- فرم ویرایش --- */
  const eGrade = document.getElementById('eGrade');
  if (eGrade) {
    fillFields(eGrade, document.getElementById('eField'), document.getElementById('eFieldHint'), eGrade.dataset.current);
    eGrade.addEventListener('change', () => fillFields(eGrade, document.getElementById('eField'), document.getElementById('eFieldHint'), ''));
    const ef = document.getElementById('editStudentForm');
    ef.addEventListener('submit', function (e) {
      const ok = HS.$$('[data-kind=name]', ef).map(validateNameInput).concat(HS.$$('[data-kind=phone]', ef).map(validatePhoneInput));
      if (ok.indexOf(false) > -1) { e.preventDefault(); HS.toast('لطفاً خطاهای فرم را برطرف کنید.', 'err'); }
    });
  }
  bindValidation(document);

  /* --- فیلتر رشته‌ها بر اساس پایه در نوار جستجو --- */
  const fltGrade = document.getElementById('fltGrade');
  if (fltGrade) fltGrade.addEventListener('change', function () {
    const list = FIELDS_BY_GRADE[fltGrade.value];
    if (!list) return;
    const fs = document.getElementById('fltField');
    const cur = fs.value;
    fs.innerHTML = '<option value="">همه رشته‌ها</option>' + list.map(f => '<option value="' + f + '">' + f + '</option>').join('');
    if (list.indexOf(cur) > -1) fs.value = cur;
    document.getElementById('fltFieldHint').innerHTML = 'رشته‌های پایه <b>' + fltGrade.value + '</b> فیلتر شد.';
  });

  /* --- دراپ‌زون آپلود --- */
  const drop = document.getElementById('drop'), file = document.getElementById('importFile');
  if (drop && file) {
    function showName() {
      const f = file.files[0];
      /* نکته: آیکون SVG را نمی‌توان در textContent گذاشت؛ باید innerHTML باشد */
      document.getElementById('fileName').innerHTML = f
        ? HS.icon('clipboard', 17) + ' ' + HS.esc(f.name) + ' — ' + HS.faNum((f.size / 1024).toFixed(1)) + ' کیلوبایت'
        : '';
    }
    drop.addEventListener('click', function (e) {
      if (e.target === file) return;      /* جلوگیری از حلقه باز شدن پنجره انتخاب فایل */
      file.click();
    });
    ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
    ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
    drop.addEventListener('drop', e => { if (e.dataTransfer.files.length) { file.files = e.dataTransfer.files; showName(); } });
    file.addEventListener('change', showName);
  }

  /* --- خروجی CSV با همان فیلترهای فعلی --- */
  const csvBtn = document.getElementById('exportCsvBtn');
  if (csvBtn) csvBtn.addEventListener('click', function () {
    const q = id => { const el = document.getElementById(id); return el ? el.value : ''; };
    const frm = document.createElement('form');
    frm.method = 'post'; frm.style.display = 'none';
    const add = (n, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; frm.appendChild(i); };
    add('csrf_token', csvBtn.dataset.csrf);
    add('action', 'export_csv');
    add('q', q('fltQ')); add('fname', q('fltFname')); add('lname', q('fltLname'));
    add('grade', q('fltGrade')); add('field', q('fltField'));
    document.body.appendChild(frm);
    HS.loading(true, 'در حال ساخت فایل CSV…');
    frm.submit();
    setTimeout(() => HS.loading(false), 2500);
  });

  /* --- حذف با تأیید --- */
  HS.$$('.delStudent').forEach(f => f.addEventListener('submit', function (e) {
    if (f.dataset.done) return;
    e.preventDefault();
    HS.confirm('دانش‌آموز «<b>' + f.dataset.name + '</b>» و تمام سوابق تأخیر/غیبت/انضباط او حذف شود؟',
      () => { f.dataset.done = '1'; HS.loading(true, 'در حال حذف دانش‌آموز…'); f.submit(); }, { yes: 'بله، حذف کن', title: 'حذف دانش‌آموز' });
  }));

  /* --- حذف گروهی --- */
  const bulkGrade = document.getElementById('bulkGrade');
  if (bulkGrade) {
    bulkGrade.addEventListener('change', () => {
      const list = FIELDS_BY_GRADE[bulkGrade.value] || [];
      const fs = document.getElementById('bulkField');
      fs.innerHTML = '<option value="">همه رشته‌ها</option>' + list.map(f => '<option value="' + f + '">' + f + '</option>').join('');
    });
    document.getElementById('bulkForm').addEventListener('submit', function (e) {
      if (this.dataset.done) return;
      e.preventDefault();
      const g = bulkGrade.value, f = document.getElementById('bulkField').value;
      if (!g) { HS.toast('انتخاب پایه الزامی است.', 'err'); return; }
      HS.confirm('همه دانش‌آموزان پایه <b>' + g + '</b>' + (f ? ' رشته <b>' + f + '</b>' : ' (همه رشته‌ها)') +
        ' به همراه تمام سوابقشان حذف شوند؟ این عملیات بازگشت‌پذیر نیست.', () => {
        this.dataset.done = '1'; HS.loading(true, 'در حال حذف گروهی…'); this.submit();
      }, { yes: 'بله، حذف گروهی', title: 'حذف گروهی دانش‌آموزان' });
    });
  }

  /* ================== انتخاب گروهی + عملیات گروهی روی انتخاب‌شده‌ها ================== */
  const selForm  = document.getElementById('selOpForm');
  const selAllCb = document.getElementById('selAll');
  const selOp    = document.getElementById('selOp');
  const selCount = document.getElementById('selCount');
  const selRun   = document.getElementById('selRun');
  const selGrade = document.getElementById('selGrade');
  const selField = document.getElementById('selField');

  const checked = () => HS.$$('.rowSel').filter(c => c.checked);

  function refreshSel() {
    const list = checked();
    const n = list.length;
    if (selCount) {
      selCount.textContent = HS.faNum(n) + ' دانش‌آموز انتخاب شده';
      selCount.classList.toggle('on', n > 0);
    }
    if (selRun) selRun.disabled = n === 0;
    if (selAllCb) {
      const all = HS.$$('.rowSel');
      selAllCb.checked = all.length > 0 && n === all.length;
      selAllCb.indeterminate = n > 0 && n < all.length;
    }
    HS.$$('.rowSel').forEach(c => { const tr = c.closest('tr'); if (tr) tr.classList.toggle('picked', c.checked); });
    updateSelOpUI();
  }

  function fillFieldSelect(sel, grade, placeholder, keepValue) {
    if (!sel) return;
    const list = FIELDS_BY_GRADE[grade] || [];
    const old = keepValue || sel.value;
    sel.innerHTML = '<option value="">' + placeholder + '</option>'
      + list.map(f => '<option value="' + f + '">' + f + '</option>').join('');
    if (list.indexOf(old) > -1) sel.value = old;
  }

  function updateSelOpUI() {
    if (!selOp) return;
    const op = selOp.value;
    const gBox = document.getElementById('selGradeBox');
    const fBox = document.getElementById('selFieldBox');
    const note = document.getElementById('selNote');
    const isGrade = op === 'grade', isField = op === 'field';
    if (gBox) gBox.classList.toggle('hidden', !isGrade);
    if (fBox) fBox.classList.toggle('hidden', !(isGrade || isField));
    if (isGrade) {
      fillFieldSelect(selField, selGrade.value, 'بدون تغییر رشته', selField.value);
      const h = document.getElementById('selFieldHint');
      if (h) h.textContent = 'خالی = رشته ثابت بماند';
    } else if (isField) {
      /* برای تغییر رشته، رشتهٔ جدید باید با پایهٔ فعلی دانش‌آموزان سازگار باشد؛ هر دو پایه را پوشش می‌دهیم */
      if (selField) {
        const rows = checked();
        const grades = Array.from(new Set(rows.map(r => r.dataset.grade)));
        let list;
        if (grades.length) {
          list = commonFields(grades);
        } else {
          /* هیچ ردیفی تیک نخورده: همهٔ رشته‌های مجاز سامانه */
          list = Object.keys(FIELDS_BY_GRADE).reduce((a, k) => a.concat(FIELDS_BY_GRADE[k]), [])
                   .filter((v, i, arr) => arr.indexOf(v) === i);
        }
        const old = selField.value;
        selField.innerHTML = '<option value="">— انتخاب رشته —</option>'
          + list.map(f => '<option value="' + f + '">' + f + '</option>').join('');
        if (list.indexOf(old) > -1) selField.value = old;
      }
      const h = document.getElementById('selFieldHint');
      if (h) h.textContent = 'رشته‌های مشترک بین پایه‌های انتخاب‌شده';
    }
    if (note) {
      note.textContent = (op === 'delete')
        ? 'حذف دانش‌آموزان و همهٔ سوابقشان بازگشت‌پذیر نیست؛ در مرحلهٔ بعد تأیید می‌گیرید.'
        : (op === 'grade' || op === 'field')
          ? 'دانش‌آموزانی که وضعیت جدید برایشان مجاز نیست (مثل کامپیوتر در دوازدهم) رد می‌شوند و فهرستشان نمایش داده می‌شود.'
          : 'برای ثبت تأخیر/غیبت/انضباط/پوشش، پس از اجرا به تب «ثبت گروهی» صفحهٔ ثبت موارد منتقل می‌شوید و تاریخ و جزئیات را آنجا تأیید می‌کنید.';
    }
  }

  /* رشته‌های مشترک بین چند پایه */
  function commonFields(grades) {
    if (!grades.length) return [];
    const keys = Object.keys(FIELDS_BY_GRADE);
    let acc = (FIELDS_BY_GRADE[grades[0]] || []).slice();
    grades.slice(1).forEach(g => { acc = acc.filter(f => (FIELDS_BY_GRADE[g] || []).indexOf(f) > -1); });
    /* اگر پایه ناشناخته بود، از کل فهرست رشته‌ها استفاده کن */
    if (!acc.length) {
      acc = keys.reduce((a, k) => a.concat(FIELDS_BY_GRADE[k]), []).filter((v, i, arr) => arr.indexOf(v) === i);
    }
    return acc;
  }

  HS.$$('.rowSel').forEach(c => c.addEventListener('change', refreshSel));
  if (selAllCb) selAllCb.addEventListener('change', function () {
    HS.$$('.rowSel').forEach(c => { c.checked = selAllCb.checked; });
    refreshSel();
  });
  if (selOp) selOp.addEventListener('change', updateSelOpUI);
  if (selGrade) selGrade.addEventListener('change', () => { fillFieldSelect(selField, selGrade.value, 'بدون تغییر رشته', ''); });

  if (selForm) selForm.addEventListener('submit', function (e) {
    if (this.dataset.done) return;
    e.preventDefault();
    const rows = checked();
    const op = selOp ? selOp.value : '';
    if (!op) { HS.toast('ابتدا نوع عملیات گروهی را انتخاب کنید.', 'err', 'عملیات گروهی'); return; }
    if (!rows.length) { HS.toast('هیچ دانش‌آموزی تیک نخورده است.', 'err', 'عملیات گروهی'); return; }
    const names = rows.slice(0, 5).map(r => r.dataset.name).join('، ') + (rows.length > 5 ? ' و …' : '');
    let q = '';
    if (op === 'delete') {
      q = 'حذف <b>' + HS.faNum(rows.length) + '</b> دانش‌آموز و همهٔ سوابقشان؟<br><span class="small">' + names + '</span><br>این عملیات بازگشت‌پذیر نیست.';
    } else if (op === 'grade') {
      if (!selGrade.value) { HS.toast('پایهٔ جدید را انتخاب کنید.', 'err', 'تغییر پایه'); return; }
      q = 'پایهٔ <b>' + HS.faNum(rows.length) + '</b> دانش‌آموز به <b>' + selGrade.value + '</b>'
        + (selField.value ? ' و رشته به <b>' + selField.value + '</b>' : '') + ' تغییر کند؟<br><span class="small">' + names + '</span>';
    } else if (op === 'field') {
      if (!selField.value) { HS.toast('رشتهٔ جدید را انتخاب کنید.', 'err', 'تغییر رشته'); return; }
      q = 'رشتهٔ <b>' + HS.faNum(rows.length) + '</b> دانش‌آموز به <b>' + selField.value + '</b> تغییر کند؟<br><span class="small">' + names + '</span>';
    } else {
      const L = { delay: 'تأخیر', absence: 'غیبت', discipline: 'مورد انضباطی', dress: 'تخلف پوششی' };
      q = 'برای <b>' + HS.faNum(rows.length) + '</b> دانش‌آموز، فرم ثبت <b>' + (L[op] || op) + '</b> باز شود؟<br><span class="small">' + names + '</span>';
    }
    HS.confirm(q, () => {
      this.dataset.done = '1';
      HS.loading(true, 'در حال اجرای عملیات گروهی…');
      this.submit();
    }, { yes: 'تأیید و ادامه', title: 'تأیید عملیات گروهی' });
  });

  refreshSel();

  /* --- فیلترهای تب عملیات گروهی (تغییر پایه/رشته گروهی) --- */
  const bindFieldSelect = (gradeId, fieldId, placeholder) => {
    const g = document.getElementById(gradeId), f = document.getElementById(fieldId);
    if (g && f) g.addEventListener('change', () => fillFieldSelect(f, g.value, placeholder, ''));
  };
  bindFieldSelect('bgFrom', 'bgField', 'همهٔ رشته‌ها');
  bindFieldSelect('bgTo', 'bgToField', 'بدون تغییر رشته');
  bindFieldSelect('bfGrade', 'bfFrom', 'همهٔ رشته‌ها');
  const bfGradeEl = document.getElementById('bfGrade'), bfToEl = document.getElementById('bfTo');
  if (bfGradeEl && bfToEl) bfGradeEl.addEventListener('change', () => fillFieldSelect(bfToEl, bfGradeEl.value, '— انتخاب رشته —', ''));

  /* --- باز شدن تب جاری از URL، و در نبود آن از آخرین تب استفاده‌شده --- */
  const urlTab = new URLSearchParams(location.search).get('tab');
  let savedTab = null;
  try { savedTab = localStorage.getItem('hs_students_tab'); } catch (e) {}
  const finalTab = (urlTab && document.getElementById('tab-' + urlTab)) ? urlTab
                 : ((savedTab && document.getElementById('tab-' + savedTab)) ? savedTab : null);
  if (finalTab && !document.querySelector('.alert.err')) goTab(finalTab);
});
JS;
/* تزریق داده‌های PHP به‌صورت امن در جاوااسکریپت
   (نکته: چون اسکریپت داخل nowdoc نوشته شده، برچسب‌های <?= ?> اجرا نمی‌شوند و
    باید با Placeholder جایگزین شوند — باگ رفع‌شده) */
$studentsJs = str_replace(
    '__FIELDS_BY_GRADE__',
    json_encode(HS_FIELDS_BY_GRADE, JSON_UNESCAPED_UNICODE),
    $studentsJs
);
layout_end([$studentsJs]);
