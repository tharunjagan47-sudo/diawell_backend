<?php
/**
 * DiaWell AI Chat Endpoint (PHP to Python FastAPI Bridge)
 * 
 * Forwards chat questions to the local Python FastAPI AI microservice (http://127.0.0.1:8000/chat)
 * which communicates with locally installed Ollama models.
 * 
 * Accepts POST JSON:
 *   { "message": "Hello DiaWell AI" }
 * 
 * Returns JSON:
 *   {
 *       "success": true,
 *       "message": "AI response generated successfully.",
 *       "response": "Hello! I am DiaWell AI..."
 *   }
 */

// Enable CORS and define JSON response type
set_time_limit(180);
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Id");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

/**
 * Helper to emit standardized JSON response with required fields: success, message, response
 */
function sendResponse($success, $message, $response = null, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode([
        "success" => (bool) $success,
        "message" => (string) $message,
        "response" => $response
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Enforce POST HTTP method
$method = $_SERVER["REQUEST_METHOD"] ?? "GET";
if ($method !== "POST") {
    sendResponse(false, "Method not allowed. Only POST requests are accepted.", null, 405);
}

// 2. Read and validate raw JSON input
$rawInput = file_get_contents("php://input");
if ($rawInput === false || trim($rawInput) === "") {
    sendResponse(false, "Request body is empty. Please provide a JSON payload with 'message'.", null, 400);
}

$data = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    sendResponse(false, "Invalid JSON format in request body. JSON parse error: " . json_last_error_msg(), null, 400);
}

// 3. Validate user message field
if (!isset($data["message"])) {
    sendResponse(false, "Missing required field 'message' in JSON request body.", null, 400);
}

$userMessage = trim((string) $data["message"]);
if ($userMessage === "") {
    sendResponse(false, "Message cannot be empty or blank.", null, 400);
}

// 4. Configure local Python AI microservice endpoint
$pythonHost = getenv("AI_SERVICE_URL") ?: "http://127.0.0.1:8000";
$pythonChatUrl = rtrim($pythonHost, "/") . "/chat";

// Build payload to forward to Python service
$forwardPayload = [
    "message" => $userMessage
];

// Optionally forward model if explicitly passed
if (!empty($data["model"]) && is_string($data["model"])) {
    $forwardPayload["model"] = trim($data["model"]);
}

$jsonPayload = json_encode($forwardPayload);

// 5. Use PHP cURL to forward request to local FastAPI service
$ch = curl_init($pythonChatUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $jsonPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Content-Length: " . strlen($jsonPayload),
        "Accept: application/json"
    ],
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 150
]);

$responseBody = curl_exec($ch);
$curlErrorNo = curl_errno($ch);
$curlErrorMsg = curl_error($ch);
$httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 6. Handle cURL and connection errors
if ($responseBody === false || $curlErrorNo !== 0) {
    sendResponse(
        false,
        "Cannot connect to local Python AI service at {$pythonChatUrl}. Please ensure FastAPI/Uvicorn is running on port 8000. (Details: {$curlErrorMsg})",
        null,
        503
    );
}

// 7. Parse response from Python FastAPI
$decodedResponse = json_decode($responseBody, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedResponse)) {
    sendResponse(
        false,
        "Malformed response received from Python AI service.",
        null,
        502
    );
}

// 8. Handle HTTP errors from FastAPI (e.g. 400, 500, 503)
if ($httpStatusCode < 200 || $httpStatusCode >= 300) {
    $errorMessage = !empty($decodedResponse["message"])
        ? $decodedResponse["message"]
        : "Python AI service returned HTTP status {$httpStatusCode}.";
    $errorDetails = isset($decodedResponse["response"]) ? $decodedResponse["response"] : null;
    
    $returnCode = ($httpStatusCode >= 400 && $httpStatusCode < 600) ? $httpStatusCode : 502;
    sendResponse(false, $errorMessage, $errorDetails, $returnCode);
}

// 9. Successfully received AI response
$success = isset($decodedResponse["success"]) ? (bool) $decodedResponse["success"] : true;
$message = isset($decodedResponse["message"]) ? (string) $decodedResponse["message"] : "AI response retrieved successfully.";
$replyContent = isset($decodedResponse["response"]) ? $decodedResponse["response"] : "";

// Optional non-blocking database logging if user_id is provided
$userId = $data["user_id"] ?? $_SERVER["HTTP_X_USER_ID"] ?? null;
if (!empty($userId) && is_numeric($userId)) {
    try {
        @require_once __DIR__ . '/../config/db.php';
        if (isset($pdo) && $pdo instanceof PDO) {
            $stmt = $pdo->prepare("INSERT INTO ai_chat_messages (user_id, sender, message) VALUES (:uid, 'user', :msg)");
            $stmt->execute([":uid" => (int) $userId, ":msg" => $userMessage]);
            
            $stmt2 = $pdo->prepare("INSERT INTO ai_chat_messages (user_id, sender, message) VALUES (:uid, 'ai', :msg)");
            $stmt2->execute([":uid" => (int) $userId, ":msg" => is_string($replyContent) ? $replyContent : json_encode($replyContent)]);
        }
    } catch (Exception $dbEx) {
        // Logging failure should never block or affect the chat response
        error_log("Optional chat DB logging notice: " . $dbEx->getMessage());
    }
}

// 10. Return final JSON response to Postman client
sendResponse($success, $message, $replyContent, 200);
