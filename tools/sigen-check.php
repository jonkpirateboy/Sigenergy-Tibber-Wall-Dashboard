<?php
declare(strict_types=1);

const SIGEN_REGIONS = [
    'eu' => 'https://api-eu.sigencloud.com',
    'us' => 'https://api-us.sigencloud.com',
    'apac' => 'https://api-apac.sigencloud.com',
    'aus' => 'https://api-aus.sigencloud.com',
    'cn' => 'https://api-cn.sigencloud.com',
];

loadEnvFile(dirname(__DIR__) . '/const.env');

$debugLogin = in_array('--debug-login', $argv, true);
$probePasswordLogin = in_array('--probe-password-login', $argv, true);
$region = strtolower((string)envValue('SIGEN_REGION', 'eu'));
$systemId = (string)envValue('SIGEN_SYSTEM_ID', '');

if (!isset(SIGEN_REGIONS[$region])) {
    fail('Unsupported SIGEN_REGION: ' . $region);
}

if ($systemId === '' || !hasAuthCredentials()) {
    fail('Missing SIGEN_SYSTEM_ID or credentials for SIGEN_AUTH_TYPE=' . authType() . ' in const.env.');
}

try {
    $baseUrl = SIGEN_REGIONS[$region];
    if ($probePasswordLogin) {
        probePasswordLogin($baseUrl);
        exit(0);
    }

    $token = getAccessToken($baseUrl);
    $includeOnboard = in_array('--include-onboard', $argv, true);

    printLine('Sigenergy API check');
    printLine('Region: ' . $region);
    printLine('System ID: ' . $systemId);
    printLine('Auth type: ' . authType());
    printLine('Login: ok');
    printLine('');

    checkEndpoint('Energy flow', 'GET', $baseUrl . '/openapi/systems/' . rawurlencode($systemId) . '/energyFlow', null, $token, $region);
    checkEndpoint('Realtime data', 'POST', $baseUrl . '/openapi/system/realtime/data', ['systemId' => $systemId], $token, $region);

    if ($includeOnboard) {
        checkEndpoint('Onboard request', 'POST', $baseUrl . '/openapi/board/onboard', [$systemId], $token, $region);
    }
} catch (Throwable $error) {
    fail($error->getMessage());
}

function checkEndpoint(string $label, string $method, string $url, ?array $body, string $token, string $region): void
{
    $response = apiRequest($method, $url, $body, $token, $region);
    $json = json_decode($response['raw'], true);

    printLine($label . ':');
    printLine('  HTTP: ' . $response['status']);
    if (is_array($json)) {
        printLine('  code: ' . json_encode($json['code'] ?? null));
        printLine('  msg: ' . json_encode($json['msg'] ?? $json['message'] ?? null, JSON_UNESCAPED_UNICODE));
        printLine('  data: ' . summarizeData($json['data'] ?? null));
    } else {
        printLine('  raw: ' . substr(trim($response['raw']), 0, 300));
    }
    printLine('');
}

function summarizeData(mixed $data): string
{
    if ($data === null || $data === []) {
        return json_encode($data);
    }

    if (is_string($data)) {
        $decoded = json_decode($data, true);
        $data = is_array($decoded) ? $decoded : $data;
    }

    if (is_array($data)) {
        $keys = array_slice(array_keys($data), 0, 10);
        return 'array keys: ' . implode(', ', array_map('strval', $keys));
    }

    return get_debug_type($data);
}

