<?php

declare(strict_types=1);

$baseDir = dirname(__DIR__);
$dbPath = $baseDir . '/db/wallos.db';
$expensesDir = $baseDir . '/Monatliche Ausgaben';

if (!file_exists($dbPath)) {
    fwrite(STDERR, "Database not found: {$dbPath}\n");
    exit(1);
}

$requiredFiles = [
    'Allgemein' => $expensesDir . '/Allgemein.html',
    'Joni' => $expensesDir . '/Joni.html',
    'Einnahmen' => $expensesDir . '/Einnahmen.html',
    'Pro_Person' => $expensesDir . '/Pro_Person.html',
    'Personen_Vergleich' => $expensesDir . '/Personen_Vergleich.html',
];

foreach ($requiredFiles as $label => $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "Missing {$label} file: {$path}\n");
        exit(1);
    }
}

$db = new SQLite3($dbPath);
$db->busyTimeout(5000);

$userId = 1;
$mainCurrencyId = 1;
$defaultPaymentMethodId = 1;
$defaultCategoryId = 1;
$today = (new DateTime('today'))->format('Y-m-d');

function readTableRows(string $filePath): array
{
    $html = file_get_contents($filePath);
    if ($html === false) {
        return [];
    }

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML($html);
    libxml_clear_errors();

    $rows = [];
    foreach ($doc->getElementsByTagName('tr') as $tr) {
        $cells = [];
        foreach ($tr->getElementsByTagName('td') as $td) {
            $cells[] = trim(preg_replace('/\s+/', ' ', html_entity_decode($td->textContent ?? '', ENT_QUOTES | ENT_HTML5)));
        }
        if (count($cells) > 0) {
            $rows[] = $cells;
        }
    }

    return $rows;
}

function parseAmount(string $value): float
{
    $clean = str_replace(["\xc2\xa0", '€', 'EUR', ' '], '', $value);
    $clean = str_replace('.', '', $clean);
    $clean = str_replace(',', '.', $clean);
    $clean = preg_replace('/[^0-9\.\-]/', '', $clean ?? '');
    if ($clean === '' || $clean === null) {
        return 0.0;
    }
    return round((float) $clean, 2);
}

function normalizeName(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/\s+/', ' ', $name);
    return $name ?? '';
}

function parsePeopleList(string $peopleCell): array
{
    $raw = array_map('trim', explode(',', $peopleCell));
    $people = [];
    foreach ($raw as $p) {
        if ($p === '' || $p === '#') {
            continue;
        }
        $p = normalizeName($p);
        if ($p !== '') {
            $people[] = $p;
        }
    }
    return array_values(array_unique($people));
}

function getOrCreateHouseholdId(SQLite3 $db, int $userId, string $name): int
{
    $stmt = $db->prepare('SELECT id FROM household WHERE user_id = :userId AND lower(name) = lower(:name) LIMIT 1');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    if ($row && isset($row['id'])) {
        return (int) $row['id'];
    }

    $insert = $db->prepare('INSERT INTO household (name, user_id) VALUES (:name, :userId)');
    $insert->bindValue(':name', $name, SQLITE3_TEXT);
    $insert->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $insert->execute();
    return (int) $db->lastInsertRowID();
}

