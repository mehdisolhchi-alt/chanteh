<?php
/**
 * Chanteh contact form handler (consumers and business customers).
 * Saves each message to data/leads.csv and (optionally) emails the sales team.
 */

// ---- Settings ----
const NOTIFY_EMAIL = '';            // e.g. 'sales@chanteh.ir' — empty = no email, CSV only
const MAX_PER_HOUR = 5;             // submissions allowed per IP per hour
const DATA_DIR = __DIR__ . '/data'; // protected by data/.htaccess

date_default_timezone_set('Asia/Tehran');

const BUSINESS = [
    'consumer'    => 'مصرف‌کننده',
    'retailer'    => 'مغازه‌دار / سوپرمارکت / ابزارفروشی',
    'wholesaler'  => 'بنکدار / عمده‌فروش',
    'distributor' => 'شرکت پخش مویرگی',
    'chain'       => 'هایپرمارکت / فروشگاه زنجیره‌ای',
];

$wantsJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

function respond(bool $ok, string $error = ''): void
{
    global $wantsJson;
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($ok ? 200 : ($error === 'rate_limited' ? 429 : 400));
        echo json_encode(['ok' => $ok, 'error' => $error]);
    } else {
        header('Location: ./?' . ($ok ? 'sent=1' : 'error=1') . '#request', true, 303);
    }
    exit;
}

function field(string $key, int $max): string
{
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) return '';
    $v = trim(preg_replace('/\s+/u', ' ', $v)); // also flattens newlines in the message
    return mb_substr($v, 0, $max);
}

function normalize_mobile(string $s): string
{
    $s = strtr($s, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
    $s = preg_replace('/[\s\-()]/', '', $s);
    if (str_starts_with($s, '+98')) $s = '0' . substr($s, 3);
    elseif (str_starts_with($s, '0098')) $s = '0' . substr($s, 4);
    elseif (str_starts_with($s, '98') && strlen($s) === 12) $s = '0' . substr($s, 2);
    return $s;
}

// Prevent spreadsheet formula injection when the CSV is opened in Excel
function csv_safe(string $v): string
{
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

// Honeypot: real users never fill this hidden field
if (field('website', 100) !== '') respond(true);

$name     = field('name', 60);
$business = field('business', 20);
$city     = field('city', 40);
$mobile   = normalize_mobile(field('mobile', 20));
$message  = field('message', 500);

if (mb_strlen($name) < 2 || mb_strlen($city) < 2
    || !isset(BUSINESS[$business])
    || !preg_match('/^09\d{9}$/', $mobile)) {
    respond(false, 'invalid');
}

if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0750, true)) respond(false, 'storage');

// Simple per-IP rate limit
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateFile = DATA_DIR . '/ratelimit.json';
$fh = fopen($rateFile, 'c+');
if ($fh && flock($fh, LOCK_EX)) {
    $data = json_decode(stream_get_contents($fh) ?: '{}', true) ?: [];
    $now = time();
    foreach ($data as $k => $times) {
        $data[$k] = array_values(array_filter($times, fn($t) => $t > $now - 3600));
        if (!$data[$k]) unset($data[$k]);
    }
    $key = hash('sha256', $ip);
    if (count($data[$key] ?? []) >= MAX_PER_HOUR) {
        flock($fh, LOCK_UN);
        fclose($fh);
        respond(false, 'rate_limited');
    }
    $data[$key][] = $now;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    flock($fh, LOCK_UN);
    fclose($fh);
}

// Save lead
$leadsFile = DATA_DIR . '/leads.csv';
$isNew = !file_exists($leadsFile);
$out = fopen($leadsFile, 'a');
if (!$out) respond(false, 'storage');
flock($out, LOCK_EX);
if ($isNew) {
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Persian correctly
    fputcsv($out, ['تاریخ', 'نام', 'نوع مخاطب', 'شهر', 'موبایل', 'پیام'], ',', '"', '');
}
$row = [date('Y-m-d H:i'), $name, BUSINESS[$business], $city, $mobile, $message];
fputcsv($out, array_map('csv_safe', $row), ',', '"', '');
flock($out, LOCK_UN);
fclose($out);

// Optional email notification (no user input goes into headers)
if (NOTIFY_EMAIL !== '') {
    $host = preg_replace('/[^a-z0-9.\-]/i', '', $_SERVER['SERVER_NAME'] ?? 'localhost');
    $subject = '=?UTF-8?B?' . base64_encode('پیام جدید از سایت چنته: ' . BUSINESS[$business]) . '?=';
    $body = "نام: $name\n"
          . "نوع مخاطب: " . BUSINESS[$business] . "\n"
          . "شهر: $city\n"
          . "موبایل: $mobile\n"
          . "پیام: " . ($message !== '' ? $message : '-') . "\n";
    $headers = "From: Chanteh Website <noreply@$host>\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";
    @mail(NOTIFY_EMAIL, $subject, $body, $headers);
}

respond(true);
