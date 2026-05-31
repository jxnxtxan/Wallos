<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/inputvalidation.php';

function jsonError($message)
{
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $message
    ]);
    exit();
}

function assertHouseholdMember($db, $userId, $householdId)
{
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM household WHERE id = :id AND user_id = :userId");
    $stmt->bindValue(':id', $householdId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return intval($row['count']) > 0;
}

function assertCurrency($db, $userId, $currencyId)
{
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM currencies WHERE id = :id AND user_id = :userId");
    $stmt->bindValue(':id', $currencyId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return intval($row['count']) > 0;
}

function assertSubscriptionOrNull($db, $userId, $subscriptionId)
{
    if ($subscriptionId === null) {
        return true;
    }
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM subscriptions WHERE id = :id AND user_id = :userId");
    $stmt->bindValue(':id', $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return intval($row['count']) > 0;
}

$id = isset($_POST['id']) && $_POST['id'] !== '' ? intval($_POST['id']) : null;
$type = $_POST['type'] ?? 'entry';
$householdId = intval($_POST['household_id'] ?? 0);
$amount = floatval($_POST['amount'] ?? 0);
$currencyId = intval($_POST['currency_id'] ?? 0);
$subscriptionId = isset($_POST['subscription_id']) && $_POST['subscription_id'] !== '' ? intval($_POST['subscription_id']) : null;
$note = validate($_POST['note'] ?? '');

if ($householdId <= 0 || $currencyId <= 0 || $amount < 0) {
    jsonError(translate('fill_mandatory_fields', $i18n));
}

if (!assertHouseholdMember($db, $userId, $householdId) || !assertCurrency($db, $userId, $currencyId) || !assertSubscriptionOrNull($db, $userId, $subscriptionId)) {
    jsonError(translate('error', $i18n));
}

if ($type === 'recurring') {
    $cycle = intval($_POST['cycle'] ?? 0);
    $frequency = intval($_POST['frequency'] ?? 0);
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? null;
    $active = isset($_POST['active']) ? 1 : 0;

    if ($cycle < 1 || $cycle > 4 || $frequency <= 0 || $startDate === '') {
        jsonError(translate('fill_mandatory_fields', $i18n));
    }

    if ($id === null) {
        $stmt = $db->prepare("INSERT INTO person_income_recurring
            (user_id, household_id, amount, currency_id, cycle, frequency, start_date, end_date, subscription_id, note, active)
            VALUES (:userId, :householdId, :amount, :currencyId, :cycle, :frequency, :startDate, :endDate, :subscriptionId, :note, :active)");
    } else {
        $stmt = $db->prepare("UPDATE person_income_recurring SET
            household_id = :householdId,
            amount = :amount,
            currency_id = :currencyId,
            cycle = :cycle,
            frequency = :frequency,
            start_date = :startDate,
            end_date = :endDate,
            subscription_id = :subscriptionId,
            note = :note,
            active = :active
            WHERE id = :id AND user_id = :userId");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    }

    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':householdId', $householdId, SQLITE3_INTEGER);
    $stmt->bindValue(':amount', $amount, SQLITE3_FLOAT);
    $stmt->bindValue(':currencyId', $currencyId, SQLITE3_INTEGER);
    $stmt->bindValue(':cycle', $cycle, SQLITE3_INTEGER);
    $stmt->bindValue(':frequency', $frequency, SQLITE3_INTEGER);
    $stmt->bindValue(':startDate', $startDate, SQLITE3_TEXT);
    $stmt->bindValue(':endDate', $endDate, SQLITE3_TEXT);
    $stmt->bindValue(':subscriptionId', $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':note', $note, SQLITE3_TEXT);
    $stmt->bindValue(':active', $active, SQLITE3_INTEGER);
} else {
    $incomeDate = $_POST['income_date'] ?? '';
    if ($incomeDate === '') {
        jsonError(translate('fill_mandatory_fields', $i18n));
    }

    if ($id === null) {
        $stmt = $db->prepare("INSERT INTO person_income_entries
            (user_id, household_id, amount, currency_id, income_date, subscription_id, note)
            VALUES (:userId, :householdId, :amount, :currencyId, :incomeDate, :subscriptionId, :note)");
    } else {
        $stmt = $db->prepare("UPDATE person_income_entries SET
            household_id = :householdId,
            amount = :amount,
            currency_id = :currencyId,
            income_date = :incomeDate,
            subscription_id = :subscriptionId,
            note = :note
            WHERE id = :id AND user_id = :userId");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    }

    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':householdId', $householdId, SQLITE3_INTEGER);
    $stmt->bindValue(':amount', $amount, SQLITE3_FLOAT);
    $stmt->bindValue(':currencyId', $currencyId, SQLITE3_INTEGER);
    $stmt->bindValue(':incomeDate', $incomeDate, SQLITE3_TEXT);
    $stmt->bindValue(':subscriptionId', $subscriptionId, SQLITE3_INTEGER);
    $stmt->bindValue(':note', $note, SQLITE3_TEXT);
}

$ok = $stmt->execute();
header('Content-Type: application/json');
if ($ok) {
    echo json_encode([
        'success' => true,
        'message' => translate('saved', $i18n)
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n)
    ]);
}

$db->close();
?>
