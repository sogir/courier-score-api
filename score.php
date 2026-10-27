<?php
/**
 * check.php — Direct multi-courier customer lookup (single file).
 *
 * No secrets live in this file. Credentials come from, in order:
 *   1. the JSON request body
 *   2. HTTP headers (X-Steadfast-Key, X-Pathao-Token, ...)
 *   3. environment variables (COURIER_STEADFAST_KEY, COURIER_PATHAO_PASSWORD, ...)
 *
 * Request:  POST JSON { "phone": "01XXXXXXXXX", ...credentials..., "debug": true }
 *
 * Optional: set COURIER_API_KEY to require "X-Api-Key: <key>" (or "Authorization: Bearer <key>")
 * on every request, so nobody else can spend your courier accounts.
 * Requires PHP 8.1+.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Key, X-Phone, X-Steadfast-Key, X-Steadfast-Secret, X-Pathao-Token, X-Redx-Token, X-Carrybee-Token, X-Carrybee-Business, X-Paperfly-Token, X-Paperfly-User, X-Paperfly-Pass, X-Ecourier-Key, X-Ecourier-Secret, X-Ecourier-Userid');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed. Use POST with a JSON body.']);
    exit;
}

// ---------------------------------------------------------------------------
// 1. Settings from environment variables (empty when unset — never hardcoded)
// ---------------------------------------------------------------------------
$env = static function (string $key, $default = '') {
    $v = getenv($key);
    if ($v === false || $v === '') return $default;
    $l = strtolower($v);
    if ($l === 'true')  return true;
    if ($l === 'false') return false;
    return $v;
};

$settings = [
    'timeout' => (int) $env('COURIER_NATIVE_TIMEOUT', 20),
    'steadfast' => [
        'enabled'    => (bool) $env('COURIER_STEADFAST_ENABLED', true),
        'endpoint'   => $env('COURIER_STEADFAST_ENDPOINT', 'https://portal.packzy.com/api/v1'),
        'api_key'    => $env('COURIER_STEADFAST_KEY'),
        'api_secret' => $env('COURIER_STEADFAST_SECRET'),
    ],
    'pathao' => [
        'enabled'       => (bool) $env('COURIER_PATHAO_ENABLED', true),
        'merchant_endpoint' => $env('COURIER_PATHAO_MERCHANT_ENDPOINT', 'https://merchant.pathao.com'),
        'endpoint'      => $env('COURIER_PATHAO_ENDPOINT', 'https://api-hermes.pathao.com'),
        'client_id'     => $env('COURIER_PATHAO_CLIENT_ID'),
        'client_secret' => $env('COURIER_PATHAO_CLIENT_SECRET'),
        'username'      => $env('COURIER_PATHAO_USERNAME'),
        'password'      => $env('COURIER_PATHAO_PASSWORD'),
    ],
    // RedX merchant web login (the Open API has no customer lookup)
    'redx' => [
        'enabled'         => (bool) $env('COURIER_REDX_ENABLED', true),
        'login_endpoint'  => $env('COURIER_REDX_LOGIN_ENDPOINT', 'https://api.redx.com.bd/v4/auth/login'),
        'lookup_endpoint' => $env('COURIER_REDX_LOOKUP_ENDPOINT', 'https://redx.com.bd/api/redx_se/admin/parcel/customer-success-return-rate'),
        'phone'           => $env('COURIER_REDX_PHONE'),
        'password'        => $env('COURIER_REDX_PASSWORD'),
    ],
    'carrybee' => [
        'enabled'        => (bool) $env('COURIER_CARRYBEE_ENABLED', true),
        'endpoint'       => $env('COURIER_CARRYBEE_ENDPOINT', 'https://api-merchant.carrybee.com/api/v2'),
        'login_endpoint' => $env('COURIER_CARRYBEE_LOGIN_ENDPOINT', 'https://merchant.carrybee.com'),
        'phone'          => $env('COURIER_CARRYBEE_PHONE'),
        'password'       => $env('COURIER_CARRYBEE_PASSWORD'),
    ],
    'paperfly' => [
        'enabled'  => (bool) $env('COURIER_PAPERFLY_ENABLED', true),
        'endpoint'       => $env('COURIER_PAPERFLY_ENDPOINT', 'https://go-app.paperfly.com.bd/merchant/api/react/smart-check/smart-check-v2.php'),
        'login_endpoint' => $env('COURIER_PAPERFLY_LOGIN_ENDPOINT', 'https://go-app.paperfly.com.bd/merchant/api/react/authentication/login_using_password.php'),
        'token'          => $env('COURIER_PAPERFLY_TOKEN'),
        'username'       => $env('COURIER_PAPERFLY_USER'),
        'password'       => $env('COURIER_PAPERFLY_PASS'),
    ],
    'ecourier' => [
        'enabled'  => (bool) $env('COURIER_ECOURIER_ENABLED', true),
        'endpoint' => $env('COURIER_ECOURIER_ENDPOINT', 'https://backoffice.ecourier.com.bd/api/fraud-status-check'),
        'api_key'  => $env('COURIER_ECOURIER_KEY'),
        'secret'   => $env('COURIER_ECOURIER_SECRET'),
        'user_id'  => $env('COURIER_ECOURIER_USERID'),
    ],
];

// ---------------------------------------------------------------------------
// 2. Access check + request input
// ---------------------------------------------------------------------------
$apiKey = (string) $env('COURIER_API_KEY');
if ($apiKey !== '') {
    $given = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($given === '') {
        $auth  = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $given = stripos($auth, 'Bearer ') === 0 ? trim(substr($auth, 7)) : '';
    }
    if (!hash_equals($apiKey, $given)) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized.']);
        exit;
    }
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];
$debug = !empty($body['debug']);

$hdr = static function (string $name): string {
    $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$k]) ? trim((string) $_SERVER[$k]) : '';
};

// First non-empty value: body paths, then header, then env setting (only if courier enabled).
$pick = static function (array $bodyPaths, string $header, string $courier, string $setting) use ($body, $hdr, $settings): string {
    foreach ($bodyPaths as $path) {
        $v = $body;
        foreach (explode('.', $path) as $seg) {
            $v = is_array($v) && array_key_exists($seg, $v) ? $v[$seg] : null;
        }
        if (is_scalar($v) && trim((string) $v) !== '') return trim((string) $v);
    }
    if ($header !== '' && $hdr($header) !== '') return $hdr($header);
    if ($settings[$courier]['enabled'] && is_string($settings[$courier][$setting] ?? null)) {
        return trim($settings[$courier][$setting]);
    }
    return '';
};

$rawPhone = $body['phone'] ?? $hdr('X-Phone');
$phone    = preg_replace('/^880/', '0', preg_replace('/\D/', '', (string) $rawPhone));
if (!preg_match('/^01[3-9]\d{8}$/', $phone)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing Bangladeshi phone number (expected 01XXXXXXXXX).']);
    exit;
}

$creds = [
    'steadfast' => [
        'key'    => $pick(['steadfast.api_key', 'steadfast_api_key'], 'X-Steadfast-Key', 'steadfast', 'api_key'),
        'secret' => $pick(['steadfast.api_secret', 'steadfast_secret_key'], 'X-Steadfast-Secret', 'steadfast', 'api_secret'),
    ],
    'pathao' => [
        'token'         => $pick(['pathao.token', 'pathao_token'], 'X-Pathao-Token', 'pathao', 'token'),
        'lookup'        => rtrim((string) $settings['pathao']['merchant_endpoint'], '/') . '/api/v1/user/success',
        'client_id'     => $pick(['pathao.client_id'], '', 'pathao', 'client_id'),
        'client_secret' => $pick(['pathao.client_secret'], '', 'pathao', 'client_secret'),
        'username'      => $pick(['pathao.username'], '', 'pathao', 'username'),
        'password'      => $pick(['pathao.password'], '', 'pathao', 'password'),
    ],
    'redx' => [
        'token'    => $pick(['redx.token', 'redx_token'], 'X-Redx-Token', 'redx', 'token'),
        'phone'    => $pick(['redx.phone'], '', 'redx', 'phone'),
        'password' => $pick(['redx.password'], '', 'redx', 'password'),
    ],
    'carrybee' => [
        'token'    => $pick(['carrybee.token', 'carrybee_token'], 'X-Carrybee-Token', 'carrybee', 'token'),
        'business' => $pick(['carrybee.business_id', 'carrybee_business_id'], 'X-Carrybee-Business', 'carrybee', 'business_id'),
        'phone'    => $pick(['carrybee.phone'], '', 'carrybee', 'phone'),
        'password' => $pick(['carrybee.password'], '', 'carrybee', 'password'),
    ],
    'paperfly' => [
        'token' => $pick(['paperfly.token', 'paperfly_token'], 'X-Paperfly-Token', 'paperfly', 'token'),
        'user'  => $pick(['paperfly.username', 'paperfly_user'], 'X-Paperfly-User', 'paperfly', 'username'),
        'pass'  => $pick(['paperfly.password', 'paperfly_pass'], 'X-Paperfly-Pass', 'paperfly', 'password'),
    ],
    'ecourier' => [
        'key'    => $pick(['ecourier.api_key', 'ecourier_api_key'], 'X-Ecourier-Key', 'ecourier', 'api_key'),
        'secret' => $pick(['ecourier.api_secret', 'ecourier_api_secret'], 'X-Ecourier-Secret', 'ecourier', 'secret'),
        'userid' => $pick(['ecourier.user_id', 'ecourier_user_id'], 'X-Ecourier-Userid', 'ecourier', 'user_id'),
    ],
];

// ---------------------------------------------------------------------------
// 3. Token logins (cached)
// ---------------------------------------------------------------------------
$loginNotes  = [];
$fromLogin   = ['pathao' => false, 'redx' => false, 'carrybee' => false, 'paperfly' => false];

$p = $creds['pathao'];
if ($p['token'] === '' && $p['username'] !== '' && $p['password'] !== '') {
    [$creds['pathao']['token'], $creds['pathao']['lookup']] = pathaoLogin($settings['pathao'], $p, $loginNotes);
    $fromLogin['pathao'] = true;
}

$r = $creds['redx'];
if ($r['token'] === '' && $r['phone'] !== '' && $r['password'] !== '') {
    $creds['redx']['token'] = redxLogin($settings['redx']['login_endpoint'], $r, $loginNotes);
    $fromLogin['redx']      = true;
}

$f = $creds['paperfly'];
if ($f['token'] === '' && $f['user'] !== '' && $f['pass'] !== '') {
    $creds['paperfly']['token'] = paperflyLogin($settings['paperfly']['login_endpoint'], $f, $loginNotes);
    $fromLogin['paperfly']      = true;
}

$c = $creds['carrybee'];
if (($c['token'] === '' || $c['business'] === '') && $c['phone'] !== '' && $c['password'] !== '') {
    [$creds['carrybee']['token'], $creds['carrybee']['business']] = carrybeeLogin($settings['carrybee']['login_endpoint'], $c, $loginNotes);
    $fromLogin['carrybee'] = true;
}

// ---------------------------------------------------------------------------
// 4. Parallel lookups
// ---------------------------------------------------------------------------
$mh      = curl_multi_init();
$handles = [];

$add = static function (string $key, string $url, string $method, array $headers, ?array $json = null) use ($mh, &$handles, $settings): void {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $settings['timeout'],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => mergeHeaders([
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
            'Accept-Language: en-US,en;q=0.9',
        ], $json !== null ? ['Content-Type: application/json'] : [], $headers),
    ]);
    if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    curl_multi_add_handle($mh, $ch);
    $handles[$key] = $ch;
};

// Steadfast — since 2026-09-27 only the score endpoint returns data
if ($creds['steadfast']['key'] !== '' && $creds['steadfast']['secret'] !== '') {
    $add('steadfast', rtrim($settings['steadfast']['endpoint'], '/') . '/fraud_check/score/' . urlencode($phone), 'GET', [
        'Api-Key: ' . $creds['steadfast']['key'],
        'Secret-Key: ' . $creds['steadfast']['secret'],
    ]);
}

// Pathao
if ($creds['pathao']['token'] !== '') {
    $pHost = parse_url($creds['pathao']['lookup'], PHP_URL_SCHEME) . '://' . parse_url($creds['pathao']['lookup'], PHP_URL_HOST);
    $add('pathao', $creds['pathao']['lookup'], 'POST', [
        'Accept: application/json, text/plain, */*',
        'Origin: ' . $pHost,
        'Referer: ' . $pHost . '/',
        'Authorization: Bearer ' . $creds['pathao']['token'],
    ], ['phone' => $phone]);
}

