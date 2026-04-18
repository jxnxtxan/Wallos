<?php
/*
This API Endpoint accepts POST requests only (JSON or form body).
It receives the following parameters:
- api_key: the API key of the user (string).
- type: "entry" (one-time) or "recurring" (default "entry").
- household_id: member id (integer, required).
- amount: decimal amount (required, >= 0).
- currency_id: currency id (integer, required).
- subscription_id: optional linked subscription id (integer or omit).
- note: optional note (string).

For type "entry" (one-time):
- income_date: YYYY-MM-DD (required for create/update).
- id: optional — if set, updates the existing entry.

For type "recurring":
- cycle: billing cycle 1–4 (same as subscriptions).
- frequency: integer (required).
- start_date: YYYY-MM-DD (required).
- end_date: optional YYYY-MM-DD or empty.
- active: optional 1/0 or true/false (default active if omitted on create).
- id: optional — if set, updates the existing recurring row.

It returns a JSON object with success, title, message, and optional notes array.
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/inputvalidation.php';
require_once '../../includes/wallos_api_auth.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'title' => 'Invalid request method',
        'message' => 'Only POST requests are allowed.',
        'notes' => []
    ]);
    exit;
}

$params = wallos_api_merge_request_params();
$apiKey = $params['api_key'] ?? $params['apiKey'] ?? null;
$user = wallos_api_user_by_key($db, $apiKey);
if (!$user) {
    echo json_encode([
        'success' => false,
        'title' => $apiKey ? 'Invalid API key' : 'Missing parameters',
        'message' => $apiKey ? 'User not found or API key invalid.' : 'api_key is required.',
        'notes' => []
    ]);
    exit;
}

$userId = intval($user['id']);

function incomeApiJsonError($title, $message)
{
    echo json_encode([
        'success' => false,
        'title' => $title,
        'message' => $message,
        'notes' => []
    ]);
    exit;
}

function incomeApiAssertHouseholdMember($db, $userId, $householdId)
{
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM household WHERE id = :id AND user_id = :userId");
    $stmt->bindValue(':id', $householdId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return intval($row['count']) > 0;
}

function incomeApiAssertCurrency($db, $userId, $currencyId)
{
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM currencies WHERE id = :id AND user_id = :userId");
    $stmt->bindValue(':id', $currencyId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return intval($row['count']) > 0;
}

function incomeApiAssertSubscriptionOrNull($db, $userId, $subscriptionId)
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

$id = isset($params['id']) && $params['id'] !== '' ? intval($params['id']) : null;
$type = $params['type'] ?? 'entry';
$householdId = intval($params['household_id'] ?? 0);
$amount = floatval($params['amount'] ?? 0);
$currencyId = intval($params['currency_id'] ?? 0);
$subscriptionId = isset($params['subscription_id']) && $params['subscription_id'] !== '' ? intval($params['subscription_id']) : null;
$note = validate($params['note'] ?? '');

if ($householdId <= 0 || $currencyId <= 0 || $amount < 0) {
    incomeApiJsonError('Validation', 'household_id, currency_id and a non-negative amount are required.');
}

if (!incomeApiAssertHouseholdMember($db, $userId, $householdId) || !incomeApiAssertCurrency($db, $userId, $currencyId) || !incomeApiAssertSubscriptionOrNull($db, $userId, $subscriptionId)) {
    incomeApiJsonError('Validation', 'Invalid household, currency, or subscription for this account.');
}

if ($type === 'recurring') {
    $cycle = intval($params['cycle'] ?? 0);
    $frequency = intval($params['frequency'] ?? 0);
    $startDate = $params['start_date'] ?? '';
    $endDate = $params['end_date'] ?? null;
    if (array_key_exists('active', $params)) {
        $v = $params['active'];
        $active = ($v === 1 || $v === '1' || $v === true || $v === 'true' || $v === 'on') ? 1 : 0;
    } else {
        $active = 1;
    }

    if ($cycle < 1 || $cycle > 4 || $frequency <= 0 || $startDate === '') {
        incomeApiJsonError('Validation', 'For recurring income, cycle (1–4), frequency, and start_date are required.');
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
    if ($endDate !== null && $endDate !== '') {
        $stmt->bindValue(':endDate', $endDate, SQLITE3_TEXT);
    } else {
        $stmt->bindValue(':endDate', null, SQLITE3_NULL);
    }
    $stmt->bindValue(':subscriptionId', $subscriptionId, $subscriptionId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(':note', $note, SQLITE3_TEXT);
    $stmt->bindValue(':active', $active, SQLITE3_INTEGER);
} else {
    $incomeDate = $params['income_date'] ?? '';
    if ($incomeDate === '') {
        incomeApiJsonError('Validation', 'income_date is required for one-time income.');
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
    $stmt->bindValue(':subscriptionId', $subscriptionId, $subscriptionId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(':note', $note, SQLITE3_TEXT);
}

$ok = $stmt->execute();
if ($ok) {
    echo json_encode([
        'success' => true,
        'title' => 'Saved',
        'message' => 'Income saved successfully.',
        'notes' => []
    ]);
} else {
    echo json_encode([
        'success' => false,
        'title' => 'Database error',
        'message' => 'Could not save income.',
        'notes' => []
    ]);
}

$db->close();
