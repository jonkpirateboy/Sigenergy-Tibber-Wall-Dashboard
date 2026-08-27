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

$confirmed = in_array('--yes', $argv, true);
$region = strtolower((string)envValue('SIGEN_REGION', 'eu'));
$systemId = (string)envValue('SIGEN_SYSTEM_ID', '');

if (!isset(SIGEN_REGIONS[$region])) {
    fail('Unsupported SIGEN_REGION: ' . $region);
}

if ($systemId === '' || !hasAuthCredentials()) {
    fail('Missing SIGEN_SYSTEM_ID or credentials for SIGEN_AUTH_TYPE=' . authType() . ' in const.env.');
}

printLine('Sigenergy onboarding');
printLine('Region: ' . $region);
printLine('System ID: ' . $systemId);
printLine('Auth type: ' . authType());

if (!$confirmed) {
    printLine('');
    printLine('This will authorize/onboard the system above for the configured application.');
    printLine('Run with --yes to execute the API call:');
    printLine('php tools/sigen-onboard.php --yes');
    exit(0);
}

try {
    $baseUrl = SIGEN_REGIONS[$region];
    $token = getAccessToken($baseUrl);
    $response = apiRequest(
        'POST',
        $baseUrl . '/openapi/board/onboard',
        [$systemId],
        $token,
        $region
    );

    $code = (int)($response['code'] ?? 0);
    printLine('');
    printLine('Onboarding response:');
    printLine(json_encode(maskResponse($response), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    if ($code === 0 || $code === 1401) {
        printLine('');
        printLine('Done. Refresh the Sigenergy developer portal authorization page and then try the dashboard API again.');
        exit(0);
    }

    fail('Unexpected onboarding response code: ' . $code);
} catch (Throwable $error) {
    fail($error->getMessage());
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
    $path = envValue('SIGEN_PASSWORD_LOGIN_PATH', '/openapi/auth/login') ?: '/openapi/auth/login';
    $path = str_starts_with($path, '/') ? $path : '/' . $path;

    $response = apiRequest('POST', $baseUrl . $path, [
        'username' => envValue('SIGEN_USERNAME'),
        'password' => envValue('SIGEN_PASSWORD'),
    ]);

    return parseAccessToken($response);
}

function parseAccessToken(array $response): string
{
    if (($response['code'] ?? 0) !== 0 && isset($response['code'])) {
        $message = $response['msg'] ?? $response['message'] ?? 'Unknown login error';
        throw new RuntimeException('Login failed: ' . (string)$message);
    }

    $data = $response['data'] ?? null;
    if (is_string($data)) {
        $decoded = json_decode($data, true);
        $data = is_array($decoded) ? $decoded : null;
    }

    $token = $data['accessToken'] ?? $data['access_token'] ?? $data['token'] ?? $response['accessToken'] ?? $response['access_token'] ?? null;
    if (!is_string($token) || $token === '') {
        throw new RuntimeException('Login response did not include an access token.');
    }

    return $token;
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

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        $preview = trim(strip_tags($raw));
        if ($preview !== '') {
            $preview = preg_replace('/\s+/', ' ', $preview) ?: $preview;
            $preview = substr($preview, 0, 500);
        }

        throw new RuntimeException(
            'Invalid JSON response from Sigenergy. HTTP ' . $status .
            ($preview !== '' ? '. Response preview: ' . $preview : '. Empty response body.')
        );
    }

    if ($status >= 400) {
        $message = $json['msg'] ?? $json['message'] ?? ('HTTP ' . $status);
        throw new RuntimeException((string)$message);
    }

    return $json;
}

function maskResponse(array $response): array
{
    array_walk_recursive($response, function (&$value, string $key): void {
        if (!is_string($value)) {
            return;
        }

        if (preg_match('/token|secret|key/i', $key)) {
            $value = '[masked]';
        }
    });

    return $response;
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
