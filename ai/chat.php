<?php
/**
 * DEPRECATION NOTICE: DiaWell Legacy PHP-to-JSON AI Bridge
 * 
 * STATUS: DEPRECATED & REPLACED
 * MIGRATION TARGET: Python FastAPI Gateway (Port 8000)
 * 
 * Standard JSON:   POST http://localhost:8000/api/v1/chat
 * SSE Streaming:   POST http://localhost:8000/api/v1/chat/stream
 * Local Engine:    Ollama (Port 11434 / qwen2.5:3b)
 * 
 * This legacy script previously introduced unnecessary latency, double JSON serialization,
 * and lacked token streaming capabilities. Direct all client traffic to Port 8000.
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Id");
header("Content-Type: application/json; charset=UTF-8");
header("Deprecation: true");
header("Link: <http://localhost:8000/api/v1/chat>; rel=\"successor-version\"");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true) ?: [];
$userMessage = trim($data["message"] ?? $data["prompt"] ?? "");

// If invoked during transitional phase, gracefully bridge to Port 8000 while alerting client
$fastApiUrl = "http://127.0.0.1:8000/api/v1/chat";

if (!empty($userMessage)) {
    $payload = json_encode([
        "prompt" => $userMessage,
        "model" => $data["model"] ?? "qwen2.5:3b"
    ]);

    $ch = curl_init($fastApiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 2
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
        $decoded = json_decode($response, true);
        echo json_encode([
            "deprecated" => true,
            "migration_notice" => "Please update client URL to http://localhost:8000/api/v1/chat or http://localhost:8000/api/v1/chat/stream",
            "success" => true,
            "message" => "AI response generated via Python FastAPI Gateway.",
            "response" => $decoded["response"] ?? $decoded
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Default deprecation response if direct request or FastAPI offline
http_response_code(200);
echo json_encode([
    "deprecated" => true,
    "status" => "deprecated",
    "migration_guide" => [
        "target_gateway" => "http://localhost:8000",
        "json_endpoint" => "POST /api/v1/chat",
        "stream_endpoint" => "POST /api/v1/chat/stream",
        "documentation" => "Direct client connection to Python FastAPI Gateway is required for SSE token streaming."
    ],
    "message" => "This PHP middleware endpoint has been deprecated. Connect directly to FastAPI on Port 8000."
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
