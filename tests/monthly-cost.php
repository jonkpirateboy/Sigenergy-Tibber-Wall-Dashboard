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

// Actual August API totals: the page includes 49 SEK, the energy nodes do not.
$august = new DateTimeImmutable('2026-08-01', $timezone);
$billed = homeWithCosts(675.093620684375, 130.504091605, '2026-08-01T00:00:00+02:00');
$billed['monthlyConsumption'] = [
    'pageInfo' => ['totalCost' => 724.093620684375],
    'nodes' => [['from' => '2026-08-01T00:00:00+02:00', 'cost' => 675.093620684375]],
];
$withFee = normalizeTibberMonthlyCost($billed, $timezone, $august);
check($withFee['monthlyFee'] === 49.0, 'Derive the subscription fee from the API, including VAT');
check($withFee['consumptionCost'] === 675.09 && $withFee['productionProfit'] === 130.5, 'Keep the visible energy amounts separate from the fee');
check($withFee['monthCost'] === 593.59, 'Include the fee in the net total');
check(normalizeMonthlyCostTotals($withFee)['monthCost'] === 593.59, 'Normalizing cached totals must not add the fee twice');
check(tibberMonthlyFee($billed['monthlyConsumption'], $month, $timezone) === null, 'Do not apply a page total from another month');
check(tibberMonthlyFee([], $august, $timezone) === null, 'Missing fee metadata must remain unknown');
$billed['monthlyConsumption']['pageInfo']['totalCost'] = 675.093620684375;
check(tibberMonthlyFee($billed['monthlyConsumption'], $august, $timezone) === 0.0, 'Keep a reported zero fee');
$billed['monthlyConsumption']['pageInfo']['totalCost'] = 49;
$billed['monthlyConsumption']['nodes'][0]['cost'] = null;
check(tibberMonthlyFee($billed['monthlyConsumption'], $august, $timezone) === null, 'Do not infer fees from empty cost placeholders');

$rounding = [
    'pageInfo' => ['totalCost' => 49.005],
    'nodes' => [['from' => '2026-08-01T00:00:00+02:00', 'cost' => 0.004]],
];
check(tibberMonthlyFee($rounding, $august, $timezone) === 49.0, 'Subtract unrounded API values before rounding the fee');
$octoberHome = homeWithCosts(12, 3, '2026-10-01T00:00:00+02:00');
$octoberHome['consumption']['nodes'][] = ['from' => '2026-10-31T23:00:00+01:00', 'to' => '2026-11-01T00:00:00+01:00', 'cost' => 2];
$octoberHome['monthlyConsumption'] = [
    'pageInfo' => ['totalCost' => 63],
    'nodes' => [['from' => '2026-10-01T00:00:00+02:00', 'cost' => 12], ['from' => '2026-10-31T00:00:00+01:00', 'cost' => 2]],
];
check(normalizeTibberMonthlyCost($octoberHome, $timezone, new DateTimeImmutable('2026-10-01', $timezone))['monthCost'] === 60.0, 'Add the full-month fee once across October hourly windows');

$september = new DateTimeImmutable('2026-09-01', $timezone);
$pendingFee = homeWithCosts(100, 20, '2026-09-20T00:00:00+02:00');
$pendingFee['monthlyConsumption'] = [
    'pageInfo' => ['totalCost' => 100],
    'nodes' => [['from' => '2026-09-20T00:00:00+02:00', 'cost' => 100]],
];
$pendingFee['previousMonthlyConsumption'] = [
    'pageInfo' => ['totalCost' => 724.093620684375],
    'nodes' => [['from' => '2026-08-01T00:00:00+02:00', 'cost' => 675.093620684375]],
];
$estimated = normalizeTibberMonthlyCost($pendingFee, $timezone, $september, true);
check($estimated['monthlyFee'] === 49.0 && $estimated['monthCost'] === 129.0, 'Use the previous API fee while the live month has none');
check($estimated['monthlyFeeEstimated'] && $estimated['monthlyFeeSourceMonth'] === '2026-08', 'Keep the fee estimate and source month explicit');
check($estimated['consumptionCost'] === 100.0 && $estimated['throughDate'] === '2026-09-20', 'Previous fee metadata must not add previous energy or change the cutoff');
check(normalizeTibberMonthlyCost($pendingFee, $timezone, $september)['monthCost'] === 80.0, 'Historical months must retain their reported zero fee');
$pendingFee['monthlyConsumption']['pageInfo']['totalCost'] = 159;
$reported = normalizeTibberMonthlyCost($pendingFee, $timezone, $september, true);
check($reported['monthlyFee'] === 59.0 && $reported['monthCost'] === 139.0, 'Replace the estimate when the current API fee arrives');
check(!$reported['monthlyFeeEstimated'] && $reported['monthlyFeeSourceMonth'] === '2026-09', 'Mark the current fee as reported');
$octoberPending = array_replace($estimated, ['month' => '2026-10', 'monthlyFee' => 0.0, 'monthlyFeeSourceMonth' => '2026-10', 'monthlyFeeEstimated' => false, 'monthCost' => 80.0]);
$carried = withCachedTibberMonthlyFee($octoberPending, $estimated);
check($carried['monthlyFee'] === 49.0 && $carried['monthlyFeeSourceMonth'] === '2026-08' && $carried['monthCost'] === 129.0, 'Keep the last known fee across a month boundary until the next fee is reported');
check(withCachedTibberMonthlyFee($reported, $estimated) === $reported, 'A new reported fee takes priority over the cached estimate');
check(withCachedTibberMonthlyFee($octoberPending, null) === $octoberPending, 'No cached fee must not invent an amount');
check(withCachedTibberMonthlyFee($octoberPending, array_replace($estimated, ['currency' => 'EUR'])) === $octoberPending, 'Do not carry a fee in a different currency');
check(withCachedTibberMonthlyFee($octoberPending, array_replace($estimated, ['monthlyFeeSourceMonth' => '2026-11'])) === $octoberPending, 'Do not carry a future fee into an earlier month');
$empty['previousMonthlyConsumption'] = $pendingFee['previousMonthlyConsumption'];
check(normalizeTibberMonthlyCost($empty, $timezone, $month, true) === null, 'A previous fee must not turn an empty month into current energy data');
echo "Monthly cost checks passed\n";
