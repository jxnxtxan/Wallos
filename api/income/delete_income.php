<?php
/*
This API Endpoint accepts POST requests only (JSON or form body).
It receives the following parameters:
- api_key: the API key of the user (string).
- id: income record id (integer).
- type: "entry" (one-time) or "recurring" (default "entry").

It returns a JSON object with success, title, message, and notes array.
*/

require_once '../../includes/connect_endpoint.php';
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
$id = intval($params['id'] ?? 0);
$type = $params['type'] ?? 'entry';

if ($id <= 0) {
    echo json_encode([
        'success' => false,
        'title' => 'Validation',
        'message' => 'Parameter id is required.',
        'notes' => []
    ]);
    exit;
}

if ($type === 'recurring') {
    $stmt = $db->prepare("DELETE FROM person_income_recurring WHERE id = :id AND user_id = :userId");
} else {
    $stmt = $db->prepare("DELETE FROM person_income_entries WHERE id = :id AND user_id = :userId");
}
$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$ok = $stmt->execute();

if ($ok) {
    echo json_encode([
        'success' => true,
        'title' => 'Deleted',
        'message' => 'Income record deleted.',
        'notes' => []
    ]);
} else {
    echo json_encode([
        'success' => false,
        'title' => 'Database error',
        'message' => 'Could not delete income.',
        'notes' => []
    ]);
}

$db->close();
