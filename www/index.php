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

if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    switch ($_GET['api']) {
        case 'snapshot':
            echo json_encode(getSigenSnapshot(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;

        case 'prices':
            echo json_encode(getElectricityPrices(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
    }

    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => t('api.unknown')]);
    exit;
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

function currentLanguage(): string
{
    $language = strtolower((string)envValue('SIGEN_LANGUAGE', 'en'));
    $language = preg_replace('/[^a-z-]/', '', $language) ?: 'en';

    return is_readable(__DIR__ . '/lang/' . $language . '.json') ? $language : 'en';
}

function translations(): array
{
    static $translations = null;

    if ($translations !== null) {
        return $translations;
    }

    $fallback = json_decode((string)file_get_contents(__DIR__ . '/lang/en.json'), true) ?: [];
    $language = currentLanguage();
    if ($language === 'en') {
        return $translations = $fallback;
    }

    $selected = json_decode((string)file_get_contents(__DIR__ . '/lang/' . $language . '.json'), true) ?: [];

    return $translations = array_replace($fallback, $selected);
}

function t(string $key): string
{
    return translations()[$key] ?? $key;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function getSigenSnapshot(): array
{
    $region = strtolower($_GET['region'] ?? envValue('SIGEN_REGION', 'eu'));
    $systemId = trim((string)($_GET['systemId'] ?? envValue('SIGEN_SYSTEM_ID', '')));

    if (!isset(SIGEN_REGIONS[$region])) {
        return demoSnapshot(t('api.invalidRegion'), $region, $systemId);
    }

    if ($systemId === '' || !hasAuthCredentials()) {
        return demoSnapshot(t('api.missingCredentials'), $region, $systemId);
    }

    try {
        $baseUrl = SIGEN_REGIONS[$region];
        $token = getAccessToken($baseUrl);
        $energyFlow = apiRequest('GET', $baseUrl . '/openapi/systems/' . rawurlencode($systemId) . '/energyFlow', null, $token, $region);
        $realtime = optionalApiRequest('POST', $baseUrl . '/openapi/system/realtime/data', ['systemId' => $systemId], $token, $region);

        $snapshot = normalizeSnapshot($energyFlow, $realtime, $region, $systemId);
        writeSnapshotCache($snapshot);

        return $snapshot;
    } catch (Throwable $error) {
        return unavailableSnapshot(t('api.liveFailed') . ' ' . $error->getMessage(), $region, $systemId);
    }
}

function getElectricityPrices(): array
{
    $apiKey = tibberApiKey();
    $homeIndex = tibberHomeIndex();

    try {
        if (!$apiKey) {
            $prices = getPublicElectricityPrices();
            writeElectricityPriceCache($prices);

            return $prices;
        }

        $response = apiRequest('POST', 'https://api.tibber.com/v1-beta/gql', [
            'query' => '{
                viewer {
                    homes {
                        currentSubscription {
                            priceInfo {
                                current { total energy tax startsAt }
                                today { total energy tax startsAt }
                                tomorrow { total energy tax startsAt }
                            }
                        }
                    }
                }
            }',
        ], $apiKey);

        if (isset($response['errors'])) {
            throw new RuntimeException(t('price.fetchFailed'));
        }

        $homes = $response['data']['viewer']['homes'] ?? [];
        $priceInfo = $homes[$homeIndex]['currentSubscription']['priceInfo'] ?? null;
        if (!is_array($priceInfo)) {
            throw new RuntimeException(t('price.noData'));
        }

        $prices = normalizeElectricityPrices($priceInfo);
        $prices['monthlyCost'] = getTibberMonthlyCost($apiKey, $homeIndex);
        writeElectricityPriceCache($prices);

        return $prices;
    } catch (Throwable $error) {
        return unavailableElectricityPrices(t('price.fetchFailed') . ' ' . $error->getMessage());
    }
}

function getPublicElectricityPrices(): array
{
    $area = strtoupper((string)envValue('ELPRICE_AREA', 'SE3'));
    if (!preg_match('/^SE[1-4]$/', $area)) {
        $area = 'SE3';
    }

    $timezone = new DateTimeZone('Europe/Stockholm');
    $today = new DateTimeImmutable('today', $timezone);
    $tomorrow = $today->modify('+1 day');
    $todayHours = fetchPublicPriceDay($today, $area, true);
    $tomorrowHours = fetchPublicPriceDay($tomorrow, $area, false);
    $current = currentPriceHour($todayHours, new DateTimeImmutable('now', $timezone));

    return [
        'ok' => true,
        'source' => 'live',
        'message' => null,
        'updatedAt' => gmdate(DATE_ATOM),
        'current' => $current,
        'today' => $todayHours,
        'tomorrow' => $tomorrowHours,
        'breakpoints' => priceBreakpoints(),
    ];
}

function fetchPublicPriceDay(DateTimeImmutable $date, string $area, bool $required): array
{
    try {
        $path = sprintf(
            'https://www.elprisetjustnu.se/api/v1/prices/%s/%s_%s.json',
            $date->format('Y'),
            $date->format('m-d'),
            $area
        );
        $response = apiRequest('GET', $path);

        return normalizePublicPriceHours($response);
    } catch (Throwable $error) {
        if ($required) {
            throw $error;
        }

        return [];
    }
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
    $cachedToken = readCachedAccessToken();
    if ($cachedToken !== null) {
        return $cachedToken;
    }

    assertLoginCooldownExpired();

    try {
        if (authType() === 'password') {
            $tokenData = getPasswordAccessToken($baseUrl);
        } else {
            $tokenData = getKeyAccessToken($baseUrl);
        }
    } catch (Throwable $error) {
        if (stripos($error->getMessage(), 'Access restriction') !== false) {
            writeLoginCooldown(15 * 60);
        }

        throw $error;
    }

    writeCachedAccessToken($tokenData['token'], $tokenData['expiresIn']);
    clearLoginCooldown();

    return $tokenData['token'];
}

function getKeyAccessToken(string $baseUrl): array
{
    $appKey = (string)envValue('SIGEN_APP_KEY');
    $appSecret = (string)envValue('SIGEN_APP_SECRET');
    $response = apiRequest('POST', $baseUrl . '/openapi/auth/login/key', [
        'key' => base64_encode($appKey . ':' . $appSecret),
    ]);

    return parseAccessToken($response);
}

function getPasswordAccessToken(string $baseUrl): array
{
    $path = envValue('SIGEN_PASSWORD_LOGIN_PATH', '/openapi/auth/login') ?: '/openapi/auth/login';
    $path = str_starts_with($path, '/') ? $path : '/' . $path;

    $response = apiRequest('POST', $baseUrl . $path, [
        'username' => envValue('SIGEN_USERNAME'),
        'password' => envValue('SIGEN_PASSWORD'),
    ]);

    return parseAccessToken($response);
}

function parseAccessToken(array $response): array
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

    return [
        'token' => $token,
        'expiresIn' => max(300, (int)($data['expiresIn'] ?? $data['expires_in'] ?? 3600)),
    ];
}

function tokenCachePath(): string
{
    $cacheKey = implode('|', [
        authType(),
        envValue('SIGEN_REGION', 'eu'),
        authType() === 'password' ? envValue('SIGEN_USERNAME', '') : envValue('SIGEN_APP_KEY', ''),
        envValue('SIGEN_PASSWORD_LOGIN_PATH', ''),
    ]);

    return dirname(__DIR__) . '/var/sigen-token-' . hash('sha256', $cacheKey) . '.json';
}

function readCachedAccessToken(): ?string
{
    $path = tokenCachePath();
    if (!is_readable($path)) {
        return null;
    }

    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) {
        return null;
    }

    $token = $data['token'] ?? null;
    $expiresAt = (int)($data['expiresAt'] ?? 0);
    if (!is_string($token) || $token === '' || $expiresAt <= time() + 300) {
        return null;
    }

    return $token;
}

