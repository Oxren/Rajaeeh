<?php
/**
 * مجموعهٔ آیکون‌های خطی سامانه (SVG درون‌خطی — بدون فایل خارجی)
 * سبک: خطی، ضخامت ۱.۷، رنگ از متن (currentColor) — سازگار با تم روشن/شب
 * همهٔ آیکون‌ها viewBox ۲۴×۲۴ و آمادهٔ درج در HTML هستند.
 */
$stroke = 'fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"';

$icons = [
    'home'      => '<path d="M3 10.6 12 3.2l9 7.4"/><path d="M5.2 9.6V20.5h13.6V9.6"/><path d="M9.8 20.5v-5.2h4.4v5.2"/>',
    'school'    => '<path d="M12 2.8 2.8 8.4v12.3h18.4V8.4z"/><path d="M9.3 20.7v-5.4h5.4v5.4"/><path d="M6.4 12h2.2M15.4 12h2.2"/>',
    'users'     => '<circle cx="9" cy="8.2" r="3.2"/><path d="M3.4 20.2c0-3.3 2.5-5.6 5.6-5.6s5.6 2.3 5.6 5.6"/><path d="M16.4 6.4a3 3 0 0 1 0 5.6"/><path d="M18 14.7c2 .8 3.4 2.6 3.4 5.5"/>',
    'student'   => '<path d="M2.6 9.1 12 5l9.4 4.1L12 13.3z"/><path d="M6.6 11.1v4.3c0 1.3 2.4 2.4 5.4 2.4s5.4-1.1 5.4-2.4v-4.3"/><path d="M21.4 9.6v4.8"/>',
    'teacher'   => '<circle cx="8.2" cy="7.4" r="3.1"/><path d="M2.6 20.2c0-3.2 2.5-5.4 5.6-5.4s5.6 2.2 5.6 5.4"/><rect x="14.6" y="4.2" width="7.6" height="6.2" rx="1.2"/><path d="M18.4 10.4v2.4"/>',
    'clipboard' => '<rect x="5.8" y="4.2" width="12.4" height="16.6" rx="2"/><rect x="9.4" y="2.4" width="5.2" height="3.2" rx="1.1"/><path d="M9.4 11h5.2M9.4 15h5.2"/>',
    'file'      => '<path d="M7.2 3h6.6l4 4v13.5H7.2z"/><path d="M13.6 3v4.2h4.2"/><path d="M10.2 12h4.4M10.2 16h4.4"/>',
    'shield'    => '<path d="M12 3 5 6v6c0 4.2 3 7.3 7 9 4-1.7 7-4.8 7-9V6z"/><path d="M9.2 12.3l2.1 2.1 3.6-4.1"/>',
    'refresh'   => '<path d="M20 12a8 8 0 1 1-2.5-5.8"/><path d="M20.2 3.8v4.3h-4.3"/>',
    'logout'    => '<path d="M14.6 3.8h3.2a2 2 0 0 1 2 2v12.4a2 2 0 0 1-2 2h-3.2"/><path d="M9.6 8.2 5.8 12l3.8 3.8"/><path d="M5.8 12h8.6"/>',
    'clock'     => '<circle cx="12" cy="12" r="8.6"/><path d="M12 7v5.4l3.6 2.1"/>',
    'ban'       => '<circle cx="12" cy="12" r="8.6"/><path d="M6.2 6.2l11.6 11.6"/>',
    'scales'    => '<path d="M12 4.2v15.2"/><path d="M7.6 19.4h8.8"/><path d="M4.4 8.2h15.2"/><path d="M4.4 8.2 2.2 13.4a2.9 2.9 0 0 0 5.4 0z"/><path d="M19.6 8.2l-2.2 5.2a2.9 2.9 0 0 0 5.4 0z"/>',
    'shirt'     => '<path d="M8.6 3.2 3.4 5.9l1.7 3.5 2.1-1v11.4h9.6V8.4l2.1 1 1.7-3.5-5.2-2.7L12 5.8z"/>',
    'bell'      => '<path d="M12 3.2a5.7 5.7 0 0 0-5.7 5.7c0 4.3-1.6 5.9-1.6 5.9h14.6s-1.6-1.6-1.6-5.9A5.7 5.7 0 0 0 12 3.2z"/><path d="M9.9 18.2a2.2 2.2 0 0 0 4.2 0"/>',
    'calendar'  => '<rect x="3.4" y="5" width="17.2" height="15.6" rx="2"/><path d="M3.4 10h17.2"/><path d="M8 3v4M16 3v4"/><circle cx="9" cy="14.6" r="1"/><circle cx="13.6" cy="14.6" r="1"/>',
    'trash'     => '<path d="M4.6 7h14.8"/><path d="M9.6 7V4.4h4.8V7"/><path d="M6.6 7l1 13.2h8.8L17.4 7"/><path d="M10.4 11v5.4M13.6 11v5.4"/>',
    'pencil'    => '<path d="M4 20.2l.9-4.2L16.4 4.4a1.9 1.9 0 0 1 2.7 0l.5.5a1.9 1.9 0 0 1 0 2.7L8.1 19.3z"/><path d="M14.6 6.2l3.2 3.2"/>',
    'plus'      => '<path d="M12 5v14M5 12h14"/>',
    'download'  => '<path d="M12 3.6v10.2"/><path d="M8 10l4 4 4-4"/><path d="M4.6 19.6h14.8"/>',
    'upload'    => '<path d="M12 14.4V4.2"/><path d="M8 8.2 12 4.2l4 4"/><path d="M4.6 19.6h14.8"/>',
    'search'    => '<circle cx="10.6" cy="10.6" r="6"/><path d="M15.2 15.2 20.4 20.4"/>',
    'save'      => '<path d="M5 4.4h10.8l3.2 3.2v11.4a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5.4a1 1 0 0 1 1-1z"/><path d="M8.2 4.4v4.8h6.4V4.4"/><rect x="8.2" y="12.8" width="7.6" height="7.2"/>',
    'print'     => '<path d="M7 8.2V3.6h10v4.6"/><rect x="3.4" y="8.2" width="17.2" height="8" rx="1.6"/><path d="M7 15.6h10v4.8H7z"/>',
    'chart'     => '<path d="M4 20V4"/><path d="M4 20h16"/><rect x="7" y="12" width="3" height="5"/><rect x="12" y="8" width="3" height="9"/><rect x="17" y="14" width="3" height="3"/>',
    'lock'      => '<rect x="4.6" y="10.4" width="14.8" height="10" rx="2"/><path d="M8.2 10.4V7.6a3.8 3.8 0 0 1 7.6 0v2.8"/><path d="M12 14.4v3"/>',
    'key'       => '<circle cx="8.2" cy="8.2" r="4"/><path d="M11 11l8.4 8.4"/><path d="M16.6 16.6l2.2-2.2M18.8 18.8l2.2-2.2"/>',
    'star'      => '<path d="M12 3.4l2.7 5.7 6.2.8-4.6 4.3 1.2 6.1-5.5-3.1-5.5 3.1 1.2-6.1L3.1 9.9l6.2-.8z"/>',
    'check'     => '<path d="M4.8 12.6 9.8 17.6 19.2 6.8"/>',
    'close'     => '<path d="M6 6l12 12M18 6L6 18"/>',
    'alert'     => '<path d="M12 3.6 2.6 20.4h18.8z"/><path d="M12 9.6v4.8"/><circle cx="12" cy="17.4" r=".95" fill="currentColor" stroke="none"/>',
    'info'      => '<circle cx="12" cy="12" r="8.6"/><path d="M12 11.2v5.4"/><circle cx="12" cy="8" r=".95" fill="currentColor" stroke="none"/>',
    'eye'       => '<path d="M2.6 12S6.2 5.6 12 5.6 21.4 12 21.4 12 17.8 18.4 12 18.4 2.6 12 2.6 12z"/><circle cx="12" cy="12" r="3.1"/>',
    'user'      => '<circle cx="12" cy="8.2" r="3.6"/><path d="M4.8 20.4c0-3.6 3-6 7.2-6s7.2 2.4 7.2 6"/>',
    'link'      => '<path d="M9.4 14.6l5.2-5.2"/><path d="M11 6.8l1.9-1.9a3.7 3.7 0 0 1 5.2 5.2l-1.9 1.9"/><path d="M13 17.2l-1.9 1.9a3.7 3.7 0 0 1-5.2-5.2l1.9-1.9"/>',
    'sun'       => '<circle cx="12" cy="12" r="4"/><path d="M12 2.6v2.6M12 18.8v2.6M2.6 12h2.6M18.8 12h2.6M5.6 5.6l1.9 1.9M16.5 16.5l1.9 1.9M18.4 5.6l-1.9 1.9M7.5 16.5l-1.9 1.9"/>',
    'moon'      => '<path d="M20.2 14.6A8.6 8.6 0 0 1 9.4 3.8a8.6 8.6 0 1 0 10.8 10.8z"/>',
    'menu'      => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    'gear'      => '<circle cx="12" cy="12" r="3.1"/><path d="M12 3.4v2.4M12 18.2v2.4M4.4 7.7l2.1 1.2M17.5 15.1l2.1 1.2M4.4 16.3l2.1-1.2M17.5 8.9l2.1-1.2"/>',
    'bolt'      => '<path d="M13.4 2.8 5.6 13.4h5.2l-.8 7.8 8.2-11h-5.4z"/>',
    'copy'      => '<rect x="8.4" y="8.4" width="11.8" height="11.8" rx="2"/><path d="M15.6 5.8H5.8a1.6 1.6 0 0 0-1.6 1.6v9.8"/>',
    'hourglass' => '<path d="M7 3.4h10M7 20.6h10"/><path d="M8.6 3.4c0 4 3.4 4.5 3.4 8.6s-3.4 4.6-3.4 8.6"/><path d="M15.4 3.4c0 4-3.4 4.5-3.4 8.6s3.4 4.6 3.4 8.6"/>',
];

$out = [];
foreach ($icons as $name => $inner) {
    $out[$name] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" '
                . $stroke . ' aria-hidden="true">' . $inner . '</svg>';
}
return $out;