function upsertSubscription(
    SQLite3 $db,
    int $userId,
    int $currencyId,
    string $name,
    float $price,
    int $cycle,
    int $frequency,
    string $notes,
    string $startDate,
    int $payerUserId,
    int $paymentMethodId,
    int $categoryId
): int {
    $select = $db->prepare('SELECT id FROM subscriptions WHERE user_id = :userId AND lower(name) = lower(:name) LIMIT 1');
    $select->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $select->bindValue(':name', $name, SQLITE3_TEXT);
    $result = $select->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    if ($row && isset($row['id'])) {
        $id = (int) $row['id'];
        $update = $db->prepare(
            'UPDATE subscriptions
             SET price = :price,
                 currency_id = :currencyId,
                 cycle = :cycle,
                 frequency = :frequency,
                 notes = :notes,
                 start_date = :startDate,
                 next_payment = :startDate,
                 payment_method_id = :paymentMethodId,
                 payer_user_id = :payerUserId,
                 category_id = :categoryId,
                 inactive = 0
             WHERE id = :id AND user_id = :userId'
        );
        $update->bindValue(':price', $price, SQLITE3_FLOAT);
        $update->bindValue(':currencyId', $currencyId, SQLITE3_INTEGER);
        $update->bindValue(':cycle', $cycle, SQLITE3_INTEGER);
        $update->bindValue(':frequency', $frequency, SQLITE3_INTEGER);
        $update->bindValue(':notes', $notes, SQLITE3_TEXT);
        $update->bindValue(':startDate', $startDate, SQLITE3_TEXT);
        $update->bindValue(':paymentMethodId', $paymentMethodId, SQLITE3_INTEGER);
        $update->bindValue(':payerUserId', $payerUserId, SQLITE3_INTEGER);
        $update->bindValue(':categoryId', $categoryId, SQLITE3_INTEGER);
        $update->bindValue(':id', $id, SQLITE3_INTEGER);
        $update->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $update->execute();
        return $id;
    }

    $insert = $db->prepare(
        'INSERT INTO subscriptions
        (name, logo, price, currency_id, next_payment, cycle, frequency, notes, payment_method_id, payer_user_id, category_id, notify, inactive, url, notify_days_before, user_id, cancellation_date, replacement_subscription_id, auto_renew, start_date)
         VALUES
        (:name, "", :price, :currencyId, :startDate, :cycle, :frequency, :notes, :paymentMethodId, :payerUserId, :categoryId, 0, 0, "", 3, :userId, NULL, NULL, 1, :startDate)'
    );
    $insert->bindValue(':name', $name, SQLITE3_TEXT);
    $insert->bindValue(':price', $price, SQLITE3_FLOAT);
    $insert->bindValue(':currencyId', $currencyId, SQLITE3_INTEGER);
    $insert->bindValue(':startDate', $startDate, SQLITE3_TEXT);
    $insert->bindValue(':cycle', $cycle, SQLITE3_INTEGER);
    $insert->bindValue(':frequency', $frequency, SQLITE3_INTEGER);
    $insert->bindValue(':notes', $notes, SQLITE3_TEXT);
    $insert->bindValue(':paymentMethodId', $paymentMethodId, SQLITE3_INTEGER);
    $insert->bindValue(':payerUserId', $payerUserId, SQLITE3_INTEGER);
    $insert->bindValue(':categoryId', $categoryId, SQLITE3_INTEGER);
    $insert->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $insert->execute();
    return (int) $db->lastInsertRowID();
}

function setSubscriptionParticipants(SQLite3 $db, int $subscriptionId, array $householdIds, float $totalPrice): void
{
    if (count($householdIds) === 0) {
        return;
    }

    $delete = $db->prepare('DELETE FROM subscription_participants WHERE subscription_id = :subscriptionId');
    $delete->bindValue(':subscriptionId', $subscriptionId, SQLITE3_INTEGER);
    $delete->execute();

    $totalCents = (int) round($totalPrice * 100);
    $count = count($householdIds);
    $base = intdiv($totalCents, $count);
    $remainder = $totalCents % $count;

    $insert = $db->prepare('INSERT INTO subscription_participants (subscription_id, household_id, amount, is_manual) VALUES (:subscriptionId, :householdId, :amount, 0)');

    foreach (array_values($householdIds) as $idx => $householdId) {
        $cents = $base + ($idx < $remainder ? 1 : 0);
        $amount = round($cents / 100, 2);
        $insert->bindValue(':subscriptionId', $subscriptionId, SQLITE3_INTEGER);
        $insert->bindValue(':householdId', $householdId, SQLITE3_INTEGER);
        $insert->bindValue(':amount', $amount, SQLITE3_FLOAT);
        $insert->execute();
    }
}

function mapMonthsToCycle(int $months): ?array
{
    if ($months === 1) {
        return ['cycle' => 3, 'frequency' => 1];
    }
    if ($months === 3) {
        return ['cycle' => 3, 'frequency' => 3];
    }
    if ($months === 6) {
        return ['cycle' => 3, 'frequency' => 6];
    }
    if ($months === 12) {
        return ['cycle' => 4, 'frequency' => 1];
    }
    return null;
}

$db->exec('BEGIN');

$stats = [
    'household_created' => 0,
    'subscriptions_upserted' => 0,
    'income_recurring_upserted' => 0,
    'income_entries_inserted' => 0,
];

$existingHouseholdCount = (int) $db->querySingle("SELECT COUNT(*) FROM household WHERE user_id = {$userId}");