function writeCachedAccessToken(string $token, int $expiresIn): void
{
    $path = tokenCachePath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    file_put_contents($path, json_encode([
        'token' => $token,
        'expiresAt' => time() + max(300, $expiresIn) - 300,
    ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    chmod($path, 0640);
}

function loginCooldownPath(): string
{
    return dirname(__DIR__) . '/var/sigen-login-cooldown.json';
}

function assertLoginCooldownExpired(): void
{
    $path = loginCooldownPath();
    if (!is_readable($path)) {
        return;
    }

    $data = json_decode((string)file_get_contents($path), true);
    $retryAt = (int)($data['retryAt'] ?? 0);
    if ($retryAt <= time()) {
        clearLoginCooldown();
        return;
    }

    throw new RuntimeException('Login paused until ' . date('H:i', $retryAt) . ' after Sigenergy Access restriction');
}

function writeLoginCooldown(int $seconds): void
{
    $path = loginCooldownPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    file_put_contents($path, json_encode([
        'retryAt' => time() + $seconds,
    ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    chmod($path, 0640);
}

function clearLoginCooldown(): void
{
    $path = loginCooldownPath();
    if (is_file($path)) {
        unlink($path);
    }
}

function snapSettings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $path = envValue('SNAP_SETTINGS_PATH', dirname(__DIR__) . '/snap-settings.json');
    if (!$path || !is_readable($path)) {
        return $settings = [];
    }

    $decoded = json_decode((string)file_get_contents($path), true);

    return $settings = is_array($decoded) ? $decoded : [];
}

function tibberApiKey(): ?string
{
    $apiKey = envValue('TIBBER_API_KEY', envValue('TIBBER_TOKEN', envValue('TIBBER_ACCESS_TOKEN', envValue('TIBBER_API_TOKEN'))));
    if ($apiKey) {
        return $apiKey;
    }

    $snapApiKey = snapSettings()['tibber_api_key'] ?? null;

    return is_string($snapApiKey) && trim($snapApiKey) !== '' ? trim($snapApiKey) : null;
}

function tibberHomeIndex(): int
{
    $envIndex = envValue('TIBBER_HOME_INDEX');
    if ($envIndex !== null && is_numeric($envIndex)) {
        return max(0, (int)$envIndex);
    }

    $snapIndex = snapSettings()['home_index'] ?? 0;

    return is_numeric($snapIndex) ? max(0, (int)$snapIndex) : 0;
}

function priceBreakpoints(): array
{
    $snapBreakpoints = snapSettings()['price_breakpoints'] ?? [];
    $defaults = [
        'very_expensive' => 3.0,
        'expensive' => 2.0,
        'ok' => 1.0,
        'cheap' => 0.5,
    ];
    $envKeys = [
        'very_expensive' => 'PRICE_BREAKPOINT_VERY_EXPENSIVE',
        'expensive' => 'PRICE_BREAKPOINT_EXPENSIVE',
        'ok' => 'PRICE_BREAKPOINT_OK',
        'cheap' => 'PRICE_BREAKPOINT_CHEAP',
    ];

    foreach ($defaults as $key => $default) {
        $snapValue = is_array($snapBreakpoints) ? ($snapBreakpoints[$key] ?? null) : null;
        $envValue = envValue($envKeys[$key]);
        $value = is_numeric($envValue) ? (float)$envValue : (is_numeric($snapValue) ? (float)$snapValue : $default);
        $defaults[$key] = round($value, 4);
    }

    return $defaults;
}

function electricityPriceCachePath(): string
{
    return dirname(__DIR__) . '/var/electricity-prices.json';
}

function readElectricityPriceCache(): ?array
{
    $path = electricityPriceCachePath();
    if (!is_readable($path)) {
        return null;
    }

    $prices = json_decode((string)file_get_contents($path), true);
    if (!is_array($prices) || empty($prices['today'])) {
        return null;
    }

    return $prices;
}

function writeElectricityPriceCache(array $prices): void
{
    $path = electricityPriceCachePath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    file_put_contents($path, json_encode($prices, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    chmod($path, 0640);
}

function normalizeElectricityPrices(array $priceInfo): array
{
    $current = is_array($priceInfo['current'] ?? null) ? normalizePriceHour($priceInfo['current']) : null;
    $today = array_values(array_filter(array_map('normalizePriceHour', is_array($priceInfo['today'] ?? null) ? $priceInfo['today'] : [])));
    $tomorrow = array_values(array_filter(array_map('normalizePriceHour', is_array($priceInfo['tomorrow'] ?? null) ? $priceInfo['tomorrow'] : [])));

    return [
        'ok' => true,
        'source' => 'live',
        'message' => null,
        'updatedAt' => gmdate(DATE_ATOM),
        'current' => $current,
        'today' => $today,
        'tomorrow' => $tomorrow,
        'breakpoints' => priceBreakpoints(),
        'monthlyCost' => null,
    ];
}

function getTibberMonthlyCost(string $apiKey, int $homeIndex): ?array
{
    try {
        $timezone = new DateTimeZone('Europe/Stockholm');
        $month = new DateTimeImmutable('first day of this month 00:00:00', $timezone);
        $monthlyCost = fetchTibberMonthCost($apiKey, $homeIndex, $month, $timezone);

        return $monthlyCost ?? fetchTibberMonthCost($apiKey, $homeIndex, $month->modify('-1 month'), $timezone);
    } catch (Throwable) {
        return null;
    }
}

function fetchTibberMonthCost(string $apiKey, int $homeIndex, DateTimeImmutable $month, DateTimeZone $timezone): ?array
{
    $monthStart = $month->format(DATE_ATOM);
    $response = apiRequest('POST', 'https://api.tibber.com/v1-beta/gql', [
        'query' => 'query($after: String!) {
            viewer {
                homes {
                    consumption(resolution: DAILY, first: 31, after: $after) {
                        nodes {
                            from
                            to
                            consumption
                            consumptionUnit
                            cost
                            currency
                        }
                    }
                    production(resolution: DAILY, first: 31, after: $after) {
                        nodes {
                            from
                            to
                            production
                            productionUnit
                            profit
                            currency
                        }
                    }
                }
            }
        }',
        'variables' => [
            'after' => base64_encode($monthStart),
        ],
    ], $apiKey);

    if (isset($response['errors'])) {
        return null;
    }

    $home = $response['data']['viewer']['homes'][$homeIndex] ?? null;
    if (!is_array($home)) {
        return null;
    }

    return normalizeTibberMonthlyCost($home, $timezone, $month);
}

function normalizeTibberMonthlyCost(array $home, DateTimeZone $timezone, ?DateTimeImmutable $month = null): ?array
{
    $now = ($month ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
    $consumptionNodes = currentMonthNodes($home['consumption']['nodes'] ?? [], $now, $timezone);
    $productionNodes = currentMonthNodes($home['production']['nodes'] ?? [], $now, $timezone);

    if ($consumptionNodes === [] && $productionNodes === []) {
        return null;
    }

    $consumptionCost = sumMoneyNumbers($consumptionNodes, 'cost', null);
    $productionProfit = sumMoneyNumbers($productionNodes, 'profit', null);
    // Empty placeholder rows are not data; a reported zero is.
    if ($consumptionCost === null && $productionProfit === null) {
        return null;
    }
    $productionProfit ??= 0.0;
    // Daily rows identify the covered day by 'from'; 'to' is the next day's boundary.
    $throughDate = null;
    foreach (['cost' => $consumptionNodes, 'profit' => $productionNodes] as $key => $nodes) {
        foreach ($nodes as $node) {
            if (is_numeric($node[$key] ?? null)) {
                $day = (new DateTimeImmutable((string)$node['from']))->setTimezone($timezone)->format('Y-m-d');
                $throughDate = $throughDate === null ? $day : max($throughDate, $day);
            }
        }
    }
    return normalizeMonthlyCostTotals([
        'month' => $now->format('Y-m'),
        'throughDate' => $throughDate,
        'from' => monthNodeBoundary($consumptionNodes, $productionNodes, 'from'),
        'to' => monthNodeBoundary($consumptionNodes, $productionNodes, 'to'),
        'currency' => monthNodeCurrency($consumptionNodes, $productionNodes),
        'consumptionCost' => $consumptionCost,
        'consumptionKwh' => sumMoneyNumbers($consumptionNodes, 'consumption', null),
        'productionProfit' => $productionProfit,
        'productionKwh' => sumMoneyNumbers($productionNodes, 'production', 0.0),
        'monthCost' => null,
        'dataSource' => 'tibber-api',
    ]);
}

function currentMonthNodes(mixed $nodes, DateTimeImmutable $now, DateTimeZone $timezone): array
{
    if (!is_array($nodes)) {
        return [];
    }

    return array_values(array_filter($nodes, static function (mixed $node) use ($now, $timezone): bool {
        if (!is_array($node) || !isset($node['from'])) {
            return false;
        }

        try {
            $from = (new DateTimeImmutable((string)$node['from']))->setTimezone($timezone);
        } catch (Throwable) {
            return false;
        }

        return $from->format('Y-m') === $now->format('Y-m');
    }));
}

function sumMoneyNumbers(array $nodes, string $key, ?float $emptyValue): ?float
{
    $values = array_values(array_filter(array_map(static function (array $node) use ($key): ?float {
        return is_numeric($node[$key] ?? null) ? (float)$node[$key] : null;
    }, $nodes), static fn (?float $value): bool => $value !== null));

    if ($values === []) {
        return $emptyValue;
    }

    return round(array_sum($values), 2);
}

function normalizeMonthlyCostTotals(?array $monthlyCost): ?array
{
    if ($monthlyCost === null) {
        return null;
    }

    $monthlyCost['monthCost'] = is_numeric($monthlyCost['consumptionCost'] ?? null)
        && is_numeric($monthlyCost['productionProfit'] ?? null)
        ? round((float)$monthlyCost['consumptionCost'] - (float)$monthlyCost['productionProfit'], 2)
        : null;
    unset($monthlyCost['gridRewards']);

    return $monthlyCost;
}

function monthNodeBoundary(array $consumptionNodes, array $productionNodes, string $key): ?string
{
    $nodes = array_merge($consumptionNodes, $productionNodes);
    if ($nodes === []) {
        return null;
    }

    $values = array_values(array_filter(array_map(
        static fn (array $node): ?string => is_string($node[$key] ?? null) ? $node[$key] : null,
        $nodes
    )));

    if ($values === []) {
        return null;
    }

    return $key === 'from' ? min($values) : max($values);
}

function monthNodeCurrency(array $consumptionNodes, array $productionNodes): string
{
    foreach (array_merge($consumptionNodes, $productionNodes) as $node) {
        if (is_string($node['currency'] ?? null) && trim($node['currency']) !== '') {
            return trim($node['currency']);
        }
    }

    return 'SEK';
}

function normalizePublicPriceHours(array $hours): array
{
    return array_values(array_filter(array_map(static function (mixed $hour): ?array {
        if (!is_array($hour) || !isset($hour['time_start']) || !is_numeric($hour['SEK_per_kWh'] ?? null)) {
            return null;
        }

        return [
            'startsAt' => (string)$hour['time_start'],
            'endsAt' => isset($hour['time_end']) ? (string)$hour['time_end'] : null,
            'total' => round((float)$hour['SEK_per_kWh'], 4),
            'energy' => null,
            'tax' => null,
        ];
    }, $hours)));
}

function normalizePriceHour(mixed $hour): ?array
{
    if (!is_array($hour) || !isset($hour['startsAt'])) {
        return null;
    }

    $total = $hour['total'] ?? null;
    if (!is_numeric($total)) {
        return null;
    }

    return [
        'startsAt' => (string)$hour['startsAt'],
        'total' => round((float)$total, 4),
        'energy' => is_numeric($hour['energy'] ?? null) ? round((float)$hour['energy'], 4) : null,
        'tax' => is_numeric($hour['tax'] ?? null) ? round((float)$hour['tax'], 4) : null,
    ];
}

function currentPriceHour(array $hours, DateTimeImmutable $now): ?array
{
    foreach ($hours as $hour) {
        if (!isset($hour['startsAt'])) {
            continue;
        }

        try {
            $startsAt = new DateTimeImmutable((string)$hour['startsAt']);
        } catch (Throwable) {
            continue;
        }

        try {
            $endsAt = isset($hour['endsAt']) && is_string($hour['endsAt'])
                ? new DateTimeImmutable($hour['endsAt'])
                : $startsAt->modify('+1 hour');
        } catch (Throwable) {
            $endsAt = $startsAt->modify('+1 hour');
        }

        if ($now >= $startsAt && $now < $endsAt) {
            return $hour;
        }
    }

    return null;
}

function unavailableElectricityPrices(string $message): array
{
    $cached = readElectricityPriceCache();
    if ($cached !== null) {
        $cached['ok'] = false;
        $cached['source'] = 'offline';
        $cached['message'] = $message . ' ' . t('price.cached');
        if (($cached['monthlyCost']['dataSource'] ?? null) !== 'tibber-api') {
            $cached['monthlyCost'] = null;
        } else {
            $cached['monthlyCost'] = normalizeMonthlyCostTotals($cached['monthlyCost']);
        }

        return $cached;
    }

    return [
        'ok' => false,
        'source' => 'offline',
        'message' => $message,
        'updatedAt' => gmdate(DATE_ATOM),
        'current' => null,
        'today' => [],
        'tomorrow' => [],
        'breakpoints' => priceBreakpoints(),
        'monthlyCost' => null,
    ];
}

function snapshotCachePath(): string
{
    return dirname(__DIR__) . '/var/sigen-last-snapshot.json';
}

function readSnapshotCache(): ?array
{
    $path = snapshotCachePath();
    if (!is_readable($path)) {
        return null;
    }

    $snapshot = json_decode((string)file_get_contents($path), true);
    if (!is_array($snapshot) || ($snapshot['source'] ?? null) !== 'live') {
        return null;
    }

    return $snapshot;
}

function writeSnapshotCache(array $snapshot): void
{
    $path = snapshotCachePath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    file_put_contents($path, json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    chmod($path, 0640);
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
        CURLOPT_TIMEOUT => 15,
    ]);

    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($raw === false || $error !== '') {
        throw new RuntimeException($error ?: t('api.networkError'));
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException(t('api.invalidJson') . ' (HTTP ' . $status . ')');
    }

    if ($status >= 400 || (($json['code'] ?? 0) !== 0 && isset($json['code']))) {
        $message = $json['msg'] ?? $json['message'] ?? ('HTTP ' . $status);
        throw new RuntimeException((string)$message);
    }

    return $json;
}

function optionalApiRequest(string $method, string $url, ?array $body = null, ?string $token = null, ?string $region = null): array
{
    try {
        return apiRequest($method, $url, $body, $token, $region);
    } catch (Throwable) {
        return ['code' => 0, 'data' => []];
    }
}

function normalizeSnapshot(array $energyFlowResponse, array $realtimeResponse, string $region, string $systemId): array
{
    $flow = unwrapData($energyFlowResponse);
    $totals = unwrapData($realtimeResponse);

    $pv = numberFrom($flow, ['pvPower', 'ppvInput', 'pv']);
    $grid = numberFrom($flow, ['gridPower', 'pMeterTotal', 'grid']);
    $battery = numberFrom($flow, ['batteryPower', 'batteryP']);
    $soc = numberFrom($flow, ['batterySoc', 'soc']);
    $load = numberFrom($flow, ['loadPower', 'load']);
    if ($load === null && $pv !== null && $battery !== null && $grid !== null) {
        $load = max(0, $pv + max(0, -$grid) + max(0, -$battery));
    }

    return [
        'ok' => true,
        'source' => 'live',
        'message' => null,
        'region' => $region,
        'systemId' => $systemId,
        'updatedAt' => gmdate(DATE_ATOM),
        'batteryCapacityKwh' => batteryCapacityKwh(),
        'batteryReservePercent' => batteryReservePercent(),
        'flow' => [
            'pvPower' => $pv ?? 0,
            'gridPower' => $grid ?? 0,
            'batteryPower' => $battery ?? 0,
            'batterySoc' => $soc ?? 0,
            'loadPower' => $load ?? 0,
        ],
        'energy' => [
            'pvToday' => numberFrom($totals, ['pvToday', 'epvDay', 'pvPowerGenerationDaily', 'todayPvEnergy']) ?? 0,
            'pvMonth' => numberFrom($totals, ['pvMonth', 'epvMonth', 'monthPvEnergy']) ?? 0,
            'pvYear' => numberFrom($totals, ['pvYear', 'epvYear', 'yearPvEnergy']) ?? 0,
            'pvTotal' => numberFrom($totals, ['pvTotal', 'epvTotal', 'totalPvEnergy']) ?? 0,
            'importToday' => numberFrom($totals, ['ebuyDay', 'importToday', 'buyToday']) ?? 0,
            'exportToday' => numberFrom($totals, ['esellDay', 'exportToday', 'sellToday']) ?? 0,
            'batteryChargeToday' => numberFrom($totals, ['eBatteryChargeDaily', 'batteryChargeToday']) ?? 0,
            'batteryDischargeToday' => numberFrom($totals, ['eBatteryDischargeDaily', 'batteryDischargeToday']) ?? 0,
        ],
    ];
}

function unwrapData(array $response): array
{
    $data = $response['data'] ?? $response;
    if (is_string($data)) {
        $decoded = json_decode($data, true);
        $data = is_array($decoded) ? $decoded : [];
    }

    return is_array($data) ? $data : [];
}

function numberFrom(array $data, array $keys): ?float
{
    foreach ($keys as $key) {
        if (isset($data[$key]) && is_numeric($data[$key])) {
            return round((float)$data[$key], 2);
        }
    }

    foreach ($data as $value) {
        if (is_array($value)) {
            $found = numberFrom($value, $keys);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

function batteryCapacityKwh(): float
{
    $capacity = envValue('SIGEN_BATTERY_CAPACITY_KWH', '8');

    if (!is_numeric($capacity) || (float)$capacity <= 0) {
        return 8.0;
    }

    return round((float)$capacity, 2);
}

function batteryReservePercent(): float
{
    $reserve = envValue('SIGEN_BATTERY_RESERVE_PERCENT', '10');

    if (!is_numeric($reserve)) {
        return 10.0;
    }

    return round(min(100, max(0, (float)$reserve)), 2);
}

function demoSnapshot(string $message, string $region, string $systemId): array
{
    $hour = (int)date('G');
    $sun = max(0, sin(($hour - 6) / 12 * pi()));
    $pv = round(7.4 * $sun, 2);
    $load = round(1.2 + ($hour >= 17 && $hour <= 21 ? 1.7 : 0.4), 2);
    $battery = round($pv > $load ? min(3.2, $pv - $load) : -min(2.4, $load - $pv), 2);
    $grid = round($load + $battery - $pv, 2);

    return [
        'ok' => false,
        'source' => 'demo',
        'message' => $message,
        'region' => $region,
        'systemId' => $systemId,
        'updatedAt' => gmdate(DATE_ATOM),
        'batteryCapacityKwh' => batteryCapacityKwh(),
        'batteryReservePercent' => batteryReservePercent(),
        'flow' => [
            'pvPower' => $pv,
            'gridPower' => $grid,
            'batteryPower' => $battery,
            'batterySoc' => 72,
            'loadPower' => $load,
        ],
        'energy' => [
            'pvToday' => round(18 + $pv * 1.6, 1),
            'pvMonth' => 524.8,
            'pvYear' => 4386.2,
            'pvTotal' => 12844.9,
            'importToday' => 4.7,
            'exportToday' => 10.3,
            'batteryChargeToday' => 8.1,
            'batteryDischargeToday' => 6.4,
        ],
    ];
}

function unavailableSnapshot(string $message, string $region, string $systemId): array
{
    $cached = readSnapshotCache();
    if ($cached !== null) {
        $cached['ok'] = false;
        $cached['source'] = 'offline';
        $cached['message'] = $message . ' Senaste live-värde visas.';

        return $cached;
    }

    return [
        'ok' => false,
        'source' => 'offline',
        'message' => $message,
        'region' => $region,
        'systemId' => $systemId,
        'updatedAt' => gmdate(DATE_ATOM),
        'batteryCapacityKwh' => batteryCapacityKwh(),
        'batteryReservePercent' => batteryReservePercent(),
        'flow' => [
            'pvPower' => 0,
            'gridPower' => 0,
            'batteryPower' => 0,
            'batterySoc' => 0,
            'loadPower' => 0,
        ],
        'energy' => [
            'pvToday' => 0,
            'pvMonth' => 0,
            'pvYear' => 0,
            'pvTotal' => 0,
            'importToday' => 0,
            'exportToday' => 0,
            'batteryChargeToday' => 0,
            'batteryDischargeToday' => 0,
        ],
    ];
}
?>
<!doctype html>
<html lang="<?= e(currentLanguage()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(t('page.title')) ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <main class="wall-screen">
        <section class="flow-board" aria-label="<?= e(t('aria.energyFlow')) ?>">
            <div class="lane">
                <div class="lane-head">
                    <span><?= e(t('incoming.title')) ?></span>
                    <strong id="incomingTotal">0,0 kW</strong>
                </div>
                <div class="stack-bar" id="incomingBar">
                    <div class="segment solar" id="incomingSolar">
                        <span><?= e(t('incoming.solar')) ?></span>
                        <strong>0,0 kW</strong>
                    </div>
                    <div class="segment grid-in" id="incomingGrid">
                        <span><?= e(t('incoming.grid')) ?></span>
                        <strong>0,0 kW</strong>
                    </div>
                    <div class="segment idle" id="incomingIdle">
                        <span><?= e(t('incoming.none')) ?></span>
                        <strong>0,0 kW</strong>
                    </div>
                </div>
            </div>

            <div class="lane">
                <div class="lane-head">
                    <span><?= e(t('usage.title')) ?></span>
                    <strong id="outgoingTotal">0,0 kW</strong>
                </div>
                <div class="stack-bar" id="outgoingBar">
                    <div class="segment solar" id="outgoingSolar">
                        <span><?= e(t('usage.solar')) ?></span>
                        <strong>0,0 kW</strong>
                    </div>
                    <div class="segment battery-use" id="outgoingBattery">
                        <span><?= e(t('usage.battery')) ?></span>
                        <strong>0,0 kW</strong>
                    </div>
                    <div class="segment grid-use" id="outgoingGrid">
                        <span><?= e(t('usage.grid')) ?></span>
                        <strong>0,0 kW</strong>
                    </div>
                    <div class="segment grid-export" id="outgoingExport">
                        <i class="export-bubble-layer one" aria-hidden="true"></i>
                        <i class="export-bubble-layer two" aria-hidden="true"></i>
                        <span><?= e(t('usage.export')) ?></span>
                        <strong>0,0 kW</strong>
                    </div>
                    <div class="segment idle" id="outgoingIdle">
                        <span><?= e(t('usage.none')) ?></span>
                        <strong>0,0 kW</strong>
                    </div>
                </div>
            </div>
        </section>

        <section class="battery-board" aria-label="<?= e(t('aria.batteryStatus')) ?>">
            <div class="lane">
                <div class="lane-head">
                    <span><?= e(t('battery.title')) ?></span>
                    <strong id="batteryPercent">0%</strong>
                </div>
                <div class="stack-bar battery-status-bar">
                    <div class="battery-level" id="batteryLevel"></div>
                    <div class="battery-forecast-layer">
                        <div class="battery-forecast">
                            <span><?= e(t('battery.only')) ?></span>
                            <strong id="batteryOnlyTime">--</strong>
                        </div>
                        <div class="battery-forecast">
                            <span><?= e(t('battery.solarBattery')) ?></span>
                            <strong id="solarBatteryTime">--</strong>
                        </div>
                        <div class="battery-forecast">
                            <span><?= e(t('battery.fullAt')) ?></span>
                            <strong id="batteryFullTime">--</strong>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="price-board" aria-label="<?= e(t('aria.electricityPrice')) ?>">
            <div class="lane">
                <div class="lane-head">
                    <span><?= e(t('price.title')) ?></span>
                    <strong id="current-energy">--</strong>
                </div>
                <div class="price-charts">
                    <div class="price-chart" id="priceToday">
                        <div class="price-chart-head">
                            <span><?= e(t('price.today')) ?></span>
                            <span><?= e(t('price.average')) ?>: <strong id="priceTodayAverage">--</strong></span>
                        </div>
                        <div class="price-bars" id="priceTodayBars"></div>
                    </div>
                    <div class="price-chart" id="priceTomorrow">
                        <div class="price-chart-head">
                            <span><?= e(t('price.tomorrow')) ?></span>
                            <span><?= e(t('price.average')) ?>: <strong id="priceTomorrowAverage">--</strong></span>
                        </div>
                        <div class="price-bars" id="priceTomorrowBars"></div>
                    </div>
                </div>
            </div>
        </section>

        <footer class="meta" aria-label="<?= e(t('aria.updateStatus')) ?>">
            <span class="monthly-cost" id="monthlyCost"><?= e(t('monthlyCost.loading')) ?></span>
            <div>
                <span class="source-status" id="source"><?= e(t('status.starting')) ?></span>
                <time id="updated"><?= e(t('status.waiting')) ?></time>
            </div>
        </footer>
    </main>

    <script>
        window.dashboardTranslations = <?= json_encode(translations(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="js/dashboard.js?v=<?= filemtime(__DIR__ . '/js/dashboard.js') ?>" defer></script>
</body>
</html>
