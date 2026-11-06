<?php
/**
 * بررسی فایل‌های فونت آپلودی استودیوی استوری (بدون وابستگی به پایگاه‌داده)
 *
 * پذیرفته‌شده: TTF، OTF، WOFF (با فشرده‌سازی zlib).
 * پذیرفته‌نشده فعلی: WOFF2 — برای خواندن جدول‌ها به رمزگشای Brotli نیاز دارد که روی این PHP نیست،
 * و بررسی مجوز (fsType) بدون آن ممکن نیست. این محدودیت در پیام خطا و در گزارش نهایی ذکر شده است.
 *
 * بررسی‌ها:
 *  - امضای فایل (magic bytes) و تطابق با پسوند
 *  - ساختار جدول‌ها و کران‌های آفست/طول (بدون خواندن خارج از فایل)
 *  - محدودیت حجم جدول‌های فشرده‌شده (ضد zip-bomb)
 *  - بیت fsType در جدول OS/2: اگر «جاسازی محدود» باشد، فونت رد می‌شود
 *  - عدم وجود کد PHP یا اسکریپت در فایل
 *  - نام خانواده‌ی فونت از جدول name (کاراکترهای کنترلی حذف می‌شوند)
 */

const IGS_FONT_MAX_BYTES = 5242880;      // ۵ مگابایت
const IGS_FONT_MAX_TABLES = 64;
const IGS_FONT_MAX_UNPACKED = 20971520;  // ۲۰ مگابایت برای کل جدول‌های باز‌شده
const IGS_FONT_MAX_COUNT = 10;
const IGS_FONT_LICENCE_TYPES = ['ofl', 'own', 'purchased', 'free_commercial'];

function igsFontU16(string $b, int $o): ?int
{
    if ($o < 0 || $o + 2 > strlen($b)) { return null; }
    return unpack('n', substr($b, $o, 2))[1];
}

function igsFontU32(string $b, int $o): ?int
{
    if ($o < 0 || $o + 4 > strlen($b)) { return null; }
    return unpack('N', substr($b, $o, 4))[1];
}

/** قالب فایل را از روی امضای آن تشخیص می‌دهد؛ null یعنی ناشناخته. */
function igsFontFormatOf(string $bytes): ?string
{
    $sig = substr($bytes, 0, 4);
    if ($sig === "\x00\x01\x00\x00" || $sig === 'true') { return 'ttf'; }
    if ($sig === 'OTTO') { return 'otf'; }
    if ($sig === 'wOFF') { return 'woff'; }
    if ($sig === 'wOF2') { return 'woff2'; }
    return null;
}

/**
 * جدول‌های فونت را به‌صورت tag => bytes برمی‌گرداند. در خطا رشته‌ی خطا برمی‌گرداند.
 * @return array{tables?:array<string,string>,error?:string}
 */
function igsFontTables(string $data, string $format): array
{
    if ($format === 'ttf' || $format === 'otf') {
        $n = igsFontU16($data, 4);
        if ($n === null || $n < 1 || $n > IGS_FONT_MAX_TABLES) { return ['error' => 'جدول‌های فونت نامعتبرند.']; }
        $tables = [];
        for ($i = 0; $i < $n; $i++) {
            $rec = 12 + $i * 16;
            $tag = substr($data, $rec, 4);
            $off = igsFontU32($data, $rec + 8);
            $len = igsFontU32($data, $rec + 12);
            if ($off === null || $len === null || $off + $len > strlen($data)) {
                return ['error' => 'ساختار فونت خراب است (جدول خارج از فایل).'];
            }
            $tables[$tag] = substr($data, $off, $len);
        }
        return ['tables' => $tables];
    }
    if ($format === 'woff') {
        $n = igsFontU16($data, 12);
        if ($n === null || $n < 1 || $n > IGS_FONT_MAX_TABLES) { return ['error' => 'جدول‌های WOFF نامعتبرند.']; }
        $tables = [];
        $unpacked = 0;
        for ($i = 0; $i < $n; $i++) {
            $rec = 44 + $i * 20;
            $tag = substr($data, $rec, 4);
            $off = igsFontU32($data, $rec + 4);
            $comp = igsFontU32($data, $rec + 8);
            $orig = igsFontU32($data, $rec + 12);
            if ($off === null || $comp === null || $orig === null || $off + $comp > strlen($data)) {
                return ['error' => 'ساختار WOFF خراب است (جدول خارج از فایل).'];
            }
            if ($orig > IGS_FONT_MAX_UNPACKED) { return ['error' => 'جدول WOFF بیش از حد بزرگ است.']; }
            $chunk = substr($data, $off, $comp);
            if ($comp < $orig) {
                $raw = @gzuncompress($chunk);
                if ($raw === false) { return ['error' => 'فشرده‌سازی جدول WOFF خراب است.']; }
            } else {
                $raw = $chunk;
            }
            if (strlen($raw) !== $orig) { return ['error' => 'اندازه‌ی جدول WOFF با هدر نمی‌خواند.']; }
            $unpacked += strlen($raw);
            if ($unpacked > IGS_FONT_MAX_UNPACKED) { return ['error' => 'حجم باز‌شده‌ی فونت بیش از حد مجاز است.']; }
            $tables[$tag] = $raw;
        }
        return ['tables' => $tables];
    }
    return ['error' => 'این قالب فونت پشتیبانی نمی‌شود.'];
}