// 1) Allgemein: shared subscriptions with participants.
$allgemeinRows = readTableRows($requiredFiles['Allgemein']);
$subscriptionIdByName = [];
foreach ($allgemeinRows as $cells) {
    $name = normalizeName($cells[0] ?? '');
    if ($name === '' || strtolower($name) === 'produkt') {
        continue;
    }

    $people = parsePeopleList($cells[1] ?? '');
    $monthlyAmount = parseAmount($cells[2] ?? '');
    $yearlyAmount = parseAmount($cells[3] ?? '');
    $note = normalizeName($cells[5] ?? '');

    if ($monthlyAmount <= 0.0 && $yearlyAmount <= 0.0) {
        continue;
    }

    if ($monthlyAmount > 0.0) {
        $price = $monthlyAmount;
        $cycle = 3;
        $frequency = 1;
    } else {
        $price = $yearlyAmount;
        $cycle = 4;
        $frequency = 1;
    }

    $householdIds = [];
    foreach ($people as $personName) {
        $householdId = getOrCreateHouseholdId($db, $userId, $personName);
        $householdIds[] = $householdId;
    }

    if (count($householdIds) === 0) {
        continue;
    }

    $payerUserId = $householdIds[0];
    $subscriptionId = upsertSubscription($db, $userId, $mainCurrencyId, $name, $price, $cycle, $frequency, $note, $today, $payerUserId, $defaultPaymentMethodId, $defaultCategoryId);
    setSubscriptionParticipants($db, $subscriptionId, $householdIds, $price);
    $subscriptionIdByName[mb_strtolower($name)] = $subscriptionId;
    $stats['subscriptions_upserted']++;
}

// 2) Joni: personal costs (single participant Joni), skip aggregate/helper rows.
$joniRows = readTableRows($requiredFiles['Joni']);
foreach ($joniRows as $cells) {
    $name = normalizeName($cells[0] ?? '');
    if ($name === '' || strtolower($name) === 'name') {
        continue;
    }

    $lowerName = mb_strtolower($name);
    if (strpos($lowerName, 'kosten aus allgemein') !== false) {
        continue;
    }

    $person = normalizeName($cells[1] ?? '');
    if ($person === '' || $person !== 'Joni') {
        continue;
    }

    $monthlyAmount = parseAmount($cells[2] ?? '');
    $yearlyAmount = parseAmount($cells[3] ?? '');
    $note = normalizeName($cells[4] ?? '');

    if ($monthlyAmount <= 0.0 && $yearlyAmount <= 0.0) {
        continue;
    }

    if ($monthlyAmount > 0.0) {
        $price = $monthlyAmount;
        $cycle = 3;
        $frequency = 1;
    } else {
        $price = $yearlyAmount;
        $cycle = 4;
        $frequency = 1;
    }

    $joniHouseholdId = getOrCreateHouseholdId($db, $userId, 'Joni');
    $subscriptionId = upsertSubscription($db, $userId, $mainCurrencyId, $name, $price, $cycle, $frequency, $note, $today, $joniHouseholdId, $defaultPaymentMethodId, $defaultCategoryId);
    setSubscriptionParticipants($db, $subscriptionId, [$joniHouseholdId], $price);
    $subscriptionIdByName[mb_strtolower($name)] = $subscriptionId;
    $stats['subscriptions_upserted']++;
}