function loadEnvFile(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        if ($key === '') {
            continue;
        }

        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function envValue(string $key, ?string $fallback = null): ?string
{
    $value = getenv($key);
    if ($value === false || trim($value) === '') {
        return $fallback;
    }

    return trim($value);
}

function authType(): string
{
    $type = strtolower((string)envValue('SIGEN_AUTH_TYPE', 'key'));

    return in_array($type, ['key', 'password'], true) ? $type : 'key';
}

function hasAuthCredentials(): bool
{
    if (authType() === 'password') {
        return (bool)envValue('SIGEN_USERNAME') && (bool)envValue('SIGEN_PASSWORD');
    }

    return (bool)envValue('SIGEN_APP_KEY') && (bool)envValue('SIGEN_APP_SECRET');
}

function getAccessToken(string $baseUrl): string
{
    if (authType() === 'password') {
        return getPasswordAccessToken($baseUrl);
    }

    return getKeyAccessToken($baseUrl);
}

function getKeyAccessToken(string $baseUrl): string
{
    $appKey = (string)envValue('SIGEN_APP_KEY');
    $appSecret = (string)envValue('SIGEN_APP_SECRET');
    $response = apiRequest('POST', $baseUrl . '/openapi/auth/login/key', [
        'key' => base64_encode($appKey . ':' . $appSecret),
    ]);

    return parseAccessToken($response);
}

function getPasswordAccessToken(string $baseUrl): string
{
    global $debugLogin;

    $path = envValue('SIGEN_PASSWORD_LOGIN_PATH', '/openapi/auth/login') ?: '/openapi/auth/login';
    $path = str_starts_with($path, '/') ? $path : '/' . $path;

    $response = apiRequest('POST', $baseUrl . $path, [
        'username' => envValue('SIGEN_USERNAME'),
        'password' => envValue('SIGEN_PASSWORD'),
    ]);

    if ($debugLogin) {
        printLine('Password login debug:');
        printLine('  path: ' . $path);
        printLine('  HTTP: ' . $response['status']);
        printLine('  response: ' . json_encode(maskSensitive(json_decode($response['raw'], true)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        printLine('');
    }

    return parseAccessToken($response);
}

function probePasswordLogin(string $baseUrl): void
{
    $paths = array_values(array_unique([
        envValue('SIGEN_PASSWORD_LOGIN_PATH', '/openapi/auth/login') ?: '/openapi/auth/login',
        '/openapi/auth/login/password',
        '/openapi/auth/login/account',
        '/openapi/auth/login/user',
        '/openapi/auth/loginByPassword',
    ]));

    $username = envValue('SIGEN_USERNAME');
    $password = envValue('SIGEN_PASSWORD');
    if (!$username || !$password) {
        fail('Missing SIGEN_USERNAME or SIGEN_PASSWORD in const.env.');
    }

    $payloads = [
        'username/password' => ['username' => $username, 'password' => $password],
        'account/password' => ['account' => $username, 'password' => $password],
        'userName/password' => ['userName' => $username, 'password' => $password],
        'email/password' => ['email' => $username, 'password' => $password],
    ];

    printLine('Password login probe');
    printLine('Base URL: ' . $baseUrl);
    printLine('');

    foreach ($paths as $path) {
        $path = str_starts_with($path, '/') ? $path : '/' . $path;
        foreach ($payloads as $label => $payload) {
            $response = apiRequest('POST', $baseUrl . $path, $payload);
            $json = json_decode($response['raw'], true);
            $token = is_array($json) ? findAccessToken($json) : null;

            printLine($path . ' [' . $label . ']');
            printLine('  HTTP: ' . $response['status']);
            if (is_array($json)) {
                printLine('  code: ' . json_encode($json['code'] ?? null));
                printLine('  msg: ' . json_encode($json['msg'] ?? $json['message'] ?? null, JSON_UNESCAPED_UNICODE));
                printLine('  token: ' . ($token ? 'yes' : 'no'));
            } else {
                printLine('  raw: ' . substr(trim($response['raw']), 0, 300));
            }
            printLine('');
        }
    }
}

function parseAccessToken(array $response): string
{
    $json = json_decode($response['raw'], true);
    if (!is_array($json)) {
        throw new RuntimeException('Login returned invalid JSON. HTTP ' . $response['status']);
    }

    if ($response['status'] >= 400 || (($json['code'] ?? 0) !== 0 && isset($json['code']))) {
        $message = $json['msg'] ?? $json['message'] ?? ('HTTP ' . $response['status']);
        throw new RuntimeException('Login failed: ' . (string)$message);
    }

    $token = findAccessToken($json);
    if (is_string($token) && $token !== '') {
        return $token;
    }

    throw new RuntimeException('Login response did not include an access token.');
}

function findAccessToken(array $json): ?string
{
    $data = $json['data'] ?? null;
    if (is_string($data)) {
        $decoded = json_decode($data, true);
        $data = is_array($decoded) ? $decoded : null;
    }

    $token = $data['accessToken'] ?? $data['access_token'] ?? $data['token'] ?? $json['accessToken'] ?? $json['access_token'] ?? null;

    return is_string($token) && $token !== '' ? $token : null;
}

function maskSensitive(mixed $value): mixed
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return json_encode(maskSensitive($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $value;
    }

    if (!is_array($value)) {
        return $value;
    }

    foreach ($value as $key => $item) {
        if (is_string($key) && preg_match('/token|secret|password|key/i', $key)) {
            $value[$key] = '[masked]';
            continue;
        }

        $value[$key] = maskSensitive($item);
    }

    return $value;
}

function apiRequest(string $method, string $url, ?array $body = null, ?string $token = null, ?string $region = null): array
{
    $headers = [
        'Accept: application/json',
        'User-Agent: SigenergyDashboard/1.0 (+https://developer.sigencloud.com)',
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($region) {
        $headers[] = 'sigen-region: ' . $region;
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($raw === false || $error !== '') {
        throw new RuntimeException($error ?: 'Network request failed.');
    }

    return [
        'status' => $status,
        'raw' => $raw,
    ];
}

function printLine(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function fail(string $message): never
{
    fwrite(STDERR, 'Error: ' . $message . PHP_EOL);
    exit(1);
}
