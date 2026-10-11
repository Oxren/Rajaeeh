<?php
/**
 * ============================================================================
 *  سامانه جامع مدیریت هنرستان شهید رجایی
 *  فایل: index.php — پنل اصلی (ورود به سامانه + داشبورد آماری)
 * ----------------------------------------------------------------------------
 *  قابلیت‌ها:
 *    • فرم ورود امن با CSRF، Rate Limiting و پیام‌های فارسی
 *    • داشبورد با کارت‌های آماری (دانش‌آموزان، غیبت امروز، تأخیر امروز، انضباط هفته)
 *    • نمودارهای میله‌ای توزیع پایه/رشته، جدول هفتگی و هنرجویان پرتخلف
 *    • API کوچک داخلی (action=…&ajax=1) برای بروزرسانی آمار بدون رفرش
 *
 *  تمام CSS و JavaScript این پنل به‌صورت Inline در همین فایل است.
 * ============================================================================
 */

require __DIR__ . '/db.php';

hs_session_start();

/* اگر سامانه نصب نشده است، به صفحه نصب هدایت شود */
if (!hs_is_installed()) {
    header('Location: install.php');
    exit;
}

/* ---------------------------------------------------------------------------
 * ۱) پردازش ورود / خروج
 * ------------------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (req('action') === 'login')) {
    csrf_require();

    $username = clean_text(req('username'), 60);
    $password = (string)req('password');

    if ($username === '' || $password === '') {
        flash_set('error', 'نام کاربری و رمز عبور را کامل وارد کنید.');
    } else {
        $rate = login_rate_check($username);
        if (!$rate['allowed']) {
            flash_set('error', 'به دلیل تلاش‌های ناموفق متعدد، ورود موقتاً مسدود شده است. لطفاً چند دقیقه بعد تلاش کنید.');
            log_activity('ورود مسدود شد (Rate Limit)', 'نام کاربری: ' . $username);
        } else {
            $admin = row('SELECT * FROM admins WHERE username = ?', [$username]);
            if (!$admin || (int)$admin['is_active'] !== 1 || !password_verify($password, (string)$admin['password'])) {
                login_attempt_log($username, false);
                $left = $rate['remaining'] - 1;
                flash_set('error', 'نام کاربری یا رمز عبور نادرست است.' . ($left > 0 ? " تلاش‌های باقی‌مانده: " . fa_num(max(0, $left)) : ''));
            } else {
                /* ورود موفق */
                login_attempt_log($username, true);
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int)$admin['id'];
                $_SESSION['login_at'] = time();
                log_activity('ورود به سامانه', 'کاربر: ' . $admin['username']);
                $redirect = $_SESSION['redirect_after_login'] ?? 'index.php';
                unset($_SESSION['redirect_after_login']);
                flash_set('success', 'خوش آمدید ' . $admin['full_name'] . '؛ به سامانهٔ هنرستان شهید رجایی وارد شدید.');
                header('Location: ' . (str_starts_with($redirect, 'index.php') ? 'index.php' : $redirect));
                exit;
            }
        }
    }
    header('Location: index.php');
    exit;
}

/* ---------------------------------------------------------------------------
 * ۲) بروزرسانی اجباری تاریخ/ساعت از API
 * ------------------------------------------------------------------------ */
if (req('refresh_time') === '1') {
    hs_now(true);
    flash_set('info', 'تاریخ و ساعت از سرویس معتبر بروزرسانی شد.');
    header('Location: index.php');
    exit;
}

/* ---------------------------------------------------------------------------
 * ۳) خروجی JSON سبک برای بروزرسانی زنده کارت‌ها
 * ------------------------------------------------------------------------ */
if (req('action') === 'stats' && req('ajax') === '1') {
    if (!hs_logged_in()) {
        json_out(['ok' => false, 'message' => 'نشست شما منقضی شده است.'], 401);
    }
    $s = dashboard_stats();
    json_out([
        'ok' => true,
        'stats' => [
            'students'        => $s['students'],
            'absences_today'  => $s['absences_today'],
            'delays_today'    => $s['delays_today'],
            'discipline_week' => $s['discipline_week'],
            'dress_today'     => $s['dress_today'],
        ],
        'now' => hs_now(),
        'server_time' => date('H:i:s'),
    ]);
}

/* ---------------------------------------------------------------------------
 * ۴) اگر وارد نشده است → نمایش فرم ورود
 * ------------------------------------------------------------------------ */
