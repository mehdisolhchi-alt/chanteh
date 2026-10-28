<?php
/**
 * Chanteh contact form handler (consumers and business customers).
 * Saves each message to data/leads.csv and (optionally) emails the sales team.
 */

// ---- Settings ----
const NOTIFY_EMAIL = '';            // e.g. 'sales@chanteh.ir' — empty = no email, CSV only
const MAX_PER_HOUR = 5;             // submissions allowed per IP per hour
const DATA_DIR = __DIR__ . '/data'; // protected by data/.htaccess

// Telegram settings live in data/config.php (not in git) — see data/config.sample.php
// Output is buffered so a malformed config.php can never leak its contents to visitors
$config = [];
$configError = '';
if (is_file(DATA_DIR . '/config.php')) {
    ob_start();
    try {
        $config = include DATA_DIR . '/config.php';
    } catch (Throwable $e) {
        $configError = 'syntax error line ' . $e->getLine();
    }
    if (ob_get_clean() !== '' && !$configError) $configError = 'file must start with <?php return [';
    if (!is_array($config)) {
        $config = [];
        if (!$configError) $configError = 'file must return [ ... ];';
    }
}

// Hosts without mbstring: fall back to iconv (always present in cPanel PHP builds)
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len) { return iconv_substr($s, $start, $len, 'UTF-8'); }
    function mb_strlen($s) { return iconv_strlen($s, 'UTF-8'); }
}

// Any PHP fatal error still answers the form with JSON (and is logged), instead of a blank 500
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    @file_put_contents(DATA_DIR . '/php-errors.log', date('Y-m-d H:i') . " {$e['message']} line {$e['line']}\n", FILE_APPEND);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'error' => 'server']);
});

// Self-check: open send.php?check=1 in a browser. Shows only yes/no, never secrets.
if (isset($_GET['check'])) {
    header('Content-Type: application/json; charset=utf-8');
    $mode = !empty($config['relay_url']) && !empty($config['relay_key']) ? 'relay'
          : (!empty($config['telegram_token']) && !empty($config['telegram_chat_id']) ? 'direct' : 'none');
    echo json_encode([
        'php'             => PHP_VERSION,
        'data_dir_ok'     => is_dir(DATA_DIR) && is_writable(DATA_DIR),
        'curl'            => function_exists('curl_init'),
        'config_php'      => is_file(DATA_DIR . '/config.php'),
        'telegram_mode'   => $mode,
        'config_error'    => $configError,
    ], JSON_PRETTY_PRINT);
    exit;
}

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
    if (strncmp($s, '+98', 3) === 0) $s = '0' . substr($s, 3);
    elseif (strncmp($s, '0098', 4) === 0) $s = '0' . substr($s, 4);
    elseif (strncmp($s, '98', 2) === 0 && strlen($s) === 12) $s = '0' . substr($s, 2);
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
        $data[$k] = array_values(array_filter($times, function ($t) use ($now) { return $t > $now - 3600; }));
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

// Telegram notification, two modes (see data/config.sample.php):
//  - relay: our Cloudflare Worker holds the bot token and chat id (relay_url + relay_key)
//  - direct: call the Bot API ourselves (telegram_token + telegram_chat_id [+ api_base])
$h = function ($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }; // relay uses parse_mode HTML
$text = "📩 <b>پیام جدید از سایت چنته</b>\n\n"
      . "👤 نام: " . $h($name) . "\n"
      . "🏷 نوع مخاطب: " . BUSINESS[$business] . "\n"
      . "📍 شهر: " . $h($city) . "\n"
      . "📞 موبایل: $mobile\n"
      . "💬 پیام: " . ($message !== '' ? $h($message) : '-') . "\n"
      . "🕒 " . date('Y-m-d H:i');

$req = null;
if (!empty($config['relay_url']) && !empty($config['relay_key'])) {
    $req = [
        'url'     => $config['relay_url'],
        'headers' => ['Content-Type: application/json', 'x-relay-key: ' . $config['relay_key']],
        'body'    => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
    ];
} elseif (!empty($config['telegram_token']) && !empty($config['telegram_chat_id'])) {
    $apiBase = rtrim($config['api_base'] ?? 'https://api.telegram.org', '/');
    $req = [
        'url'     => $apiBase . '/bot' . $config['telegram_token'] . '/sendMessage',
        'headers' => [],
        'body'    => ['chat_id' => $config['telegram_chat_id'], 'text' => $text, 'parse_mode' => 'HTML'],
    ];
}

if ($req && function_exists('curl_init')) {
    $ch = curl_init($req['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $req['headers'],
        CURLOPT_POSTFIELDS     => $req['body'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false || $code !== 200) {
        // The lead is already saved; just log why the notification failed
        $err = $resp === false ? curl_error($ch) : "HTTP $code $resp";
        @file_put_contents(DATA_DIR . '/notify-errors.log', date('Y-m-d H:i') . " $err\n", FILE_APPEND);
    }
    curl_close($ch);
}

respond(true);