// RedX — GET {lookup}?phoneNumber=8801XXXXXXXXX
if ($creds['redx']['token'] !== '') {
    $add('redx', $settings['redx']['lookup_endpoint'] . '?phoneNumber=' . urlencode('88' . $phone), 'GET', [
        'Accept: application/json, text/plain, */*',
        'Content-Type: application/json',
        'Origin: https://redx.com.bd',
        'Referer: https://redx.com.bd/',
        'Authorization: Bearer ' . $creds['redx']['token'],
    ]);
}

// Carrybee
// GET {endpoint}/businesses/{businessId}/customers/{+8801XXXXXXXXX}
if ($creds['carrybee']['token'] !== '' && $creds['carrybee']['business'] !== '') {
    $add('carrybee', rtrim($settings['carrybee']['endpoint'], '/') . '/businesses/' . rawurlencode($creds['carrybee']['business'])
        . '/customers/' . rawurlencode('+88' . $phone), 'GET', [
        'Authorization: Bearer ' . $creds['carrybee']['token'],
    ]);
}

// Paperfly (smart-check v2, POST with bearer token)
if ($creds['paperfly']['token'] !== '') {
    $add('paperfly', $settings['paperfly']['endpoint'] . '?search_text=' . urlencode($phone), 'POST', [
        'Authorization: Bearer ' . $creds['paperfly']['token'],
    ], ['search_text' => $phone]);
}

