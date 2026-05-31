<?php
/*
This API Endpoint accepts both POST and GET requests.
It receives the following parameters:
- api_key: the API key of the user (string).
- scope: time window for income and (where applicable) recurring matching: "month" (default), "year", "range", or "all".
- start_date: required when scope is "range" (YYYY-MM-DD).
- end_date: required when scope is "range" (YYYY-MM-DD).

It returns a JSON object with the following properties:
- success: whether the request was successful (boolean).
- title: the title of the response (string).
- ledger: object with per-member subscription and income totals in the user's main currency (see buildLedgerData).
- notes: warning messages or additional information (array).

The ledger object includes:
- members: array of { household_id, name, subscription_breakdown, subscriptions_total, income_total, net_difference }.
- main_currency_id, main_currency_code, main_currency_symbol.
- grand_subscriptions_total, grand_income_total, grand_net_difference.
- scope, range_start, range_end.

Example response:
{
  "success": true,
  "title": "ledger",
  "ledger": { "members": [], "main_currency_code": "EUR", "grand_net_difference": 0 },
  "notes": []
}
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/ledger_calculations.php';
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
$scope = $_REQUEST['scope'] ?? 'month';
$startDate = $_REQUEST['start_date'] ?? null;
$endDate = $_REQUEST['end_date'] ?? null;

if (!in_array($scope, ['month', 'year', 'range', 'all'], true)) {
    $scope = 'month';
}

if ($scope === 'range' && (!$startDate || !$endDate)) {
    echo json_encode([
        'success' => false,
        'title' => 'Missing parameters',
        'notes' => ['start_date and end_date are required when scope is "range".']
    ]);
    exit;
}

$data = buildLedgerData($db, $userId, $scope, $startDate, $endDate);

echo json_encode([
    'success' => true,
    'title' => 'ledger',
    'ledger' => $data,
    'notes' => []
]);

$db->close();
