<?php
declare(strict_types=1);

const DB_FILE = __DIR__ . '/atimessage_data.json';

const JSON_SERVER_URL = 'https://holobot.ir/s/edit.php';
const JSON_SERVER_NAME = 'paiamnashenas';
const JSON_SERVER_PASSWORD = 'paiamnashenas';

function respond(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function loadDB(): array {
    if (!is_file(DB_FILE)) {
        return ['users' => [], 'messages' => []];
    }

    $raw = file_get_contents(DB_FILE);
    $data = json_decode($raw ?: '', true);

    if (!is_array($data)) {
        return ['users' => [], 'messages' => []];
    }

    if (!isset($data['users']) && isset($data['accounts'])) {
        $data['users'] = $data['accounts'];
    }

    $data['users'] = is_array($data['users'] ?? null)
        ? $data['users'] : [];

    $data['messages'] = is_array($data['messages'] ?? null)
        ? $data['messages'] : [];

    foreach ($data['users'] as &$user) {
        if (!is_array($user)) {
            $user = [];
        }

        if (empty($user['public_id'])) {
            $user['public_id'] = bin2hex(random_bytes(6));
        }

        if (empty($user['link_id'])) {
            $user['link_id'] = bin2hex(random_bytes(16));
        }
    }
    unset($user);

    return $data;
}

function saveDB(array $data): bool {
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    return $json !== false &&
        file_put_contents(DB_FILE, $json, LOCK_EX) !== false;
}

/**
 * ارسال کل آرایه پیام‌های محلی به API خارجی.
 * این تابع حذف یا جایگزینی اطلاعات را در سرور خارجی تضمین نمی‌کند؛
 * رفتار دقیق به پیاده‌سازی edit.php بستگی دارد.
 */
function syncMessagesToJsonServer(array $messages): bool {
    $json = json_encode(
        array_values($messages),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        error_log('AtiMessage: JSON encoding failed.');
        return false;
    }

    $postData = [
        'name' => JSON_SERVER_NAME,
        'password' => JSON_SERVER_PASSWORD,
        'data' => $json,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init(JSON_SERVER_URL);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ],
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch);

        curl_close($ch);

        if (
            $response === false ||
            $error !== 0 ||
            $status < 200 ||
            $status >= 300
        ) {
            error_log('AtiMessage: JSON sync failed; HTTP=' . $status);
            return false;
        }

        error_log(
            'AtiMessage JSON API response: ' .
            substr((string)$response, 0, 500)
        );

        return true;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' =>
                "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($postData),
            'timeout' => 25,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents(
        JSON_SERVER_URL,
        false,
        $context
    );

    $status = 0;

    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) {
            $status = (int)$match[1];
        }
    }

    if (
        $response === false ||
        $status < 200 ||
        $status >= 300
    ) {
        error_log('AtiMessage: JSON sync failed.');
        return false;
    }

    error_log(
        'AtiMessage JSON API response: ' .
        substr($response, 0, 500)
    );

    return true;
}

function baseUrl(): string {
    $https = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    );

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    if (!preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]{1,5})?$/', $host)) {
        $host = 'localhost';
    }

    $path = parse_url(
        $_SERVER['REQUEST_URI'] ?? '/',
        PHP_URL_PATH
    );

    if (!is_string($path) || $path === '') {
        $path = '/';
    }

    return ($https ? 'https://' : 'http://') . $host . $path;
}

function anonymousLink(array $user): string {
    return baseUrl() . '?page=send&user=' .
        rawurlencode((string)$user['public_id']);
}

function findUsername(array $db, string $username): int {
    foreach ($db['users'] as $i => $user) {
        if (($user['username'] ?? '') === $username) {
            return $i;
        }
    }

    return -1;
}

function findPublicId(array $db, string $id): int {
    foreach ($db['users'] as $i => $user) {
        $publicId = (string)($user['public_id'] ?? '');
        $linkId = (string)($user['link_id'] ?? '');

        if (
            ($publicId !== '' && hash_equals($publicId, $id)) ||
            ($linkId !== '' && hash_equals($linkId, $id))
        ) {
            return $i;
        }
    }

    return -1;
}