if (!hs_logged_in()) {
    $now = hs_now();
    /* نمایش راهنمای حساب‌های نمونه فقط اگر رمز پیش‌فرض تغییر نکرده باشد */
    $demoAdmin = row('SELECT password FROM admins WHERE username = ?', ['admin']);
    $showDemo  = $demoAdmin && password_verify('admin123', (string)$demoAdmin['password']);
    $studentsCount = (int)one('SELECT COUNT(*) FROM students');
    $adminsCount   = (int)one('SELECT COUNT(*) FROM admins');
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ورود به سامانه | <?= e(HS_SCHOOL_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#0f2a52"/><text x="32" y="44" font-size="34" text-anchor="middle" fill="#fff">ه</text></svg>') ?>">
<style>
@font-face{font-family:"Vazirmatn";font-style:normal;font-weight:400 900;font-display:swap;
 src:url("https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/fonts/webfonts/Vazirmatn%5Bwght%5D.woff2") format("woff2")}
:root{--navy:#0f2a52;--navy2:#123c6b;--navy-bg:#0f2a52;--navy-bg-2:#123c6b;--blue:#2f6fed;--blue-soft:#e8f0ff;--gray-50:#f5f7fb;--gray-100:#eef2f7;
 --gray-200:#e2e8f0;--gray-300:#cbd5e1;--gray-500:#64748b;--gray-700:#334155;
 --green:#16a34a;--green-soft:#e7f8ee;--amber:#f59e0b;--amber-soft:#fef3c7;--red:#dc2626;--red-soft:#fee2e2;
 --font:"Vazirmatn","Vazir",Tahoma,"Segoe UI",sans-serif}
*{box-sizing:border-box}
body{margin:0;font-family:var(--font);direction:rtl;line-height:1.9;color:var(--gray-700);
 background:#0f2a52;min-height:100vh;display:grid;place-items:center;padding:22px;position:relative;overflow-x:hidden}
body::before,body::after{content:"";position:absolute;border-radius:50%;filter:blur(70px);opacity:.5;z-index:0}
body::before{width:420px;height:420px;background:#2f6fed;top:-120px;inset-inline-start:-100px}
body::after{width:380px;height:380px;background:#22d3ee;bottom:-140px;inset-inline-end:-90px;opacity:.35}
.login{width:100%;max-width:940px;display:grid;grid-template-columns:1.05fr .95fr;background:#fff;border-radius:24px;
 overflow:hidden;box-shadow:0 30px 80px rgba(4,14,32,.5);position:relative;z-index:2;animation:up .5s ease}
@keyframes up{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:none}}
.side{background:linear-gradient(165deg,var(--navy-bg) 0%,var(--navy-bg-2) 55%,#1b4b85 100%);color:#fff;padding:36px 30px;position:relative}
.side .logo{width:70px;height:70px;border-radius:20px;background:linear-gradient(135deg,var(--blue),#22d3ee);
 display:grid;place-items:center;font-size:34px;box-shadow:0 14px 34px rgba(47,111,237,.45);margin-bottom:18px}
.side h1{margin:0 0 8px;font-size:23px;color:#fff}
.side p{margin:0;color:#b9cdea;font-size:13.4px}
.side .facts{margin-top:26px;display:grid;gap:11px}
.side .fact{background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.14);border-radius:14px;padding:12px 14px;
 display:flex;align-items:center;gap:11px;font-size:13px;backdrop-filter:blur(6px)}
.side .fact b{color:#fff;font-size:17px;min-width:34px}
.side .feat{margin-top:24px;font-size:12.6px;color:#a9c1e2;line-height:2.1}
.side .feat i{font-style:normal;color:#7dd3fc}
.side .clock{margin-top:22px;background:rgba(0,0,0,.22);border-radius:13px;padding:11px 14px;font-size:13px;
 font-weight:700;display:flex;gap:8px;align-items:center;flex-wrap:wrap;color:#dbeafe}
.form-side{padding:38px 34px}
.form-side h2{margin:0 0 5px;color:var(--navy);font-size:20px}
.form-side .sub{color:var(--gray-500);font-size:13px;margin-bottom:22px}
label.fl{display:block;font-weight:700;font-size:13.2px;margin-bottom:7px}
.inp{position:relative;margin-bottom:16px}
.inp input{width:100%;padding:13px 42px 13px 14px;border:1.7px solid var(--gray-200);border-radius:13px;
 font-family:inherit;font-size:14px;outline:none;transition:.25s;background:#fff;color:var(--gray-700)}
.inp input:focus{border-color:var(--blue);box-shadow:0 0 0 4px rgba(47,111,237,.14)}
.inp .ic{position:absolute;inset-inline-start:13px;top:50%;transform:translateY(-50%);font-size:16px;opacity:.6}
.inp .eye{position:absolute;inset-inline-end:12px;top:50%;transform:translateY(-50%);background:none;border:none;
 cursor:pointer;font-size:15px;opacity:.65}
.inp input.bad{border-color:var(--red);background:#fff6f6}
.err{font-size:12px;color:var(--red);font-weight:700;margin-top:-9px;margin-bottom:12px;display:none}
.err.show{display:block}
.btn{width:100%;border:none;cursor:pointer;font-family:inherit;font-weight:800;font-size:15px;padding:14px;
 border-radius:13px;background:linear-gradient(135deg,var(--navy-bg),var(--navy-bg-2));color:#fff;transition:.25s;
 display:flex;align-items:center;justify-content:center;gap:9px}
.btn:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(15,42,82,.28)}
.btn:disabled{opacity:.6;cursor:not-allowed;transform:none}
.alert{padding:12px 15px;border-radius:12px;font-size:13px;margin-bottom:15px;border:1px solid transparent}
.alert.err{background:var(--red-soft);border-color:#fecaca;color:#991b1b}
.alert.ok{background:var(--green-soft);border-color:#b7ebc9;color:#14532d}
.alert.info{background:var(--blue-soft);border-color:#bfd4fb;color:#1e3a8a}
.alert.warn{background:var(--amber-soft);border-color:#fde68a;color:#92400e}
.demo{margin-top:20px;border:1.5px dashed var(--gray-300);border-radius:14px;padding:14px;background:var(--gray-50);font-size:12.4px}
.demo b{color:var(--navy);display:block;margin-bottom:7px;font-size:12.8px}
.demo table{width:100%;border-collapse:collapse}
.demo td{padding:4px 3px}
code{background:#0f172a;color:#7dd3fc;padding:2px 7px;border-radius:6px;font-size:12px;direction:ltr;display:inline-block}
.copy{cursor:pointer;font-size:11px;color:var(--blue);background:none;border:none;font-family:inherit;font-weight:700}
.foot{margin-top:18px;text-align:center;color:var(--gray-500);font-size:11.8px}
#loader{position:fixed;inset:0;background:rgba(15,42,82,.72);display:none;align-items:center;justify-content:center;
 flex-direction:column;gap:14px;z-index:99;backdrop-filter:blur(3px)}
#loader b{color:#fff}
.sp{width:58px;height:58px;border-radius:50%;border:5px solid rgba(255,255,255,.25);border-top-color:#7dd3fc;animation:s .85s linear infinite}
@keyframes s{to{transform:rotate(360deg)}}
.toasts{position:fixed;top:14px;inset-inline-start:14px;z-index:999;display:flex;flex-direction:column;gap:9px;max-width:360px}
.toast{background:#fff;border-radius:13px;padding:12px 15px;box-shadow:0 16px 40px rgba(4,14,32,.28);
 border-inline-start:5px solid var(--blue);font-size:13px;animation:tIn .3s ease}
.toast.ok{border-color:var(--green)} .toast.err{border-color:var(--red)} .toast.info{border-color:var(--blue)}
@keyframes tIn{from{opacity:0;transform:translateY(-12px)}to{opacity:1;transform:none}}
@media(max-width:860px){.login{grid-template-columns:1fr}.side{padding:26px 22px}.form-side{padding:28px 22px}}
</style>
</head>
<body>
<div class="login">

  <!-- ستون معرفی -->
  <div class="side">
    <div class="logo"><?= hs_icon('school', 30) ?></div>
    <h1><?= e(HS_SCHOOL_NAME) ?></h1>
    <p>سامانه جامع مدیریت آموزشی — دانش‌آموزان، انضباط، غیبت، تأخیر و گزارش‌های PDF</p>

    <div class="facts">
      <div class="fact"><b><?= fa_num($studentsCount) ?></b> هنرجوی ثبت‌شده در سامانه</div>
      <div class="fact"><b><?= fa_num($adminsCount) ?></b> کاربر (مدیر، معاون، معلم)</div>
      <div class="fact"><b><?= fa_num(count(HS_PERMISSIONS)) ?></b> پرمیشن قابل تخصیص</div>
    </div>

    <div class="feat">
      <i><?= hs_icon('check', 14) ?></i> ثبت تأخیر، غیبت، موارد انضباطی و تخلف پوششی<br>
      <i><?= hs_icon('check', 14) ?></i> خروجی PDF با جدول‌بندی روزبه‌روز بازه تاریخی<br>
      <i><?= hs_icon('check', 14) ?></i> تقویم شمسی با پشتیبانی API معتبر<br>
      <i><?= hs_icon('check', 14) ?></i> مدیریت ادمین‌ها با پرمیشن granular
    </div>

    <div class="clock" id="loginClock"><?= hs_icon('hourglass', 17) ?> در حال دریافت تاریخ…</div>
  </div>

  <!-- ستون فرم ورود -->
  <div class="form-side">
    <h2>ورود به حساب کاربری</h2>
    <div class="sub">برای ادامه، نام کاربری و رمز عبور خود را وارد کنید.</div>

    <div id="flashBox"></div>

    <form method="post" id="loginForm" autocomplete="off" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="login">

      <label class="fl" for="username">نام کاربری</label>
      <div class="inp">
        <span class="ic"><?= hs_icon('user', 18) ?></span>
        <input type="text" id="username" name="username" placeholder="مثال: admin" required autofocus
               maxlength="60" spellcheck="false">
      </div>
      <div class="err" id="errUser">نام کاربری را وارد کنید (حداقل ۳ کاراکتر).</div>

      <label class="fl" for="password">رمز عبور</label>
      <div class="inp">
        <span class="ic"><?= hs_icon('lock', 18) ?></span>
        <input type="password" id="password" name="password" placeholder="••••••••" required minlength="4">
        <button type="button" class="eye" id="eye" title="نمایش/پنهان کردن رمز"><?= hs_icon('eye', 16) ?></button>
      </div>
      <div class="err" id="errPass">رمز عبور را وارد کنید (حداقل ۴ کاراکتر).</div>

      <button class="btn" type="submit" id="loginBtn">ورود به سامانه <?= hs_icon('logout', 16) ?></button>
    </form>

    <?php if ($showDemo): ?>
      <div class="demo">
        <b><?= hs_icon('key', 14) ?> حساب‌های نمونه (برای تست سامانه)</b>
        <table>
          <tr><td>مدیر کل:</td><td><code>admin</code></td><td><code>admin123</code></td><td><button class="copy" data-u="admin" data-p="admin123"><?= hs_icon('copy', 14) ?> درج</button></td></tr>
          <tr><td>معاون:</td><td><code>deputy</code></td><td><code>Deputy@1404</code></td><td><button class="copy" data-u="deputy" data-p="Deputy@1404"><?= hs_icon('copy', 14) ?> درج</button></td></tr>
          <tr><td>معلم:</td><td><code>teacher</code></td><td><code>Teacher@1404</code></td><td><button class="copy" data-u="teacher" data-p="Teacher@1404"><?= hs_icon('copy', 14) ?> درج</button></td></tr>
        </table>
        <div style="margin-top:8px;color:var(--gray-500)"><?= hs_icon('alert', 17) ?> پس از نخستین ورود، رمزها را از پنل مدیریت ادمین‌ها تغییر دهید.</div>
      </div>
    <?php endif; ?>

    <div class="foot">نسخه <?= fa_num(HS_VERSION) ?> — <?= e(HS_SCHOOL_NAME) ?></div>
  </div>
</div>

<div id="loader"><div class="sp"></div><b>در حال بررسی اطلاعات…</b></div>
<div class="toasts" id="toasts"></div>

<script>
/* ============ اسکریپت صفحه ورود ============ */
(function () {
  "use strict";
  const $ = s => document.querySelector(s);

  /* توست */
  function toast(msg, type) {
    const box = $('#toasts');
    const el = document.createElement('div');
    el.className = 'toast ' + (type || 'info');
    el.innerHTML = (type === 'err' ? '<?= hs_icon('ban', 17) ?> ' : type === 'ok' ? '<?= hs_icon('check', 17) ?> ' : '<?= hs_icon('info', 17) ?> ');
    el.appendChild(document.createTextNode(msg));   /* متن پیام هرگز به‌صورت HTML تفسیر نمی‌شود */
    box.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 250); }, 5000);
  }

  /* نمایش پیام‌های Flash سرور */
  <?php foreach (flash_take() as $f):
      $cls = in_array($f['type'], ['error', 'danger'], true) ? 'err' : ($f['type'] === 'success' || $f['type'] === 'ok' ? 'ok' : $f['type']);
  ?>
  toast(<?= json_encode($f['message'], JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($cls, JSON_UNESCAPED_UNICODE) ?>);
  <?php endforeach; ?>

  /* نمودن/پنهان کردن رمز */
  $('#eye').onclick = function () {
    const p = $('#password');
    const show = p.type === 'password';
    p.type = show ? 'text' : 'password';
    this.innerHTML = '<?= hs_icon('eye', 17) ?>';
  };

  /* درج سریع حساب نمونه */
  document.querySelectorAll('.copy').forEach(b => b.onclick = () => {
    $('#username').value = b.dataset.u;
    $('#password').value = b.dataset.p;
    toast('اطلاعات حساب «' + b.dataset.u + '» درج شد.', 'ok');
  });

  /* اعتبارسنجی سمت کلاینت */
  const f = $('#loginForm');
  f.addEventListener('submit', function (e) {
    let ok = true;
    const u = $('#username'), p = $('#password');
    const eu = $('#errUser'), ep = $('#errPass');
    if (u.value.trim().length < 3) { eu.classList.add('show'); u.classList.add('bad'); ok = false; }
    else { eu.classList.remove('show'); u.classList.remove('bad'); }
    if (p.value.length < 4) { ep.classList.add('show'); p.classList.add('bad'); ok = false; }
    else { ep.classList.remove('show'); p.classList.remove('bad'); }
    if (!ok) {
      e.preventDefault();
      toast('لطفاً خطاهای فرم را برطرف کنید.', 'err');
      return false;
    }
    $('#loader').style.display = 'flex';
    $('#loginBtn').disabled = true;
    $('#loginBtn').textContent = 'در حال ورود…';
  });
  [$('#username'), $('#password')].forEach(i => i.addEventListener('input', () => {
    i.classList.remove('bad');
    (i.id === 'username' ? $('#errUser') : $('#errPass')).classList.remove('show');
  }));

  /* ساعت زنده شمسی در ستون معرفی */
  const now = <?= json_encode(['jdate' => $now['jdate'], 'time' => $now['time'], 'source' => $now['source']], JSON_UNESCAPED_UNICODE) ?>;
  const WD = ['شنبه', 'یک‌شنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه'];
  const FA = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
  const fa = v => String(v).replace(/\d/g, d => FA[+d]);
  let [jy, jm, jd] = now.jdate.split('/').map(Number);
  let secs = (function () { const t = (now.time || '00:00').split(':').map(Number); return t[0] * 3600 + t[1] * 60; })();
  function g2j(gy, gm, gd) {
    const gdm = [0,31,59,90,120,151,181,212,243,273,304,334], gy2 = gm > 2 ? gy + 1 : gy;
    let days = 355666 + 365 * gy + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) +
               Math.floor((gy2 + 399) / 400) + gd + gdm[gm - 1];
    let jy = -1595 + 33 * Math.floor(days / 12053); days %= 12053;
    jy += 4 * Math.floor(days / 1461); days %= 1461;
    if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
    return days < 186 ? [jy, 1 + Math.floor(days / 31), 1 + days % 31]
                      : [jy, 7 + Math.floor((days - 186) / 30), 1 + (days - 186) % 30];
  }
  function nextDay() { const d = new Date(); const j = g2j(d.getFullYear(), d.getMonth() + 1, d.getDate()); jy = j[0]; jm = j[1]; jd = j[2]; }
  function wd(jv) { const p = jv.split('/').map(Number);
    const g = [0,31,59,90,120,151,181,212,243,273,304,334], gy2 = p[1] > 2 ? p[0] + 1 : p[0];
    let days = 355666 + 365 * p[0] + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) +
               Math.floor((gy2 + 399) / 400) + p[2] + g[p[1] - 1];
    let jy2 = -1595 + 33 * Math.floor(days / 12053); days %= 12053;
    jy2 += 4 * Math.floor(days / 1461); days %= 1461;
    if (days > 365) { jy2 += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
    return WD[(days + 1) % 7] || '';
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function paint() {
    $('#loginClock').innerHTML = '<?= hs_icon('calendar', 17) ?> ' + fa(jy + '/' + pad(jm) + '/' + pad(jd)) + ' <span style="opacity:.75">' + wd(jy + '/' + pad(jm) + '/' + pad(jd)) + '</span> <?= hs_icon('clock', 17) ?> ' +
      fa(pad(Math.floor(secs / 3600)) + ':' + pad(Math.floor(secs % 3600 / 60)) + ':' + pad(secs % 60)) +
      ' <span style="font-size:10.5px;background:rgba(255,255,255,.16);padding:2px 8px;border-radius:20px">' +
      (now.source.indexOf('api') === 0 ? 'API' : 'محلی') + '</span>';
  }
  setInterval(() => { secs++; if (secs >= 86400) { secs = 0; nextDay(); } paint(); }, 1000);
  paint();
})();
</script>
</body>
</html>
    <?php
    exit;
}

/* ---------------------------------------------------------------------------
 * ۵) داشبورد (کاربر وارد شده)
 * ------------------------------------------------------------------------ */
$admin = current_admin();
$stats = dashboard_stats();
$now   = hs_now();

/* آخرین رکوردهای روز آماری (امروز یا آخرین روز کاری، اگر امروز تعطیل باشد)
   — محتوای فهرست‌ها بر پایه دامنه دسترسی کاربر فیلتر می‌شود */
$todayJ  = $stats['today'];
$statDay = $stats['stat_day'];
$viewAll = $stats['view_all'];

[$fDelay, $pDelay] = creator_filter('d');
[$fAbs,   $pAbs]   = creator_filter('a');
[$fDisc,  $pDisc]  = creator_filter('dc');
[$fDress, $pDress] = creator_filter('v');

$todayDelays = rows(
    "SELECT d.time, s.first_name, s.last_name, s.grade, s.field, ad.full_name AS by_name
     FROM delays d JOIN students s ON s.id = d.student_id
     LEFT JOIN admins ad ON ad.id = d.created_by
     WHERE d.jdate = ? AND {$fDelay} ORDER BY d.time DESC LIMIT 8",
    array_merge([$statDay], $pDelay)
);
$todayAbsences = rows(
    "SELECT a.day_of_week, s.first_name, s.last_name, s.grade, s.field, ad.full_name AS by_name
     FROM absences a JOIN students s ON s.id = a.student_id
     LEFT JOIN admins ad ON ad.id = a.created_by
     WHERE a.jdate = ? AND {$fAbs} ORDER BY a.id DESC LIMIT 8",
    array_merge([$statDay], $pAbs)
);
$todayDiscipline = rows(
    "SELECT dc.type, dc.description, dc.deduction, s.first_name, s.last_name, ad.full_name AS by_name
     FROM disciplines dc JOIN students s ON s.id = dc.student_id
     LEFT JOIN admins ad ON ad.id = dc.created_by
     WHERE dc.jdate = ? AND {$fDisc} ORDER BY dc.id DESC LIMIT 8",
    array_merge([$statDay], $pDisc)
);
$todayDress = rows(
    "SELECT v.type, v.description, s.first_name, s.last_name, ad.full_name AS by_name
     FROM dress_violations v JOIN students s ON s.id = v.student_id
     LEFT JOIN admins ad ON ad.id = v.created_by
     WHERE v.jdate = ? AND {$fDress} ORDER BY v.id DESC LIMIT 8",
    array_merge([$statDay], $pDress)
);
$top = top_offenders(6);

/* نمودار ۷ روز گذشته — روزهای تعطیل (پنجشنبه و جمعه) نمایش داده نمی‌شوند */
$weekBars = [];
for ($i = 6; $i >= 0; $i--) {
    $j = jalali_add_days($todayJ, -$i);
    if (hs_is_off_day($j)) {
        continue;                          // روز تعطیل شمرده نمی‌شود
    }
    $g  = jalali_to_gregorian_str($j);
    $dl = (int)one("SELECT COUNT(*) FROM delays d WHERE d.jdate = ? AND {$fDelay}", array_merge([$j], $pDelay));
    $ab = (int)one("SELECT COUNT(*) FROM absences a WHERE a.jdate = ? AND {$fAbs}", array_merge([$j], $pAbs));
    $dc = (int)one("SELECT COUNT(*) FROM disciplines dc WHERE dc.gdate = ? AND {$fDisc}", array_merge([$g], $pDisc));
    $dr = (int)one("SELECT COUNT(*) FROM dress_violations v WHERE v.jdate = ? AND {$fDress}", array_merge([$j], $pDress));
    $weekBars[] = ['jdate' => $j, 'delays' => $dl, 'absences' => $ab, 'disc' => $dc, 'dress' => $dr, 'total' => $dl + $ab + $dc + $dr];
}
if (!$weekBars) {
    $weekBars[] = ['jdate' => $statDay, 'delays' => 0, 'absences' => 0, 'disc' => 0, 'dress' => 0, 'total' => 0];
}

/* آمار تفکیکی ثبت‌کنندگان در هفته جاری (فقط برای مدیر / کاربران با پرمیشن مشاهده همه سوابق) */
$creatorsSummary = [];
if ($viewAll) {
    $work = hs_working_week_days();
    $wf = $work ? jalali_to_gregorian_str($work[0]) : jalali_current_week()['from_g'];
    $wt = $work ? jalali_to_gregorian_str($work[count($work) - 1]) : jalali_current_week()['to_g'];
    $creatorsSummary = rows(
        "SELECT a.id, a.full_name, a.username, a.role, a.is_super,
                (SELECT COUNT(*) FROM delays d WHERE d.created_by = a.id AND d.gdate BETWEEN ? AND ?) AS delays_c,
                (SELECT COUNT(*) FROM absences ab WHERE ab.created_by = a.id AND ab.gdate BETWEEN ? AND ?) AS absences_c,
                (SELECT COUNT(*) FROM disciplines dc WHERE dc.created_by = a.id AND dc.gdate BETWEEN ? AND ?) AS disc_c,
                (SELECT COUNT(*) FROM dress_violations v WHERE v.created_by = a.id AND v.gdate BETWEEN ? AND ?) AS dress_c
         FROM admins a WHERE a.is_active = 1
         ORDER BY (delays_c + absences_c + disc_c + dress_c) DESC, a.full_name ASC",
        [$wf, $wt, $wf, $wt, $wf, $wt, $wf, $wt]
    );
}
$maxTotal = max(1, max(array_column($weekBars, 'total')));
$maxGrade = max(1, max(array_map(static fn($r) => (int)$r['c'], $stats['students_grades'] ?: [['c' => 1]])));

$dashboardCss = <<<'CSS'
/* ===== استایل اختصاصی داشبورد ===== */
.quick{display:flex;gap:10px;flex-wrap:wrap}
.quick a{display:flex;align-items:center;gap:9px;background:var(--white);border:1.6px solid var(--gray-200);
  border-radius:14px;padding:12px 15px;font-weight:700;font-size:13.2px;color:var(--navy);transition:var(--transition);
  box-shadow:var(--shadow)}
.quick a:hover{transform:translateY(-3px);border-color:var(--blue);color:var(--blue);box-shadow:var(--shadow-lg)}
.quick a .q-ic{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;font-size:16px;background:var(--blue-soft)}
.wbars{display:flex;gap:10px;align-items:flex-end;height:190px;padding:12px 6px 0;border-bottom:2px solid var(--gray-200)}
.wbars .wb{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end}
.wbars .stack{width:100%;max-width:44px;display:flex;flex-direction:column;justify-content:flex-end;gap:3px;height:100%}
.wbars .seg{border-radius:6px 6px 3px 3px;min-height:3px;transition:var(--transition)}
.wbars .seg.d{background:linear-gradient(180deg,#60a5fa,#2f6fed)}
.wbars .seg.a{background:linear-gradient(180deg,#fca5a5,#dc2626)}
.wbars .seg.c{background:linear-gradient(180deg,#fdba74,#ea580c)}
.wbars .seg.v{background:linear-gradient(180deg,#c4b5fd,#7c3aed)}
.wbars .wl{font-size:10.6px;color:var(--gray-500);font-weight:700;white-space:nowrap}
.wbars .wt{font-size:11px;font-weight:800;color:var(--navy)}
.legend{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--gray-500);margin-top:12px}
.legend i{width:12px;height:12px;border-radius:4px;display:inline-block;margin-inline-end:5px}
.legend .d{background:#2f6fed} .legend .a{background:#dc2626} .legend .c{background:#ea580c} .legend .v{background:#7c3aed}
.grow{display:grid;gap:11px}
.grow .gr{display:grid;grid-template-columns:92px 1fr 52px;align-items:center;gap:11px;font-size:13px}
.grow .gk{font-weight:700;color:var(--navy)}
html[data-theme="dark"] .grow .gk{color:#e8eefb}
.grow .gv{font-weight:800;color:var(--gray-500);text-align:left;direction:ltr}
.mini-list{display:grid;gap:9px}
.mini{display:flex;align-items:center;gap:11px;padding:10px 12px;border:1px solid var(--gray-200);border-radius:12px;
  background:var(--gray-50);font-size:13px}
.mini .m-ic{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;flex:0 0 auto;font-size:15px}
.mini .m-main{flex:1;min-width:0}
.mini .m-main b{color:var(--navy);font-size:13.3px}
html[data-theme="dark"] .mini .m-main b{color:#e8eefb}
.mini .m-side{font-size:11.6px;color:var(--gray-500);font-weight:700;white-space:nowrap}
.rank{display:flex;align-items:center;gap:11px;padding:10px 12px;border:1px solid var(--gray-200);border-radius:12px;background:var(--white)}
.rank .no{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;font-weight:900;font-size:13px;
  background:var(--gray-100);color:var(--gray-500)}
.rank.top1 .no{background:linear-gradient(135deg,#f59e0b,#fbbf24);color:#fff}
.rank.top2 .no{background:linear-gradient(135deg,#94a3b8,#cbd5e1);color:#fff}
.rank.top3 .no{background:linear-gradient(135deg,#b45309,#f59e0b);color:#fff}
.rank .info{flex:1;min-width:0}
.rank .info b{display:block;color:var(--navy);font-size:13.4px}
html[data-theme="dark"] .rank .info b{color:#e8eefb}
.rank .tags{display:flex;gap:5px;flex-wrap:wrap}
.pulse{animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.45}}
CSS;

layout_head('داشبورد مدیریت', 'dashboard', [$dashboardCss]);
?>

<?php if ($stats['is_off_today']): ?>
  <div class="alert info"><span><?= hs_icon('info', 17) ?></span><span>
    امروز (<?= jalali_full($todayJ) ?>) روز تعطیل است (<b><?= e(implode(' و ', $stats['off_names'])) ?></b>).
    آمار «امروز» بر پایه آخرین روز کاری <b><?= jalali_pretty($statDay) ?></b> محاسبه شده و روزهای تعطیل در هیچ گزارشی شمرده نمی‌شوند.
  </span></div>
<?php endif; ?>

<!-- نوار دامنه نمایش سوابق (ثبت‌کننده) -->
<div class="scope-bar no-print">
  <span class="who-chip <?= $viewAll ? '' : 'mine' ?>">
    <?= $viewAll ? hs_icon('eye', 17) . ' دامنه: ' . e($stats['scope_label']) : hs_icon('user', 17) . ' دامنه: فقط سوابق ثبت‌شده توسط شما' ?>
  </span>
  <?php if ($viewAll): ?>
    <a class="sb-item <?= req('by') === '' || req('by') === 'all' ? 'on' : '' ?>" href="index.php">همه ثبت‌کنندگان</a>
    <a class="sb-item <?= req('by') === 'me' ? 'on' : '' ?>" href="index.php?by=me">فقط خودم</a>
    <?php foreach (creator_options() as $c):
        if ((int)$c['id'] === (int)$admin['id']) { continue; }
    ?>
      <a class="sb-item <?= req('by') === (string)$c['id'] ? 'on' : '' ?>" href="index.php?by=<?= (int)$c['id'] ?>">
        <?= e($c['full_name']) ?><span class="small muted">(<?= e(role_label($c['role'])) ?>)</span>
      </a>
    <?php endforeach; ?>
  <?php else: ?>
    <span class="small muted">برای مشاهده سوابق سایر کاربران، پرمیشن «مشاهده سوابق همه کاربران» لازم است (در اختیار مدیر کل).</span>
  <?php endif; ?>
</div>

<!-- ================= کارت‌های آماری ================= -->
<div class="grid stats mb10">
  <div class="stat blue">
    <div class="icon"><?= hs_icon('student', 22) ?></div>
    <div><div class="v" id="st-students"><?= fa_num($stats['students']) ?></div><div class="l">کل دانش‌آموزان</div></div>
  </div>
  <div class="stat red">
    <div class="icon"><?= hs_icon('ban', 22) ?></div>
    <div><div class="v" id="st-absences"><?= fa_num($stats['absences_today']) ?></div>
      <div class="l">غیبت‌های <?= $stats['is_off_today'] ? 'آخرین روز کاری' : 'امروز' ?> (<?= jalali_pretty($statDay) ?>)</div></div>
  </div>
  <div class="stat amber">
    <div class="icon"><?= hs_icon('clock', 22) ?></div>
    <div><div class="v" id="st-delays"><?= fa_num($stats['delays_today']) ?></div>
      <div class="l">تأخیرهای <?= $stats['is_off_today'] ? 'آخرین روز کاری' : 'امروز' ?></div></div>
  </div>
  <div class="stat purple">
    <div class="icon"><?= hs_icon('scales', 22) ?></div>
    <div><div class="v" id="st-disc"><?= fa_num($stats['discipline_week']) ?></div>
      <div class="l">موارد انضباطی هفته کاری جاری</div></div>
  </div>
  <div class="stat green">
    <div class="icon"><?= hs_icon('shirt', 22) ?></div>
    <div><div class="v" id="st-dress"><?= fa_num($stats['dress_today']) ?></div>
      <div class="l">تخلف پوششی <?= $stats['is_off_today'] ? 'آخرین روز کاری' : 'امروز' ?></div></div>
  </div>
</div>

<!-- ================= دسترسی سریع ================= -->
<div class="card">
  <h3><?= hs_icon('bolt', 18) ?> دسترسی سریع</h3>
  <div class="quick">
    <?php if (has_perm('add_student')): ?>
      <a href="students.php?tab=add"><span class="q-ic"><?= hs_icon('plus', 18) ?></span> افزودن دانش‌آموز</a>
      <a href="students.php?tab=import"><span class="q-ic"><?= hs_icon('download', 18) ?></span> ورود گروهی (Excel/CSV)</a>
    <?php endif; ?>
    <?php if (has_perm('view_students')): ?>
      <a href="students.php"><span class="q-ic"><?= hs_icon('search', 18) ?></span> جستجوی دانش‌آموزان</a>
    <?php endif; ?>
    <?php if (has_perm('register_delay')): ?>
      <a href="records.php?tab=delay"><span class="q-ic"><?= hs_icon('clock', 18) ?></span> ثبت تأخیر</a>
    <?php endif; ?>
    <?php if (has_perm('register_absence')): ?>
      <a href="records.php?tab=absence"><span class="q-ic"><?= hs_icon('calendar', 18) ?></span> ثبت غیبت</a>
    <?php endif; ?>
    <?php if (has_perm('register_discipline')): ?>
      <a href="records.php?tab=discipline"><span class="q-ic"><?= hs_icon('alert', 18) ?></span> ثبت مورد انضباطی</a>
    <?php endif; ?>
    <?php if (has_perm('register_dress')): ?>
      <a href="records.php?tab=dress"><span class="q-ic"><?= hs_icon('shirt', 18) ?></span> ثبت تخلف پوششی</a>
    <?php endif; ?>
    <?php if (has_perm('teacher_panel')): ?>
      <a href="teacher.php"><span class="q-ic"><?= hs_icon('teacher', 18) ?>‍<?= hs_icon('school', 18) ?></span> پنل معلم — کلاس‌ها و ثبت سریع</a>
    <?php endif; ?>
    <?php if (has_perm('export_pdf')): ?>
      <a href="export.php"><span class="q-ic"><?= hs_icon('file', 18) ?></span> گزارش و خروجی PDF</a>
    <?php endif; ?>
    <?php if (has_perm('manage_admins')): ?>
      <a href="admin.php"><span class="q-ic"><?= hs_icon('shield', 18) ?></span> مدیریت ادمین‌ها</a>
    <?php endif; ?>
  </div>
</div>

<!-- ================= نمودار هفته + توزیع پایه ================= -->
<div class="grid g2">
  <div class="card">
    <h3><?= hs_icon('chart', 18) ?> رخدادهای روزهای کاری اخیر <span class="tag" style="margin-inline-start:auto">
      هفته جاری: <?= jalali_pretty($stats['week']['from_j']) ?> الی <?= jalali_pretty($stats['week']['to_j']) ?>
      — <?= e(implode(' و ', $stats['off_names'])) ?> تعطیل</span></h3>
    <div class="wbars">
      <?php foreach ($weekBars as $b):
          $h = static fn(int $v): float => round(($v / $maxTotal) * 100, 2);
      ?>
        <div class="wb" title="<?= e($b['jdate']) ?> — تأخیر: <?= fa_num($b['delays']) ?>، غیبت: <?= fa_num($b['absences']) ?>، انضباط: <?= fa_num($b['disc']) ?>">
          <div class="wt"><?= fa_num($b['total']) ?></div>
          <div class="stack">
            <?php if ($b['disc']): ?><div class="seg c" style="height:<?= $h($b['disc']) ?>%"></div><?php endif; ?>
            <?php if ($b['dress']): ?><div class="seg v" style="height:<?= $h($b['dress']) ?>%"></div><?php endif; ?>
            <?php if ($b['absences']): ?><div class="seg a" style="height:<?= $h($b['absences']) ?>%"></div><?php endif; ?>
            <?php if ($b['delays']): ?><div class="seg d" style="height:<?= $h($b['delays']) ?>%"></div><?php endif; ?>
            <?php if (!$b['total']): ?><div class="seg" style="height:4px;background:var(--gray-200)"></div><?php endif; ?>
          </div>
          <div class="wl"><?= fa_num((int)explode('/', $b['jdate'])[2]) ?><br><?= e(jalali_weekday_name($b['jdate'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="legend">
      <span><i class="d"></i>تأخیر</span><span><i class="a"></i>غیبت</span>
      <span><i class="c"></i>انضباطی</span><span><i class="v"></i>پوششی</span>
    </div>
  </div>

  <div class="card">
    <h3><?= hs_icon('school', 18) ?> توزیع هنرجویان بر اساس پایه و رشته</h3>
    <div class="grow">
      <?php foreach (HS_GRADES as $g):
          $c = 0;
          foreach ($stats['students_grades'] as $r) { if ($r['grade'] === $g) { $c = (int)$r['c']; } }
      ?>
        <div class="gr">
          <span class="gk">پایه <?= e($g) ?></span>
          <span class="bar"><i style="width:<?= $c ? round(($c / $maxGrade) * 100) : 0 ?>%"></i></span>
          <span class="gv"><?= fa_num($c) ?> نفر</span>
        </div>
      <?php endforeach; ?>
    </div>
    <h3 style="margin-top:22px"><?= hs_icon('chart', 18) ?> به تفکیک رشته</h3>
    <div class="grow">
      <?php $maxField = max(1, ...array_map(static fn($r) => (int)$r['c'], $stats['students_fields'] ?: [['c' => 1]])); ?>
      <?php foreach ($stats['students_fields'] as $r): ?>
        <div class="gr">
          <span class="gk"><?= e($r['field']) ?></span>
          <span class="bar"><i style="width:<?= round(((int)$r['c'] / $maxField) * 100) ?>%;background:linear-gradient(90deg,#7c3aed,#22d3ee)"></i></span>
          <span class="gv"><?= fa_num((int)$r['c']) ?> نفر</span>
        </div>
      <?php endforeach; ?>
      <?php if (!$stats['students_fields']): ?>
        <div class="empty"><div class="e-ic"><?= hs_icon('clipboard', 26) ?></div>هنوز دانش‌آموزی ثبت نشده است.<br>
          <a class="btn btn-blue btn-sm mt10" href="students.php?tab=import">افزودن گروهی از فایل</a></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ================= رکوردهای امروز ================= -->
<div class="grid g3">
  <div class="card collapse" id="c-delays">
    <h3 class="collapse-head"><?= hs_icon('clock', 18) ?> تأخیرهای امروز <span class="badge amber"><?= fa_num(count($todayDelays)) ?></span>
      <span class="chev">▾</span></h3>
    <div class="collapse-body mini-list">
      <?php if (!$todayDelays): ?>
        <div class="empty" style="padding:22px"><div class="e-ic"><?= hs_icon('star', 26) ?></div>امروز تأخیری ثبت نشده است.</div>
      <?php endif; ?>
      <?php foreach ($todayDelays as $d): ?>
        <div class="mini">
          <span class="m-ic" style="background:var(--amber-soft)"><?= hs_icon('clock', 18) ?></span>
          <span class="m-main"><b><?= e($d['first_name'] . ' ' . $d['last_name']) ?></b><br>
            <span class="small muted">پایه <?= e($d['grade']) ?> — <?= e($d['field']) ?></span></span>
          <span class="m-side"><?= fa_num($d['time']) ?>
            <?php if ($viewAll && !empty($d['by_name'])): ?><br><span class="small muted">ثبت: <?= e($d['by_name']) ?></span><?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card collapse" id="c-abs">
    <h3 class="collapse-head"><?= hs_icon('ban', 18) ?> غیبت‌های امروز <span class="badge red"><?= fa_num(count($todayAbsences)) ?></span>
      <span class="chev">▾</span></h3>
    <div class="collapse-body mini-list">
      <?php if (!$todayAbsences): ?>
        <div class="empty" style="padding:22px"><div class="e-ic"><?= hs_icon('star', 26) ?></div>امروز غیبتی ثبت نشده است.</div>
      <?php endif; ?>
      <?php foreach ($todayAbsences as $a): ?>
        <div class="mini">
          <span class="m-ic" style="background:var(--red-soft)"><?= hs_icon('ban', 18) ?></span>
          <span class="m-main"><b><?= e($a['first_name'] . ' ' . $a['last_name']) ?></b><br>
            <span class="small muted">پایه <?= e($a['grade']) ?> — <?= e($a['field']) ?></span></span>
          <span class="m-side"><?= e($a['day_of_week']) ?>
            <?php if ($viewAll && !empty($a['by_name'])): ?><br><span class="small muted">ثبت: <?= e($a['by_name']) ?></span><?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card collapse" id="c-dress">
    <h3 class="collapse-head"><?= hs_icon('shirt', 18) ?> تخلفات پوششی <?= $stats['is_off_today'] ? 'آخرین روز کاری' : 'امروز' ?>
      <span class="badge blue"><?= fa_num(count($todayDress)) ?></span><span class="chev">▾</span></h3>
    <div class="collapse-body mini-list">
      <?php if (!$todayDress): ?>
        <div class="empty" style="padding:22px"><div class="e-ic"><?= hs_icon('star', 26) ?></div>موردی ثبت نشده است.</div>
      <?php endif; ?>
      <?php foreach ($todayDress as $v): ?>
        <div class="mini">
          <span class="m-ic" style="background:var(--blue-soft)"><?= hs_icon('shirt', 18) ?></span>
          <span class="m-main"><b><?= e($v['first_name'] . ' ' . $v['last_name']) ?></b><br>
            <span class="small muted">نوع: <?= e($v['type']) ?><?= $v['description'] ? ' — ' . e(mb_substr((string)$v['description'], 0, 40, 'UTF-8')) : '' ?></span></span>
          <?php if ($viewAll && !empty($v['by_name'])): ?>
            <span class="m-side small muted">ثبت: <?= e($v['by_name']) ?></span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card collapse" id="c-disc">
    <h3 class="collapse-head"><?= hs_icon('scales', 18) ?> موارد انضباطی امروز <span class="badge purple"><?= fa_num(count($todayDiscipline)) ?></span>
      <span class="chev">▾</span></h3>
    <div class="collapse-body mini-list">
      <?php if (!$todayDiscipline): ?>
        <div class="empty" style="padding:22px"><div class="e-ic"><?= hs_icon('star', 26) ?></div>امروز مورد انضباطی ثبت نشده است.</div>
      <?php endif; ?>
      <?php foreach ($todayDiscipline as $d): ?>
        <div class="mini">
          <span class="m-ic" style="background:var(--purple-soft)"><?= hs_icon('scales', 18) ?></span>
          <span class="m-main"><b><?= e($d['first_name'] . ' ' . $d['last_name']) ?></b><br>
            <span class="small muted"><?= e($d['type']) ?><?= $d['description'] ? ' — ' . e(mb_substr((string)$d['description'], 0, 42, 'UTF-8')) : '' ?></span></span>
          <span class="m-side">
            <?php if ((int)$d['deduction'] > 0): ?><span class="badge red">کسر <?= fa_num((int)$d['deduction']) ?></span><?php endif; ?>
            <?php if ($viewAll && !empty($d['by_name'])): ?><br><span class="small muted">ثبت: <?= e($d['by_name']) ?></span><?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if ($viewAll && $creatorsSummary): ?>
<!-- ================= آمار تفکیکی ثبت‌کنندگان (مدیر/مجاز) ================= -->
<div class="card" id="creatorsCard">
  <div class="between mb10">
    <h3 class="mb0"><?= hs_icon('users', 18) ?> عملکرد ثبت‌کنندگان در هفته کاری جاری
      <span class="tag">ثبت‌ها از هم جدا و قابل مشاهده برای مدیر</span></h3>
    <?php if (has_perm('export_pdf')): ?>
      <a class="btn btn-sm btn-blue" href="export.php?mode=creators&quick=week"><?= hs_icon('file', 16) ?> گزارش تفکیکی ثبت‌کنندگان (PDF)</a>
    <?php endif; ?>
  </div>
  <div class="table-wrap">
    <table class="tbl" style="min-width:640px">
      <thead>
        <tr><th>#</th><th>ثبت‌کننده</th><th>نقش</th><th>تأخیر</th><th>غیبت</th><th>انضباطی</th><th>پوششی</th><th>جمع</th><th>مشاهده سوابق</th></tr>
      </thead>
      <tbody>
      <?php foreach ($creatorsSummary as $i => $c):
          $sum = (int)$c['delays_c'] + (int)$c['absences_c'] + (int)$c['disc_c'] + (int)$c['dress_c'];
      ?>
        <tr>
          <td><?= fa_num($i + 1) ?></td>
          <td>
            <b><?= e($c['full_name']) ?></b>
            <?= (int)$c['is_super'] === 1 ? '<span class="badge purple">مدیر کل</span>' : '' ?>
            <br><span class="small muted"><?= e($c['username']) ?></span>
          </td>
          <td><span class="badge <?= (int)$c['is_super'] === 1 ? 'purple' : 'blue' ?>"><?= e(role_label($c['role'])) ?></span></td>
          <td><span class="badge amber"><?= fa_num((int)$c['delays_c']) ?></span></td>
          <td><span class="badge red"><?= fa_num((int)$c['absences_c']) ?></span></td>
          <td><span class="badge purple"><?= fa_num((int)$c['disc_c']) ?></span></td>
          <td><span class="badge blue"><?= fa_num((int)$c['dress_c']) ?></span></td>
          <td><b><?= fa_num($sum) ?></b></td>
          <td><a class="btn btn-sm btn-ghost" href="index.php?by=<?= (int)$c['id'] ?>"><?= hs_icon('eye', 17) ?> فقط این کاربر</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="small muted mt10">هر کاربر (معلم، معاون، مسئول انضباط) سوابق خودش را در پنل خود می‌بیند؛
     مدیر کل و دارندگان پرمیشن «مشاهده سوابق همه کاربران» همه ثبت‌ها را به‌صورت تفکیکی مشاهده می‌کنند.</p>
</div>
<?php endif; ?>

<!-- ================= پرتخلف‌های هفته ================= -->
<div class="card">
  <h3><?= hs_icon('clipboard', 18) ?> بیشترین موارد ثبت‌شده در هفته کاری جاری
    <span class="tag" style="margin-inline-start:auto">بر پایه <?= e($stats['scope_label']) ?> — تأخیر ×۱ + غیبت ×۳ + انضباط ×۲</span></h3>
  <?php if (!$top): ?>
    <div class="empty"><div class="e-ic"><?= hs_icon('star', 26) ?></div>داده‌ای برای نمایش وجود ندارد.</div>
  <?php else: ?>
    <div class="grow" style="gap:9px">
      <?php foreach ($top as $i => $t):
          $score = (int)$t['delays_c'] + (int)$t['absences_c'] * 3 + (int)$t['disc_c'] * 2;
          if ($score === 0) { continue; }
          $cls = $i === 0 ? 'top1' : ($i === 1 ? 'top2' : ($i === 2 ? 'top3' : ''));
      ?>
        <div class="rank <?= $cls ?>">
          <span class="no"><?= fa_num($i + 1) ?></span>
          <span class="info">
            <b><?= e($t['first_name'] . ' ' . $t['last_name']) ?></b>
            <span class="small muted">پایه <?= e($t['grade']) ?> — <?= e($t['field']) ?></span>
          </span>
          <span class="tags">
            <?php if ((int)$t['delays_c']): ?><span class="badge amber">تأخیر <?= fa_num((int)$t['delays_c']) ?></span><?php endif; ?>
            <?php if ((int)$t['absences_c']): ?><span class="badge red">غیبت <?= fa_num((int)$t['absences_c']) ?></span><?php endif; ?>
            <?php if ((int)$t['disc_c']): ?><span class="badge purple">انضباط <?= fa_num((int)$t['disc_c']) ?></span><?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php
$dashboardJs = <<<'JS'
/* ===== اسکریپت داشبورد ===== */
document.addEventListener('DOMContentLoaded', function () {
  /* مینیمایز کردن کارت‌ها */
  HS.$$('.collapse-head').forEach(h => h.onclick = () => h.closest('.collapse').classList.toggle('collapsed'));

  /* بروزرسانی زنده آمار هر ۶۰ ثانیه */
  function refreshStats(silent) {
    fetch('index.php?action=stats&ajax=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) { if (!silent) HS.toast(d.message, 'err'); return; }
        const S = d.stats;
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = HS.faNum(v); };
        set('st-students', S.students); set('st-absences', S.absences_today);
        set('st-delays', S.delays_today); set('st-disc', S.discipline_week); set('st-dress', S.dress_today);
        if (!silent) HS.toast('آمار داشبورد بروزرسانی شد.', 'ok', 'بروزرسانی');
      })
      .catch(() => { if (!silent) HS.toast('ارتباط با سرور برقرار نشد.', 'err'); });
  }
  const btn = document.getElementById('refreshStats');
  if (btn) btn.onclick = () => { HS.loading(true, 'در حال بروزرسانی…'); refreshStats(false); setTimeout(() => HS.loading(false), 700); };
  setInterval(() => refreshStats(true), 60000);
});
JS;
layout_end([$dashboardJs]);
