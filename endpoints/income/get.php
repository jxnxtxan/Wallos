<?php
require_once '../../includes/connect_endpoint.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => translate('session_expired', $i18n)]);
    exit();
}

$id = intval($_GET['id'] ?? 0);
$type = $_GET['type'] ?? 'entry';

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n)]);
    exit();
}

if ($type === 'recurring') {
    $stmt = $db->prepare("SELECT * FROM person_income_recurring WHERE id = :id AND user_id = :userId");
} else {
    $stmt = $db->prepare("SELECT * FROM person_income_entries WHERE id = :id AND user_id = :userId");
}
$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$row = $result->fetchArray(SQLITE3_ASSOC);

header('Content-Type: application/json');
if ($row) {
    echo json_encode(['success' => true, 'item' => $row, 'type' => $type]);
} else {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n)]);
}

$db->close();
?>
