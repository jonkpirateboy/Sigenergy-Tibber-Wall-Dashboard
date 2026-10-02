<?php
declare(strict_types=1);

ob_start();
require dirname(__DIR__) . '/www/index.php';
ob_end_clean();

$cases = [
    'battery exports' => [['pvPower' => 0, 'gridPower' => 12.71, 'batteryPower' => -13.2], 0.49],
    'solar and battery export' => [['pvPower' => 3, 'gridPower' => 4, 'batteryPower' => -2], 1.0],
    'solar charges and exports' => [['pvPower' => 7, 'gridPower' => 4, 'batteryPower' => 2], 1.0],
    'grid charges battery' => [['pvPower' => 1, 'gridPower' => -3, 'batteryPower' => 2], 2.0],
    'measured load takes precedence' => [['pvPower' => 3, 'gridPower' => 4, 'batteryPower' => -2, 'loadPower' => 0.8], 0.8],
    'measured zero takes precedence' => [['pvPower' => 3, 'gridPower' => 4, 'batteryPower' => -2, 'loadPower' => 0], 0.0],
    'negative inferred load is clamped' => [['pvPower' => 0, 'gridPower' => 1, 'batteryPower' => 0], 0.0],
];
foreach ($cases as $label => [$flow, $expected]) {
    $snapshot = normalizeSnapshot(['data' => $flow], [], 'eu', 'test');
    if (abs($snapshot['flow']['loadPower'] - $expected) > 0.000001) {
        throw new RuntimeException("Incorrect household load: $label");
    }
}

// Exercise demo flows at every local hour without relying on the test's run time.
$originalTimezone = date_default_timezone_get();
try {
    for ($offset = -12; $offset <= 12; $offset++) {
        if (!date_default_timezone_set(sprintf('Etc/GMT%+d', $offset))) {
            throw new RuntimeException('Could not set test timezone');
        }
        $flow = demoSnapshot('', 'eu', 'test')['flow'];
        $balance = $flow['pvPower'] - $flow['loadPower'] - $flow['batteryPower'] - $flow['gridPower'];
        if (abs($balance) > 0.000001) {
            throw new RuntimeException('Demo flow must balance with positive grid export');
        }
    }
} finally {
    date_default_timezone_set($originalTimezone);
}
echo "Energy flow checks passed\n";