/** نام خانواده‌ی فونت (nameID=1) از جدول name؛ '' اگر پیدا نشد. */
function igsFontFamilyName(string $name): string
{
    $count = igsFontU16($name, 2);
    $strOff = igsFontU16($name, 4);
    if ($count === null || $strOff === null) { return ''; }
    $found = ['win' => null, 'mac' => null];
    for ($i = 0; $i < min($count, 500); $i++) {
        $rec = 6 + $i * 12;
        $pid = igsFontU16($name, $rec);
        $eid = igsFontU16($name, $rec + 2);
        $nid = igsFontU16($name, $rec + 6);
        $len = igsFontU16($name, $rec + 8);
        $off = igsFontU16($name, $rec + 10);
        if ($pid === null || $nid !== 1 || $len === null || $off === null) { continue; }
        $start = $strOff + $off;
        if ($start + $len > strlen($name)) { continue; }
        $raw = substr($name, $start, $len);
        if ($pid === 3 && $eid === 1 && $found['win'] === null) {
            $found['win'] = mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
        } elseif ($pid === 1 && $found['mac'] === null) {
            $found['mac'] = $raw;
        }
    }
    $s = $found['win'] ?? $found['mac'] ?? '';
    // کاراکترهای کنترلی و جداکننده‌های نامطمئن حذف؛ طول محدود
    $s = preg_replace('/[\x00-\x1F\x7F<>"\'`]/u', '', (string)$s);
    $s = trim((string)$s);
    return mb_substr($s, 0, 60, 'UTF-8');
}

/**
 * بررسی کامل یک فایل فونت. خروجی:
 * ok, format, family, fsType, embedding, errors[], warnings[]
 */
function igsFontInspect(string $bytes, string $originalName = ''): array
{
    $out = ['ok' => false, 'format' => null, 'family' => '', 'fsType' => null, 'embedding' => null, 'errors' => [], 'warnings' => []];
    $size = strlen($bytes);
    if ($size === 0) { $out['errors'][] = 'فایل خالی است.'; return $out; }
    if ($size > IGS_FONT_MAX_BYTES) { $out['errors'][] = 'حجم فونت باید کمتر از ۵ مگابایت باشد.'; return $out; }

    // ۱. محتوای خطرناک: فایل فونت نباید کد اجرایی یا اسکریپت داشته باشد
    if (preg_match('/<\?(php|=)|<script\b/i', $bytes)) {
        $out['errors'][] = 'فایل حاوی کد اجرایی است و رد شد.';
        return $out;
    }

    // ۲. قالب و تطابق با پسوند
    $format = igsFontFormatOf($bytes);
    if ($format === null) { $out['errors'][] = 'فایل فونت معتبر نیست (امضای فایل شناخته نشد).'; return $out; }
    if ($format === 'woff2') {
        $out['format'] = 'woff2';
        $out['errors'][] = 'فرمت WOFF2 فعلاً پذیرفته نمی‌شود؛ همان فونت را به‌صورت TTF، OTF یا WOFF بفرستید.';
        return $out;
    }
    $out['format'] = $format;
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $extOk = ['ttf' => ['ttf'], 'otf' => ['otf'], 'woff' => ['woff']][$format];
    if ($originalName !== '' && !in_array($ext, $extOk, true)) {
        $out['errors'][] = 'پسوند فایل با محتوای آن نمی‌خواند (' . $format . ' در برابر .' . $ext . ').';
        return $out;
    }

    // ۳. جدول‌ها
    $t = igsFontTables($bytes, $format);
    if (isset($t['error'])) { $out['errors'][] = $t['error']; return $out; }
    $tables = $t['tables'];

    // ۴. OS/2: بیت fsType برای مجوز جاسازی
    if (!isset($tables['OS/2']) || strlen($tables['OS/2']) < 10) {
        $out['errors'][] = 'جدول OS/2 در فونت نیست؛ مجوز جاسازی قابل بررسی نیست.';
        return $out;
    }
    $fs = igsFontU16($tables['OS/2'], 8);
    $out['fsType'] = $fs;
    if ($fs & 0x0002) {
        $out['embedding'] = 'restricted';
        $out['errors'][] = 'مجوز این فونت جاسازی را محدود کرده است (fsType Restricted License). استفاده از آن رد شد.';
        return $out;
    }
    if ($fs & 0x0008) { $out['embedding'] = 'editable'; }
    elseif ($fs & 0x0004) { $out['embedding'] = 'preview_print'; $out['warnings'][] = 'مجوز فونت فقط پیش‌نمایش و چاپ را می‌دهد؛ مطمئن شوید برای خروجی تصویری مجاز است.'; }
    else { $out['embedding'] = 'installable'; }

    // ۵. نام خانواده
    if (isset($tables['name'])) { $out['family'] = igsFontFamilyName($tables['name']); }
    if ($out['family'] === '') {
        $out['family'] = pathinfo($originalName, PATHINFO_FILENAME) ?: 'custom-font';
        $out['warnings'][] = 'نام خانواده‌ی فونت در فایل پیدا نشد؛ نام فایل استفاده شد.';
    }
    $out['ok'] = true;
    return $out;
}

/** بررسی فرم مجوز. خروجی: لیست خطا (خالی یعنی درست). */
function igsFontLicenceErrors(array $in): array
{
    $errs = [];
    $confirm = $in['licence_confirm'] ?? '';
    if (!($confirm === true || $confirm === '1' || $confirm === 1)) {
        $errs[] = 'تأیید مجوز الزامی است: باید اعلام کنید مجوز استفاده‌ی تجاری از این فونت را دارید.';
    }
    $type = (string)($in['licence_type'] ?? '');
    if (!in_array($type, IGS_FONT_LICENCE_TYPES, true)) {
        $errs[] = 'نوع مجوز انتخاب نشده یا نامعتبر است.';
    }
    $note = trim((string)($in['licence_note'] ?? ''));
    if (mb_strlen($note, 'UTF-8') > 200) { $errs[] = 'یادداشت مجوز بیش از ۲۰۰ نویسه است.'; }
    return $errs;
}
