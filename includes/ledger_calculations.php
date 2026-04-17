<?php

function ledgerGetCurrencyRate($db, $userId, $currencyId)
{
    $stmt = $db->prepare("SELECT rate FROM currencies WHERE id = :currencyId AND user_id = :userId");
    $stmt->bindValue(':currencyId', $currencyId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if (!$row || floatval($row['rate']) == 0.0) {
        return 1.0;
    }
    return floatval($row['rate']);
}

function ledgerConvertToMainCurrency($db, $userId, $amount, $currencyId, $mainCurrencyId)
{
    if (intval($currencyId) === intval($mainCurrencyId)) {
        return floatval($amount);
    }
    $fromRate = ledgerGetCurrencyRate($db, $userId, $currencyId);
    $mainRate = ledgerGetCurrencyRate($db, $userId, $mainCurrencyId);
    if ($fromRate == 0.0) {
        return floatval($amount);
    }
    return (floatval($amount) / $fromRate) * $mainRate;
}

function ledgerGetPricePerMonth($cycle, $frequency, $price)
{
    switch (intval($cycle)) {
        case 1:
            return $price * (30 / $frequency);
        case 2:
            return $price * (4.35 / $frequency);
        case 3:
            return $price * (1 / $frequency);
        case 4:
            return $price / (12 * $frequency);
        default:
            return floatval($price);
    }
}

function ledgerGetRange($scope, $startDate, $endDate)
{
    $today = new DateTime('today');
    if ($scope === 'month') {
        $start = new DateTime($today->format('Y-m-01'));
        $end = new DateTime($today->format('Y-m-t'));
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }
    if ($scope === 'range') {
        return [$startDate, $endDate];
    }
    return [null, null];
}

function ledgerRecurringIntersectsRange($row, $rangeStart, $rangeEnd)
{
    if ($rangeStart === null || $rangeEnd === null) {
        return true;
    }
    if (strcmp($row['start_date'], $rangeEnd) > 0) {
        return false;
    }
    if (!empty($row['end_date']) && strcmp($row['end_date'], $rangeStart) < 0) {
        return false;
    }
    return true;
}

function ledgerRecurringMonthlyAmountInScope($row, $scope, $rangeStart, $rangeEnd)
{
    if (intval($row['active']) !== 1) {
        return 0.0;
    }
    if (!ledgerRecurringIntersectsRange($row, $rangeStart, $rangeEnd)) {
        return 0.0;
    }
    if ($scope === 'all') {
        return ledgerGetPricePerMonth($row['cycle'], $row['frequency'], $row['amount']);
    }
    return ledgerGetPricePerMonth($row['cycle'], $row['frequency'], $row['amount']);
}

function buildLedgerData($db, $userId, $scope, $startDate, $endDate)
{
    $range = ledgerGetRange($scope, $startDate, $endDate);
    $rangeStart = $range[0];
    $rangeEnd = $range[1];

    $userStmt = $db->prepare("SELECT main_currency FROM user WHERE id = :userId");
    $userStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $userResult = $userStmt->execute();
    $userRow = $userResult->fetchArray(SQLITE3_ASSOC);
    $mainCurrencyId = intval($userRow['main_currency']);

    $currencyStmt = $db->prepare("SELECT code, symbol FROM currencies WHERE id = :id AND user_id = :userId");
    $currencyStmt->bindValue(':id', $mainCurrencyId, SQLITE3_INTEGER);
    $currencyStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $currencyResult = $currencyStmt->execute();
    $currencyRow = $currencyResult->fetchArray(SQLITE3_ASSOC);
    $mainCurrencyCode = $currencyRow ? $currencyRow['code'] : '';
    $mainCurrencySymbol = $currencyRow ? $currencyRow['symbol'] : '';

    $members = [];
    $memberStmt = $db->prepare("SELECT id, name FROM household WHERE user_id = :userId");
    $memberStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $memberResult = $memberStmt->execute();
    while ($row = $memberResult->fetchArray(SQLITE3_ASSOC)) {
        $members[intval($row['id'])] = [
            'household_id' => intval($row['id']),
            'name' => $row['name'],
            'subscription_breakdown' => [],
            'subscriptions_total' => 0.0,
            'income_total' => 0.0,
            'net_difference' => 0.0
        ];
    }

    $subSql = "SELECT sp.household_id, sp.amount, sp.subscription_id, s.name as subscription_name, s.cycle, s.frequency, s.currency_id
               FROM subscription_participants sp
               INNER JOIN subscriptions s ON s.id = sp.subscription_id
               INNER JOIN household h ON h.id = sp.household_id
               WHERE h.user_id = :userId AND s.user_id = :userId AND s.inactive = 0";
    $subStmt = $db->prepare($subSql);
    $subStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $subResult = $subStmt->execute();
    while ($row = $subResult->fetchArray(SQLITE3_ASSOC)) {
        $memberId = intval($row['household_id']);
        if (!isset($members[$memberId])) {
            continue;
        }
        $converted = ledgerConvertToMainCurrency($db, $userId, $row['amount'], $row['currency_id'], $mainCurrencyId);
        $monthly = ledgerGetPricePerMonth($row['cycle'], $row['frequency'], $converted);
        $members[$memberId]['subscriptions_total'] += $monthly;
        $members[$memberId]['subscription_breakdown'][] = [
            'subscription_id' => intval($row['subscription_id']),
            'subscription_name' => $row['subscription_name'],
            'monthly_amount' => round($monthly, 2)
        ];
    }

    $entrySql = "SELECT household_id, amount, currency_id
                 FROM person_income_entries
                 WHERE user_id = :userId";
    if ($rangeStart !== null) {
        $entrySql .= " AND income_date >= :rangeStart AND income_date <= :rangeEnd";
    }
    $entryStmt = $db->prepare($entrySql);
    $entryStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    if ($rangeStart !== null) {
        $entryStmt->bindValue(':rangeStart', $rangeStart, SQLITE3_TEXT);
        $entryStmt->bindValue(':rangeEnd', $rangeEnd, SQLITE3_TEXT);
    }
    $entryResult = $entryStmt->execute();
    while ($row = $entryResult->fetchArray(SQLITE3_ASSOC)) {
        $memberId = intval($row['household_id']);
        if (!isset($members[$memberId])) {
            continue;
        }
        $converted = ledgerConvertToMainCurrency($db, $userId, $row['amount'], $row['currency_id'], $mainCurrencyId);
        $members[$memberId]['income_total'] += $converted;
    }

    $recurringStmt = $db->prepare("SELECT * FROM person_income_recurring WHERE user_id = :userId");
    $recurringStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $recurringResult = $recurringStmt->execute();
    while ($row = $recurringResult->fetchArray(SQLITE3_ASSOC)) {
        $memberId = intval($row['household_id']);
        if (!isset($members[$memberId])) {
            continue;
        }
        $monthlyAmount = ledgerRecurringMonthlyAmountInScope($row, $scope, $rangeStart, $rangeEnd);
        if ($monthlyAmount <= 0) {
            continue;
        }
        $converted = ledgerConvertToMainCurrency($db, $userId, $monthlyAmount, $row['currency_id'], $mainCurrencyId);
        $members[$memberId]['income_total'] += $converted;
    }

    $grandSubscriptions = 0.0;
    $grandIncome = 0.0;
    foreach ($members as &$member) {
        $member['subscriptions_total'] = round($member['subscriptions_total'], 2);
        $member['income_total'] = round($member['income_total'], 2);
        $member['net_difference'] = round($member['income_total'] - $member['subscriptions_total'], 2);
        $grandSubscriptions += $member['subscriptions_total'];
        $grandIncome += $member['income_total'];
    }
    unset($member);

    return [
        'members' => array_values($members),
        'main_currency_id' => $mainCurrencyId,
        'main_currency_code' => $mainCurrencyCode,
        'main_currency_symbol' => $mainCurrencySymbol,
        'grand_subscriptions_total' => round($grandSubscriptions, 2),
        'grand_income_total' => round($grandIncome, 2),
        'grand_net_difference' => round($grandIncome - $grandSubscriptions, 2),
        'scope' => $scope,
        'range_start' => $rangeStart,
        'range_end' => $rangeEnd
    ];
}

?>
