<?php
/**
 * ============================================================================
 *  سامانه جامع مدیریت هنرستان شهید رجایی
 *  فایل: router.php — روتر آزمایشی برای سرور داخلی PHP
 * ----------------------------------------------------------------------------
 *  کاربرد: فقط برای اجرای آزمایشی روی سیستم شخصی با دستور زیر
 *      php -S 0.0.0.0:8080 router.php
 *
 *  روی هاست واقعی (Apache/LiteSpeed) به این فایل نیازی نیست؛ محافظت از
 *  فایل‌های حساس توسط فایل .htaccess انجام می‌شود.
 *
 *  وظیفه: مسدودسازی دسترسی مستقیم مرورگر به فایل‌های حساس و پوشه data
 *         و هدایت درخواست «/» به index.php
 * ============================================================================
 */

/** آیکون قفل خطی برای صفحهٔ ۴۰۳ (بدون ایموجی) */
function svg_lock(int $size = 48): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" '
        . 'fill="none" stroke="#dc2626" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'
        . '<rect x="4.6" y="10.4" width="14.8" height="10" rx="2"/>'
        . '<path d="M8.2 10.4V7.6a3.8 3.8 0 0 1 7.6 0v2.8"/><path d="M12 14.4v3"/></svg>';
}

$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = ltrim(rawurldecode($uri), '/');

/* ۱) هدایت ریشه به پنل اصلی */
if ($path === '' || $path === 'honarestan' || $path === 'honarestan/') {
    require __DIR__ . '/index.php';
    return true;
}

/* حذف پیشوند پوشه در صورت اجرا از مسیر والد */
$path = preg_replace('#^honarestan/#', '', $path) ?? $path;

/* ۲) مسدودسازی مسیرهای حساس (معادل قواعد .htaccess) */
$blocked = [
    '#^data/#',                             // پایگاه داده SQLite، کش تاریخ، فایل قفل
    '#^config\.php$#',
    '#^db\.php$#',
    '#^icons\.php$#',                       // فایل آیکون‌ها (فقط از داخل کد فراخوانی می‌شود)
    '#\.(sql|sqlite|sqlite3|lock|log|md|ini|sh|bak|old)$#i',
    '#^\.#',                                // فایل‌های مخفی
];

foreach ($blocked as $pattern) {
    if (preg_match($pattern, $path)) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>۴۰۳ — دسترسی مسدود</title></head>'
           . '<body style="font-family:Tahoma,sans-serif;background:#f4f6fa;display:flex;align-items:center;'
           . 'justify-content:center;min-height:100vh;margin:0">'
           . '<div style="background:#fff;padding:32px 40px;border-radius:16px;text-align:center;'
           . 'box-shadow:0 14px 40px rgba(15,42,82,.12);border-top:6px solid #dc2626;max-width:520px">'
           . '<div>' . svg_lock(52) . '</div><h2 style="color:#0f2a52;margin:8px 0">۴۰۳ — دسترسی مسدود است</h2>'
           . '<p style="color:#475569;line-height:2">دسترسی مستقیم به فایل‌های حساس سامانه مجاز نیست.</p>'
           . '<a href="/index.php" style="display:inline-block;background:#0f2a52;color:#fff;padding:11px 22px;'
           . 'border-radius:11px;text-decoration:none;font-weight:bold">بازگشت به سامانه</a></div></body></html>';
        return true;
    }
}

/* ۳) اجرای فایل‌های PHP با شبیه‌سازی محیط واقعی سرور
      (بدون این بخش، $_SERVER['SCRIPT_NAME'] به router.php اشاره می‌کرد و
       لینک‌های صفحه‌بندی/مرتب‌سازی اشتباه ساخته می‌شدند) */
$real = __DIR__ . '/' . $path;
if ($path !== '' && is_file($real)) {
    if (strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'php') {
        $_SERVER['SCRIPT_NAME']     = '/' . $path;
        $_SERVER['PHP_SELF']        = '/' . $path;
        $_SERVER['SCRIPT_FILENAME'] = $real;
        $_SERVER['DOCUMENT_ROOT']   = __DIR__;
        chdir(dirname($real));
        require $real;
        return true;
    }
    return false;      /* فایل استاتیک توسط سرور داخلی سرو می‌شود */
}

/* ۴) سایر درخواست‌ها → خطای ۴۰۴ فارسی */
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>۴۰۴</title></head>'
   . '<body style="font-family:Tahoma,sans-serif;background:#f4f6fa;display:flex;align-items:center;'
   . 'justify-content:center;min-height:100vh;margin:0">'
   . '<div style="background:#fff;padding:32px 40px;border-radius:16px;text-align:center;box-shadow:0 14px 40px rgba(15,42,82,.12);'
   . 'border-top:6px solid #f59e0b;max-width:520px"><div style="font-size:50px"></div>'
   . '<h2 style="color:#0f2a52;margin:8px 0">۴۰۴ — صفحه یافت نشد</h2>'
   . '<p style="color:#475569;line-height:2">آدرس درخواستی در سامانه وجود ندارد.</p>'
   . '<a href="/index.php" style="display:inline-block;background:#0f2a52;color:#fff;padding:11px 22px;'
   . 'border-radius:11px;text-decoration:none;font-weight:bold">صفحه ورود</a></div></body></html>';
return true;
