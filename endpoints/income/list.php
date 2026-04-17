<?php
require_once '../../includes/connect_endpoint.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => translate('session_expired', $i18n)]);
    exit();
}

$type = $_GET['type'] ?? 'all';
$householdId = isset($_GET['household_id']) && $_GET['household_id'] !== '' ? intval($_GET['household_id']) : null;
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;

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

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'entries' => $entries,
    'recurring' => $recurring
]);

$db->close();
?>
