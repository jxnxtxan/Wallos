<?php

/**
 * Merge query, form POST, and JSON body (for application/json) into one array.
 * Use for API POST endpoints that accept JSON or form data.
 */
function wallos_api_merge_request_params()
{
    $params = array_merge($_GET, $_POST);
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $params = array_merge($params, $decoded);
        }
    }
    return $params;
}

/**
 * Resolve user row by API key, or null if missing/invalid.
 */
function wallos_api_user_by_key(SQLite3 $db, $apiKey)
{
    if ($apiKey === null || $apiKey === '') {
        return null;
    }
    $stmt = $db->prepare("SELECT * FROM user WHERE api_key = :apiKey");
    $stmt->bindValue(':apiKey', $apiKey);
    $result = $stmt->execute();
    $user = $result->fetchArray(SQLITE3_ASSOC);
    return $user ?: null;
}