// eCourier
$ecc = $creds['ecourier'];
if ($ecc['key'] !== '' && $ecc['secret'] !== '' && $ecc['userid'] !== '') {
    $add('ecourier', $settings['ecourier']['endpoint'], 'POST', [
        'API-KEY: ' . $ecc['key'],
        'API-SECRET: ' . $ecc['secret'],
        'USER-ID: ' . $ecc['userid'],
    ], ['number' => $phone]);
}

do {
    $mrc = curl_multi_exec($mh, $active);
    if ($active && curl_multi_select($mh, 1.0) === -1) usleep(1000);
} while ($active && $mrc === CURLM_OK);

$raw = [];
foreach ($handles as $key => $ch) {
    $txt = (string) curl_multi_getcontent($ch);
    $raw[$key] = [
        'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'err'  => curl_error($ch),
        'body' => $txt,
        'data' => json_decode($txt, true),
    ];
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

// RedX answers malformed/unauthorised lookups with HTTP 400 and a fixed placeholder body
// (10 total / 6 delivered for every number). Never show that: flag it and treat as an error.
if (($raw['redx']['code'] ?? 0) === 400) {
    $raw['redx']['placeholder'] = true;
}
if (!isset($handles['redx'])) unset($raw['redx']);

// A cached login token that the courier rejects is dropped, so the next request logs in again.
if ($fromLogin['pathao'] && in_array($raw['pathao']['code'] ?? 0, [401, 403], true)) {
    cacheForget('pathao2_' . md5($creds['pathao']['username']));
}
if ($fromLogin['redx'] && in_array($raw['redx']['code'] ?? 0, [401, 403], true)) {
    cacheForget('redx_' . md5($creds['redx']['phone']));
}
if ($fromLogin['paperfly'] && in_array($raw['paperfly']['code'] ?? 0, [401, 403], true)) {
    cacheForget('paperfly_' . md5($creds['paperfly']['user']));
}
if ($fromLogin['carrybee'] && in_array($raw['carrybee']['code'] ?? 0, [401, 403], true)) {
    cacheForget('carrybee_' . md5($creds['carrybee']['phone']));
}

// ---------------------------------------------------------------------------
// 5. Build response
// ---------------------------------------------------------------------------
$box = static fn(int $t = 0, int $d = 0, int $c = 0): array => [
    'Total Parcels'     => $t,
    'Delivered Parcels' => $d,
    'Canceled Parcels'  => $c,
];
$pathaoBox = static fn(int $t = 0, int $s = 0): array => [
    'Total Delivery'      => $t,
    'Successful Delivery' => $s,
    'Canceled Delivery'   => max(0, $t - $s),
];

// Not Connected / Error status, or null when the courier answered with JSON.
$problem = static function (string $key, string $label, string $hint) use ($handles, $raw, $loginNotes): ?array {
    if (!isset($handles[$key])) {
        $detail = $loginNotes[$key] ?? $hint; // only failure notes are keyed by courier name
        return ['Status' => 'Not Connected', 'Details' => [$detail]];
    }
    $r = $raw[$key];
    if ($r['err'] !== '')                     return ['Status' => 'Error', 'Details' => ["$label request failed: {$r['err']}"]];
    if ($r['code'] < 200 || $r['code'] >= 300) return ['Status' => 'Error', 'Details' => ["$label returned HTTP {$r['code']}."]];
    if (!is_array($r['data']))                 return ['Status' => 'Error', 'Details' => ["$label returned a non-JSON response."]];
    return null;
};

// First numeric / string value found under any of the keys.
$arr = static fn($v): array => is_array($v) ? $v : [];
$num = static function (array $a, array $keys): ?int {
    foreach ($keys as $k) if (isset($a[$k]) && is_numeric($a[$k])) return (int) $a[$k];
    return null;
};
$str = static function (array $a, array $keys): ?string {
    foreach ($keys as $k) if (isset($a[$k]) && is_string($a[$k]) && $a[$k] !== '') return $a[$k];
    return null;
};
$pretty = static fn(string $s): string => ucwords(str_replace('_', ' ', $s));

$summaries = [];

// Steadfast
if ($pr = $problem('steadfast', 'Steadfast', 'Add Steadfast api_key/api_secret (request body or COURIER_STEADFAST_KEY/SECRET env).')) {
    $summaries['Steadfast'] = $box() + $pr;
} else {
    $sf = $arr($raw['steadfast']['data']);
    if (isset($sf['data']) && is_array($sf['data'])) $sf = $sf['data'] + $sf;
    $del = $num($sf, ['delivered_count', 'total_delivered', 'delivered']) ?? 0;
    $can = $num($sf, ['cancelled_count', 'canceled_count', 'total_cancelled', 'total_canceled', 'cancelled', 'canceled']) ?? 0;
    $out = $box($num($sf, ['total_count', 'total_parcels', 'total']) ?? ($del + $can), $del, $can);
    if (isset($sf['delivery_ratio']) && is_numeric($sf['delivery_ratio'])) $out['Success Ratio'] = $sf['delivery_ratio'] + 0;
    if (isset($sf['score']) && is_numeric($sf['score']))                   $out['Score']         = $sf['score'] + 0;
    if (($label = $str($sf, ['level', 'rating', 'risk_level'])) !== null)  $out['Rating']        = $pretty($label);
    if (isset($sf['total_reports']) && is_numeric($sf['total_reports']))   $out['Fraud Reports'] = (int) $sf['total_reports'];
    if (!empty($sf['volume_range']) && is_string($sf['volume_range']))     $out['Volume']        = $sf['volume_range'];
    $summaries['Steadfast'] = $out;
}

// RedX
if ($pr = $problem('redx', 'RedX', 'Add RedX merchant phone/password (request body or COURIER_REDX_PHONE/PASSWORD env).')) {
    if (!empty($raw['redx']['placeholder'])) {
        $pr['Details'] = ['RedX rejected the lookup (HTTP 400) and sent placeholder data, which was ignored.'];
    } else {
        $rxMsg = $raw['redx']['data']['message'] ?? '';
        if (is_string($rxMsg) && $rxMsg !== '' && isset($pr['Details'][0])) $pr['Details'][0] .= ' ' . $rxMsg;
    }
    $summaries['RedX'] = $box() + $pr;
} elseif (!array_key_exists('data', $raw['redx']['data'])) {
    $summaries['RedX'] = $box() + ['Status' => 'Error', 'Details' => ['Unexpected RedX response (possibly rate limited; retry in a minute).']];
} else {
    $rx  = $arr($raw['redx']['data']['data']);
    $tot = $num($rx, ['totalParcels', 'total']) ?? 0;
    $del = $num($rx, ['deliveredParcels', 'delivered']) ?? 0;
    $out = $box($tot, $del, max(0, $tot - $del));
    $out['Success Ratio'] = isset($rx['successRate']) && is_numeric($rx['successRate'])
        ? $rx['successRate'] + 0
        : ($tot > 0 ? round($del / $tot * 100, 2) : 0);
    if (!empty($rx['customerSegment']) && is_string($rx['customerSegment']))    $out['Customer Segment']  = $rx['customerSegment'];
    $summaries['RedX'] = $out;
}

// Pathao
if ($pr = $problem('pathao', 'Pathao', 'Add Pathao merchant username/password (request body or COURIER_PATHAO_USERNAME/PASSWORD env).')) {
    $summaries['Pathao'] = $pathaoBox() + $pr;
} else {
    $pd  = $arr($raw['pathao']['data']['data'] ?? []);
    $pt  = $arr($pd['customer'] ?? $pd);
    $out = $pathaoBox((int) ($pt['total_delivery'] ?? 0), (int) ($pt['successful_delivery'] ?? 0));
    if (!empty($pd['customer_rating']) && is_string($pd['customer_rating'])) {
        $out['Customer Rating'] = $pretty($pd['customer_rating']);
    }
    $countsAvailable = ($pd['show_count'] ?? true) !== false && isset($pt['total_delivery']);
    $out['Counts Available'] = $countsAvailable;
    if (!$countsAvailable) {
        $out['Details'] = ['Pathao only returns delivery counts to entitled merchant accounts; this account gets a rating only.'];
    }
    $summaries['Pathao'] = $out;
}

// Carrybee ("Customer not found" means this number has no Carrybee history)
$cbMsg = (string) ($raw['carrybee']['data']['message'] ?? '');
if (($raw['carrybee']['code'] ?? 0) === 404 && stripos($cbMsg, 'not found') !== false) {
    $summaries['Carrybee'] = $box() + ['Success Ratio' => 0, 'Details' => ['No Carrybee history for this number.']];
} elseif ($pr = $problem('carrybee', 'Carrybee', 'Add Carrybee phone/password (request body or COURIER_CARRYBEE_PHONE/PASSWORD env).')) {
    $summaries['Carrybee'] = $box() + $pr;
} else {
    $cb  = $arr($raw['carrybee']['data']['data'] ?? $raw['carrybee']['data']);
    $tot = $num($cb, ['total_order', 'total', 'total_parcels']);
    $can = $num($cb, ['cancelled_order', 'canceled_order', 'cancelled', 'canceled', 'returned']) ?? 0;
    $del = $num($cb, ['delivered_order', 'delivered', 'success']) ?? max(0, ($tot ?? 0) - $can);
    $out = $box($tot ?? ($del + $can), $del, $can);
    if (isset($cb['success_rate']) && is_numeric($cb['success_rate'])) $out['Success Ratio'] = $cb['success_rate'] + 0;
    if (isset($cb['fraud_count']) && is_numeric($cb['fraud_count']))   $out['Fraud Reports'] = (int) $cb['fraud_count'];
    $summaries['Carrybee'] = $out;
}

// Paperfly
if ($pr = $problem('paperfly', 'Paperfly', 'Add Paperfly username/password or token (request body or COURIER_PAPERFLY_USER/PASS/TOKEN env).')) {
    $summaries['Paperfly'] = $box() + $pr;
} else {
    $pf = $arr($raw['paperfly']['data']);
    foreach (['records', 'data', 'result', 'response'] as $wrap) {
        if (isset($pf[$wrap]) && is_array($pf[$wrap])) {
            $pf = array_is_list($pf[$wrap]) ? ($pf[$wrap][0] ?? []) : $pf[$wrap];
            break;
        }
    }
    $pf  = is_array($pf) ? $pf : [];
    $del = $num($pf, ['delivered', 'delivered_parcels', 'total_delivered', 'success', 'successful']) ?? 0;
    $can = $num($pf, ['returned', 'canceled', 'cancelled', 'total_returned', 'total_cancelled', 'cancel', 'return']) ?? 0;
    $out = $box($num($pf, ['total', 'total_parcels', 'total_orders', 'total_parcel']) ?? ($del + $can), $del, $can);
    if (($partial = $num($pf, ['partial', 'partial_delivered'])) !== null) $out['Partial Parcels'] = $partial;
    if (isset($pf['smart_check']) && is_array($pf['smart_check'])) {
        $out['Smart Check'] = $pf['smart_check']; // label, color, icon, delivery_rate, return_rate, note
    }
    $summaries['Paperfly'] = $out;
}

// eCourier — official "Fraud Alert Check" (POST /fraud-status-check). It returns a status
// label ("Verified by eCourier", "New Customer for eCourier", "Warning!") and, for warnings,
// a list of reasons. It never returns parcel counts.
if ($pr = $problem('ecourier', 'eCourier', 'Add eCourier api_key, api_secret, user_id (request body or COURIER_ECOURIER_KEY/SECRET/USERID env).')) {
    $ecErr = $raw['ecourier']['data']['errors'] ?? null;
    if (is_array($ecErr) && $ecErr && isset($pr['Details'][0])) {
        $pr['Details'][0] .= ' ' . implode(' ', array_filter($ecErr, 'is_string'));
    }
    $summaries['eCourier'] = $box() + $pr;
} else {
    $ec  = $arr($raw['ecourier']['data']);
    $msg = $ec['message'] ?? '';
    if (($ec['success'] ?? false) !== true) {
        // response_code 203: invalid number, service not enabled, or insufficient COD balance
        $summaries['eCourier'] = $box() + [
            'Status'  => 'Error',
            'Details' => [is_string($msg) && trim($msg) !== '' ? trim($msg) : 'eCourier did not return a fraud status.'],
        ];
    } else {
        $status = is_string($ec['customer_status'] ?? null) ? trim($ec['customer_status']) : '';
        $out = $box() + [
            'Customer Status'  => $status !== '' ? $status : 'Unknown',
            'Counts Available' => false,
        ];
        $reasons = is_array($msg) ? array_values(array_filter($msg, static fn($m) => is_string($m) && trim($m) !== ''))
                                  : (is_string($msg) && trim($msg) !== '' ? [trim($msg)] : []);
        if ($reasons) $out['Fraud Reasons'] = $reasons;
        $out['Details'] = ['eCourier only returns a customer status, not parcel counts.'];
        $summaries['eCourier'] = $out;
    }
}

$response = ['Summaries' => $summaries];

if ($debug) { // never includes credentials
    $d = ['logins' => $loginNotes, 'couriers' => []];
    foreach (['steadfast', 'redx', 'pathao', 'carrybee', 'paperfly', 'ecourier'] as $k) {
        $d['couriers'][$k] = isset($raw[$k])
            ? ['queried' => true, 'http' => $raw[$k]['code'],
               'curl_error' => $raw[$k]['err'], 'body' => substr($raw[$k]['body'], 0, 800)]
            : ['queried' => false];
    }
    $response['Debug'] = $d;
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// ===========================================================================
// Helpers
// ===========================================================================

/** Small blocking HTTP call. Returns [status, body, redirect URL]. */
function courierHttp(string $url, string $method = 'GET', array $headers = [], ?string $payload = null, ?string $jar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => mergeHeaders([
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
            'Accept-Language: en-US,en;q=0.9',
        ], $headers),
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    if ($jar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    $res  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc  = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$code, $res, $loc];
}

function cacheFile(): string
{
    return sys_get_temp_dir() . '/courier_tokens.json';
}

function cacheGet(string $key): ?string
{
    $f = cacheFile();
    if (!is_file($f)) return null;
    $c = json_decode((string) file_get_contents($f), true);
    return (!empty($c[$key]['token']) && ($c[$key]['exp'] ?? 0) > time()) ? $c[$key]['token'] : null;
}

function cacheForget(string $key): void
{
    $f = cacheFile();
    if (!is_file($f)) return;
    $c = json_decode((string) file_get_contents($f), true) ?: [];
    unset($c[$key]);
    @file_put_contents($f, json_encode($c), LOCK_EX);
}

function cachePut(string $key, string $token, int $ttl): void
{
    $f = cacheFile();
    $c = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $c[$key] = ['token' => $token, 'exp' => time() + max(60, $ttl)];
    if (@file_put_contents($f, json_encode($c), LOCK_EX) !== false) @chmod($f, 0600);
}

/**
 * Pathao login. Tries the merchant panel login first (merchant.pathao.com, username/password),
 * then the Hermes password grant (needs client_id/client_secret too).
 * Returns [token, lookupUrl]; token '' on failure.
 */
function pathaoLogin(array $cfg, array $p, array &$notes): array
{
    $key = 'pathao2_' . md5($p['username']);
    if (($c = cacheGet($key)) && ($d = json_decode($c, true)) && !empty($d['t']) && !empty($d['u'])) {
        return [$d['t'], $d['u']];
    }

    $tries = [[
        rtrim($cfg['merchant_endpoint'], '/') . '/api/v1/login',
        ['username' => $p['username'], 'password' => $p['password']],
        rtrim($cfg['merchant_endpoint'], '/') . '/api/v1/user/success',
    ]];
    if ($p['client_id'] !== '' && $p['client_secret'] !== '') {
        $tries[] = [
            rtrim($cfg['endpoint'], '/') . '/aladdin/api/v1/issue-token',
            ['client_id' => $p['client_id'], 'client_secret' => $p['client_secret'],
             'username' => $p['username'], 'password' => $p['password'], 'grant_type' => 'password'],
            rtrim($cfg['endpoint'], '/') . '/aladdin/api/v1/user/success',
        ];
    }

    $codes = [];
    foreach ($tries as [$loginUrl, $payload, $lookupUrl]) {
        [$code, $res] = courierHttp($loginUrl, 'POST', ['Content-Type: application/json'], json_encode($payload));
        $j = json_decode($res, true) ?: [];
        $t = trim((string) ($j['access_token'] ?? $j['data']['access_token'] ?? ''));
        $host = parse_url($loginUrl, PHP_URL_HOST);
        if ($t !== '') {
            cachePut($key, json_encode(['t' => $t, 'u' => $lookupUrl]), (int) ($j['expires_in'] ?? 3600) - 300);
            $notes['pathao_login_via'] = $host . ($codes ? ' (after: ' . implode(', ', $codes) . ')' : '');
            return [$t, $lookupUrl];
        }
        $msg     = $j['message'] ?? $j['error'] ?? '';
        $codes[] = "$host HTTP $code" . (is_string($msg) && $msg !== '' ? ": $msg" : '');
    }
    $notes['pathao'] = 'Pathao login failed (' . implode(', ', $codes) . ').';
    return ['', ''];
}

/** Merge header lists; a later header with the same name replaces an earlier one. */
function mergeHeaders(array ...$lists): array
{
    $out = [];
    foreach ($lists as $list) {
        foreach ($list as $h) {
            $out[strtolower(trim(strstr($h, ':', true) ?: $h))] = $h;
        }
    }
    return array_values($out);
}

/** RedX merchant web login: POST {phone: 8801XXXXXXXXX, password} -> data.accessToken */
function redxLogin(string $url, array $r, array &$notes): string
{
    $key = 'redx_' . md5($r['phone']);
    if ($t = cacheGet($key)) return $t;

    $local = preg_replace('/^(?:88)?(01\d{9})$/', '$1', preg_replace('/\D/', '', $r['phone']));
    [$code, $res] = courierHttp($url, 'POST', [
            'Content-Type: application/json',
            'Accept: application/json, text/plain, */*',
            'Origin: https://redx.com.bd',
            'Referer: https://redx.com.bd/',
        ], json_encode(['phone' => '88' . $local, 'password' => $r['password']]));
    $j = json_decode($res, true) ?: [];
    $t = $j['data']['accessToken'] ?? $j['accessToken'] ?? '';
    if (!is_string($t) || $t === '') {
        $msg = is_string($j['message'] ?? null) ? ' ' . $j['message'] : '';
        $notes['redx'] = "RedX login failed (HTTP $code).$msg";
        return '';
    }
    cachePut($key, $t, 50 * 60);
    return $t;
}

/** Paperfly merchant panel login: POST {username, password} -> token */
function paperflyLogin(string $url, array $f, array &$notes): string
{
    $key = 'paperfly_' . md5($f['user']);
    if ($t = cacheGet($key)) return $t;

    [$code, $res] = courierHttp($url, 'POST', ['Content-Type: application/json'],
        json_encode(['username' => $f['user'], 'password' => $f['pass']]));
    $j = json_decode($res, true) ?: [];
    $t = $j['token'] ?? $j['data']['token'] ?? '';
    if (!is_string($t) || $t === '') {
        $msg = is_string($j['message'] ?? null) ? ' ' . $j['message'] : '';
        $notes['paperfly'] = "Paperfly login failed (HTTP $code).$msg";
        return '';
    }
    cachePut($key, $t, 55 * 60);
    return $t;
}

/**
 * Carrybee merchant dashboard login (NextAuth, provider id "login").
 * Unofficial: Carrybee's public API has no fraud-check endpoint, so this signs
 * in the way merchant.carrybee.com does. Fragile by nature.
 *
 * Returns [accessToken, businessId]; both '' on failure.
 */
function carrybeeLogin(string $base, array $c, array &$notes): array
{
    $key = 'carrybee_' . md5($c['phone']);
    if (($cached = cacheGet($key)) && ($d = json_decode($cached, true)) && !empty($d['t']) && !empty($d['b'])) {
        return [$d['t'], (string) $d['b']];
    }

    $base    = rtrim($base, '/');
    $headers = ["Referer: $base/login", "Origin: $base"];
    $jar     = tempnam(sys_get_temp_dir(), 'cbjar');

    // Login phone must be +8801XXXXXXXXX
    $digits = preg_replace('/\D/', '', $c['phone']);
    $local  = preg_replace('/^(?:88)?(01\d{9})$/', '$1', $digits);
    $login  = '+88' . $local;

    try {
        // 1. CSRF token (+ cookie)
        [$code, $res] = courierHttp("$base/api/auth/csrf", 'GET', $headers, null, $jar);
        $csrf = json_decode($res, true)['csrfToken'] ?? '';
        if ($csrf === '') {
            $notes['carrybee'] = "Carrybee login: no CSRF token (HTTP $code).";
            return ['', ''];
        }

        // 2. Sign in through the "login" credentials provider (sets the session cookie)
        [$code, , $loc] = courierHttp("$base/api/auth/callback/login", 'POST',
            array_merge($headers, ['Content-Type: application/x-www-form-urlencoded']),
            http_build_query([
                'phone'       => $login,
                'password'    => $c['password'],
                'csrfToken'   => $csrf,
                'callbackUrl' => "$base/login",
                'json'        => 'true',
            ]), $jar);

        // 3. Session -> access token + selected business id
        [$scode, $res] = courierHttp("$base/api/auth/session", 'GET', $headers, null, $jar);
        $s   = json_decode($res, true) ?: [];
        $tok = $s['accessToken'] ?? $s['user']['accessToken'] ?? '';
        $biz = $s['user']['selectedBusinessId'] ?? $s['selectedBusinessId'] ?? $s['user']['businessId'] ?? '';

        if (!is_string($tok) || $tok === '' || !is_scalar($biz) || (string) $biz === '') {
            // Diagnostics only: redirect path/query and session key NAMES (never values).
            $where = $loc !== '' ? (parse_url($loc, PHP_URL_PATH) ?: '') . (($q = parse_url($loc, PHP_URL_QUERY)) ? "?$q" : '') : 'none';
            $keys  = array_keys($s);
            if (isset($s['user']) && is_array($s['user'])) {
                $keys = array_merge($keys, array_map(static fn($k) => "user.$k", array_keys($s['user'])));
            }
            $hint = match (true) {
                str_contains($where, 'CredentialsSignin') => ' Wrong phone or password.',
                str_contains($where, 'error=')            => ' Carrybee rejected the sign-in request.',
                $s === []                                  => ' Sign-in did not create a session.',
                default                                    => ' Session has no access token or business id.',
            };
            $notes['carrybee'] = "Carrybee login failed (sign-in HTTP $code → $where; session HTTP $scode, keys: "
                . ($keys ? implode(', ', $keys) : 'none') . ").$hint";
            return ['', ''];
        }

        cachePut($key, json_encode(['t' => $tok, 'b' => (string) $biz]), 55 * 60);
        return [$tok, (string) $biz];
    } finally {
        @unlink($jar);
    }
}
