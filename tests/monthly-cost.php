<?php
declare(strict_types=1);

ob_start();
require dirname(__DIR__) . '/www/index.php';
ob_end_clean();

$timezone = new DateTimeZone('Europe/Stockholm');
$month = new DateTimeImmutable('2027-01-01T00:00:00', $timezone);
$previous = $month->modify('-1 month');

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function homeWithCosts(?float $cost, ?float $profit, string $from): array
{
    $to = (new DateTimeImmutable($from))->modify('+1 day')->format(DATE_ATOM);
    return [
        'consumption' => ['nodes' => [['from' => $from, 'to' => $to, 'cost' => $cost, 'currency' => 'SEK']]],
        'production' => ['nodes' => [['from' => $from, 'to' => $to, 'profit' => $profit, 'currency' => 'SEK']]],
    ];
}

$old = homeWithCosts(224.89, 165.26, '2026-12-31T00:00:00+01:00');
$empty = homeWithCosts(null, null, '2027-01-01T00:00:00+01:00');
check(normalizeTibberMonthlyCost([], $timezone, $month) === null, 'No rows must allow fallback');
check(normalizeTibberMonthlyCost($empty, $timezone, $month) === null, 'Null placeholders must allow fallback');
check(normalizeTibberMonthlyCost($old, $timezone, $month) === null, 'Previous month must not enter current totals');
$fallback = normalizeTibberMonthlyCost($empty, $timezone, $month)
    ?? normalizeTibberMonthlyCost($old, $timezone, $previous);
check($fallback['month'] === '2026-12', 'Fallback must identify December across year boundary');
check($fallback['monthCost'] === 59.63, 'Fallback must preserve the previous net amount');
check($fallback['throughDate'] === '2026-12-31', 'Covered date must stay in December despite the January end boundary');

$zero = normalizeTibberMonthlyCost(homeWithCosts(0, 0, '2027-01-01T00:00:00+01:00'), $timezone, $month);
check($zero !== null && $zero['monthCost'] === 0.0 && $zero['month'] === '2027-01', 'Reported zero must switch to current month');
check($zero['throughDate'] === '2027-01-01', 'Reported zero must count toward the covered date');
$currentHome = homeWithCosts(12, 3, '2027-01-01T00:00:00+01:00');
foreach (['consumption', 'production'] as $key) {
    $currentHome[$key]['nodes'] = array_merge($old[$key]['nodes'], $currentHome[$key]['nodes']);
}
$current = normalizeTibberMonthlyCost($currentHome, $timezone, $month);
check($current['monthCost'] === 9.0, 'Months must never be summed together');
// UTC December timestamp is January in Sweden.
$boundary = normalizeTibberMonthlyCost(homeWithCosts(5, 1, '2026-12-31T23:00:00Z'), $timezone, $month);
check($boundary['monthCost'] === 4.0, 'Month selection must use Stockholm time');
check($boundary['throughDate'] === '2027-01-01', 'Covered date must use Stockholm time');
$datedHome = homeWithCosts(12, 3, '2027-01-12T00:00:00+01:00');
$placeholder = homeWithCosts(null, null, '2027-01-13T00:00:00+01:00');
foreach (['consumption', 'production'] as $key) {
    $datedHome[$key]['nodes'] = array_merge($datedHome[$key]['nodes'], $placeholder[$key]['nodes']);
}
check(normalizeTibberMonthlyCost($datedHome, $timezone, $month)['throughDate'] === '2027-01-12', 'Empty later rows must not advance the covered date');
$datedHome['production']['nodes'][1]['profit'] = 0;
check(normalizeTibberMonthlyCost($datedHome, $timezone, $month)['throughDate'] === '2027-01-13', 'Latest reported production must advance the covered date');
check(normalizeTibberMonthlyCost([], $timezone, $previous) === null, 'No prior data must remain empty');
$hourly = [
    'consumption' => ['nodes' => [
        ['from' => '2027-01-12T10:00:00+01:00', 'to' => '2027-01-12T11:00:00+01:00', 'cost' => 2.25],
        ['from' => '2027-01-12T11:00:00+01:00', 'to' => '2027-01-12T12:00:00+01:00', 'cost' => 0],
        ['from' => '2027-01-12T12:00:00+01:00', 'to' => '2027-01-12T13:00:00+01:00', 'cost' => null],
    ]],
    'production' => ['nodes' => [
        ['from' => '2027-01-12T10:00:00+01:00', 'to' => '2027-01-12T11:00:00+01:00', 'profit' => 3.75],
    ]],
];
$timed = normalizeTibberMonthlyCost($hourly, $timezone, $month);
check($timed['monthCost'] === -1.5, 'Sum reported hourly amounts');
check($timed['throughAt'] === '2027-01-12T12:00:00+01:00', 'Use last reported interval end, including zero, ignoring placeholders');
check($timed['consumptionThroughAt'] === '2027-01-12T12:00:00+01:00', 'Keep consumption cutoff');
check($timed['productionThroughAt'] === '2027-01-12T11:00:00+01:00', 'Keep earlier production cutoff');
$hourly['production']['nodes'][] = ['from' => '2027-01-12T12:00:00+01:00', 'to' => '2027-01-12T13:00:00+01:00', 'profit' => 0];
check(normalizeTibberMonthlyCost($hourly, $timezone, $month)['throughAt'] === '2027-01-12T13:00:00+01:00', 'Production can advance the cutoff');
check($fallback['throughAt'] === '2027-01-01T00:00:00+01:00', 'Month-end cutoff is midnight in the following month');
check(reportedMoneyThrough([
    ['from' => '2027-01-01T00:00:00Z', 'to' => 'bad date', 'cost' => 1],
    ['from' => '2027-01-01T00:00:00Z', 'to' => '', 'cost' => 1],
], 'cost', $timezone) === null, 'Do not invent a cutoff for invalid end times');
$dstEnd = reportedMoneyThrough([
    ['from' => '2026-10-25T02:00:00+01:00', 'to' => '2026-10-25T03:00:00+01:00', 'cost' => 1],
    ['from' => '2026-10-25T02:00:00+02:00', 'to' => '2026-10-25T02:00:00+01:00', 'cost' => 1],
], 'cost', $timezone);
check($dstEnd->format(DATE_ATOM) === '2026-10-25T03:00:00+01:00', 'Compare actual instants across DST');
$october = tibberMonthHourWindows(new DateTimeImmutable('2026-10-01', $timezone), $timezone);
check(array_column($october, 'hours') === [744, 1], 'Include all 745 October hours within the API page limit');
check($october[1]['from'] === '2026-10-31T23:00:00+01:00', 'Second window includes the final hour without overlap');
$march = tibberMonthHourWindows(new DateTimeImmutable('2026-03-01', $timezone), $timezone);
check(array_column($march, 'hours') === [743], 'Account for the missing spring DST hour');
echo "Monthly cost checks passed\n";
