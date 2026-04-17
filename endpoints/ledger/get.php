<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/ledger_calculations.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => translate('session_expired', $i18n)]);
    exit();
}

$scope = $_GET['scope'] ?? 'month';
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;

if (!in_array($scope, ['month', 'range', 'all'])) {
    $scope = 'month';
}

if ($scope === 'range' && (!$startDate || !$endDate)) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => translate('fill_mandatory_fields', $i18n)
    ]);
    exit();
}

$data = buildLedgerData($db, $userId, $scope, $startDate, $endDate);

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'ledger' => $data
]);

$db->close();
?>
