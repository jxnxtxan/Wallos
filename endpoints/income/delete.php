<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$id = intval($data['id'] ?? 0);
$type = $data['type'] ?? 'entry';

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n)]);
    exit();
}

if ($type === 'recurring') {
    $stmt = $db->prepare("DELETE FROM person_income_recurring WHERE id = :id AND user_id = :userId");
} else {
    $stmt = $db->prepare("DELETE FROM person_income_entries WHERE id = :id AND user_id = :userId");
}
$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$ok = $stmt->execute();

header('Content-Type: application/json');
if ($ok) {
    echo json_encode(['success' => true, 'message' => translate('success', $i18n)]);
} else {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n)]);
}

$db->close();
?>
