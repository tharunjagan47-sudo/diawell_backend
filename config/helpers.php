<?php
// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable CORS for mobile apps, emulators, and Postman
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Id");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

/**
 * Standard JSON response helper
 */
function respond($success, $message, $statusCode = 200, $data = null) {
    http_response_code($statusCode);

    $response = [
        "success" => (bool) $success,
        "message" => (string) $message
    ];

    if ($data !== null) {
        $response["data"] = $data;
    }

    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Reads request data from JSON body or POST form data
 */
function getRequestData() {
    $rawInput = file_get_contents("php://input");
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
    }
    return is_array($_POST) ? $_POST : [];
}

/**
 * Resolves current user ID from payload, query param, POST, header, or session
 */
function getUserId($input = []) {
    if (isset($input["user_id"]) && is_numeric($input["user_id"])) {
        return (int) $input["user_id"];
    }
    if (isset($_GET["user_id"]) && is_numeric($_GET["user_id"])) {
        return (int) $_GET["user_id"];
    }
    if (isset($_POST["user_id"]) && is_numeric($_POST["user_id"])) {
        return (int) $_POST["user_id"];
    }
    if (isset($_SERVER["HTTP_X_USER_ID"]) && is_numeric($_SERVER["HTTP_X_USER_ID"])) {
        return (int) $_SERVER["HTTP_X_USER_ID"];
    }
    if (isset($_SESSION["user_id"]) && is_numeric($_SESSION["user_id"])) {
        return (int) $_SESSION["user_id"];
    }
    return null;
}

/**
 * Parses numeric values safely from strings like "165 cm", "68 kg", "128 mg/dL", "30 years"
 */
function parseNumericValue($value) {
    if ($value === null || $value === "") {
        return null;
    }
    if (is_numeric($value)) {
        return (float) $value;
    }
    if (is_string($value)) {
        // Extract first numeric match (integer or decimal)
        if (preg_match('/[-+]?[0-9]*\.?[0-9]+/', $value, $matches)) {
            return (float) $matches[0];
        }
    }
    return null;
}

/**
 * Normalize diabetes type from UI variants (e.g. "LADA / Other" vs "LADA/Other")
 */
function normalizeDiabetesType($type) {
    if (!is_string($type)) {
        return null;
    }
    $trimmed = trim($type);
    if (strcasecmp($trimmed, "LADA / Other") === 0 || strcasecmp($trimmed, "LADA/Other") === 0) {
        return "LADA / Other";
    }
    $allowed = ["Type 1", "Type 2", "Gestational", "Pre-diabetes", "LADA / Other"];
    foreach ($allowed as $item) {
        if (strcasecmp($trimmed, $item) === 0) {
            return $item;
        }
    }
    return null;
}

/**
 * Encodes array/object to JSON string for DB storage, or decodes if string
 */
function encodeDbJson($value) {
    if ($value === null) {
        return null;
    }
    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    if (is_string($value)) {
        return $value;
    }
    return json_encode($value);
}

/**
 * Decodes DB JSON string to PHP array
 */
function decodeDbJson($value) {
    if (empty($value)) {
        return [];
    }
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode($value, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return $decoded;
    }
    // If comma-separated string
    if (is_string($value) && strpos($value, ",") !== false) {
        return array_map('trim', explode(',', $value));
    }
    return [$value];
}

/**
 * Returns base URL of server
 */
function getBaseUrl() {
    $host = $_SERVER["HTTP_HOST"] ?? "localhost";
    return "http://" . $host . "/DiaWell_backend";
}

/**
 * Returns full profile photo URL from relative path
 */
function getProfilePhotoUrl($path) {
    if (empty($path)) {
        return null;
    }
    return getBaseUrl() . "/" . ltrim($path, "/");
}


