<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Id");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true) ?: [];

// Extract user message across all common parameter names
$userMessage = trim($data["message"] ?? $data["prompt"] ?? $data["query"] ?? $_POST["message"] ?? $_POST["prompt"] ?? $_GET["message"] ?? "");

if (empty($userMessage)) {
    http_response_code(200);
    echo json_encode([
        "success" => false,
        "status" => "error",
        "response" => "Message cannot be empty.",
        "reply" => "Message cannot be empty.",
        "message" => "Message cannot be empty.",
        "answer" => "Message cannot be empty.",
        "data" => [
            "response" => "Message cannot be empty.",
            "reply" => "Message cannot be empty.",
            "message" => "Message cannot be empty."
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$systemPrompt = "You are Dr. Jarvis, the AI Diabetes Assistant for DiaWell. Give concise, practical advice on low-GI diet, blood sugar, and exercise in 3 short bullet points. Remind users to consult a doctor for medication changes.";

$ollamaUrl = "http://127.0.0.1:11434/api/chat";
$model = "qwen2.5:0.5b";

$payload = json_encode([
    "model" => $model,
    "messages" => [
        [
            "role" => "system",
            "content" => $systemPrompt
        ],
        [
            "role" => "user",
            "content" => $userMessage
        ]
    ],
    "stream" => false,
    "keep_alive" => "30m",
    "options" => [
        "num_gpu" => 0,
        "num_ctx" => 512,
        "num_predict" => 150,
        "temperature" => 0.7
    ]
]);

$ch = curl_init($ollamaUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 3
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$aiReply = "";

if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
    $decoded = json_decode($response, true);
    $aiReply = trim($decoded["message"]["content"] ?? "");
}

if (empty($aiReply)) {
    $aiReply = "I am currently reviewing your diabetes wellness query. Please ensure local Ollama is active or try again shortly.";
}

http_response_code(200);
echo json_encode([
    "success" => true,
    "status" => "success",
    "response" => $aiReply,
    "reply" => $aiReply,
    "message" => $aiReply,
    "answer" => $aiReply,
    "data" => [
        "response" => $aiReply,
        "reply" => $aiReply,
        "message" => $aiReply
    ]
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
