<?php
/*
This API Endpoint accepts both POST and GET requests.
It receives the following parameters:
- api_key: the API key of the user (string).
- type: optional filter for list mode: "all" (default), "entry", or "recurring".
- household_id: optional member/household id (integer).
- start_date: optional lower bound for one-time entries, YYYY-MM-DD (string).
- end_date: optional upper bound for one-time entries, YYYY-MM-DD (string).
- id: optional — if set, returns a single record instead of a list.
- item_type: optional when id is set: "entry" or "recurring" (default "entry"). If omitted, "type" is used only when it is "entry" or "recurring" (so "type=all" does not break single fetch).

It returns a JSON object with the following properties:
- success: whether the request was successful (boolean).
- title: the title of the response (string).
- entries: array of one-time income rows (with household_name, subscription_name, currency_code), list mode only.
- recurring: array of recurring income rows, list mode only.
- item: a single income record, single-item mode only.
- item_type: "entry" or "recurring", single-item mode only.
- notes: warning messages or additional information (array).

Example list response:
{
  "success": true,
  "title": "income",
  "entries": [],
  "recurring": [],
  "notes": []
}
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/wallos_api_auth.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode([
        'success' => false,
        'title' => 'Invalid request method',
        'notes' => ['Only GET and POST are allowed.']
    ]);
    exit;
}

$apiKey = $_REQUEST['api_key'] ?? $_REQUEST['apiKey'] ?? null;
$user = wallos_api_user_by_key($db, $apiKey);
if (!$user) {
    echo json_encode([
        'success' => false,
        'title' => $apiKey ? 'Invalid API key' : 'Missing parameters',
        'notes' => [$apiKey ? 'User not found or API key invalid.' : 'api_key is required.']
    ]);
    exit;
}

$userId = intval($user['id']);
$id = isset($_REQUEST['id']) && $_REQUEST['id'] !== '' ? intval($_REQUEST['id']) : 0;

if ($id > 0) {
    $itemType = $_REQUEST['item_type'] ?? null;
    if ($itemType !== 'entry' && $itemType !== 'recurring') {
        $t = $_REQUEST['type'] ?? 'entry';
        $itemType = ($t === 'recurring') ? 'recurring' : 'entry';
    }
    if ($itemType === 'recurring') {
        $stmt = $db->prepare("SELECT * FROM person_income_recurring WHERE id = :id AND user_id = :userId");
    } else {
        $stmt = $db->prepare("SELECT * FROM person_income_entries WHERE id = :id AND user_id = :userId");
        $itemType = 'entry';
    }
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        echo json_encode([
            'success' => true,
            'title' => 'income',
            'item' => $row,
            'item_type' => $itemType,
            'notes' => []
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'title' => 'Not found',
            'notes' => ['No income record with this id for your account.']
        ]);
    }
    $db->close();
    exit;
}

$type = $_REQUEST['type'] ?? 'all';
$householdId = isset($_REQUEST['household_id']) && $_REQUEST['household_id'] !== '' ? intval($_REQUEST['household_id']) : null;
$startDate = $_REQUEST['start_date'] ?? null;
$endDate = $_REQUEST['end_date'] ?? null;

$entries = [];
$recurring = [];

if ($type === 'all' || $type === 'entry') {
    $sql = "SELECT e.*, h.name as household_name, s.name as subscription_name, c.code as currency_code
            FROM person_income_entries e
            INNER JOIN household h ON h.id = e.household_id
            INNER JOIN currencies c ON c.id = e.currency_id
            LEFT JOIN subscriptions s ON s.id = e.subscription_id
            WHERE e.user_id = :userId";

    if ($householdId !== null) {
        $sql .= " AND e.household_id = :householdId";
    }
    if ($startDate) {
        $sql .= " AND e.income_date >= :startDate";
    }
    if ($endDate) {
        $sql .= " AND e.income_date <= :endDate";
    }
    $sql .= " ORDER BY e.income_date DESC, e.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    if ($householdId !== null) {
        $stmt->bindValue(':householdId', $householdId, SQLITE3_INTEGER);
    }
    if ($startDate) {
        $stmt->bindValue(':startDate', $startDate, SQLITE3_TEXT);
    }
    if ($endDate) {
        $stmt->bindValue(':endDate', $endDate, SQLITE3_TEXT);
    }
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $entries[] = $row;
    }
}

if ($type === 'all' || $type === 'recurring') {
    $sql = "SELECT r.*, h.name as household_name, s.name as subscription_name, c.code as currency_code
            FROM person_income_recurring r
            INNER JOIN household h ON h.id = r.household_id
            INNER JOIN currencies c ON c.id = r.currency_id
            LEFT JOIN subscriptions s ON s.id = r.subscription_id
            WHERE r.user_id = :userId";

    if ($householdId !== null) {
        $sql .= " AND r.household_id = :householdId";
    }
    $sql .= " ORDER BY r.start_date DESC, r.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    if ($householdId !== null) {
        $stmt->bindValue(':householdId', $householdId, SQLITE3_INTEGER);
    }
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $recurring[] = $row;
    }
}

echo json_encode([
    'success' => true,
    'title' => 'income',
    'entries' => $entries,
    'recurring' => $recurring,
    'notes' => []
]);

$db->close();