// 3) Einnahmen: money expected back from people.
//    Use recurring rows for 1/3/6/12 month patterns, otherwise create one-time entry.
$einnahmenRows = readTableRows($requiredFiles['Einnahmen']);
foreach ($einnahmenRows as $cells) {
    $person = normalizeName($cells[0] ?? '');
    if ($person === '' || mb_strtolower($person) === 'name') {
        continue;
    }

    $amount = parseAmount($cells[1] ?? '');
    $product = normalizeName($cells[2] ?? '');
    $months = (int) parseAmount($cells[3] ?? '0');
    $status = normalizeName($cells[4] ?? '');

    if ($amount <= 0.0) {
        continue;
    }

    if (mb_strtolower($status) === 'ignore') {
        continue;
    }

    $householdId = getOrCreateHouseholdId($db, $userId, $person);
    $subscriptionId = null;
    $productKey = mb_strtolower($product);
    if (isset($subscriptionIdByName[$productKey])) {
        $subscriptionId = $subscriptionIdByName[$productKey];
    }

    $note = "Import Einnahmen: {$product}";
    $mappedCycle = mapMonthsToCycle($months);

    if ($mappedCycle !== null) {
        $check = $db->prepare(
            'SELECT id FROM person_income_recurring
             WHERE user_id = :userId
               AND household_id = :householdId
               AND amount = :amount
               AND cycle = :cycle
               AND frequency = :frequency
               AND ifnull(subscription_id, 0) = ifnull(:subscriptionId, 0)
             LIMIT 1'
        );
        $check->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $check->bindValue(':householdId', $householdId, SQLITE3_INTEGER);
        $check->bindValue(':amount', $amount, SQLITE3_FLOAT);
        $check->bindValue(':cycle', $mappedCycle['cycle'], SQLITE3_INTEGER);
        $check->bindValue(':frequency', $mappedCycle['frequency'], SQLITE3_INTEGER);
        $check->bindValue(':subscriptionId', $subscriptionId, SQLITE3_INTEGER);
        $found = $check->execute();
        $foundRow = $found ? $found->fetchArray(SQLITE3_ASSOC) : false;

        if ($foundRow && isset($foundRow['id'])) {
            $update = $db->prepare(
                'UPDATE person_income_recurring
                 SET note = :note, active = 1, start_date = :startDate
                 WHERE id = :id AND user_id = :userId'
            );
            $update->bindValue(':note', $note, SQLITE3_TEXT);
            $update->bindValue(':startDate', $today, SQLITE3_TEXT);
            $update->bindValue(':id', (int) $foundRow['id'], SQLITE3_INTEGER);
            $update->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $update->execute();
        } else {
            $insert = $db->prepare(
                'INSERT INTO person_income_recurring
                (user_id, household_id, amount, currency_id, cycle, frequency, start_date, end_date, subscription_id, note, active)
                 VALUES
                (:userId, :householdId, :amount, :currencyId, :cycle, :frequency, :startDate, NULL, :subscriptionId, :note, 1)'
            );
            $insert->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $insert->bindValue(':householdId', $householdId, SQLITE3_INTEGER);
            $insert->bindValue(':amount', $amount, SQLITE3_FLOAT);
            $insert->bindValue(':currencyId', $mainCurrencyId, SQLITE3_INTEGER);
            $insert->bindValue(':cycle', $mappedCycle['cycle'], SQLITE3_INTEGER);
            $insert->bindValue(':frequency', $mappedCycle['frequency'], SQLITE3_INTEGER);
            $insert->bindValue(':startDate', $today, SQLITE3_TEXT);
            $insert->bindValue(':subscriptionId', $subscriptionId, SQLITE3_INTEGER);
            $insert->bindValue(':note', $note, SQLITE3_TEXT);
            $insert->execute();
        }
        $stats['income_recurring_upserted']++;
    } else {
        $insert = $db->prepare(
            'INSERT INTO person_income_entries
            (user_id, household_id, amount, currency_id, income_date, subscription_id, note)
             VALUES
            (:userId, :householdId, :amount, :currencyId, :incomeDate, :subscriptionId, :note)'
        );
        $insert->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $insert->bindValue(':householdId', $householdId, SQLITE3_INTEGER);
        $insert->bindValue(':amount', $amount, SQLITE3_FLOAT);
        $insert->bindValue(':currencyId', $mainCurrencyId, SQLITE3_INTEGER);
        $insert->bindValue(':incomeDate', $today, SQLITE3_TEXT);
        $insert->bindValue(':subscriptionId', $subscriptionId, SQLITE3_INTEGER);
        $insert->bindValue(':note', $note . " ({$months} Monate)", SQLITE3_TEXT);
        $insert->execute();
        $stats['income_entries_inserted']++;
    }
}

$newHouseholdCount = (int) $db->querySingle("SELECT COUNT(*) FROM household WHERE user_id = {$userId}");
$stats['household_created'] = max(0, $newHouseholdCount - $existingHouseholdCount);

$db->exec('COMMIT');
$db->close();

echo "Import complete.\n";
echo "Household created: {$stats['household_created']}\n";
echo "Subscriptions upserted: {$stats['subscriptions_upserted']}\n";
echo "Recurring incomes upserted: {$stats['income_recurring_upserted']}\n";
echo "One-time incomes inserted: {$stats['income_entries_inserted']}\n";

?>