function findToken(array $db, string $token): int {
    if ($token === '') {
        return -1;
    }

    $hash = hash('sha256', $token);

    foreach ($db['users'] as $i => $user) {
        $stored = (string)($user['token_hash'] ?? '');

        if ($stored !== '' && hash_equals($stored, $hash)) {
            return $i;
        }

        // سازگاری با نسخه‌های قدیمی
        if (
            isset($user['token']) &&
            is_string($user['token']) &&
            hash_equals($user['token'], $token)
        ) {
            return $i;
        }
    }

    return -1;
}

function page(string $title, string $body): void {
    header('Content-Type: text/html; charset=utf-8');

    echo '<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#100d20">
<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>
<style>
:root{
 --bg:#0d0b18;--panel:rgba(27,22,48,.83);
 --line:rgba(207,190,255,.16);--text:#fbf9ff;
 --muted:#b9afd5;--purple:#a78bfa;
 --purple2:#7448f5;--pink:#ed8bc6;
}
*{box-sizing:border-box}
html{min-height:100%;background:var(--bg)}
body{
 margin:0;min-height:100vh;padding:30px 16px;
 display:flex;align-items:center;justify-content:center;
 font-family:Tahoma,Arial,sans-serif;color:var(--text);
 background:
 radial-gradient(ellipse at 12% 10%,rgba(132,83,255,.23),transparent 38%),
 radial-gradient(ellipse at 90% 85%,rgba(224,86,178,.13),transparent 36%),
 linear-gradient(145deg,#100d20,#171129 52%,#0b0915);
 overflow-x:hidden;
}
body:before,body:after{
 content:"";position:fixed;pointer-events:none;
 border-radius:50%;filter:blur(2px);z-index:0;
}
body:before{
 width:220px;height:220px;top:4%;right:-130px;
 border:1px solid #a78bfa30;box-shadow:0 0 80px #895bff12;
}
body:after{
 width:180px;height:180px;bottom:3%;left:-110px;
 border:1px solid #ed8bc630;
}
.wrap{width:100%;max-width:470px;position:relative;z-index:1}
.brand{text-align:center;margin-bottom:24px}
.logo{
 display:inline-flex;align-items:center;justify-content:center;
 width:76px;height:76px;border-radius:26px;
 background:linear-gradient(145deg,#c4b5fd,#8056f5 58%,#6139cf);
 border:1px solid #ffffff45;box-shadow:0 15px 45px #8259f84d,inset 0 1px #ffffff70;
 font-size:34px;transform:rotate(-3deg);
}
.brand h1{
 font-size:29px;letter-spacing:.2px;margin:17px 0 8px;
 background:linear-gradient(100deg,#fff,#d7caff 55%,#f6c3e5);
 -webkit-background-clip:text;background-clip:text;color:transparent;
}
.brand p{color:var(--muted);font-size:13px;margin:0;line-height:2}
.card{
 position:relative;overflow:hidden;
 background:linear-gradient(145deg,rgba(39,31,67,.92),rgba(20,17,37,.94));
 border:1px solid var(--line);border-radius:28px;padding:26px;
 box-shadow:0 28px 90px #0006,inset 0 1px #ffffff0c;
 backdrop-filter:blur(20px);
}
.card:before{
 content:"";position:absolute;top:0;right:12%;left:12%;height:1px;
 background:linear-gradient(90deg,transparent,#c4b5fd80,transparent);
}
h2{font-size:21px;line-height:1.8;margin:0 0 9px}
.desc{font-size:13px;line-height:2.1;color:var(--muted);margin:0 0 21px}
label{display:block;font-size:13px;margin-bottom:10px;color:#f0eaff}
textarea{
 display:block;width:100%;min-height:170px;resize:vertical;
 background:rgba(11,9,23,.76);color:#fff;
 border:1px solid #65528c;border-radius:18px;
 padding:17px;outline:none;
 font:15px Tahoma,Arial,sans-serif;line-height:2;
 transition:border-color .2s,box-shadow .2s,background .2s;
}
textarea:focus{
 border-color:var(--purple);background:rgba(13,10,28,.96);
 box-shadow:0 0 0 4px #a78bfa18,0 0 30px #8b5cf615;
}
textarea::placeholder{color:#82779f}
.meta{
 display:flex;justify-content:space-between;align-items:center;
 gap:8px;margin-top:11px;font-size:11px;color:#a99dc9;
}
.private{
 display:inline-flex;align-items:center;gap:5px;padding:6px 9px;
 border:1px solid #a78bfa26;border-radius:999px;
 background:#a78bfa0b;color:#d7caff;
}
.count{font-variant-numeric:tabular-nums}
button,.again{
 display:flex;align-items:center;justify-content:center;gap:9px;
 width:100%;min-height:54px;padding:15px;margin-top:20px;
 border:1px solid #ffffff26;border-radius:16px;
 background:linear-gradient(110deg,#a78bfa,#8055f4 60%,#6d43dc);
 color:white;font:bold 15px Tahoma,Arial,sans-serif;
 cursor:pointer;text-decoration:none;
 box-shadow:0 12px 30px #7848e946,inset 0 1px #ffffff4d;
 transition:transform .18s,filter .18s,box-shadow .18s;
}
button:hover,.again:hover{filter:brightness(1.09);box-shadow:0 14px 35px #7848e960}
button:active,.again:active{transform:scale(.985)}
button:disabled{opacity:.7;cursor:wait}
.notice{
 margin:0 0 19px;padding:14px 15px;border-radius:15px;
 background:#28213f;border:1px solid #625184;
 color:#eee5ff;font-size:13px;line-height:2;overflow-wrap:anywhere;
}
.success{background:#123b32;border-color:#287d62;color:#baffdf}
.error{background:#481e31;border-color:#a34265;color:#ffd5e1}
.footer{text-align:center;color:#8d82ab;font-size:11px;line-height:2.1;margin-top:20px}
.footer .heart{color:#ed8bc6}
@media(max-width:400px){
 body{padding:23px 12px}
 .card{padding:21px;border-radius:23px}
 .logo{width:68px;height:68px;border-radius:23px}
 .brand h1{font-size:26px}
 h2{font-size:19px}
 textarea{min-height:150px}
}
@media(prefers-reduced-motion:reduce){
 *,*:before,*:after{transition:none!important;animation:none!important}
}
</style>
</head>
<body>
<div class="wrap">
 <header class="brand">
  <div class="logo" aria-hidden="true">✉</div>
  <h1>AtiMessage</h1>
  <p>حرف دلت رو آزادانه و ناشناس بگو <span>💜</span></p>
 </header>
 <main class="card">' . $body . '</main>
 <footer class="footer">پیامت بدون نمایش نام فرستنده ثبت می‌شود <span class="heart">♥</span></footer>
</div>
</body>
</html>';

    exit;
}

function anonymousPage(): void {
    $db = loadDB();

    $id = trim((string)($_GET['user'] ?? $_GET['id'] ?? ''));

    if (
        $id === '' ||
        strlen($id) > 100 ||
        !preg_match('/^[a-zA-Z0-9_-]+$/', $id)
    ) {
        page('لینک نامعتبر', '
            <h2>این لینک معتبر نیست</h2>
            <p class="desc">لینک ناقص است یا شناسهٔ آن اشتباه است.</p>
        ');
    }

    $index = findPublicId($db, $id);

    if ($index < 0 && isset($_GET['user'])) {
        $index = findUsername($db, $id);
    }

    if ($index < 0) {
        page('لینک پیدا نشد', '
            <h2>لینک پیدا نشد 😕</h2>
            <p class="desc">این لینک به حسابی متصل نیست. لینک جدید را از داخل برنامه دریافت کن.</p>
        ');
    }

    $username = (string)($db['users'][$index]['username'] ?? 'کاربر');
    $safeName = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $link = anonymousLink($db['users'][$index]);
    $notice = '';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $message = trim((string)($_POST['message'] ?? ''));

        if ($message === '' || mb_strlen($message, 'UTF-8') > 3000) {
            $notice = '<div class="notice error">متن پیام خالی است یا بیشتر از ۳۰۰۰ کاراکتر است.</div>';
        } else {
            $db['messages'][] = [
                'username' => $username,
                'message' => $message,
                'created_at' => date('c'),
                'anonymous' => true,
            ];

            if (!saveDB($db)) {
                $notice = '<div class="notice error">ذخیرهٔ پیام ناموفق بود. دوباره تلاش کن.</div>';
            } else {
                $synced = syncMessagesToJsonServer($db['messages']);

                // نمایش فقط همین پیام؛ خطای همگام‌سازی در لاگ سرور ثبت می‌شود.
                $notice = '<div class="notice success">✓ پیامت ثبت شد! 💜</div>';

                if (!$synced) {
                    error_log('AtiMessage: anonymous message JSON sync failed.');
                }
            }
        }
    }

    $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

    $body = '
<div style="display:flex;align-items:center;gap:13px;margin-bottom:17px">
 <div style="width:47px;height:47px;flex-shrink:0;border-radius:16px;display:grid;place-items:center;background:linear-gradient(145deg,#a78bfa,#7448f5);font-size:22px">💌</div>
 <div>
  <h2 style="margin:0 0 3px">پیام برای ' . $safeName . '</h2>
  <div style="font-size:11px;color:#b9afd5">ارسال ناشناس · سریع و ساده</div>
 </div>
</div>
<p class="desc">هرچی دوست داری بنویس؛ اسمت همراه پیام نمایش داده نمی‌شه.</p>
' . $notice . '
<form method="post" action="' . $safeLink . '">
 <label for="message">پیامت رو بنویس 💬</label>
 <textarea id="message" name="message" maxlength="3000" required placeholder="سلام! یه چیزی می‌خواستم بهت بگم..."></textarea>
 <div class="meta">
  <span class="private">🔒 ارسال ناشناس</span>
  <span class="count"><span id="count">۰</span> / ۳۰۰۰</span>
 </div>
 <button type="submit" id="send">
  <span>ارسال پیام</span><span aria-hidden="true">✦</span>
 </button>
</form>
<script>
(function(){
 const box=document.getElementById("message");
 const count=document.getElementById("count");
 const button=document.getElementById("send");
 const form=box.closest("form");
 const digits="۰۱۲۳۴۵۶۷۸۹";

 function updateCount(){
  count.textContent=String(box.value.length).replace(/[0-9]/g,function(n){
   return digits[Number(n)];
  });
 }

 box.addEventListener("input",updateCount);
 updateCount();

 form.addEventListener("submit",function(event){
  if(box.value.trim().length===0){
   event.preventDefault();
   box.focus();
   return;
  }

  if(box.value.length>3000){
   event.preventDefault();
   return;
  }

  button.disabled=true;
  button.innerHTML="<span>در حال ارسال...</span><span>⌛</span>";
 });
})();
</script>';

    page('ارسال پیام ناشناس', $body);
}

function handleApi(): void {
    $input = file_get_contents('php://input');
    $data = json_decode($input ?: '', true);

    if (!is_array($data)) {
        respond(['ok'=>false,'message'=>'JSON نامعتبر است.'], 400);
    }

    $action = (string)($data['action'] ?? '');
    $username = trim((string)($data['username'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $db = loadDB();

    if ($action === 'register') {
        if (
            !preg_match('/^[a-zA-Z0-9_-]{3,32}$/', $username) ||
            strlen($password) < 6 ||
            strlen($password) > 200
        ) {
            respond(['ok'=>false,'message'=>'نام کاربری یا رمز عبور معتبر نیست.'], 400);
        }

        if (findUsername($db, $username) >= 0) {
            respond(['ok'=>false,'message'=>'این نام کاربری قبلاً ثبت شده است.'], 409);
        }

        $token = bin2hex(random_bytes(32));

        $user = [
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'token_hash' => hash('sha256', $token),
            'public_id' => bin2hex(random_bytes(6)),
            'link_id' => bin2hex(random_bytes(16)),
            'created_at' => date('c'),
        ];

        $db['users'][] = $user;

        if (!saveDB($db)) {
            respond(['ok'=>false,'message'=>'ذخیره اطلاعات ناموفق بود.'], 500);
        }

        respond([
            'ok'=>true,
            'message'=>'ثبت‌نام موفق بود.',
            'token'=>$token,
            'public_id'=>$user['public_id'],
            'anonymous_link'=>anonymousLink($user),
        ]);
    }

    if ($action === 'login') {
        $index = findUsername($db, $username);

        if (
            $index < 0 ||
            !password_verify(
                $password,
                (string)($db['users'][$index]['password_hash'] ?? '')
            )
        ) {
            respond(['ok'=>false,'message'=>'نام کاربری یا رمز عبور اشتباه است.'], 401);
        }

        $token = bin2hex(random_bytes(32));
        $db['users'][$index]['token_hash'] = hash('sha256', $token);

        if (empty($db['users'][$index]['public_id'])) {
            $db['users'][$index]['public_id'] = bin2hex(random_bytes(6));
        }

        if (empty($db['users'][$index]['link_id'])) {
            $db['users'][$index]['link_id'] = bin2hex(random_bytes(16));
        }

        if (!saveDB($db)) {
            respond(['ok'=>false,'message'=>'ذخیره اطلاعات ناموفق بود.'], 500);
        }

        respond([
            'ok'=>true,
            'message'=>'ورود موفق بود.',
            'token'=>$token,
            'public_id'=>$db['users'][$index]['public_id'],
            'anonymous_link'=>anonymousLink($db['users'][$index]),
        ]);
    }

    $token = (string)($data['token'] ?? '');
    $index = findToken($db, $token);

    if ($index < 0) {
        respond(['ok'=>false,'message'=>'توکن نامعتبر است؛ دوباره وارد شو.'], 401);
    }

    if ($action === 'send_message') {
        $message = trim((string)($data['message'] ?? ''));

        if ($message === '' || mb_strlen($message, 'UTF-8') > 3000) {
            respond(['ok'=>false,'message'=>'متن پیام معتبر نیست.'], 400);
        }

        $db['messages'][] = [
            'username' => $db['users'][$index]['username'],
            'message' => $message,
            'created_at' => date('c'),
            'anonymous' => false,
        ];

        if (!saveDB($db)) {
            respond(['ok'=>false,'message'=>'ذخیره پیام ناموفق بود.'], 500);
        }

        // حفظ رفتار قبلی و تلاش برای همگام‌سازی پیام‌ها.
        if (!syncMessagesToJsonServer($db['messages'])) {
            error_log('AtiMessage: send_message JSON sync failed.');
        }

        respond(['ok'=>true,'message'=>'پیام ارسال شد.']);
    }

    if ($action === 'get_messages') {
        $mine = (string)$db['users'][$index]['username'];
        $messages = [];

        foreach ($db['messages'] as $message) {
            if (
                is_array($message) &&
                ($message['username'] ?? '') === $mine
            ) {
                $messages[] = $message;
            }
        }

        respond(['ok'=>true,'messages'=>array_values($messages)]);
    }

    if ($action === 'delete_all_messages') {
        $mine = (string)$db['users'][$index]['username'];

        $db['messages'] = array_values(array_filter(
            $db['messages'],
            fn($m) => !is_array($m) ||
                ($m['username'] ?? '') !== $mine
        ));

        if (!saveDB($db)) {
            respond(['ok'=>false,'message'=>'حذف پیام‌ها ناموفق بود.'], 500);
        }

        // تلاش برای فرستادن فهرست جدید، پس از حذف محلی.
        // موفقیت HTTP به‌تنهایی حذف روی سرور خارجی را ثابت نمی‌کند.
        $synced = syncMessagesToJsonServer($db['messages']);

        respond([
            'ok'=>true,
            'message'=>'پیام‌ها از حافظه محلی حذف شدند.',
            'sync_attempted'=>true,
            'sync_http_success'=>$synced,
        ]);
    }

    respond(['ok'=>false,'message'=>'عملیات ناشناخته است.'], 400);
}

try {
    if (($_GET['page'] ?? '') === 'send') {
        anonymousPage();
    }

    handleApi();
} catch (Throwable $e) {
    error_log('AtiMessage: ' . $e->getMessage());

    respond([
        'ok' => false,
        'message' => 'خطای داخلی سرور رخ داد.'
    ], 500);
}