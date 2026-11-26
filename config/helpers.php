<?php
// Suppress HTML error output so PHP warnings/notices never corrupt JSON responses
@ini_set("display_errors", "0");
@ini_set("html_errors", "0");
error_reporting(E_ALL);

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    error_log("PHP Error [{$severity}] {$message} in {$file}:{$line}");
    return true;
});

set_exception_handler(function ($exception) {
    error_log("Uncaught Exception: " . $exception->getMessage());
    if (!headers_sent()) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(500);
    }
    echo json_encode([
        "success" => false,
        "status" => "error",
        "message" => "Internal server error. Please try again."
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log("Fatal Error: {$err['message']} in {$err['file']}:{$err['line']}");
        if (!headers_sent()) {
            header("Content-Type: application/json; charset=UTF-8");
            http_response_code(500);
        }
        echo json_encode([
            "success" => false,
            "status" => "error",
            "message" => "Server error encountered. Please try again."
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
});

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
if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
    http_response_code(200);
    exit;
}

/**
 * Standard JSON response helper
 */
function respond($success, $message, $statusCode = 200, $data = null) {
    if (!headers_sent()) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code($statusCode);
    }

    $response = [
        "success" => (bool) $success,
        "status" => $success ? "success" : "error",
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
    $isHttps = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off")
        || (($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https");
    $scheme = $isHttps ? "https" : "http";
    $host = $_SERVER["HTTP_HOST"] ?? "localhost";
    return $scheme . "://" . $host . "/DiaWell_backend";
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

/**
 * Checks whether the incoming request explicitly asks to remove the profile photo.
 */
function isRemovePhotoRequest($method, $data = []) {
    if ($method === "DELETE") {
        return true;
    }
    if (!is_array($data)) {
        return false;
    }
    $action = strtolower(trim((string) ($data["action"] ?? "")));
    return $action === "remove" || $action === "delete"
        || !empty($data["remove_photo"]) || !empty($data["remove"]);
}

/**
 * Clears both stored photo file and Base64 column for the specified user.
 * Returns null if user does not exist, or true on success.
 */
function clearUserProfilePhoto($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT id, profile_photo FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([":id" => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        return null;
    }

    if (!empty($user["profile_photo"])) {
        $photoFile = __DIR__ . "/../" . ltrim($user["profile_photo"], "/");
        if (is_file($photoFile)) {
            @unlink($photoFile);
        }
    }

    $clearStmt = $pdo->prepare("UPDATE users SET profile_photo = NULL, profile_pic = NULL WHERE id = :id");
    $clearStmt->execute([":id" => $userId]);

    return true;
}




/**
 * Fast check to see if an external microservice is listening before initiating cURL.
 * Prevents long 3-6 second hangs on localhost/closed ports on Windows.
 */
function isMicroserviceAvailable($url = "http://127.0.0.1:8001", $timeout = 0.05) {
    $parsed = parse_url($url);
    $host = $parsed["host"] ?? "127.0.0.1";
    $port = $parsed["port"] ?? 8001;
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return true;
    }
    return false;
}
